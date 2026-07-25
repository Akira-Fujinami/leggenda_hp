import { randomUUID } from "node:crypto";
import { stat, unlink } from "node:fs/promises";
import path from "node:path";
import type { Page } from "playwright";
import { getActiveContextCount, withPage } from "./browser.js";
import { env } from "./env.js";
import { PhaseError, ScreenshotResourceExhaustedError } from "./errorClassification.js";
import { logMemorySnapshot } from "./memoryLog.js";
import { navigateResilient, type NavigationStatus } from "./navigation.js";
import { ensureDir, relativeStoragePath, screenshotsDir } from "./storage.js";

export type Device = "desktop" | "mobile";

const VIEWPORTS: Record<Device, { width: number; height: number }> = {
  desktop: { width: 1440, height: 1000 },
  mobile: { width: 390, height: 844 },
};

export type ScreenshotStatus = "ok" | "partial";

/**
 * "full": 要求どおりdocument全体をfullPageで撮影できた。
 * "clip": 高さ/ピクセル数上限超過のため、viewport幅×縮小した高さでクリップ撮影した。
 * "viewport": 上限内での撮影が繰り返し失敗し、最終手段として現在の画面
 *   (スクロールしない1画面分)のみを撮影した。
 */
export type CaptureMode = "full" | "clip" | "viewport";

export interface ScreenshotResult {
  storagePath: string;
  width: number;
  height: number;
  fileSize: number;
  mimeType: string;
  navigationStatus: NavigationStatus;
  warning: string | null;
  screenshotStatus: ScreenshotStatus;
  truncated: boolean;
  documentHeight: number;
  capturedHeight: number;
  captureMode: CaptureMode;
}

/**
 * 撮影直前のブレ要因を軽減する。CSSアニメーション/トランジション/caretの
 * 停止自体はpage.screenshot()の{animations:"disabled", caret:"hide"}に
 * 委ねる(独自CSSでanimation:noneを注入すると、animation-fill-mode:forwards
 * 依存で表示されている要素が初期状態(非表示)に戻ってしまう副作用があるため、
 * Playwright組み込みの安全な実装を優先する)。ここでは組み込みオプションが
 * カバーしないもの(prefers-reduced-motionメディアクエリに反応するCSS、
 * および動画の再生位置)のみ個別に処理する。
 */
async function stabilizeForScreenshot(page: Page): Promise<void> {
  await page.emulateMedia({ reducedMotion: "reduce" }).catch(() => undefined);
  await page
    .evaluate(() => {
      document.querySelectorAll("video").forEach((video) => {
        try {
          video.pause();
        } catch {
          // 再生停止に失敗しても撮影自体は継続する。
        }
      });
    })
    .catch(() => undefined);
}

/**
 * 実効ピクセル数(幅×高さ×deviceScaleFactor²、deviceScaleFactorは常に1に
 * 固定するため実質幅×高さ)がmaxPixelsを超えないような最大高さを返す。
 */
export function maxHeightForPixelBudget(width: number, maxPixels: number): number {
  return Math.max(1, Math.floor(maxPixels / width));
}

export interface CaptureAttempt {
  label: string;
  captureMode: CaptureMode;
  fullPage: boolean;
  viewportHeight: number;
  quality: number;
  truncated: boolean;
}

/**
 * 巨大ページ(例: 画像点数の多いECサイト)でメモリ予算を超過しないよう、
 * 束縛された段階的フォールバックで撮影する。無制限の再試行・無制限の
 * fullPage撮影・画像の分割合成は一切行わない。
 *
 * fullPage要求時の段階:
 *   1. primary: 高さ/ピクセル数上限まで(超過分はクリップ)、既定quality
 *   2. quality_retry: 同じ高さ、qualityを1段階下げて再試行
 *   3. height_halved: 高さを半減し、qualityは下げたまま再試行
 *   4. viewport_fallback: 現在の画面(1画面分)のみを撮影する最終手段
 * いずれかの段階でANALYZER_SCREENSHOT_MAX_BYTES以下に収まった時点で確定する。
 * 全段階が失敗/超過した場合はScreenshotResourceExhaustedErrorを投げる。
 */
export function buildAttempts(fullPage: boolean, viewportHeight: number, targetHeight: number, initialTruncated: boolean): CaptureAttempt[] {
  const reducedQuality = Math.max(40, env.ANALYZER_SCREENSHOT_QUALITY - 15);

  if (!fullPage) {
    return [
      { label: "primary", captureMode: "viewport", fullPage: false, viewportHeight, quality: env.ANALYZER_SCREENSHOT_QUALITY, truncated: false },
      { label: "quality_retry", captureMode: "viewport", fullPage: false, viewportHeight, quality: reducedQuality, truncated: false },
    ];
  }

  const primaryMode: CaptureMode = initialTruncated ? "clip" : "full";

  return [
    { label: "primary", captureMode: primaryMode, fullPage: !initialTruncated, viewportHeight: targetHeight, quality: env.ANALYZER_SCREENSHOT_QUALITY, truncated: initialTruncated },
    { label: "quality_retry", captureMode: primaryMode, fullPage: !initialTruncated, viewportHeight: targetHeight, quality: reducedQuality, truncated: initialTruncated },
    { label: "height_halved", captureMode: "clip", fullPage: false, viewportHeight: Math.max(1, Math.floor(targetHeight / 2)), quality: reducedQuality, truncated: true },
    { label: "viewport_fallback", captureMode: "viewport", fullPage: false, viewportHeight, quality: reducedQuality, truncated: true },
  ];
}

export async function captureScreenshot(
  url: string,
  device: Device,
  analysisId: number,
  websiteAnalysisId: number,
  fullPage: boolean,
  timeoutMs: number,
  requestId: string,
): Promise<ScreenshotResult> {
  const viewport = VIEWPORTS[device];

  return withPage(
    {
      viewport,
      afterClose: () => logMemorySnapshot("context_closed", requestId, { active_contexts: getActiveContextCount() }),
    },
    async (page) => {
      logMemorySnapshot("context_ready", requestId, { active_contexts: getActiveContextCount() });

      const navigation = await navigateResilient(page, url, timeoutMs);
      logMemorySnapshot("navigation_complete", requestId, { active_contexts: getActiveContextCount() });

      await stabilizeForScreenshot(page);

      // documentElement/bodyのどちらか大きい方を採用する(quirks mode等で
      // 片方しか実寸を反映しないページがあるため)。
      const documentSize = await page.evaluate(() => ({
        width: Math.max(document.documentElement.scrollWidth, document.body?.scrollWidth ?? 0),
        height: Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0),
      }));
      logMemorySnapshot("document_dimensions", requestId, {
        document_width: documentSize.width,
        document_height: documentSize.height,
        active_contexts: getActiveContextCount(),
      });

      const dir = screenshotsDir(analysisId, websiteAnalysisId);
      await ensureDir(dir);

      const heightCap = Math.min(
        env.ANALYZER_SCREENSHOT_MAX_HEIGHT,
        maxHeightForPixelBudget(viewport.width, env.ANALYZER_SCREENSHOT_MAX_PIXELS),
      );
      const targetHeight = fullPage ? Math.min(documentSize.height, heightCap) : viewport.height;
      const initialTruncated = fullPage && targetHeight < documentSize.height;

      const attempts = buildAttempts(fullPage, viewport.height, targetHeight, initialTruncated);

      const filename = `${randomUUID()}.jpg`;
      const absolutePath = path.join(dir, filename);

      logMemorySnapshot("before_screenshot", requestId, {
        document_width: documentSize.width,
        document_height: documentSize.height,
        active_contexts: getActiveContextCount(),
      });

      let lastErr: unknown;

      for (const attempt of attempts) {
        try {
          if (attempt.captureMode !== "full") {
            await page.setViewportSize({ width: viewport.width, height: attempt.viewportHeight });
          }

          try {
            await page.screenshot({
              path: absolutePath,
              fullPage: attempt.fullPage,
              type: "jpeg",
              quality: attempt.quality,
              timeout: env.ANALYZER_SCREENSHOT_TIMEOUT_MS,
              animations: "disabled",
              caret: "hide",
            });
          } catch (err) {
            // page.gotoは既に完了しているため、ここでのTimeoutError等は
            // ナビゲーションではなくpage.screenshot()自体の失敗。呼び出し元の
            // classifyError()がNAVIGATION_TIMEOUTへ誤分類しないよう、
            // captureフェーズとして明示する。
            throw new PhaseError("capture", err);
          }

          const stats = await stat(absolutePath);

          logMemorySnapshot("buffer_ready", requestId, {
            attempt: attempt.label,
            capture_mode: attempt.captureMode,
            captured_width: viewport.width,
            captured_height: attempt.viewportHeight,
            image_bytes: stats.size,
            active_contexts: getActiveContextCount(),
          });
          // Analyzerは画像をBase64へ変換せず共有Storageへ直接書き込むため、
          // base64_lengthは常に計測対象外(undefined)。この段階のログは
          // 「もしBase64化していたら増加していたはずの地点」を可視化するために
          // buffer_ready直後の同じ時点で残す。
          logMemorySnapshot("post_base64", requestId, {
            attempt: attempt.label,
            image_bytes: stats.size,
            active_contexts: getActiveContextCount(),
          });

          if (stats.size <= env.ANALYZER_SCREENSHOT_MAX_BYTES) {
            const truncated = attempt.truncated || attempt.captureMode === "viewport";

            return {
              storagePath: relativeStoragePath(absolutePath),
              width: viewport.width,
              height: attempt.viewportHeight,
              fileSize: stats.size,
              mimeType: "image/jpeg",
              navigationStatus: navigation.status,
              warning: navigation.warning,
              screenshotStatus: truncated ? "partial" : "ok",
              truncated,
              documentHeight: documentSize.height,
              capturedHeight: attempt.viewportHeight,
              captureMode: attempt.captureMode,
            };
          }

          lastErr = new Error(
            `screenshot exceeded ANALYZER_SCREENSHOT_MAX_BYTES (${stats.size} > ${env.ANALYZER_SCREENSHOT_MAX_BYTES})`,
          );
          await unlink(absolutePath).catch(() => undefined);
        } catch (err) {
          lastErr = err;
          await unlink(absolutePath).catch(() => undefined);
        }
      }

      throw new ScreenshotResourceExhaustedError(lastErr);
    },
  );
}

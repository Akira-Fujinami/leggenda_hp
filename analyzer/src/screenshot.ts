import { randomUUID } from "node:crypto";
import { stat } from "node:fs/promises";
import path from "node:path";
import type { Page } from "playwright";
import { withPage } from "./browser.js";
import { env } from "./env.js";
import { PhaseError } from "./errorClassification.js";
import { navigateResilient, type NavigationStatus } from "./navigation.js";
import { ensureDir, relativeStoragePath, screenshotsDir } from "./storage.js";

export type Device = "desktop" | "mobile";

const VIEWPORTS: Record<Device, { width: number; height: number }> = {
  desktop: { width: 1440, height: 1000 },
  mobile: { width: 390, height: 844 },
};

export type ScreenshotStatus = "ok" | "partial";

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
 * スクリーンショットを撮影し、Laravelとの共有Dockerボリュームへ直接保存する。
 * 画像バイト列はレスポンスに一切含めない(storage_path等のメタデータのみ返す)。
 * ファイル名はUUIDで、利用者入力(URL等)を一切パスに使わない。
 *
 * fullPage指定時にdocument高さがANALYZER_SCREENSHOT_MAX_HEIGHTを超える場合、
 * 無制限のfullPage撮影(低メモリなRender環境でのOOM/ハングの原因になり得る)
 * や画像の分割合成は行わず、viewport幅×上限高さでクリップして撮影し
 * truncated=trueの部分成功として返す。
 */
export async function captureScreenshot(
  url: string,
  device: Device,
  analysisId: number,
  websiteAnalysisId: number,
  fullPage: boolean,
  timeoutMs: number,
): Promise<ScreenshotResult> {
  const viewport = VIEWPORTS[device];

  return withPage({ viewport }, async (page) => {
    const navigation = await navigateResilient(page, url, timeoutMs);

    await stabilizeForScreenshot(page);

    // documentElement/bodyのどちらか大きい方を採用する(quirks mode等で
    // 片方しか実寸を反映しないページがあるため)。
    const documentSize = await page.evaluate(() => ({
      width: Math.max(document.documentElement.scrollWidth, document.body?.scrollWidth ?? 0),
      height: Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0),
    }));

    const dir = screenshotsDir(analysisId, websiteAnalysisId);
    await ensureDir(dir);

    const filename = `${randomUUID()}.jpg`;
    const absolutePath = path.join(dir, filename);

    const truncated = fullPage && documentSize.height > env.ANALYZER_SCREENSHOT_MAX_HEIGHT;
    const captureFullPage = fullPage && !truncated;

    if (truncated) {
      await page.setViewportSize({ width: viewport.width, height: env.ANALYZER_SCREENSHOT_MAX_HEIGHT });
    }

    try {
      await page.screenshot({
        path: absolutePath,
        fullPage: captureFullPage,
        type: "jpeg",
        quality: 80,
        timeout: env.ANALYZER_SCREENSHOT_TIMEOUT_MS,
        animations: "disabled",
        caret: "hide",
      });
    } catch (err) {
      // page.gotoは既に完了しているため、ここでのTimeoutErrorはナビゲーション
      // ではなくpage.screenshot()自体の失敗。呼び出し元のclassifyError()が
      // NAVIGATION_TIMEOUTへ誤分類しないよう、captureフェーズとして明示する。
      throw new PhaseError("capture", err);
    }

    const stats = await stat(absolutePath);
    const capturedHeight = truncated ? env.ANALYZER_SCREENSHOT_MAX_HEIGHT : fullPage ? documentSize.height : viewport.height;

    return {
      storagePath: relativeStoragePath(absolutePath),
      width: viewport.width,
      height: capturedHeight,
      fileSize: stats.size,
      mimeType: "image/jpeg",
      navigationStatus: navigation.status,
      warning: navigation.warning,
      screenshotStatus: truncated ? "partial" : "ok",
      truncated,
      documentHeight: documentSize.height,
      capturedHeight,
    };
  });
}

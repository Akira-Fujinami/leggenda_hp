import { randomUUID } from "node:crypto";
import { stat } from "node:fs/promises";
import path from "node:path";
import { withPage } from "./browser.js";
import { ensureDir, relativeStoragePath, screenshotsDir } from "./storage.js";

export type Device = "desktop" | "mobile";

const VIEWPORTS: Record<Device, { width: number; height: number }> = {
  desktop: { width: 1440, height: 1000 },
  mobile: { width: 390, height: 844 },
};

export interface ScreenshotResult {
  storagePath: string;
  width: number;
  height: number;
  fileSize: number;
  mimeType: string;
}

/**
 * スクリーンショットを撮影し、Laravelとの共有Dockerボリュームへ直接保存する。
 * 画像バイト列はレスポンスに一切含めない(storage_path等のメタデータのみ返す)。
 * ファイル名はUUIDで、利用者入力(URL等)を一切パスに使わない。
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
    await page.goto(url, { waitUntil: "networkidle", timeout: timeoutMs });

    const dir = screenshotsDir(analysisId, websiteAnalysisId);
    await ensureDir(dir);

    const filename = `${randomUUID()}.png`;
    const absolutePath = path.join(dir, filename);

    await page.screenshot({ path: absolutePath, fullPage });

    const stats = await stat(absolutePath);

    return {
      storagePath: relativeStoragePath(absolutePath),
      width: viewport.width,
      height: viewport.height,
      fileSize: stats.size,
      mimeType: "image/png",
    };
  });
}

import { mkdir } from "node:fs/promises";
import path from "node:path";
import { env } from "./env.js";

/**
 * Laravel側 (AnalysisStoragePaths) と同じディレクトリレイアウトを共有Docker
 * ボリューム上に再現する。analysis_id/website_analysis_idは数値のみを受け付け、
 * ユーザー入力を一切パスに含めないため、パストラバーサルの余地はない。
 */
export function screenshotsDir(analysisId: number, websiteAnalysisId: number): string {
  assertSafeId(analysisId);
  assertSafeId(websiteAnalysisId);

  return path.join(
    env.ANALYSIS_STORAGE_PATH,
    "analyses",
    String(analysisId),
    "websites",
    String(websiteAnalysisId),
    "screenshots",
  );
}

export async function ensureDir(dir: string): Promise<void> {
  await mkdir(dir, { recursive: true });
}

function assertSafeId(id: number): void {
  if (!Number.isInteger(id) || id <= 0) {
    throw new Error(`不正なIDです: ${id}`);
  }
}

/**
 * Laravelのfilesystems.php 'analysis' diskからの相対パス
 * (analyses/{id}/websites/{id}/screenshots/{file}) を返す。
 * レスポンスにはこの相対パスのみを含め、コンテナ内の絶対パスは含めない。
 */
export function relativeStoragePath(absolutePath: string): string {
  return path.relative(env.ANALYSIS_STORAGE_PATH, absolutePath).split(path.sep).join("/");
}

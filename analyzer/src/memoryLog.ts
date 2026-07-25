import { logger } from "./logger.js";

/**
 * Analyzerが本番でRenderのメモリ上限(Analyzer exceeded its memory limit)に
 * 到達した障害の原因究明用に、screenshot処理の各段階でprocess.memoryUsage()と
 * 撮影寸法を構造化ログへ残す。HTML本文・Secret・Cookie・Tokenは一切含めない
 * (寸法・バイト数・件数などの安全な数値のみ)。
 */
export interface MemorySnapshotExtra {
  document_width?: number;
  document_height?: number;
  captured_width?: number;
  captured_height?: number;
  deviceScaleFactor?: number;
  image_bytes?: number;
  base64_length?: number;
  active_contexts?: number;
  queued_sessions?: number;
  capture_mode?: string;
  attempt?: string;
}

export function logMemorySnapshot(stage: string, requestId: string, extra: MemorySnapshotExtra = {}): void {
  const mem = process.memoryUsage();

  logger.info(
    {
      request_id: requestId,
      stage,
      rss: mem.rss,
      heapUsed: mem.heapUsed,
      heapTotal: mem.heapTotal,
      external: mem.external,
      arrayBuffers: mem.arrayBuffers,
      ...extra,
    },
    "screenshot_memory_snapshot",
  );
}

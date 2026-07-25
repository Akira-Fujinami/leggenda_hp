import { buildServer } from "./server.js";
import { env } from "./env.js";
import { logger } from "./logger.js";
import { closeBrowser } from "./browser.js";

if (!env.ANALYZER_TOKEN && env.NODE_ENV === "production") {
  logger.warn("ANALYZER_TOKEN is not set; the internal API is running without authentication.");
}

const app = buildServer();

app
  .listen({ port: env.PORT, host: env.HOST })
  .catch((err) => {
    logger.error({ err }, "failed_to_start_server");
    process.exit(1);
  });

// Renderの再起動要因(OOM/クラッシュ)調査のため、メモリ使用量だけは
// どのシャットダウン経路でも記録する(Secret・URL全体等は含まない)。
function safeMemoryUsage() {
  const mem = process.memoryUsage();
  return {
    rss_mb: Math.round(mem.rss / 1024 / 1024),
    heap_used_mb: Math.round(mem.heapUsed / 1024 / 1024),
    heap_total_mb: Math.round(mem.heapTotal / 1024 / 1024),
  };
}

const SHUTDOWN_HARD_TIMEOUT_MS = 10_000;

let shuttingDown = false;

async function shutdown(reason: string, exitCode: number): Promise<void> {
  if (shuttingDown) {
    return;
  }
  shuttingDown = true;

  logger.info({ reason, memory: safeMemoryUsage() }, "shutting_down");

  const hardTimeout = setTimeout(() => {
    logger.error({ reason }, "shutdown_timed_out_forcing_exit");
    process.exit(exitCode);
  }, SHUTDOWN_HARD_TIMEOUT_MS);
  hardTimeout.unref();

  try {
    await app.close();
  } catch (err) {
    logger.error({ err }, "error_closing_server_during_shutdown");
  }

  try {
    await closeBrowser();
  } catch (err) {
    logger.error({ err }, "error_closing_browser_during_shutdown");
  }

  clearTimeout(hardTimeout);
  process.exit(exitCode);
}

for (const signal of ["SIGINT", "SIGTERM"] as const) {
  process.on(signal, () => {
    void shutdown(signal, 0);
  });
}

// 未捕捉の例外・Promise拒否を握りつぶしてプロセスを不整合な状態のまま
// 継続させない(Node.jsのプロセス状態は保証されなくなるため、継続は危険)。
// 安全な情報(例外class/message、メモリ使用量)のみをログした上でgraceful
// shutdown(browser.close()を含む)を試み、プロセスを終了する。
// これによりOSからのSIGKILLで強制終了されるより、Chromiumの子プロセスが
// orphanになりにくくなる。
process.on("uncaughtException", (err) => {
  logger.error(
    { exceptionClass: err?.constructor?.name ?? "Unknown", message: err?.message, memory: safeMemoryUsage() },
    "uncaught_exception",
  );
  void shutdown("uncaughtException", 1);
});

process.on("unhandledRejection", (reason) => {
  const err = reason instanceof Error ? reason : new Error(String(reason));
  logger.error(
    { exceptionClass: err.constructor?.name ?? "Unknown", message: err.message, memory: safeMemoryUsage() },
    "unhandled_rejection",
  );
  void shutdown("unhandledRejection", 1);
});

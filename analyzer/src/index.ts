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

for (const signal of ["SIGINT", "SIGTERM"] as const) {
  process.on(signal, async () => {
    logger.info({ signal }, "shutting_down");
    await app.close();
    await closeBrowser();
    process.exit(0);
  });
}

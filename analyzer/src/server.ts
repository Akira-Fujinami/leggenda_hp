import Fastify, { type FastifyReply, type FastifyRequest } from "fastify";
import sensible from "@fastify/sensible";
import { z } from "zod";
import { logger } from "./logger.js";
import { assertSafeUrl, SsrfError } from "./ssrf.js";
import { requireAnalyzerToken } from "./auth.js";
import { ConcurrencyLimiter, CONCURRENCY_LIMIT_EXCEEDED } from "./concurrency.js";
import { env } from "./env.js";
import { renderPage } from "./render.js";
import { captureScreenshot, type Device } from "./screenshot.js";
import { runLighthouse } from "./lighthouse.js";
import { detectTechnologies } from "./technology.js";
import { classifyError, type AnalyzerOperation } from "./errorClassification.js";
import { getActiveContextCount, isBrowserConnected } from "./browser.js";
import { logMemorySnapshot } from "./memoryLog.js";

const renderRequestSchema = z.object({
  url: z.string().min(1),
  timeout_ms: z.coerce.number().int().positive().max(180_000).default(60_000),
  max_html_bytes: z.coerce.number().int().positive().optional(),
});

const screenshotRequestSchema = z.object({
  url: z.string().min(1),
  device: z.enum(["desktop", "mobile"]).default("desktop"),
  full_page: z.boolean().default(true),
  analysis_id: z.coerce.number().int().positive(),
  website_analysis_id: z.coerce.number().int().positive(),
});

const lighthouseRequestSchema = z.object({
  url: z.string().min(1),
  timeout_ms: z.coerce.number().int().positive().max(180_000).default(60_000),
});

const technologyRequestSchema = z.object({
  url: z.string().min(1),
  html: z.string().optional(),
});

function safeHost(url: string): string {
  try {
    return new URL(url).host;
  } catch {
    return "[unparseable-url]";
  }
}

function memoryUsageMb(): { rss_mb: number; heap_used_mb: number } {
  const mem = process.memoryUsage();
  return {
    rss_mb: Math.round(mem.rss / 1024 / 1024),
    heap_used_mb: Math.round(mem.heapUsed / 1024 / 1024),
  };
}

/**
 * OOM・クラッシュ・Render instance再起動の原因調査用に、operation単位の
 * 実行結果を構造化ログへ残す。URL全体(query等に機密情報を含み得る)は
 * 記録せずhostのみとし、HTML本文・Secret・Tokenは一切含めない。
 */
function logOperationOutcome(params: {
  operation: AnalyzerOperation;
  requestId: string;
  url: string;
  startedAt: number;
  limiter: ConcurrencyLimiter;
  outcome: "success" | "failed" | "too_busy";
  code?: string;
  // screenshot操作固有の追加フィールド(viewport/document_height/
  // captured_height/full_page/truncated/output_bytes等)。HTML本文・
  // Secret・Token・Cookieは絶対に含めないこと。
  extra?: Record<string, unknown>;
}): void {
  logger.info(
    {
      request_id: params.requestId,
      operation: params.operation,
      host: safeHost(params.url),
      elapsed_ms: Date.now() - params.startedAt,
      outcome: params.outcome,
      code: params.code,
      active_sessions: params.limiter.activeCount,
      queued_sessions: params.limiter.queuedCount,
      memory: memoryUsageMb(),
      ...params.extra,
    },
    "analyzer_operation_outcome",
  );
}

function sendValidationError(reply: FastifyReply, error: z.ZodError) {
  return reply.code(400).send({
    success: false,
    data: null,
    error: { code: "VALIDATION_ERROR", message: "リクエスト内容が不正です。", details: error.flatten() },
  });
}

function sendSsrfBlocked(reply: FastifyReply, err: SsrfError) {
  return reply.code(422).send({
    success: false,
    data: null,
    error: { code: "SSRF_BLOCKED", message: err.message },
  });
}

/**
 * Playwright/Lighthouse等が投げた例外を分類し、ユーザー向けの短いメッセージで
 * 応答する。生のエラーメッセージ・スタックトレースはlogger経由でのみ記録し、
 * レスポンスボディには含めない(秘密情報・内部実装の詳細を返さないため)。
 */
function sendClassifiedError(reply: FastifyReply, err: unknown, requestId: string, operation: AnalyzerOperation) {
  const classified = classifyError(err, operation);
  logger.error({ err, code: classified.code, operation, requestId }, "analyzer_operation_failed");

  return reply.code(500).send({
    success: false,
    data: null,
    error: { code: classified.code, message: classified.message, retryable: classified.retryable },
  });
}

export function buildServer() {
  const app = Fastify({
    loggerInstance: logger,
    disableRequestLogging: false,
    bodyLimit: 1 * 1024 * 1024,
  });

  app.register(sensible);

  const limiter = new ConcurrencyLimiter(
    env.ANALYZER_MAX_CONCURRENCY,
    env.ANALYZER_QUEUE_MAX_WAIT_MS,
    env.ANALYZER_QUEUE_MAX_SIZE,
  );

  // Renderのデフォルトヘルスチェック(GET /)向け。認証不要・副作用無し。
  // HEAD /はFastifyがGETルートから自動的に応答する。
  app.get("/", async () => {
    return { status: "ok", service: "analyzer" };
  });

  // livenessに近い軽量チェック(プロセスが生きているか)。processが実際に
  // ブラウザを起動・operationを処理できるかまでは保証しない
  // (health=200でも分析処理自体は失敗し得る)。Secret・内部パスは含めない。
  app.get("/health", async () => {
    return {
      success: true,
      data: {
        status: "ok",
        uptime_seconds: Math.round(process.uptime()),
        active_sessions: limiter.activeCount,
        queued_sessions: limiter.queuedCount,
        browser_connected: isBrowserConnected(),
        active_contexts: getActiveContextCount(),
      },
      error: null,
    };
  });

  app.register(async (analyze) => {
    analyze.addHook("preHandler", requireAnalyzerToken);
    registerAnalyzeRoutes(analyze, limiter);
  });

  app.setErrorHandler((error, request, reply) => {
    request.log.error({ err: error }, "unhandled_error");
    reply.code(500).send({
      success: false,
      data: null,
      error: { code: "INTERNAL_ERROR", message: "サーバー内部でエラーが発生しました。" },
    });
  });

  return app;
}

function registerAnalyzeRoutes(
  app: import("fastify").FastifyInstance,
  limiter: ConcurrencyLimiter,
) {
  app.post("/analyze/render", async (request: FastifyRequest, reply: FastifyReply) => {
    const parsed = renderRequestSchema.safeParse(request.body);
    if (!parsed.success) return sendValidationError(reply, parsed.error);

    const startedAt = Date.now();
    const logArgs = { operation: "render" as const, requestId: request.id, url: parsed.data.url, startedAt, limiter };

    try {
      await assertSafeUrl(parsed.data.url);
    } catch (err) {
      if (err instanceof SsrfError) return sendSsrfBlocked(reply, err);
      throw err;
    }

    let result;
    try {
      result = await limiter.run(() =>
        renderPage(parsed.data.url, {
          timeoutMs: parsed.data.timeout_ms,
          maxHtmlBytes: parsed.data.max_html_bytes ?? env.MAX_HTML_BYTES,
        }),
      );
    } catch (err) {
      const classified = classifyError(err, "render");
      logOperationOutcome({ ...logArgs, outcome: "failed", code: classified.code });
      return sendClassifiedError(reply, err, request.id, "render");
    }

    if (result === CONCURRENCY_LIMIT_EXCEEDED) {
      logOperationOutcome({ ...logArgs, outcome: "too_busy" });
      return sendTooBusy(reply);
    }

    logOperationOutcome({ ...logArgs, outcome: "success" });

    return reply.send({
      success: true,
      data: {
        html: result.html,
        final_url: result.finalUrl,
        http_status: result.httpStatus,
        load_time_ms: result.loadTimeMs,
        fixed_cta: result.fixedCta,
        navigation_status: result.navigationStatus,
        warning: result.warning,
      },
      error: null,
    });
  });

  app.post("/analyze/screenshot", async (request: FastifyRequest, reply: FastifyReply) => {
    const parsed = screenshotRequestSchema.safeParse(request.body);
    if (!parsed.success) return sendValidationError(reply, parsed.error);

    const startedAt = Date.now();
    const logArgs = { operation: "screenshot" as const, requestId: request.id, url: parsed.data.url, startedAt, limiter };

    logMemorySnapshot("request_received", request.id, {
      active_contexts: getActiveContextCount(),
      queued_sessions: limiter.queuedCount,
    });

    try {
      await assertSafeUrl(parsed.data.url);
    } catch (err) {
      if (err instanceof SsrfError) return sendSsrfBlocked(reply, err);
      throw err;
    }

    const screenshotLogExtra = {
      device: parsed.data.device,
      full_page: parsed.data.full_page,
    };

    logMemorySnapshot("queue_wait_start", request.id, {
      active_contexts: getActiveContextCount(),
      queued_sessions: limiter.queuedCount,
    });

    let result;
    try {
      result = await limiter.run(() =>
        captureScreenshot(
          parsed.data.url,
          parsed.data.device as Device,
          parsed.data.analysis_id,
          parsed.data.website_analysis_id,
          parsed.data.full_page,
          env.BROWSER_TIMEOUT_MS,
          request.id,
        ),
      );
    } catch (err) {
      const classified = classifyError(err, "screenshot");
      logOperationOutcome({ ...logArgs, outcome: "failed", code: classified.code, extra: screenshotLogExtra });
      return sendClassifiedError(reply, err, request.id, "screenshot");
    }

    if (result === CONCURRENCY_LIMIT_EXCEEDED) {
      logOperationOutcome({ ...logArgs, outcome: "too_busy", extra: screenshotLogExtra });
      return sendTooBusy(reply);
    }

    logOperationOutcome({
      ...logArgs,
      outcome: "success",
      extra: {
        ...screenshotLogExtra,
        document_height: result.documentHeight,
        captured_height: result.capturedHeight,
        truncated: result.truncated,
        capture_mode: result.captureMode,
        output_bytes: result.fileSize,
      },
    });

    const responseBody = {
      success: true,
      data: {
        storage_path: result.storagePath,
        width: result.width,
        height: result.height,
        file_size: result.fileSize,
        mime_type: result.mimeType,
        navigation_status: result.navigationStatus,
        warning: result.warning,
        screenshot_status: result.screenshotStatus,
        truncated: result.truncated,
        document_height: result.documentHeight,
        captured_height: result.capturedHeight,
        capture_mode: result.captureMode,
      },
      error: null,
    };

    logMemorySnapshot("before_response", request.id, {
      image_bytes: result.fileSize,
      active_contexts: getActiveContextCount(),
      queued_sessions: limiter.queuedCount,
    });

    const sent = reply.send(responseBody);

    logMemorySnapshot("request_complete", request.id, {
      active_contexts: getActiveContextCount(),
      queued_sessions: limiter.queuedCount,
    });

    return sent;
  });

  app.post("/analyze/lighthouse", async (request: FastifyRequest, reply: FastifyReply) => {
    const parsed = lighthouseRequestSchema.safeParse(request.body);
    if (!parsed.success) return sendValidationError(reply, parsed.error);

    const startedAt = Date.now();
    const logArgs = { operation: "lighthouse" as const, requestId: request.id, url: parsed.data.url, startedAt, limiter };

    try {
      await assertSafeUrl(parsed.data.url);
    } catch (err) {
      if (err instanceof SsrfError) return sendSsrfBlocked(reply, err);
      throw err;
    }

    let result;
    try {
      result = await limiter.run(() => runLighthouse(parsed.data.url, parsed.data.timeout_ms));
    } catch (err) {
      const classified = classifyError(err, "lighthouse");
      logOperationOutcome({ ...logArgs, outcome: "failed", code: classified.code });
      return sendClassifiedError(reply, err, request.id, "lighthouse");
    }

    if (result === CONCURRENCY_LIMIT_EXCEEDED) {
      logOperationOutcome({ ...logArgs, outcome: "too_busy" });
      return sendTooBusy(reply);
    }

    logOperationOutcome({ ...logArgs, outcome: "success" });

    return reply.send({
      success: true,
      data: {
        scores: result.scores,
        metrics: result.metrics,
        raw_report: result.rawReport,
        metadata: result.metadata,
      },
      error: null,
    });
  });

  app.post("/analyze/technology", async (request: FastifyRequest, reply: FastifyReply) => {
    const parsed = technologyRequestSchema.safeParse(request.body);
    if (!parsed.success) return sendValidationError(reply, parsed.error);

    const startedAt = Date.now();
    const logArgs = { operation: "technology" as const, requestId: request.id, url: parsed.data.url, startedAt, limiter };

    try {
      await assertSafeUrl(parsed.data.url);
    } catch (err) {
      if (err instanceof SsrfError) return sendSsrfBlocked(reply, err);
      throw err;
    }

    let result;
    try {
      result = await limiter.run(async () => {
        if (parsed.data.html !== undefined) {
          return { technologies: detectTechnologies(parsed.data.html), navigationStatus: null, warning: null };
        }

        const rendered = await renderPage(parsed.data.url, {
          timeoutMs: env.BROWSER_TIMEOUT_MS,
          maxHtmlBytes: env.MAX_HTML_BYTES,
        });

        return {
          technologies: detectTechnologies(rendered.html),
          navigationStatus: rendered.navigationStatus,
          warning: rendered.warning,
        };
      });
    } catch (err) {
      const classified = classifyError(err, "technology");
      logOperationOutcome({ ...logArgs, outcome: "failed", code: classified.code });
      return sendClassifiedError(reply, err, request.id, "technology");
    }

    if (result === CONCURRENCY_LIMIT_EXCEEDED) {
      logOperationOutcome({ ...logArgs, outcome: "too_busy" });
      return sendTooBusy(reply);
    }

    logOperationOutcome({ ...logArgs, outcome: "success" });

    return reply.send({
      success: true,
      data: {
        technologies: result.technologies,
        navigation_status: result.navigationStatus,
        warning: result.warning,
      },
      error: null,
    });
  });
}

function sendTooBusy(reply: FastifyReply) {
  return reply.code(503).send({
    success: false,
    data: null,
    error: { code: "TOO_BUSY", message: "analyzerが混雑しています。", retryable: true },
  });
}

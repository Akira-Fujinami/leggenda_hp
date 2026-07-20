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
import { classifyError } from "./errorClassification.js";

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
function sendClassifiedError(reply: FastifyReply, err: unknown, requestId: string) {
  const classified = classifyError(err);
  logger.error({ err, code: classified.code, requestId }, "analyzer_operation_failed");

  return reply.code(500).send({
    success: false,
    data: null,
    error: { code: classified.code, message: classified.message },
  });
}

export function buildServer() {
  const app = Fastify({
    loggerInstance: logger,
    disableRequestLogging: false,
    bodyLimit: 1 * 1024 * 1024,
  });

  app.register(sensible);

  const limiter = new ConcurrencyLimiter(env.ANALYZER_MAX_CONCURRENCY);

  app.get("/health", async () => {
    return {
      success: true,
      data: {
        status: "ok",
        uptime_seconds: Math.round(process.uptime()),
        active_sessions: limiter.activeCount,
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
      return sendClassifiedError(reply, err, request.id);
    }

    if (result === CONCURRENCY_LIMIT_EXCEEDED) {
      return reply.code(503).send({
        success: false,
        data: null,
        error: { code: "TOO_BUSY", message: "analyzerが混雑しています。" },
      });
    }

    return reply.send({
      success: true,
      data: {
        html: result.html,
        final_url: result.finalUrl,
        http_status: result.httpStatus,
        load_time_ms: result.loadTimeMs,
        fixed_cta: result.fixedCta,
      },
      error: null,
    });
  });

  app.post("/analyze/screenshot", async (request: FastifyRequest, reply: FastifyReply) => {
    const parsed = screenshotRequestSchema.safeParse(request.body);
    if (!parsed.success) return sendValidationError(reply, parsed.error);

    try {
      await assertSafeUrl(parsed.data.url);
    } catch (err) {
      if (err instanceof SsrfError) return sendSsrfBlocked(reply, err);
      throw err;
    }

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
        ),
      );
    } catch (err) {
      return sendClassifiedError(reply, err, request.id);
    }

    if (result === CONCURRENCY_LIMIT_EXCEEDED) {
      return reply.code(503).send({
        success: false,
        data: null,
        error: { code: "TOO_BUSY", message: "analyzerが混雑しています。" },
      });
    }

    return reply.send({
      success: true,
      data: {
        storage_path: result.storagePath,
        width: result.width,
        height: result.height,
        file_size: result.fileSize,
        mime_type: result.mimeType,
      },
      error: null,
    });
  });

  app.post("/analyze/lighthouse", async (request: FastifyRequest, reply: FastifyReply) => {
    const parsed = lighthouseRequestSchema.safeParse(request.body);
    if (!parsed.success) return sendValidationError(reply, parsed.error);

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
      return sendClassifiedError(reply, err, request.id);
    }

    if (result === CONCURRENCY_LIMIT_EXCEEDED) {
      return reply.code(503).send({
        success: false,
        data: null,
        error: { code: "TOO_BUSY", message: "analyzerが混雑しています。" },
      });
    }

    return reply.send({
      success: true,
      data: {
        scores: result.scores,
        metrics: result.metrics,
        raw_report: result.rawReport,
      },
      error: null,
    });
  });

  app.post("/analyze/technology", async (request: FastifyRequest, reply: FastifyReply) => {
    const parsed = technologyRequestSchema.safeParse(request.body);
    if (!parsed.success) return sendValidationError(reply, parsed.error);

    try {
      await assertSafeUrl(parsed.data.url);
    } catch (err) {
      if (err instanceof SsrfError) return sendSsrfBlocked(reply, err);
      throw err;
    }

    let result;
    try {
      result = await limiter.run(async () => {
        const html = parsed.data.html ?? (await renderPage(parsed.data.url, {
          timeoutMs: env.BROWSER_TIMEOUT_MS,
          maxHtmlBytes: env.MAX_HTML_BYTES,
        })).html;

        return detectTechnologies(html);
      });
    } catch (err) {
      return sendClassifiedError(reply, err, request.id);
    }

    if (result === CONCURRENCY_LIMIT_EXCEEDED) {
      return reply.code(503).send({
        success: false,
        data: null,
        error: { code: "TOO_BUSY", message: "analyzerが混雑しています。" },
      });
    }

    return reply.send({
      success: true,
      data: { technologies: result },
      error: null,
    });
  });
}

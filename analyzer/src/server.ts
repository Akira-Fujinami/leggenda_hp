import Fastify from "fastify";
import sensible from "@fastify/sensible";
import { z } from "zod";
import { logger } from "./logger.js";
import { assertSafeUrl, SsrfError } from "./ssrf.js";

const analyzeRequestSchema = z.object({
  url: z.string().min(1),
  analysis_id: z.union([z.string(), z.number()]),
  device: z.enum(["desktop", "mobile"]).optional().default("desktop"),
});

export function buildServer() {
  const app = Fastify({
    loggerInstance: logger,
    disableRequestLogging: false,
    bodyLimit: 1 * 1024 * 1024,
  });

  app.register(sensible);

  app.get("/health", async () => {
    return {
      success: true,
      data: {
        status: "ok",
        uptime_seconds: Math.round(process.uptime()),
      },
      error: null,
    };
  });

  const notImplemented = (routeName: string) =>
    async (
      request: import("fastify").FastifyRequest,
      reply: import("fastify").FastifyReply,
    ) => {
      const parseResult = analyzeRequestSchema.safeParse(request.body);
      if (!parseResult.success) {
        return reply.code(400).send({
          success: false,
          data: null,
          error: {
            code: "VALIDATION_ERROR",
            message: "リクエスト内容が不正です。",
            details: parseResult.error.flatten(),
          },
        });
      }

      try {
        await assertSafeUrl(parseResult.data.url);
      } catch (err) {
        if (err instanceof SsrfError) {
          request.log.warn({ route: routeName, analysisId: parseResult.data.analysis_id }, "ssrf_blocked");
          return reply.code(422).send({
            success: false,
            data: null,
            error: { code: "SSRF_BLOCKED", message: err.message },
          });
        }
        throw err;
      }

      // Playwright/Lighthouse本体の実装はPhase 3で行う。
      // Phase 0時点ではSSRF検証と契約(リクエスト/レスポンス形式)のみ確定させる。
      return reply.code(501).send({
        success: false,
        data: null,
        error: { code: "NOT_IMPLEMENTED", message: `${routeName} はまだ実装されていません。` },
      });
    };

  app.post("/analyze/render", notImplemented("render"));
  app.post("/analyze/screenshot", notImplemented("screenshot"));
  app.post("/analyze/lighthouse", notImplemented("lighthouse"));
  app.post("/analyze/technology", notImplemented("technology"));

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

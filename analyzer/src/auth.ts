import type { FastifyReply, FastifyRequest } from "fastify";
import { env } from "./env.js";

/**
 * X-Analyzer-Token による共有シークレット認証。
 * analyzerはDocker内部ネットワークからのみ到達可能な想定だが、
 * それでも防御の多層化としてトークン検証を必須にする。
 * トークン自体は絶対にログへ出力しない。
 */
export async function requireAnalyzerToken(
  request: FastifyRequest,
  reply: FastifyReply,
): Promise<FastifyReply | undefined> {
  if (!env.ANALYZER_TOKEN) {
    // トークン未設定(開発環境等)。本番相当ではANALYZER_TOKENの設定を必須にすること。
    return undefined;
  }

  const provided = request.headers["x-analyzer-token"];

  if (typeof provided !== "string" || provided.length === 0 || provided !== env.ANALYZER_TOKEN) {
    request.log.warn({ route: request.url }, "analyzer_auth_failed");
    return reply.code(401).send({
      success: false,
      data: null,
      error: { code: "UNAUTHORIZED", message: "認証に失敗しました。" },
    });
  }
}

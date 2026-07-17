import { afterAll, beforeAll, describe, expect, it } from "vitest";

// env.tsはモジュール読み込み時にprocess.envを評価するため、
// server.ts (ひいてはenv.ts) をimportするより前にトークンを設定する必要がある。
process.env.ANALYZER_TOKEN = "test-secret-token";

const { buildServer } = await import("../src/server.js");

describe("analyzer token authentication", () => {
  let app: Awaited<ReturnType<typeof buildServer>>;

  beforeAll(() => {
    app = buildServer();
  });

  afterAll(async () => {
    await app.close();
  });

  it("rejects requests with no token", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/render",
      payload: { url: "https://example.com" },
    });

    expect(response.statusCode).toBe(401);
    expect(response.json().error.code).toBe("UNAUTHORIZED");
  });

  it("rejects requests with an incorrect token", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/render",
      headers: { "x-analyzer-token": "wrong-token" },
      payload: { url: "https://example.com" },
    });

    expect(response.statusCode).toBe(401);
  });

  it("allows requests with the correct token past authentication", async () => {
    // ボディを不正にして、認証を通過した後の「次の層」(バリデーション)に
    // 到達したことを400で確認する(実際のレンダリングは発生させない)。
    const response = await app.inject({
      method: "POST",
      url: "/analyze/render",
      headers: { "x-analyzer-token": "test-secret-token" },
      payload: {},
    });

    expect(response.statusCode).toBe(400);
    expect(response.json().error.code).toBe("VALIDATION_ERROR");
  });

  it("health endpoint does not require a token", async () => {
    const response = await app.inject({ method: "GET", url: "/health" });

    expect(response.statusCode).toBe(200);
  });
});

import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { startFixtureServer, type FixtureServer } from "./helpers/fixtureServer.js";

const FIXTURE_HTML = `
  <html><head><title>Fixture Page</title></head>
  <body><h1>Hello from fixture</h1></body></html>
`;

const fixture: FixtureServer = await startFixtureServer(FIXTURE_HTML);
process.env.SSRF_TEST_ALLOWLIST = fixture.hostAndPort;
process.env.ANALYSIS_STORAGE_PATH = "/tmp/analysis-storage-test-resource-limit";
process.env.ANALYZER_TOKEN = "";
// どのquality/高さでも絶対に収まらない極端な上限にすることで、quality低下→
// height半減→viewportフォールバックの全段階が尽きた場合の挙動を決定的に検証する
// (実サイズはJPEG圧縮結果に依存するため、確実に失敗させるにはこの値まで
// 下げる必要がある)。
process.env.ANALYZER_SCREENSHOT_MAX_BYTES = "50";

const { buildServer } = await import("../src/server.js");
const { closeBrowser } = await import("../src/browser.js");

describe("screenshot resource-limit fallback exhaustion", () => {
  let app: Awaited<ReturnType<typeof buildServer>>;

  beforeAll(() => {
    app = buildServer();
  });

  afterAll(async () => {
    await app.close();
    await closeBrowser();
    await fixture.close();
  });

  it("returns SCREENSHOT_RESOURCE_LIMIT (not a generic failure) once every fallback attempt still exceeds the byte budget", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/screenshot",
      payload: {
        url: `${fixture.origin}/`,
        device: "desktop",
        full_page: true,
        analysis_id: 1,
        website_analysis_id: 1,
      },
    });

    expect(response.statusCode).toBe(500);
    const body = response.json();
    expect(body.success).toBe(false);
    expect(body.error.code).toBe("SCREENSHOT_RESOURCE_LIMIT");
    expect(body.error.retryable).toBe(true);

    // 画像バイト列やBase64文字列がエラーレスポンスに含まれていないこと
    // (Base64 JSON方式を使っていないことの回帰テスト)。
    const raw = JSON.stringify(body);
    expect(raw.length).toBeLessThan(2000);
    expect(body.data).toBeNull();
  }, 30_000);
});

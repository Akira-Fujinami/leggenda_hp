import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { startFixtureServer, type FixtureServer } from "./helpers/fixtureServer.js";

const FIXTURE_HTML = `
  <html><head><title>Fixture Page</title>
    <meta name="generator" content="WordPress 6.4">
  </head><body><h1>Hello from fixture</h1></body></html>
`;

// env.ts はモジュール読み込み時に process.env を評価するため、fixtureサーバーの
// ポートが決まった後、server.ts をimportするより前に設定する必要がある。
// ANALYZER_TOKENは(本番運用中のコンテナ環境変数がテストプロセスにも継承されて
// いる場合に備えて)明示的に空にし、このファイルでは認証を対象外にする
// (認証自体はauth.test.tsで別途検証済み)。
const fixture: FixtureServer = await startFixtureServer(FIXTURE_HTML);
process.env.SSRF_TEST_ALLOWLIST = fixture.hostAndPort;
process.env.ANALYSIS_STORAGE_PATH = "/tmp/analysis-storage-test";
process.env.ANALYZER_TOKEN = "";

const { buildServer } = await import("../src/server.js");
const { closeBrowser } = await import("../src/browser.js");

describe("analyzer routes", () => {
  let app: Awaited<ReturnType<typeof buildServer>>;

  beforeAll(() => {
    app = buildServer();
  });

  afterAll(async () => {
    await app.close();
    await closeBrowser();
    await fixture.close();
  });

  it("health check succeeds", async () => {
    const response = await app.inject({ method: "GET", url: "/health" });

    expect(response.statusCode).toBe(200);
    expect(response.json().data.status).toBe("ok");
  });

  it("rejects render requests targeting blocked hosts before any network access", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/render",
      payload: { url: "http://169.254.169.254/latest/meta-data/" },
    });

    expect(response.statusCode).toBe(422);
    expect(response.json().error.code).toBe("SSRF_BLOCKED");
  });

  it("rejects screenshot requests targeting docker service hostnames", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/screenshot",
      payload: { url: "http://backend:8000", device: "desktop", analysis_id: 1, website_analysis_id: 1 },
    });

    expect(response.statusCode).toBe(422);
  });

  it("rejects malformed request bodies with a validation error", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/screenshot",
      payload: { url: fixture.origin }, // analysis_id/website_analysis_id missing
    });

    expect(response.statusCode).toBe(400);
    expect(response.json().error.code).toBe("VALIDATION_ERROR");
  });

  it("renders the local fixture page end-to-end", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/render",
      payload: { url: `${fixture.origin}/`, timeout_ms: 20_000 },
    });

    expect(response.statusCode).toBe(200);
    const body = response.json();
    expect(body.success).toBe(true);
    expect(body.data.html).toContain("Hello from fixture");
    expect(body.data.http_status).toBe(200);
  }, 30_000);

  it("captures a screenshot of the local fixture page and stores it on disk", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/screenshot",
      payload: {
        url: `${fixture.origin}/`,
        device: "mobile",
        analysis_id: 1,
        website_analysis_id: 1,
      },
    });

    expect(response.statusCode).toBe(200);
    const body = response.json();
    expect(body.data.storage_path).toMatch(/^analyses\/1\/websites\/1\/screenshots\/.+\.png$/);
    expect(body.data.width).toBe(390);
    expect(body.data.height).toBe(844);
    expect(body.data.file_size).toBeGreaterThan(0);
  }, 30_000);

  it("detects technology signatures on the fixture page's html", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/technology",
      payload: { url: `${fixture.origin}/`, html: FIXTURE_HTML },
    });

    expect(response.statusCode).toBe(200);
    const names = response.json().data.technologies.map((t: { name: string }) => t.name);
    expect(names).toContain("WordPress");
  });
});

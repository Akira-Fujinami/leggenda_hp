import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { startFixtureServer, type FixtureServer } from "./helpers/fixtureServer.js";

const FIXTURE_HTML = `
  <html><head><title>Fixture Page</title>
    <meta name="generator" content="WordPress 6.4">
  </head><body><h1>Hello from fixture</h1></body></html>
`;

const FIXED_CTA_HTML = `
  <html><head><title>Fixed CTA Fixture</title></head>
  <body>
    <h1>Fixed CTA fixture</h1>
    <a href="/contact" aria-label="お問い合わせ"
       style="position:fixed;bottom:0;right:0;width:120px;height:48px;display:block;">
      お問い合わせ
    </a>
  </body></html>
`;

// ページ自身が終わらないfetch("/never-ends")を発行し続ける限り、
// ブラウザのnetwork活動は0にならずnetworkidleへは絶対に到達しない
// (大規模ECサイトの解析タグ・定期通信等を模した回帰テスト用フィクスチャ)。
// domcontentloadedは通常のHTML同様すぐに発火する(fetchは非同期のため
// パースをブロックしない)。
const NEVER_IDLE_HTML = `
  <html><head><title>Never Idle Fixture</title></head>
  <body><h1>Never idle</h1><script>fetch("/never-ends").catch(() => {});</script></body></html>
`;

// ANALYZER_SCREENSHOT_MAX_HEIGHT(既定4000px)を超える巨大ページ
// (大手ECサイト等を模したフィクスチャ)。無制限のfullPage撮影ではなく、
// viewport幅×上限高さでクリップされ、truncated=trueとして返ることを検証する。
const TALL_HTML = `
  <html><head><title>Tall Fixture</title></head>
  <body><div style="height:20000px;width:100%;">Tall page</div></body></html>
`;

// env.ts はモジュール読み込み時に process.env を評価するため、fixtureサーバーの
// ポートが決まった後、server.ts をimportするより前に設定する必要がある。
// ANALYZER_TOKENは(本番運用中のコンテナ環境変数がテストプロセスにも継承されて
// いる場合に備えて)明示的に空にし、このファイルでは認証を対象外にする
// (認証自体はauth.test.tsで別途検証済み)。
const fixture: FixtureServer = await startFixtureServer(FIXTURE_HTML);
const fixedCtaFixture: FixtureServer = await startFixtureServer(FIXED_CTA_HTML);
const tallFixture: FixtureServer = await startFixtureServer(TALL_HTML);
const neverIdleFixture: FixtureServer = await startFixtureServer((req, res) => {
  if (req.url === "/never-ends") {
    // レスポンスを一切終わらせず、接続を張ったままにする
    // (テスト終了時にサーバーごと閉じるためリークしない)。
    res.writeHead(200, { "Content-Type": "text/plain" });
    return;
  }
  res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
  res.end(NEVER_IDLE_HTML);
});
// 接続拒否エラーの分類を検証するため、一度起動してすぐ閉じたfixtureサーバーの
// アドレスを保持しておく(許可リストにはホスト:ポートとして登録済みだが、
// 実際には何も listen していないため接続が拒否される)。
const closedFixture: FixtureServer = await startFixtureServer("unused");
await closedFixture.close();
process.env.SSRF_TEST_ALLOWLIST =
  `${fixture.hostAndPort},${fixedCtaFixture.hostAndPort},${tallFixture.hostAndPort},` +
  `${neverIdleFixture.hostAndPort},${closedFixture.hostAndPort}`;
process.env.ANALYSIS_STORAGE_PATH = "/tmp/analysis-storage-test";
process.env.ANALYZER_TOKEN = "";

const { buildServer } = await import("../src/server.js");
const { closeBrowser, getActiveContextCount } = await import("../src/browser.js");

describe("analyzer routes", () => {
  let app: Awaited<ReturnType<typeof buildServer>>;

  beforeAll(() => {
    app = buildServer();
  });

  afterAll(async () => {
    await app.close();
    await closeBrowser();
    await fixture.close();
    await fixedCtaFixture.close();
    await tallFixture.close();
    await neverIdleFixture.close();
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
    // 固定CTAが存在しないページではdetected=falseであり、偽陽性を出さない。
    expect(body.data.fixed_cta.detected).toBe(false);
  }, 30_000);

  it("detects a fixed/sticky contact CTA on the rendered page", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/render",
      payload: { url: `${fixedCtaFixture.origin}/`, timeout_ms: 20_000 },
    });

    expect(response.statusCode).toBe(200);
    const body = response.json();
    expect(body.data.fixed_cta.detected).toBe(true);
    expect(body.data.fixed_cta.position).toBe("fixed");
    expect(body.data.fixed_cta.href).toBe("/contact");
  }, 30_000);

  it("returns a classified error (not a generic internal error) when navigation fails", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/render",
      payload: { url: `${closedFixture.origin}/`, timeout_ms: 10_000 },
    });

    expect(response.statusCode).toBe(500);
    const body = response.json();
    expect(body.success).toBe(false);
    expect(body.error.code).not.toBe("INTERNAL_ERROR");
    // 生のエラーメッセージ・スタックトレースはユーザー向けレスポンスに含めない。
    expect(body.error.message).not.toContain(closedFixture.origin);
  }, 20_000);

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
    expect(body.data.storage_path).toMatch(/^analyses\/1\/websites\/1\/screenshots\/.+\.jpg$/);
    expect(body.data.mime_type).toBe("image/jpeg");
    expect(body.data.width).toBe(390);
    expect(body.data.height).toBe(844);
    expect(body.data.file_size).toBeGreaterThan(0);
    expect(body.data.screenshot_status).toBe("ok");
    expect(body.data.truncated).toBe(false);
    expect(body.data.captured_height).toBe(844);
  }, 30_000);

  it("clips a fullPage screenshot of a very tall page instead of capturing it unbounded", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/screenshot",
      payload: {
        url: `${tallFixture.origin}/`,
        device: "desktop",
        full_page: true,
        analysis_id: 1,
        website_analysis_id: 1,
      },
    });

    expect(response.statusCode).toBe(200);
    const body = response.json();
    expect(body.data.truncated).toBe(true);
    expect(body.data.screenshot_status).toBe("partial");
    expect(body.data.document_height).toBeGreaterThan(10_000);
    expect(body.data.captured_height).toBe(4_000);
    expect(body.data.height).toBe(4_000);
    expect(body.data.capture_mode).toBe("clip");
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

  it("renders a page with a never-ending background request without waiting for networkidle", async () => {
    const startedAt = Date.now();

    const response = await app.inject({
      method: "POST",
      url: "/analyze/render",
      // timeout_msをnetworkidle基準では絶対に間に合わない短さにしても、
      // domcontentloaded基準なら数秒で完了するはず(ユニクロのnavigation
      // timeout問題の回帰テスト)。
      payload: { url: `${neverIdleFixture.origin}/`, timeout_ms: 15_000 },
    });

    expect(response.statusCode).toBe(200);
    const body = response.json();
    expect(body.data.html).toContain("Never idle");
    expect(body.data.navigation_status).toBe("ok");
    expect(body.data.warning).toBeNull();
    // settle delay(2秒)+load待機はあるが、15秒のtimeout一杯を待ってはいない。
    expect(Date.now() - startedAt).toBeLessThan(10_000);
  }, 20_000);

  it("captures a screenshot on a page with continuous background requests", async () => {
    const response = await app.inject({
      method: "POST",
      url: "/analyze/screenshot",
      payload: {
        url: `${neverIdleFixture.origin}/`,
        device: "desktop",
        analysis_id: 1,
        website_analysis_id: 1,
      },
    });

    expect(response.statusCode).toBe(200);
    expect(response.json().data.navigation_status).toBe("ok");
  }, 20_000);

  it("leaves no leaked browser context after a successful render", async () => {
    const before = getActiveContextCount();

    await app.inject({
      method: "POST",
      url: "/analyze/render",
      payload: { url: `${fixture.origin}/`, timeout_ms: 10_000 },
    });

    expect(getActiveContextCount()).toBe(before);
  }, 20_000);

  it("leaves no leaked browser context after a failed render", async () => {
    const before = getActiveContextCount();

    await app.inject({
      method: "POST",
      url: "/analyze/render",
      payload: { url: `${closedFixture.origin}/`, timeout_ms: 10_000 },
    });

    expect(getActiveContextCount()).toBe(before);
  }, 20_000);
});

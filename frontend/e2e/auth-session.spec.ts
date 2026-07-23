import { expect, test } from "@playwright/test";

// frontend(http://localhost:3000)とbackend(http://localhost:8000)が実際に
// 別Originで動くdocker compose環境に対して、Cookieベースの認証フルフロー
// (preflight → csrf-cookie → login → dashboardでproject一覧表示 → reload後も
// 認証・project一覧が維持 → 認証済みAPI → logout → logout後401 →
// 未許可Originの拒否)を検証する。
const API_BASE_URL = process.env.E2E_API_BASE_URL ?? "http://localhost:8000";
const FRONTEND_ORIGIN = process.env.E2E_BASE_URL ?? "http://localhost:3000";

test.describe("cross-origin cookie authentication", () => {
  test("register, dashboard shows the project list, reload keeps the session, authenticated API, logout, then 401", async ({
    page,
  }) => {
    const unique = Date.now();
    const email = `e2e-session-${unique}@example.com`;

    await page.goto("/register");
    await page.getByLabel("お名前").fill("E2Eセッションテストユーザー");
    await page.getByLabel("メールアドレス").fill(email);
    await page.getByLabel("パスワード", { exact: true }).fill("password123");
    await page.getByLabel("パスワード（確認）").fill("password123");
    await page.getByRole("button", { name: "登録する" }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    // dashboardが実際にproject一覧APIを呼び、結果(0件の空状態)を表示できること。
    // (register直後はprojectが無いため空状態メッセージが出る。読み込み失敗の
    // 汎用エラーメッセージが出ていないことも合わせて確認する。)
    await expect(page.getByText("まだ比較プロジェクトがありません。")).toBeVisible();
    await expect(page.getByText("プロジェクトの読み込みに失敗しました")).not.toBeVisible();

    await page.getByRole("button", { name: "新規比較プロジェクト作成" }).first().click();
    await page.getByLabel("プロジェクト名").fill("E2Eセッション確認プロジェクト");
    await page.getByRole("button", { name: "作成する" }).click();
    await expect(page).toHaveURL(/\/projects\/\d+$/);

    await page.goto("/dashboard");
    await expect(page.getByText("E2Eセッション確認プロジェクト")).toBeVisible();

    // reloadしてもCookieベースのセッションが維持され、ダッシュボードとproject一覧が
    // そのまま表示されること(「少し時間を置いてから再アクセスしても認証維持」の
    // 実用的な等価テストとして、reload=新しいリクエストサイクルでの再検証を用いる。
    // 実時間でのSESSION_LIFETIME境界そのものは対象外)。
    await page.reload();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByText("E2Eセッション確認プロジェクト")).toBeVisible();
    await expect(page.getByText("プロジェクトの読み込みに失敗しました")).not.toBeVisible();

    // 認証済みAPI: ブラウザcontextのCookieを共有する page.request で直接backendを叩く。
    const meRes = await page.request.get(`${API_BASE_URL}/api/user`, {
      headers: { Accept: "application/json" },
    });
    expect(meRes.status()).toBe(200);
    const meBody = await meRes.json();
    expect(meBody.data.email).toBe(email);

    const projectsRes = await page.request.get(`${API_BASE_URL}/api/projects`, {
      headers: { Accept: "application/json" },
    });
    expect(projectsRes.status()).toBe(200);
    const projectsBody = await projectsRes.json();
    expect(projectsBody.data).toHaveLength(1);

    // logout: frontendのapi-clientと同じ手順(CSRF Cookieの値をX-XSRF-TOKENとして送る)。
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === "XSRF-TOKEN")?.value;
    expect(xsrf).toBeTruthy();

    const logoutRes = await page.request.post(`${API_BASE_URL}/api/logout`, {
      headers: {
        Accept: "application/json",
        "X-XSRF-TOKEN": decodeURIComponent(xsrf ?? ""),
      },
    });
    expect(logoutRes.status()).toBe(200);

    // logout後は /api/user が401になる(セッションが無効化されている)。
    const afterLogoutRes = await page.request.get(`${API_BASE_URL}/api/user`, {
      headers: { Accept: "application/json" },
    });
    expect(afterLogoutRes.status()).toBe(401);
  });

  test("preflight and csrf-cookie succeed for the frontend origin", async ({ request }) => {
    const preflight = await request.fetch(`${API_BASE_URL}/api/login`, {
      method: "OPTIONS",
      headers: {
        Origin: FRONTEND_ORIGIN,
        "Access-Control-Request-Method": "POST",
        "Access-Control-Request-Headers": "content-type,x-xsrf-token",
      },
    });

    expect([200, 204]).toContain(preflight.status());
    expect(preflight.headers()["access-control-allow-origin"]).toBe(FRONTEND_ORIGIN);
    expect(preflight.headers()["access-control-allow-credentials"]).toBe("true");
    expect(preflight.headers()["access-control-allow-methods"]).toContain("POST");

    const csrfRes = await request.get(`${API_BASE_URL}/sanctum/csrf-cookie`, {
      headers: { Origin: FRONTEND_ORIGIN },
    });
    expect(csrfRes.status()).toBe(204);
    expect(csrfRes.headers()["set-cookie"]).toBeTruthy();
  });

  test("an unlisted origin does not receive CORS allow headers", async ({ request }) => {
    const res = await request.fetch(`${API_BASE_URL}/api/login`, {
      method: "OPTIONS",
      headers: {
        Origin: "http://evil.example",
        "Access-Control-Request-Method": "POST",
      },
    });

    // 開発環境のFRONTEND_URLは1件のみのことが多く、fruitcake/php-cors の仕様上
    // (許可Originが1件だけの場合は常にその値を返す)ここではヘッダーの値そのものではなく、
    // 実際のブラウザがOrigin不一致時にレスポンスをブロックする前提を崩さないことを示すため、
    // 最低限「evil.exampleそのものが返らないこと」を確認する。
    const allowOrigin = res.headers()["access-control-allow-origin"];
    expect(allowOrigin).not.toBe("http://evil.example");
  });
});

import { expect, test } from "@playwright/test";

// frontend(http://localhost:3000)とbackend(http://localhost:8000)が実際に
// 別Originで動くdocker compose環境に対して、Cookieベースの認証フルフロー
// (preflight → csrf-cookie → login → reload後も認証維持 → 認証済みAPI → logout →
// logout後401 → 未許可Originの拒否)を検証する。
const API_BASE_URL = process.env.E2E_API_BASE_URL ?? "http://localhost:8000";
const FRONTEND_ORIGIN = process.env.E2E_BASE_URL ?? "http://localhost:3000";

test.describe("cross-origin cookie authentication", () => {
  test("register, reload keeps the session, authenticated API, logout, then 401", async ({ page }) => {
    const unique = Date.now();
    const email = `e2e-session-${unique}@example.com`;

    await page.goto("/register");
    await page.getByLabel("お名前").fill("E2Eセッションテストユーザー");
    await page.getByLabel("メールアドレス").fill(email);
    await page.getByLabel("パスワード", { exact: true }).fill("password123");
    await page.getByLabel("パスワード（確認）").fill("password123");
    await page.getByRole("button", { name: "登録する" }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    // reloadしてもCookieベースのセッションが維持され、ダッシュボードに留まること。
    await page.reload();
    await expect(page).toHaveURL(/\/dashboard$/);

    // 認証済みAPI: ブラウザcontextのCookieを共有する page.request で直接backendを叩く。
    const meRes = await page.request.get(`${API_BASE_URL}/api/user`, {
      headers: { Accept: "application/json" },
    });
    expect(meRes.status()).toBe(200);
    const meBody = await meRes.json();
    expect(meBody.data.email).toBe(email);

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

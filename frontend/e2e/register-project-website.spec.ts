import { expect, test } from "@playwright/test";

// ユーザー登録 → プロジェクト作成 → サイト登録 → ダッシュボード/詳細画面での確認までを
// 通しで検証する唯一のE2Eテスト。docker compose環境 (frontend:3000 / backend:8000) に
// 対して実行する。実行のたびに一意なメールアドレスを使うことで再実行を可能にする。
test("register, create a project, and register a website", async ({ page }) => {
  const unique = Date.now();
  const email = `e2e-${unique}@example.com`;

  await page.goto("/register");
  await page.getByLabel("お名前").fill("E2Eテストユーザー");
  await page.getByLabel("メールアドレス").fill(email);
  await page.getByLabel("パスワード", { exact: true }).fill("password123");
  await page.getByLabel("パスワード（確認）").fill("password123");
  await page.getByRole("button", { name: "登録する" }).click();

  await expect(page).toHaveURL(/\/dashboard$/);

  // shadcn/ui(base-ui)のButtonはLinkと合成してもrole="button"を明示するため
  // アクセシビリティ上の役割は"button"になる。空状態のダッシュボードには
  // ヘッダーと空状態カードの両方に同名の要素があるため先頭の1つを対象にする。
  await page.getByRole("button", { name: "新規比較プロジェクト作成" }).first().click();
  await expect(page).toHaveURL(/\/projects\/new$/);

  await page.getByLabel("プロジェクト名").fill("E2E比較プロジェクト");
  await page.getByLabel("業種").fill("旅行");
  await page.getByLabel("比較目的").fill("競合分析");
  await page.getByRole("button", { name: "作成する" }).click();

  await expect(page).toHaveURL(/\/projects\/\d+$/);
  await expect(page.getByRole("heading", { name: "E2E比較プロジェクト" })).toBeVisible();

  await page.getByLabel("サイト名").fill("自社サイト");
  await page.getByLabel("URL").fill("example.com");
  await page.getByLabel("自社サイトとして登録する").check();
  await page.getByRole("button", { name: "サイトを追加" }).click();

  await expect(page.getByText("自社サイト")).toBeVisible();
  await expect(page.getByText("https://example.com")).toBeVisible();

  await page.goto("/dashboard");
  await expect(page.getByText("E2E比較プロジェクト")).toBeVisible();
  await expect(page.getByText("サイト 1件")).toBeVisible();
});

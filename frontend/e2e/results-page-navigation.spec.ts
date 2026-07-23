import { expect, test } from "@playwright/test";

// 結果画面のUI/UX再設計(サイトタブ切り替え・URL状態・セクションナビ・
// モバイル表示)を、複数サイトを含むプロジェクトで検証する。実行には
// compose.e2e.yamlのオーバーレイが必要:
//   docker compose -f compose.yaml -f compose.override.yaml -f compose.e2e.yaml up -d --build

async function registerAndCreateProject(page: import("@playwright/test").Page, label: string): Promise<void> {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
  const email = `e2e-nav-${label}-${unique}@example.com`;

  await page.goto("/register");
  await page.getByLabel("お名前").fill(`E2E ${label}`);
  await page.getByLabel("メールアドレス").fill(email);
  await page.getByLabel("パスワード", { exact: true }).fill("password123");
  await page.getByLabel("パスワード（確認）").fill("password123");
  await page.getByRole("button", { name: "登録する" }).click();
  await expect(page).toHaveURL(/\/dashboard$/);

  await page.getByRole("button", { name: "新規比較プロジェクト作成" }).first().click();
  await page.getByLabel("プロジェクト名").fill(`E2E ${label} プロジェクト`);
  await page.getByRole("button", { name: "作成する" }).click();
  await expect(page).toHaveURL(/\/projects\/\d+$/);
}

async function registerWebsite(page: import("@playwright/test").Page, name: string, url: string, isPrimary: boolean) {
  const nameField = page.getByLabel("サイト名");
  await expect(nameField).toHaveValue("");
  await nameField.fill(name);
  await page.getByLabel("URL").fill(url);
  if (isPrimary) await page.getByLabel("自社サイトとして登録する").check();
  await page.getByRole("button", { name: "サイトを追加" }).click();
  await expect(page.getByRole("cell", { name, exact: true }).first()).toBeVisible();
}

test("switches between site tabs, updates the URL, and restores the selected site on reload", async ({ page }) => {
  test.setTimeout(240_000);

  await registerAndCreateProject(page, "tabs");
  await registerWebsite(page, "サイトA", "http://e2e-fixture-a:8080", true);
  await registerWebsite(page, "サイトC", "http://e2e-fixture-c:8080", false);

  const startButton = page.getByRole("button", { name: "分析を開始する" });
  await expect(startButton).toBeEnabled();
  await startButton.click();
  await page.getByRole("button", { name: "開始する" }).click();

  await expect(page).toHaveURL(/\/analyses\/\d+\/results$/, { timeout: 150_000 });
  await expect(page.getByText(/^(総合スコア|参考スコア)$/).first()).toBeVisible();

  // 初期表示はいずれか1サイトのみ(順序はAPI応答依存のため断定しない)。
  // 他方のサイトの内容は表示されない(従来のような複数サイトの縦連続表示ではない)。
  const tabs = page.getByRole("tab");
  await expect(tabs).toHaveCount(2);
  const initiallySelected = page.getByRole("tab", { selected: true });
  await expect(initiallySelected).toHaveCount(1);
  const otherTabName = (await initiallySelected.innerText()).includes("サイトA") ? "サイトC" : "サイトA";

  // タブを切り替えるとURLの?siteが更新される。
  const otherTab = page.getByRole("tab", { name: new RegExp(otherTabName) });
  await otherTab.click();
  await expect(page).toHaveURL(/[?&]site=\d+/);
  await expect(otherTab).toHaveAttribute("aria-selected", "true");

  // リロードしても選択中サイトが維持される。
  await page.reload();
  await expect(page.getByText(/^(総合スコア|参考スコア)$/).first()).toBeVisible();
  await expect(page.getByRole("tab", { name: new RegExp(otherTabName) })).toHaveAttribute("aria-selected", "true");
});

test("mobile viewport: section nav falls back to a select, and site tabs remain usable via horizontal scroll", async ({ page }) => {
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 390, height: 844 });

  await registerAndCreateProject(page, "mobile");
  await registerWebsite(page, "サイトB", "http://e2e-fixture-b:8080", true);

  const startButton = page.getByRole("button", { name: "分析を開始する" });
  await expect(startButton).toBeEnabled();
  await startButton.click();
  await page.getByRole("button", { name: "開始する" }).click();

  await expect(page).toHaveURL(/\/analyses\/\d+\/results$/, { timeout: 150_000 });
  await expect(page.getByText(/^(総合スコア|参考スコア)$/).first()).toBeVisible();

  // 1サイトのみのためタブは表示されない。
  await expect(page.getByRole("tab")).toHaveCount(0);

  // モバイル幅ではセクションナビが<select>にフォールバックする(ナビの各ボタンも
  // aria-labelに「セクションへ移動」を含むため、role="combobox"で絞り込む)。
  const select = page.getByRole("combobox", { name: "セクションへ移動" });
  await expect(select).toBeVisible();
  await select.selectOption("content");
  await expect(page.getByText("コンテンツ分析")).toBeVisible();

  // ページ全体が横スクロールしない(横方向のoverflowが発生していない)。
  const hasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  expect(hasHorizontalOverflow).toBe(false);
});

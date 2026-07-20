import { expect, test } from "@playwright/test";

// 営業・コンバージョン・信頼性系Metric(料金/FAQ/導入事例リンク・固定CTA・
// フォーム入力負担)が実際のHTMLから検出されることを確認するE2E。
//
// register-project-website.spec.ts等は実サイト(example.com)へ依存するが、
// このテストで検証したい項目は内容が固定されたページでなければ再現性が
// 無いため、Docker Compose内のローカルfixtureサイト(e2e-fixture-a/b。
// 内容はfrontend/e2e/fixtures配下)を使う。実行にはcompose.e2e.yamlの
// オーバーレイ(SSRF許可リストの設定)が必要:
//   docker compose -f compose.yaml -f compose.e2e.yaml up -d --build

async function registerAndCreateProject(page: import("@playwright/test").Page, label: string): Promise<void> {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
  const email = `e2e-biz-${label}-${unique}@example.com`;

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

async function registerSiteAndAnalyze(page: import("@playwright/test").Page, url: string) {
  const nameField = page.getByLabel("サイト名");
  await nameField.fill("自社サイト");
  await page.getByLabel("URL").fill(url);
  await page.getByLabel("自社サイトとして登録する").check();
  await page.getByRole("button", { name: "サイトを追加" }).click();
  await expect(page.getByRole("cell", { name: "自社サイト", exact: true }).first()).toBeVisible();

  const startButton = page.getByRole("button", { name: "分析を開始する" });
  await expect(startButton).toBeEnabled();
  await startButton.click();
  await page.getByRole("button", { name: "開始する" }).click();

  await expect(page).toHaveURL(/\/analyses\/\d+$/);
  // terminal status到達後、進捗画面から結果画面へ自動的に遷移する。
  await expect(page).toHaveURL(/\/analyses\/\d+\/results$/, { timeout: 130_000 });
  await expect(page.getByText(/^(総合スコア|参考スコア)$/).first()).toBeVisible();
}

test("detects business/conversion signals on a content-rich fixture site", async ({ page }) => {
  test.setTimeout(180_000);

  await registerAndCreateProject(page, "site-a");
  await registerSiteAndAnalyze(page, "http://e2e-fixture-a:8080");

  // 料金/導入事例リンクは検出され、実際に検出したリンクのURL・テキストも
  // 表示される(カードの見出しと検出リンクのテキストが同じ文言になり得るため
  // .first()で曖昧さを避ける)。
  await expect(page.getByText("料金情報リンク").first()).toBeVisible();
  await expect(page.getByRole("link", { name: "導入事例・お客様の声" })).toHaveAttribute("href", "/case-study");

  // 固定表示CTA(画面右下に常時表示される問い合わせボタン)がレンダリング後DOMから検出される。
  await expect(page.getByText("固定表示CTA(常時追従)")).toBeVisible();

  // 必須項目2つの小規模フォーム: 入力負担は「少ない」。
  await expect(page.getByText(/負担: 少ない/)).toBeVisible();
});

test("detects a heavy input burden and absent business links on a thin fixture site", async ({ page }) => {
  test.setTimeout(180_000);

  await registerAndCreateProject(page, "site-b");
  await registerSiteAndAnalyze(page, "http://e2e-fixture-b:8080");

  // 料金・導入事例ページへのリンクが存在しないサイトでは「検出されませんでした」と表示され、
  // 0点や「未取得」と誤って表示されない(exclude方針により減点対象からも外れる)。
  const contentSection = page.locator("text=コンテンツ分析").locator("..").locator("..");
  await expect(contentSection.getByText("検出されませんでした").first()).toBeVisible();

  // 必須項目11個の大規模フォーム: 入力負担は「多い」。
  await expect(page.getByText(/負担: 多い/)).toBeVisible();
});

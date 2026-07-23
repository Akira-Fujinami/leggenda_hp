import { expect, test } from "@playwright/test";

// 静的HTML(一次解析)には何も存在せず、JavaScript実行後(レンダリング済み
// HTMLの二次解析)にのみH1・SNS・価格カード・固定CTA・チャットボットが
// 出現するfixture(e2e-fixture-e)で、最終的な結果画面がレンダリング後の
// 値に基づいて表示されることを確認する。実行にはcompose.e2e.yamlの
// オーバーレイが必要:
//   docker compose -f compose.yaml -f compose.e2e.yaml up -d --build

const SECTION_NAV_LABEL: Record<string, string> = { seo: "SEO", content: "コンテンツ", conversion: "集客・CTA" };

// 複数セクションを同時に開くと「良好な項目を表示」トリガーがページ全体に
// 複数存在し得るため、対象セクションのAccordionItem(id指定)内に絞って操作する。
async function openSectionAndGoodItems(page: import("@playwright/test").Page, sectionId: keyof typeof SECTION_NAV_LABEL) {
  await page.getByRole("button", { name: `${SECTION_NAV_LABEL[sectionId]}セクションへ移動`, exact: true }).click();
  const section = page.locator(`#${sectionId}`);
  const trigger = section.getByRole("button", { name: /良好な項目を表示/ });
  if ((await trigger.count()) > 0) {
    await trigger.first().click();
  }
}

async function registerAndCreateProject(page: import("@playwright/test").Page, label: string): Promise<void> {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
  const email = `e2e-rendered-${label}-${unique}@example.com`;

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

test("最終結果はレンダリング後のDOMに基づき、静的一次解析の未検出状態を引きずらない", async ({ page }) => {
  test.setTimeout(180_000);

  await registerAndCreateProject(page, "site-e");

  await page.getByLabel("サイト名").fill("自社サイト");
  await page.getByLabel("URL").fill("http://e2e-fixture-e:8080");
  await page.getByLabel("自社サイトとして登録する").check();
  await page.getByRole("button", { name: "サイトを追加" }).click();
  await expect(page.getByRole("cell", { name: "自社サイト", exact: true }).first()).toBeVisible();

  const startButton = page.getByRole("button", { name: "分析を開始する" });
  await expect(startButton).toBeEnabled();
  await startButton.click();
  await page.getByRole("button", { name: "開始する" }).click();

  await expect(page).toHaveURL(/\/analyses\/\d+$/);
  // 二次解析(ReanalyzeRenderedHtmlJob)の完了をFinalizeが待つ分、
  // 通常より時間がかかり得るためtimeoutを広めに取る。
  await expect(page).toHaveURL(/\/analyses\/\d+\/results$/, { timeout: 150_000 });
  await expect(page.getByText(/^(総合スコア|参考スコア)$/).first()).toBeVisible();

  // H1: 静的HTMLでは0件だったが、最終結果ではJS描画されたH1が検出される。
  await openSectionAndGoodItems(page, "seo");
  await expect(page.getByText("有効なH1: 1件")).toBeVisible();
  await expect(page.getByText("代表H1: JS描画された宿泊プランランキング")).toBeVisible();

  // SNS・固定表示CTA・チャットサポート: 最終結果では検出される。
  await openSectionAndGoodItems(page, "conversion");
  await expect(page.getByText("Facebook、X、Instagram、LINE、YouTube")).toBeVisible();
  await expect(page.getByText("固定表示CTA(常時追従)")).toBeVisible();
  await expect(page.getByText("チャットサポート").first()).toBeVisible();

  // 価格付き商品カード: 最終結果では検出される。
  await openSectionAndGoodItems(page, "content");
  await expect(page.getByText("価格付き商品・プラン")).toBeVisible();
  await expect(page.getByText(/JS描画プランA 15,000円〜/)).toBeVisible();

  // データ品質欄にHTML解析元(レンダリング済みページ)が表示される。
  await expect(page.getByText("HTML解析元: レンダリング済みページ").first()).toBeVisible();

  // Recommendationは最終結果に基づく: H1・SNS・価格導線が検出済みのため、
  // それらを「未設置」と促す緊急の改善提案は出ない。
  await expect(page.getByText("ページの主題を表すH1見出しを設定")).toHaveCount(0);
});

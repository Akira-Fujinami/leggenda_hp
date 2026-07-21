import { expect, test } from "@playwright/test";

// H1の内部矛盾修正・SNS多段階検出・代替サポート導線・料金/価格付き商品カードの
// 区別・alt充足率優先度・not_found判定という、汎用ロジック修正の中核部分を
// 固定内容のfixtureサイト(e2e-fixture-c/d)で検証する。実サイト(nta.co.jp/
// travel.rakuten.co.jp)は内容が変化し得るため再現性が無く、別途手動で
// 確認する。実行にはcompose.e2e.yamlのオーバーレイが必要:
//   docker compose -f compose.yaml -f compose.e2e.yaml up -d --build

async function registerAndCreateProject(page: import("@playwright/test").Page, label: string): Promise<void> {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
  const email = `e2e-generic-${label}-${unique}@example.com`;

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
  await expect(page).toHaveURL(/\/analyses\/\d+\/results$/, { timeout: 130_000 });
  await expect(page.getByText(/^(総合スコア|参考スコア)$/).first()).toBeVisible();
}

test("Fixture A: H1・SNS・代替サポート・価格付き商品カードが矛盾なく表示される", async ({ page }) => {
  test.setTimeout(180_000);

  await registerAndCreateProject(page, "site-c");
  await registerSiteAndAnalyze(page, "http://e2e-fixture-c:8080");

  // H1: count=3(【PR】/非表示/有効1件)なのに「検出されませんでした」等の
  // 矛盾した表示にならないこと ―― 今回報告されたバグそのものの回帰確認。
  await expect(page.getByText("有効なH1: 1件")).toBeVisible();
  await expect(page.getByText("検出したH1: 3件")).toBeVisible();
  await expect(page.getByText("代表H1: 人気の宿・ホテルランキング")).toBeVisible();
  await expect(page.getByText(/【PR】/)).not.toBeVisible();

  // SNS: href host以外の複数シグナル(aria-label/img alt/title/クエリパラメータ)
  // 経由で5種類とも検出される。
  await expect(page.getByText("Facebook、X、Instagram、LINE、YouTube")).toBeVisible();

  // 代替サポート導線(ヘルプ)が検出される。
  await expect(page.getByText("チャットサポート").first()).toBeVisible();

  // 問い合わせフォームが無くても、緊急度の高い提案として単独で断定しない
  // (ヘルプ導線があるため優先度が下がった提案として表示される)。
  await expect(page.getByText(/ヘルプ・サポートページが確認できるため、緊急度は低めです/)).toBeVisible();

  // 固定の料金情報リンクは無いが、価格付き商品・プランカードは検出される。
  await expect(page.getByText("料金情報リンク").first()).toBeVisible();
  await expect(page.getByText("価格付き商品・プラン")).toBeVisible();
  await expect(page.getByText(/人気宿泊プランA 12,000円〜/)).toBeVisible();

  // alt充足率98%(49/50)は高優先度・緊急の改善提案として扱われない
  // (提案自体がリストに出る場合でも、優先度バッジは「緊急」にならない)。
  const altCard = page.locator(".rounded-md.border.p-3").filter({ hasText: "alt" });
  if ((await altCard.count()) > 0) {
    await expect(altCard.getByText("優先度: 緊急")).toHaveCount(0);
  }
});

test("Fixture B: 何も検出されない場合はnot_foundとして明確に表示される", async ({ page }) => {
  test.setTimeout(180_000);

  await registerAndCreateProject(page, "site-d");
  await registerSiteAndAnalyze(page, "http://e2e-fixture-d:8080");

  await expect(page.getByText("検出したH1: 0件")).toBeVisible();
  await expect(page.getByText("有効なH1: 0件")).toBeVisible();

  const conversionSection = page.locator("text=集客・コンバージョン導線").locator("..").locator("..");
  await expect(conversionSection.getByText("検出されませんでした").first()).toBeVisible();
});

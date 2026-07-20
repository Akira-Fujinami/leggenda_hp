import { expect, test } from "@playwright/test";

// 進捗画面(/analyses/{id})の自動遷移機能をfixtureサイトで検証する。
// 実行にはcompose.e2e.yamlのオーバーレイ(fixtureサービス・SSRF許可リスト)
// が必要:
//   docker compose -f compose.yaml -f compose.override.yaml -f compose.e2e.yaml up -d --build
//
// シナリオA: 全Job成功 -> completed -> 100% -> 自動的にresultsへ遷移
// シナリオB: fetch_robotsだけが確実にタイムアウト失敗 -> partial -> 100% ->
//            partial説明表示 -> 自動的にresultsへ遷移 -> 取得済み結果が表示される

async function registerAndCreateProject(page: import("@playwright/test").Page, label: string): Promise<void> {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
  const email = `e2e-redirect-${label}-${unique}@example.com`;

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

async function registerSiteAndStartAnalysis(page: import("@playwright/test").Page, url: string) {
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
}

test("scenario A: all jobs succeed -> completed -> 100% -> auto-redirects to results", async ({ page }) => {
  test.setTimeout(180_000);

  await registerAndCreateProject(page, "scenario-a");
  await registerSiteAndStartAnalysis(page, "http://e2e-fixture-a:8080");

  // terminal statusに達すると「分析が完了しました」等の最終結果説明が
  // 進捗100%と併記される(単なる「処理の進捗：100%」だけで終わらない)。
  await expect(page.getByText("処理の進捗：100%")).toBeVisible({ timeout: 150_000 });
  // 完了アナウンス("分析が完了しました。結果画面へ移動します。")と
  // 結果サマリーの見出し("分析が完了しました")は文言が重なるため、
  // exact指定で見出し側だけを特定する。
  await expect(page.getByText("分析が完了しました", { exact: true })).toBeVisible();
  await expect(page.getByText(/失敗\s*0/).first()).toBeVisible();

  // 自動遷移の案内が表示され、completedは1秒後に結果画面へ自動遷移する。
  await expect(page.getByText("分析が完了しました。結果画面へ移動します。")).toBeVisible();
  await expect(page).toHaveURL(/\/analyses\/\d+\/results$/, { timeout: 10_000 });
  await expect(page.getByRole("heading", { name: "分析結果" })).toBeVisible();
});

test("scenario B: fetch_robots times out -> partial -> 100% -> partial explanation -> auto-redirects with available results", async ({
  page,
}) => {
  test.setTimeout(240_000);

  await registerAndCreateProject(page, "scenario-b");
  await registerSiteAndStartAnalysis(page, "http://e2e-fixture-partial:8080");

  // fetch_robotsのタイムアウト(ジョブ$timeout超過によるリトライを含む)を
  // 待つため、他のシナリオより長いタイムアウトを取る。
  await expect(page.getByText("処理の進捗：100%")).toBeVisible({ timeout: 220_000 });
  // ステータスバッジ横の説明文("分析処理は完了しましたが、一部の項目を
  // 取得できませんでした。")と結果サマリーの見出し("分析処理は完了しました")
  // は文言が重なるため、exact指定で見出し側だけを特定する。
  await expect(page.getByText("分析処理は完了しました", { exact: true })).toBeVisible();
  await expect(page.getByText("一部の分析項目を取得できませんでした")).toBeVisible();

  // 進捗100%でもstatusがpartialである理由(失敗件数)が分かるようになっている。
  const failureCount = await page.getByText(/失敗\s*\d+/).first().textContent();
  expect(failureCount).not.toMatch(/失敗\s*0/);

  // 失敗したJob(robots.txt取得)には赤い点だけでなく具体的な説明が付く。
  await expect(page.getByText("robots.txt取得").first()).toBeVisible();

  // partialは2秒後に自動遷移する。
  await expect(
    page.getByText("分析処理が完了しました。一部取得できなかった項目があります。取得済みの結果を表示します。")
  ).toBeVisible();
  await expect(page).toHaveURL(/\/analyses\/\d+\/results$/, { timeout: 10_000 });

  // 取得済みの結果(料金プランリンク等、robots.txt以外は正常取得できている)が表示される。
  await expect(page.getByRole("heading", { name: "分析結果" })).toBeVisible();
  await expect(page.getByText(/^(総合スコア|参考スコア)$/).first()).toBeVisible();
});

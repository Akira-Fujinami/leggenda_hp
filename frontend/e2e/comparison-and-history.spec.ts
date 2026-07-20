import { expect, test } from "@playwright/test";

// SEO_PROVIDER=mock(.envのデフォルト)で動作する前提のE2E。実際のSemrush APIは
// 一切呼び出さない。Phase 3で追加した比較画面・改善提案・履歴比較画面の
// 通し確認を行う。
//
// example.com/example.orgへの実ネットワークアクセスを伴うため、既存のE2E
// (register-project-website.spec.ts)と同様に、外部サイトの可用性に依存する
// 個別の値そのものは断言せず、画面が正しい構造で表示されることを検証する。

async function registerAndCreateProject(page: import("@playwright/test").Page, label: string): Promise<string> {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
  const email = `e2e-${label}-${unique}@example.com`;

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

  return page.url();
}

async function registerWebsite(
  page: import("@playwright/test").Page,
  name: string,
  url: string,
  isPrimary: boolean
) {
  // フォーム送信成功後にreact-hook-formのreset()が非同期に走るため、直前の
  // 送信によるreset()がまだ反映されていない状態で入力すると値が上書きで
  // クリアされてしまうことがある。入力前に空欄であることを確認して
  // このレースを避ける。
  const nameField = page.getByLabel("サイト名");
  await expect(nameField).toHaveValue("");
  await nameField.fill(name);
  await page.getByLabel("URL").fill(url);
  if (isPrimary) {
    await page.getByLabel("自社サイトとして登録する").check();
  }
  await expect(nameField).toHaveValue(name);
  await page.getByRole("button", { name: "サイトを追加" }).click();
  // 種別バッジ("自社サイト"/"競合サイト")の文言とサイト名が衝突しうるため、
  // 先頭(サイト名列)のセルに絞って確認する。
  await expect(page.getByRole("cell", { name, exact: true }).first()).toBeVisible();
}

async function startAnalysisAndWaitForResults(page: import("@playwright/test").Page) {
  const startButton = page.getByRole("button", { name: "分析を開始する" });
  await expect(startButton).toBeEnabled();
  await startButton.click();
  await page.getByRole("button", { name: "開始する" }).click();

  await expect(page).toHaveURL(/\/analyses\/\d+$/);
  await expect(page.getByRole("button", { name: "結果を見る" })).toBeVisible({ timeout: 120_000 });
  await page.getByRole("button", { name: "結果を見る" }).click();
  await expect(page).toHaveURL(/\/analyses\/\d+\/results$/);
  // スクリーンショット画像等の読み込みによるレイアウトシフトが収まってから
  // 次の操作に移ることで、クリックの不安定さを避ける。
  // 測定カバー率によって「総合スコア」または「参考スコア」のいずれかを表示する。
  await expect(page.getByText(/^(総合スコア|参考スコア)$/).first()).toBeVisible();
}

test("analyze a primary site and a competitor, then view the comparison and recommendations screens", async ({
  page,
}) => {
  test.setTimeout(300_000);

  await registerAndCreateProject(page, "comparison");
  await registerWebsite(page, "自社サイト", "example.com", true);
  // "競合サイト"は種別列のバッジ文言と衝突するため、サイト名には使わない。
  await registerWebsite(page, "競合A社サイト", "example.org", false);

  await startAnalysisAndWaitForResults(page);

  await page.getByRole("link", { name: "他サイトと比較する" }).first().click();
  await expect(page).toHaveURL(/\/analyses\/\d+\/comparison$/, { timeout: 30_000 });
  await expect(page.getByRole("heading", { name: "サイト比較" })).toBeVisible();

  // ランキング・改善提案の両セクションがクラッシュせず表示されること。
  // これらはCard内のCardTitle(<div>)で表示されるため、ARIAのheadingロールは
  // 持たない。getByTextで見出し文言そのものを確認する。
  await expect(page.getByText("サイト別ランキング")).toBeVisible();
  await expect(page.getByText("ルールベース改善提案", { exact: true })).toBeVisible();
});

test("analyze the same project twice and view the history comparison screen", async ({ page }) => {
  test.setTimeout(300_000);

  const projectUrl = await registerAndCreateProject(page, "history");
  await registerWebsite(page, "自社サイト", "example.com", true);

  await startAnalysisAndWaitForResults(page);

  // 2回目の分析を開始するため、プロジェクト詳細ページへ戻る
  await page.goto(projectUrl);
  await startAnalysisAndWaitForResults(page);

  await page.getByRole("link", { name: "他サイトと比較する" }).first().click();
  await expect(page).toHaveURL(/\/analyses\/\d+\/comparison$/, { timeout: 30_000 });
  // ランキングセクションの表示を待ってから遷移することで、比較データの
  // 読み込み中に発生するレイアウトシフトによるクリックの不安定さを避ける。
  await expect(page.getByText("サイト別ランキング")).toBeVisible();

  await page.getByRole("link", { name: "過去の分析と比較" }).click();
  await expect(page).toHaveURL(/\/analyses\/\d+\/history$/, { timeout: 30_000 });
  await expect(page.getByRole("heading", { name: "過去の分析との比較" })).toBeVisible();

  // 直近の完了済み分析(1回目)が自動選択され、前回の分析が存在するはず
  await expect(page.getByText("比較できる過去の分析がありません")).not.toBeVisible();
  await expect(page.getByText("自社サイト")).toBeVisible();
});

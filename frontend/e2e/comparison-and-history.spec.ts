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
  // terminal status(completed/partial/failed)到達後、進捗画面から結果画面へ
  // 自動的に遷移する(手動クリックは不要)。実サイト依存でcompleted/partial
  // いずれになるか変わり得るため、ボタン文言までは断定せずURL遷移を待つ。
  await expect(page).toHaveURL(/\/analyses\/\d+\/results$/, { timeout: 130_000 });
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

  await page.getByRole("link", { name: "比較", exact: true }).first().click();
  await expect(page).toHaveURL(/\/analyses\/\d+\/comparison$/, { timeout: 30_000 });
  await expect(page.getByRole("heading", { name: "サイト比較" })).toBeVisible();

  // ランキング・改善提案の両セクションがクラッシュせず表示されること。
  // これらはCard内のCardTitle(<div>)で表示されるため、ARIAのheadingロールは
  // 持たない。getByTextで見出し文言そのものを確認する。
  await expect(page.getByText("サイト別ランキング")).toBeVisible();
  await expect(page.getByText("ルールベース改善提案", { exact: true })).toBeVisible();

  // この開発環境はSEO_PROVIDER=mockのため、外部SEO(authority)カテゴリは
  // 両サイトともMockデータのみとなり、採点対象0件=評価不可として表示される
  // (他のカテゴリは実サイトの内容次第で正当に0点になり得るため、「0/N」表示
  // そのものを全面禁止はせず、評価不可バッジの存在だけを確認する)。
  await expect(page.getByText("評価不可").first()).toBeVisible();

  // Mockデータの警告はAnalysis全体で一度だけ表示され、サイトごとに繰り返されない。
  await expect(page.getByText("外部SEOデータについて")).toHaveCount(1);

  // カテゴリ比較はAccordionで、既定では最も差/問題が多い1カテゴリだけが
  // 開いている(全カテゴリのMetric行が見えているわけではない)。
  // どのカテゴリが既定で開いているかは実サイトのデータ次第で変わり得るため、
  // 既定で「閉じている」カテゴリ(7つ中6つは必ず閉じている)を1つ選んで開く
  // ことで、状態変化を確定的に作る(既に開いているカテゴリを閉じる方向は、
  // 「最も問題が多いカテゴリを自動的に開く」既定ロジックがリロード後に
  // 再選択してしまい直交しないため、開く方向のみを検証する)。
  const categoryNames = ["技術SEO", "コンテンツ", "表示速度", "アクセシビリティ", "技術・計測環境", "集客・コンバージョン", "外部SEO・ドメイン評価"];
  let collapsedCategoryName: string | null = null;
  for (const name of categoryNames) {
    const trigger = page.getByRole("button", { name });
    if ((await trigger.getAttribute("aria-expanded")) === "false") {
      collapsedCategoryName = name;
      break;
    }
  }
  expect(collapsedCategoryName).not.toBeNull();

  const targetTrigger = page.getByRole("button", { name: collapsedCategoryName! });
  await targetTrigger.click();
  await expect(targetTrigger).toHaveAttribute("aria-expanded", "true");
  // 展開後、Metricのソースバッジ(HTML計測等)が表示される。
  await expect(page.getByText(/計測|検出|Semrush|AI推定/).first()).toBeVisible();
  // Next.jsのrouter.replaceによるURL更新が実際に反映されるのを待ってから
  // 次の操作(フィルタ変更)に進む。続けて操作すると、直前のcategory=更新が
  // 反映される前にfilter=の更新が発行され、互いに上書きし合うことがある。
  await expect(page).toHaveURL(/category=/);

  // フィルタを「すべて表示」に変更しても画面が壊れないこと
  // (レーダーチャートの軸ラベルにも同じカテゴリ名が出るため、
  // カテゴリ比較セクション内に絞って確認する)。
  await page.getByRole("combobox", { name: "比較項目の絞り込み" }).selectOption("all");
  await expect(page.locator("#categories").getByText(collapsedCategoryName!).first()).toBeVisible();
  await expect(page).toHaveURL(/filter=all/);

  // リロード後も展開中カテゴリの状態(このクリックの結果)がURLから復元される。
  const urlAfterExpand = page.url();
  expect(urlAfterExpand).toContain("category=");
  await page.reload();
  await expect(page.getByRole("button", { name: collapsedCategoryName! })).toHaveAttribute("aria-expanded", "true");

  // モバイル幅では横長テーブルにならない(横スクロールが発生しない)。
  await page.setViewportSize({ width: 390, height: 844 });
  const hasHorizontalOverflow = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  );
  expect(hasHorizontalOverflow).toBe(false);
});

test("analyze the same project twice and view the history comparison screen", async ({ page }) => {
  test.setTimeout(300_000);

  const projectUrl = await registerAndCreateProject(page, "history");
  await registerWebsite(page, "自社サイト", "example.com", true);

  await startAnalysisAndWaitForResults(page);

  // 2回目の分析を開始するため、プロジェクト詳細ページへ戻る
  await page.goto(projectUrl);
  await startAnalysisAndWaitForResults(page);

  await page.getByRole("link", { name: "比較", exact: true }).first().click();
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

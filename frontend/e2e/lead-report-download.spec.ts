import { expect, test } from "@playwright/test";

// リード向けセルフ診断フォーム(会社情報入力→自社/競合URL入力→結果画面)から
// 4観点表示・Word/PDFレポートのダウンロード・相談リクエストボタンまでを
// 一気通貫で検証する。実行にはcompose.e2e.yamlのオーバーレイ(fixtureサービス・
// SSRF許可リスト)が必要:
//   docker compose -f compose.yaml -f compose.override.yaml -f compose.e2e.yaml up -d --build
//
// リード分析はLighthouseも実行する(skip_lighthouse既定false)ため、内部向けの
// 既存E2E(progress-auto-redirect.spec.ts)より完了までの時間が長くなり得る。
// さらにレポート生成(Word/PDF)は結果表示後に非同期で走るため、ダウンロード
// リンクが有効になるまでさらに数十秒待つ必要がある。

test("lead onboarding -> diagnose -> results shows the 4 perspectives, report downloads, and the consultation button", async ({ page }) => {
  test.setTimeout(300_000);

  const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;

  await page.goto("/lead/start");
  await page.getByLabel("会社名").fill("E2Eテスト株式会社");
  await page.getByLabel("ご担当者名").fill("E2E太郎");
  await page.getByLabel("メールアドレス").fill(`e2e-lead-${unique}@example.com`);
  await page.getByLabel("プライバシーポリシーに同意します").check();
  await page.getByRole("button", { name: "無料で診断をはじめる" }).click();

  await expect(page).toHaveURL(/\/lead\/diagnose\?token=/);

  await page.getByLabel("自社サイトのURL").fill("http://e2e-fixture-a:8080");
  await page.getByLabel("比較したい競合サイトのURL(任意)").fill("http://e2e-fixture-b:8080");
  await page.getByRole("button", { name: "診断をはじめる" }).click();

  // 分析完了(completed/partial)を待つ。Lighthouseを含むため内部向けE2Eより長め。
  // Phase 3: リード向けスコアの見出しは社内版と別建てで
  // 「採用サイトとして重要な4観点での評価」(参考時は「…参考評価」)。
  await expect(
    page.getByText("採用サイトとして重要な4観点での評価").or(page.getByText("採用サイトとして重要な4観点での参考評価")).first(),
  ).toBeVisible({
    timeout: 240_000,
  });

  // レポート生成は結果表示の後に非同期で走るため、ダウンロードリンクが
  // 「準備中」から有効なリンクに変わるまで待つ。
  await expect(page.getByRole("link", { name: "Wordでダウンロード" })).toBeVisible({ timeout: 60_000 });
  await expect(page.getByRole("link", { name: "PDFでダウンロード" })).toBeVisible({ timeout: 60_000 });

  const wordHref = await page.getByRole("link", { name: "Wordでダウンロード" }).getAttribute("href");
  const pdfHref = await page.getByRole("link", { name: "PDFでダウンロード" }).getAttribute("href");
  expect(wordHref).toContain("/reports/docx");
  expect(pdfHref).toContain("/reports/pdf");

  // 4観点は採用担当の問いの形の見出しで出る(内部向けのカテゴリ名ではない)。
  // 自社・競合の2サイト分表示されるため、各見出しは複数件ヒットし得る。
  await expect(page.getByText("書くべきことが書けているか").first()).toBeVisible();
  await expect(page.getByText("伝えたいことが分かりやすく伝わっているか").first()).toBeVisible();
  await expect(page.getByText("知りたい情報にたどり着けるか").first()).toBeVisible();
  await expect(page.getByText("見やすく、使いやすいか").first()).toBeVisible();
  // 数値の分数表示(旧・内部カテゴリの「X / Y」表示)は出ない。
  await expect(page.getByText("技術SEO")).toHaveCount(0);

  // スクリーンショットは撮影自体を省略しているため、画面上に一切出ない。
  await expect(page.locator("img")).toHaveCount(0);

  // 相談リクエストボタン: 確認ダイアログ→実際に送信→成功表示まで。
  await page.getByRole("button", { name: "相談をリクエストする" }).click();
  await expect(page.getByText("相談をリクエストしますか？")).toBeVisible();
  await page.getByRole("button", { name: "リクエストする" }).click();
  await expect(page.getByText("ご相談リクエストを受け付けました")).toBeVisible({ timeout: 15_000 });
});

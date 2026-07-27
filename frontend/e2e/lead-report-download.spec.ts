import { expect, test } from "@playwright/test";

// リード向けセルフ診断フォーム(会社情報入力→自社/競合URL入力→結果画面)から
// Word/PDFレポートのダウンロードボタンが有効になるまでを一気通貫で検証する。
// 実行にはcompose.e2e.yamlのオーバーレイ(fixtureサービス・SSRF許可リスト)が必要:
//   docker compose -f compose.yaml -f compose.override.yaml -f compose.e2e.yaml up -d --build
//
// リード分析はLighthouseも実行する(skip_lighthouse既定false)ため、内部向けの
// 既存E2E(progress-auto-redirect.spec.ts)より完了までの時間が長くなり得る。
// さらにレポート生成(Word/PDF)は結果表示後に非同期で走るため、ダウンロード
// リンクが有効になるまでさらに数十秒待つ必要がある。

test("lead onboarding -> diagnose -> results shows download links once reports are ready", async ({ page }) => {
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
  await expect(page.getByText("総合スコア").or(page.getByText("参考スコア")).first()).toBeVisible({
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
});

/**
 * リード向け公開ページ専用のレイアウト。既存の(app)/(guest)とは独立
 * ―― RequireAuth/RequireGuestは使わない(未ログインの一般公開ページのため)。
 * ヘッダー・ナビゲーションを持たないランディング用の簡素な見た目にする。
 *
 * 幅について(2026-07-30のユーザー指摘「PCで縦に長すぎる」への対応):
 * 入力フォームは狭いほうが読みやすいのでmax-w-lg(512px)を既定にするが、
 * 診断結果は4観点×2サイトの比較を並べるため、その幅では横が余って縦に
 * 間延びする。結果画面(LeadResultsのルート)だけがdata-lead-wideを付けており、
 * それを含むときに限って広げる ―― レイアウトを一律に広げてフォームまで
 * 間延びさせない、かつページ側から幅を宣言できるようにするための構成。
 * URL入力画面(ガイダンス枠を含む)はdata-lead-mediumで中間幅に広げる
 * (2026-08-18追加、data-lead-wideと同じ仕組み)。
 *
 * 2026-08-18: リードブランド適用。ルート要素に.lead-brandを付け、
 * globals.cssのスコープ限定トークンで配色・角丸を変える(管理画面
 * ((app)配下)の:rootは一切変更していない)。固定の<h1>「無料ホームページ
 * 診断」は各ページ固有の見出しに置き換わるため削除し、代わりにロゴ+
 * 英字タグラインの共通ヘッダーを置く(全リードページで共通)。
 */
export default function LeadLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="lead-brand flex flex-1 items-center justify-center px-4 py-12">
      <div className="w-full max-w-lg space-y-6 has-[[data-lead-wide]]:max-w-5xl has-[[data-lead-medium]]:max-w-2xl">
        <header className="flex items-center justify-between">
          {/* eslint-disable-next-line @next/next/no-img-element -- このリポジトリでnext/imageの使用実績が無く、固定サイズのロゴ表示に留まるため素のimgで揃える */}
          <img src="/leggenda-logo.png" alt="LEGGENDA" className="h-[26px] w-auto" />
          <span className="lead-tag">RECRUITING BRAND DIAGNOSIS</span>
        </header>
        <div className="rounded-[2px] border bg-card p-6">{children}</div>
      </div>
    </div>
  );
}

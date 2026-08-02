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
 */
export default function LeadLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex flex-1 items-center justify-center bg-zinc-50 px-4 py-12 dark:bg-black">
      <div className="w-full max-w-lg space-y-6 has-[[data-lead-wide]]:max-w-5xl">
        <div className="text-center">
          <h1 className="text-xl font-semibold tracking-tight">無料ホームページ診断</h1>
        </div>
        <div className="rounded-lg border bg-card p-6 shadow-sm">{children}</div>
      </div>
    </div>
  );
}

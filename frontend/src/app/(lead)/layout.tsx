/**
 * リード向け公開ページ専用のレイアウト。既存の(app)/(guest)とは独立
 * ―― RequireAuth/RequireGuestは使わない(未ログインの一般公開ページのため)。
 * ヘッダー・ナビゲーションを持たないランディング用の簡素な見た目にする。
 */
export default function LeadLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex flex-1 items-center justify-center bg-zinc-50 px-4 py-12 dark:bg-black">
      <div className="w-full max-w-lg space-y-6">
        <div className="text-center">
          <h1 className="text-xl font-semibold tracking-tight">無料ホームページ診断</h1>
        </div>
        <div className="rounded-lg border bg-card p-6 shadow-sm">{children}</div>
      </div>
    </div>
  );
}

import type { LeadPerspectiveStatus } from "@/types/lead";

// バックエンドのLeadPerspectiveComposer::statusLabel()と表現を一致させる
// (画面とWord/PDFレポートの文言が食い違わないようにするための唯一の変換元は
// バックエンド側だが、フロントエンドはAPIから生の状態文字列しか受け取らない
// ため、ここで同じ変換をミラーする)。
export const PERSPECTIVE_STATUS_LABELS: Record<LeadPerspectiveStatus, string> = {
  good: "良好",
  needs_review: "確認をおすすめします",
  needs_improvement: "改善の余地があります",
  not_applicable: "該当なし",
  not_detected: "計測対象外",
  unavailable: "評価不可",
  not_measured: "計測できませんでした",
};

// good/needs_review/needs_improvementの3値だけを主表示として色分けし、
// 「未取得」系の4値(not_measured/not_applicable/not_detected/unavailable)は
// ネガティブ評価と混同されないよう、まとめて中立色にする
// (未取得を悪い評価に丸めない、という誠実性の維持)。
export const PERSPECTIVE_STATUS_STYLES: Record<LeadPerspectiveStatus, string> = {
  good: "bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300",
  needs_review: "bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300",
  needs_improvement: "bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300",
  not_measured: "bg-muted text-muted-foreground",
  not_applicable: "bg-muted text-muted-foreground",
  not_detected: "bg-muted text-muted-foreground",
  unavailable: "bg-muted text-muted-foreground",
};

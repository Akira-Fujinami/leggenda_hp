import type { AnalysisStatus, JobStatusSummary } from "@/types/analysis";

/**
 * 進捗画面の文言を1箇所に集約する。「全体の進捗」という曖昧な表現を避け、
 * 「処理の進捗」(処理終了率であり成功率ではない)であることを明示する。
 */
export function progressHeading(progress: number): string {
  return `処理の進捗：${progress}%`;
}

export interface ResultSummary {
  title: string;
  subtitle: string | null;
}

const RESULT_SUMMARIES: Partial<Record<AnalysisStatus, ResultSummary>> = {
  completed: { title: "分析が完了しました", subtitle: null },
  partial: { title: "分析処理は完了しました", subtitle: "一部の分析項目を取得できませんでした" },
  failed: { title: "分析処理は終了しました", subtitle: "分析結果を作成できませんでした" },
};

/** 進捗100%到達後に表示する最終結果の説明。実行中はnull(まだ結果が確定していないため)。 */
export function resultSummary(status: AnalysisStatus): ResultSummary | null {
  return RESULT_SUMMARIES[status] ?? null;
}

const STATUS_DESCRIPTIONS: Partial<Record<AnalysisStatus, string>> = {
  completed: "すべての分析項目を取得しました。",
  partial: "分析処理は完了しましたが、一部の項目を取得できませんでした。",
  failed: "分析に必要なデータを取得できませんでした。",
};

/** ステータスバッジに添える短い説明文。 */
export function statusDescription(status: AnalysisStatus): string | null {
  return STATUS_DESCRIPTIONS[status] ?? null;
}

export type RedirectableStatus = "completed" | "partial" | "failed";

const REDIRECT_STATUSES: RedirectableStatus[] = ["completed", "partial", "failed"];

/** 自動遷移の対象となるterminal status(cancelledは対象外)。 */
export function isRedirectableStatus(status: AnalysisStatus): status is RedirectableStatus {
  return (REDIRECT_STATUSES as AnalysisStatus[]).includes(status);
}

/** completedは1秒後、partial/failedは2秒後に自動遷移する。 */
export function redirectDelayMs(status: RedirectableStatus): number {
  return status === "completed" ? 1000 : 2000;
}

const REDIRECT_ANNOUNCEMENTS: Record<RedirectableStatus, string> = {
  completed: "分析が完了しました。結果画面へ移動します。",
  partial: "分析処理が完了しました。一部取得できなかった項目があります。取得済みの結果を表示します。",
  failed: "分析処理が終了しました。結果を確認します。",
};

export function redirectAnnouncement(status: RedirectableStatus): string {
  return REDIRECT_ANNOUNCEMENTS[status];
}

/** 「結果を見る」ボタンの文言。runningの間は途中結果表示に対応済みのため案内文言を変える。 */
export function resultButtonLabel(status: AnalysisStatus): string {
  switch (status) {
    case "completed":
      return "結果を見る";
    case "partial":
      return "取得済みの結果を見る";
    case "failed":
      return "エラー詳細を見る";
    default:
      return "途中結果を見る";
  }
}

/**
 * failedで結果画面に表示できるデータが一切無い(1件もJobが成功していない)かどうか。
 * この場合は結果画面へ遷移せず、その場でエラー表示にする。
 */
export function hasNoUsableResult(status: AnalysisStatus, jobs: JobStatusSummary): boolean {
  return status === "failed" && jobs.completed === 0;
}

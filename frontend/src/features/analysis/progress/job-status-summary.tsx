import type { JobStatusSummary as JobStatusSummaryType } from "@/types/analysis";

export function JobStatusSummary({ summary }: { summary: JobStatusSummaryType }) {
  return (
    <div className="text-sm">
      <p>
        処理済み {summary.finished} / {summary.total}
      </p>
      <p className="text-muted-foreground">
        成功 {summary.completed}　失敗 {summary.failed}
        {summary.running > 0 && `　実行中 ${summary.running}`}
        {summary.pending > 0 && `　待機中 ${summary.pending}`}
        {summary.skipped > 0 && `　スキップ ${summary.skipped}`}
      </p>
    </div>
  );
}

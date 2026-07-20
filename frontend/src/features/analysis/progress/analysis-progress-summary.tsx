import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { JobStatusSummary } from "@/features/analysis/progress/job-status-summary";
import { ProgressBar } from "@/features/analysis/progress/progress-bar";
import { progressHeading, resultSummary } from "@/features/analysis/progress-copy";
import type { AnalysisStatus, JobStatusSummary as JobStatusSummaryType } from "@/types/analysis";

/**
 * 進捗(0-100)は「処理終了率」であり成功率ではない ―― 100%でも失敗Jobが
 * あればstatusはpartialになる。そのため見出しは常に「処理の進捗」とし、
 * 100%到達後は最終結果の説明(成功/失敗件数)を必ず併記する。
 */
export function AnalysisProgressSummary({
  status,
  progress,
  jobs,
}: {
  status: AnalysisStatus;
  progress: number;
  jobs: JobStatusSummaryType;
}) {
  const summary = resultSummary(status);

  return (
    <Card>
      <CardHeader>
        <CardTitle>{progressHeading(progress)}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <ProgressBar value={progress} />
        {summary && (
          <div>
            <p className="font-medium">{summary.title}</p>
            {summary.subtitle && <p className="text-sm text-muted-foreground">{summary.subtitle}</p>}
          </div>
        )}
        <JobStatusSummary summary={jobs} />
      </CardContent>
    </Card>
  );
}

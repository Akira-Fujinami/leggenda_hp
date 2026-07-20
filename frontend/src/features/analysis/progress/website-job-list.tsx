import { jobTypeLabel } from "@/features/analysis/job-labels";
import type { AnalysisJobProgress } from "@/types/analysis";

function StatusDot({ status }: { status: AnalysisJobProgress["status"] }) {
  const className =
    status === "completed"
      ? "size-1.5 rounded-full bg-primary"
      : status === "failed"
        ? "size-1.5 rounded-full bg-destructive"
        : "size-1.5 rounded-full bg-muted-foreground/40";

  return <span className={className} />;
}

/**
 * サイト単位のJob一覧。赤い点だけでなく、失敗したJobには実際の
 * error_message(短い説明)を併記する ―― 何が起きたか分からない「赤い点」
 * だけの表示を避けるため。
 */
export function WebsiteJobList({ jobs }: { jobs: AnalysisJobProgress[] }) {
  const failedJobs = jobs.filter((job) => job.status === "failed");

  return (
    <div className="space-y-3">
      <ul className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-muted-foreground sm:grid-cols-3">
        {jobs.map((job) => (
          <li key={job.job_type} className="flex items-center gap-1.5">
            <StatusDot status={job.status} />
            {jobTypeLabel(job.job_type)}
          </li>
        ))}
      </ul>

      {failedJobs.length > 0 && (
        <ul className="space-y-1 rounded-md border border-destructive/30 bg-destructive/5 p-2 text-xs">
          {failedJobs.map((job) => (
            <li key={job.job_type}>
              <span className="font-medium text-destructive">{jobTypeLabel(job.job_type)}</span>
              <span className="ml-1 text-muted-foreground">{job.error_message ?? "取得に失敗しました。"}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

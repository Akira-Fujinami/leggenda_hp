"use client";

import { use } from "react";
import { useRouter } from "next/navigation";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { AnalysisStatusBadge } from "@/features/analysis/analysis-status-badge";
import { useAnalysisProgress } from "@/features/analysis/hooks";
import { AnalysisProgressSummary } from "@/features/analysis/progress/analysis-progress-summary";
import { AutoRedirectNotice } from "@/features/analysis/progress/auto-redirect-notice";
import { JobStatusSummary } from "@/features/analysis/progress/job-status-summary";
import { ProgressBar } from "@/features/analysis/progress/progress-bar";
import { WebsiteJobList } from "@/features/analysis/progress/website-job-list";
import { hasNoUsableResult, resultButtonLabel, statusDescription } from "@/features/analysis/progress-copy";
import { useAutoRedirectToResults } from "@/features/analysis/use-auto-redirect";
import type { AnalysisStatus } from "@/types/analysis";

export default function AnalysisProgressPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const analysisId = Number(id);
  const router = useRouter();
  const { data, isLoading, isError } = useAnalysisProgress(analysisId);

  const analysis = data?.data ?? null;
  const noUsableResult = analysis !== null && hasNoUsableResult(analysis.status, analysis.jobs);

  // failedで結果画面に表示できるデータが1件も無い場合は、結果画面へは
  // 遷移させず(存在しないに等しい結果を見せても混乱するだけのため)、
  // この場でエラーとして案内する。
  const redirectStatus: AnalysisStatus | null = analysis !== null && !noUsableResult ? analysis.status : null;
  const redirect = useAutoRedirectToResults(analysisId, redirectStatus);

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-1/2" />
        <Skeleton className="h-40" />
      </div>
    );
  }

  if (isError || !analysis) {
    return (
      <Alert variant="destructive">
        <AlertDescription>
          進捗の取得に失敗しました。しばらくしてからページを再読み込みしてください。
        </AlertDescription>
      </Alert>
    );
  }

  const buttonLabel = resultButtonLabel(analysis.status);
  const description = statusDescription(analysis.status);

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-xl font-semibold tracking-tight">分析の進捗</h1>
            <AnalysisStatusBadge status={analysis.status} />
          </div>
          {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
        </div>
        {!noUsableResult && (
          <Button onClick={() => router.push(`/analyses/${analysisId}/results`)}>{buttonLabel}</Button>
        )}
      </div>

      {noUsableResult && (
        <Alert variant="destructive">
          <AlertDescription>
            分析処理は終了しましたが、結果画面に表示できるデータを取得できませんでした。サイトのURLが正しいか、サイトが正常に応答しているかをご確認のうえ、再度分析をお試しください。
          </AlertDescription>
        </Alert>
      )}

      {!noUsableResult && (
        <AutoRedirectNotice
          status={analysis.status}
          pending={redirect.pending}
          cancelled={redirect.cancelled}
          onRedirectNow={redirect.redirectNow}
          onCancel={redirect.cancel}
        />
      )}

      <AnalysisProgressSummary status={analysis.status} progress={analysis.progress} jobs={analysis.jobs} />

      <div className="space-y-4">
        {analysis.websites.map((website) => (
          <Card key={website.website_analysis_id}>
            <CardHeader className="flex-row items-center justify-between space-y-0">
              <CardTitle className="text-base">{website.website_name ?? `サイト #${website.website_id}`}</CardTitle>
              <AnalysisStatusBadge status={website.status} />
            </CardHeader>
            <CardContent className="space-y-3">
              <ProgressBar value={website.progress} />
              <JobStatusSummary summary={website.job_summary} />
              <WebsiteJobList jobs={website.jobs} />
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}

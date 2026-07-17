"use client";

import { use } from "react";
import { useRouter } from "next/navigation";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { AnalysisStatusBadge } from "@/features/analysis/analysis-status-badge";
import { isAnalysisTerminal, useAnalysisProgress } from "@/features/analysis/hooks";

const JOB_TYPE_LABELS: Record<string, string> = {
  fetch_static_page: "静的HTML取得",
  fetch_robots: "robots.txt取得",
  fetch_sitemap: "sitemap.xml取得",
  render_page: "レンダリング",
  capture_screenshot_desktop: "スクリーンショット(PC)",
  capture_screenshot_mobile: "スクリーンショット(モバイル)",
  run_lighthouse: "Lighthouse計測",
  analyze_html_seo: "SEO解析",
  detect_technology: "使用技術検出",
  finalize_website_analysis: "サイト分析の確定",
};

export default function AnalysisProgressPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const analysisId = Number(id);
  const router = useRouter();
  const { data, isLoading, isError } = useAnalysisProgress(analysisId);

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-1/2" />
        <Skeleton className="h-40" />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <Alert variant="destructive">
        <AlertDescription>
          進捗の取得に失敗しました。しばらくしてからページを再読み込みしてください。
        </AlertDescription>
      </Alert>
    );
  }

  const analysis = data.data;
  const terminal = isAnalysisTerminal(analysis.status);

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <h1 className="text-xl font-semibold tracking-tight">分析の進捗</h1>
          <AnalysisStatusBadge status={analysis.status} />
        </div>
        {terminal && (
          <Button onClick={() => router.push(`/analyses/${analysisId}/results`)}>結果を見る</Button>
        )}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>全体の進捗: {analysis.progress}%</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
            <div
              className="h-full rounded-full bg-primary transition-all"
              style={{ width: `${analysis.progress}%` }}
            />
          </div>
        </CardContent>
      </Card>

      <div className="space-y-4">
        {analysis.websites.map((website) => (
          <Card key={website.website_analysis_id}>
            <CardHeader className="flex-row items-center justify-between space-y-0">
              <CardTitle className="text-base">{website.website_name ?? `サイト #${website.website_id}`}</CardTitle>
              <AnalysisStatusBadge status={website.status} />
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                  className="h-full rounded-full bg-primary transition-all"
                  style={{ width: `${website.progress}%` }}
                />
              </div>
              <ul className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-muted-foreground sm:grid-cols-3">
                {website.jobs.map((job) => (
                  <li key={job.job_type} className="flex items-center gap-1.5">
                    <span
                      className={
                        job.status === "completed"
                          ? "size-1.5 rounded-full bg-primary"
                          : job.status === "failed"
                            ? "size-1.5 rounded-full bg-destructive"
                            : "size-1.5 rounded-full bg-muted-foreground/40"
                      }
                    />
                    {JOB_TYPE_LABELS[job.job_type] ?? job.job_type}
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}

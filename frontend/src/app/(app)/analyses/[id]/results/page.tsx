"use client";

import { use } from "react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { AnalysisStatusBadge } from "@/features/analysis/analysis-status-badge";
import { useAnalysisResults } from "@/features/analysis/hooks";
import type { WebsiteAnalysisResult } from "@/types/analysis";

export default function AnalysisResultsPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const analysisId = Number(id);
  const { data, isLoading, isError } = useAnalysisResults(analysisId);

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-1/2" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <Alert variant="destructive">
        <AlertDescription>結果の取得に失敗しました。しばらくしてからページを再読み込みしてください。</AlertDescription>
      </Alert>
    );
  }

  const analysis = data.data;

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <h1 className="text-xl font-semibold tracking-tight">分析結果</h1>
        <AnalysisStatusBadge status={analysis.status} />
      </div>

      <div className="space-y-6">
        {analysis.websites.map((website) => (
          <WebsiteResultCard key={website.website_analysis_id} website={website} />
        ))}
      </div>
    </div>
  );
}

function WebsiteResultCard({ website }: { website: WebsiteAnalysisResult }) {
  const { score } = website;

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <div>
          <CardTitle>{website.website_name ?? `サイト #${website.website_id}`}</CardTitle>
          {website.url && <p className="text-xs text-muted-foreground">{website.url}</p>}
        </div>
        <AnalysisStatusBadge status={website.status} />
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-md border p-4">
            <p className="text-sm text-muted-foreground">総合スコア</p>
            <p className="text-2xl font-semibold">
              {score.total_score} <span className="text-sm font-normal text-muted-foreground">/ {score.max_available_score}</span>
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
              測定カバー率: {Math.round(score.coverage_rate * 100)}%
              {score.failed_metric_count > 0 && ` ・失敗: ${score.failed_metric_count}件`}
              {score.unavailable_metric_count > 0 && ` ・測定不可: ${score.unavailable_metric_count}件`}
            </p>
          </div>
          <div className="rounded-md border p-4">
            <p className="text-sm text-muted-foreground">カテゴリ別スコア</p>
            <ul className="mt-1 space-y-0.5 text-sm">
              {Object.entries(score.categories).map(([key, value]) => (
                <li key={key} className="flex justify-between">
                  <span className="text-muted-foreground">{key}</span>
                  <span>
                    {value.score} / {value.max_score}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        </div>

        {website.seo && (
          <div className="space-y-1 text-sm">
            <p className="font-medium">SEO基本情報</p>
            <p className="text-muted-foreground">タイトル: {website.seo.title ?? "(未設定)"}</p>
            <p className="text-muted-foreground">meta description: {website.seo.meta_description ?? "(未設定)"}</p>
            <p className="text-muted-foreground">
              H1: {website.seo.h1_count ?? "-"}件 ・ 本文文字数: {website.seo.word_count ?? "-"}
            </p>
          </div>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <p className="text-sm font-medium">Lighthouse</p>
            <ul className="mt-1 space-y-0.5 text-sm text-muted-foreground">
              <li>Performance: {website.lighthouse.scores.performance ?? "-"}</li>
              <li>SEO: {website.lighthouse.scores.seo ?? "-"}</li>
              <li>Accessibility: {website.lighthouse.scores.accessibility ?? "-"}</li>
            </ul>
          </div>
          <div>
            <p className="text-sm font-medium">使用技術</p>
            <div className="mt-1 flex flex-wrap gap-1">
              {website.technology.length === 0 ? (
                <span className="text-sm text-muted-foreground">検出なし</span>
              ) : (
                website.technology.map((tech) => (
                  <Badge key={tech.name} variant="outline">
                    {tech.name}
                  </Badge>
                ))
              )}
            </div>
          </div>
        </div>

        {website.screenshots.length > 0 && (
          <div>
            <p className="text-sm font-medium">スクリーンショット</p>
            <div className="mt-2 flex flex-wrap gap-4">
              {website.screenshots.map((screenshot) => (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  key={screenshot.device}
                  src={screenshot.url}
                  alt={`${website.website_name ?? ""} (${screenshot.device})`}
                  className="h-48 w-auto rounded-md border object-cover object-top"
                />
              ))}
            </div>
          </div>
        )}

        {website.errors.length > 0 && (
          <Alert variant="destructive">
            <AlertDescription>
              <p className="font-medium">一部の処理でエラーが発生しました</p>
              <ul className="mt-1 space-y-0.5">
                {website.errors.map((error) => (
                  <li key={error.job_type}>
                    {error.job_type}: {error.error_message}
                  </li>
                ))}
              </ul>
            </AlertDescription>
          </Alert>
        )}
      </CardContent>
    </Card>
  );
}

"use client";

import { use } from "react";
import Link from "next/link";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { AnalysisStatusBadge } from "@/features/analysis/analysis-status-badge";
import { useAnalysisResults } from "@/features/analysis/hooks";
import { WebsiteResultCard } from "@/features/analysis/website-result-card";

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
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <h1 className="text-xl font-semibold tracking-tight">分析結果</h1>
          <AnalysisStatusBadge status={analysis.status} />
        </div>
        <Link href={`/analyses/${analysisId}/comparison`} className="text-sm text-muted-foreground hover:underline">
          サイト比較・改善提案を見る
        </Link>
      </div>

      {analysis.status === "partial" && (
        <Alert>
          <AlertDescription>
            一部の分析項目を取得できませんでした。取得済みの結果を表示しています。
          </AlertDescription>
        </Alert>
      )}

      {analysis.status === "failed" && analysis.websites.length > 0 && (
        <Alert variant="destructive">
          <AlertDescription>
            分析は失敗しましたが、取得できた範囲の結果を表示しています。
          </AlertDescription>
        </Alert>
      )}

      <div className="space-y-6">
        {analysis.websites.map((website) => (
          <WebsiteResultCard key={website.website_analysis_id} website={website} />
        ))}
      </div>
    </div>
  );
}

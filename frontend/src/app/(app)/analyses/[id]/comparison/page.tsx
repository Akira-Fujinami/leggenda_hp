"use client";

import { use } from "react";
import Link from "next/link";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { ComparisonTable } from "@/features/comparison/comparison-table";
import { DataQualityWarnings } from "@/features/comparison/data-quality-warnings";
import { useComparison } from "@/features/comparison/hooks";
import { RankingSummary } from "@/features/comparison/ranking-summary";
import { RecommendationList } from "@/features/comparison/recommendation-list";
import { ScoreCharts } from "@/features/comparison/score-charts";
import { StrengthsWeaknesses } from "@/features/comparison/strengths-weaknesses";

export default function AnalysisComparisonPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const analysisId = Number(id);
  const { data, isLoading, isError } = useComparison(analysisId);

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
        <AlertDescription>比較結果の取得に失敗しました。しばらくしてからページを再読み込みしてください。</AlertDescription>
      </Alert>
    );
  }

  const comparison = data.data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold tracking-tight">サイト比較</h1>
        <div className="flex gap-3 text-sm">
          <Link href={`/analyses/${analysisId}/results`} className="text-muted-foreground hover:underline">
            結果一覧に戻る
          </Link>
          <Link href={`/analyses/${analysisId}/history`} className="text-muted-foreground hover:underline">
            過去の分析と比較
          </Link>
        </div>
      </div>

      {comparison.ranking.length === 0 ? (
        <Alert>
          <AlertDescription>比較できるサイトの分析結果がありません。</AlertDescription>
        </Alert>
      ) : (
        <>
          <DataQualityWarnings ranking={comparison.ranking} dataQuality={comparison.data_quality} />
          <RankingSummary ranking={comparison.ranking} />
          <ScoreCharts ranking={comparison.ranking} categories={comparison.categories} />
          <ComparisonTable ranking={comparison.ranking} categories={comparison.categories} metrics={comparison.metrics} />
          <StrengthsWeaknesses ranking={comparison.ranking} strengths={comparison.strengths} weaknesses={comparison.weaknesses} />
          <RecommendationList analysisId={analysisId} ranking={comparison.ranking} />
        </>
      )}
    </div>
  );
}

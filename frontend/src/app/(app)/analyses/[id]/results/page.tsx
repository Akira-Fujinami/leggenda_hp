"use client";

import { use } from "react";
import Link from "next/link";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { AnalysisStatusBadge } from "@/features/analysis/analysis-status-badge";
import { useAnalysisResults } from "@/features/analysis/hooks";
import { AnalysisSummary } from "@/features/analysis/results/analysis-summary";
import { CategoryScoreCard } from "@/features/analysis/results/category-score-card";
import { ContentDetails } from "@/features/analysis/results/content-details";
import { ConversionDetails } from "@/features/analysis/results/conversion-details";
import { DataQualityNotice } from "@/features/analysis/results/data-quality-notice";
import { ExternalSeoDetails } from "@/features/analysis/results/external-seo-details";
import { FailedAnalysisItems } from "@/features/analysis/results/failed-analysis-items";
import { PerformanceDetails } from "@/features/analysis/results/performance-details";
import { PriorityRecommendations } from "@/features/analysis/results/priority-recommendations";
import { ScreenshotSection } from "@/features/analysis/results/screenshot-section";
import { SeoDetails } from "@/features/analysis/results/seo-details";
import { TechnologyDetails } from "@/features/analysis/results/technology-details";
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
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <h1 className="text-xl font-semibold tracking-tight">分析結果</h1>
          <AnalysisStatusBadge status={analysis.status} />
        </div>
        <ResultsNav analysisId={analysisId} />
      </div>

      {analysis.status === "partial" && (
        <Alert>
          <AlertDescription>一部の分析項目を取得できませんでした。取得済みの結果を表示しています。</AlertDescription>
        </Alert>
      )}

      {analysis.status === "failed" && analysis.websites.length > 0 && (
        <Alert variant="destructive">
          <AlertDescription>分析は失敗しましたが、取得できた範囲の結果を表示しています。</AlertDescription>
        </Alert>
      )}

      <div className="space-y-8">
        {analysis.websites.map((website) => (
          <WebsiteResultSections key={website.website_analysis_id} website={website} generatedAt={analysis.completed_at} />
        ))}
      </div>

      <ResultsNav analysisId={analysisId} />
    </div>
  );
}

function ResultsNav({ analysisId }: { analysisId: number }) {
  return (
    <div className="flex flex-wrap gap-3 text-sm">
      <Link href={`/analyses/${analysisId}/comparison`} className="text-muted-foreground hover:underline">
        他サイトと比較する
      </Link>
      <Link href={`/analyses/${analysisId}/comparison`} className="text-muted-foreground hover:underline">
        改善提案を見る
      </Link>
      <Link href={`/analyses/${analysisId}/history`} className="text-muted-foreground hover:underline">
        過去分析と比較する
      </Link>
      <Button variant="ghost" size="sm" disabled title="この機能は現在準備中です">
        再分析する(準備中)
      </Button>
    </div>
  );
}

function WebsiteResultSections({ website, generatedAt }: { website: WebsiteAnalysisResult; generatedAt: string | null }) {
  const { score, metrics } = website;

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader className="flex-row items-center justify-between space-y-0">
          <div>
            <CardTitle>{website.website_name ?? `サイト #${website.website_id}`}</CardTitle>
            {website.url && <p className="text-xs text-muted-foreground">{website.url}</p>}
          </div>
          <AnalysisStatusBadge status={website.status} />
        </CardHeader>
        <CardContent>
          <DataQualityNotice score={score} />
        </CardContent>
      </Card>

      {/* A. 分析サマリー */}
      <AnalysisSummary
        websiteName={website.website_name}
        score={score}
        recommendations={website.recommendations}
        generatedAt={generatedAt}
      />

      {/* B. 優先改善項目 */}
      <PriorityRecommendations recommendations={website.recommendations} url={website.url} />

      {/* C. カテゴリ別評価 */}
      <div className="grid gap-4 sm:grid-cols-2">
        {score.category_scores.map((category) => (
          <CategoryScoreCard key={category.key} category={category} metrics={metrics} />
        ))}
      </div>

      {/* D. SEO基本情報の詳細化 */}
      <SeoDetails metrics={metrics} seo={website.seo} />

      {/* E. コンテンツ分析 */}
      <ContentDetails metrics={metrics} />

      {/* F. 集客・コンバージョン */}
      <ConversionDetails metrics={metrics} />

      {/* G. 表示速度・アクセシビリティ */}
      <PerformanceDetails metrics={metrics} />

      {/* H. 技術・計測環境 */}
      <TechnologyDetails metrics={metrics} />

      {/* I. 外部SEO */}
      <ExternalSeoDetails metrics={metrics} />

      {/* J. スクリーンショット */}
      <ScreenshotSection screenshots={website.screenshots} errors={website.errors} websiteName={website.website_name} />

      {/* 分析失敗一覧 */}
      <FailedAnalysisItems errors={website.errors} />
    </div>
  );
}

"use client";

import { Suspense, use, useState } from "react";
import Link from "next/link";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { AiAnalysisSummary } from "@/features/comparison/ai-analysis-summary";
import {
  CategoryComparisonAccordion,
  findInitialOpenCategory,
} from "@/features/comparison/category-comparison-accordion";
import { ComparisonChartTabs } from "@/features/comparison/comparison-chart-tabs";
import {
  ComparisonFilters,
  DEFAULT_COMPARISON_FILTER,
  type ComparisonFilterValue,
} from "@/features/comparison/comparison-filters";
import { ComparisonStickyNav } from "@/features/comparison/comparison-sticky-nav";
import { ComparisonSummary } from "@/features/comparison/comparison-summary";
import { ExternalSeoComparison } from "@/features/comparison/external-seo-comparison";
import { useComparison } from "@/features/comparison/hooks";
import { RecommendationPreview } from "@/features/comparison/recommendation-preview";
import { StrengthWeaknessSummary } from "@/features/comparison/strength-weakness-summary";
import { useActiveSection } from "@/features/analysis/results/use-active-section";
import { useComparisonUrlState } from "@/features/comparison/use-comparison-url-state";

const SECTION_IDS = ["summary", "charts", "categories", "strengths", "recommendations", "ai"];

export default function AnalysisComparisonPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const analysisId = Number(id);

  return (
    <Suspense fallback={<ComparisonSkeleton />}>
      <AnalysisComparisonContent analysisId={analysisId} />
    </Suspense>
  );
}

function ComparisonSkeleton() {
  return (
    <div className="space-y-4">
      <Skeleton className="h-10 w-1/2" />
      <Skeleton className="h-64" />
    </div>
  );
}

function AnalysisComparisonContent({ analysisId }: { analysisId: number }) {
  const { data, isLoading, isError } = useComparison(analysisId);
  const { category: urlCategory, filter: urlFilter, setUrlState } = useComparisonUrlState();

  const [openCategories, setOpenCategories] = useState<string[]>([]);
  const [openCategoriesComputedForId, setOpenCategoriesComputedForId] = useState<number | null>(null);
  const filter = (urlFilter as ComparisonFilterValue | null) ?? DEFAULT_COMPARISON_FILTER;

  const comparison = data?.data ?? null;

  // 初期展開カテゴリ(最も差/問題が多いカテゴリ、またはURLのcategory指定)を
  // レンダー中に調整する(useEffect+setStateによるcascading renderを避けるため)。
  if (comparison && openCategoriesComputedForId !== analysisId) {
    const defaults = new Set<string>();
    const autoKey = findInitialOpenCategory(comparison.categories, comparison.metrics);
    if (autoKey) defaults.add(autoKey);
    if (urlCategory) defaults.add(urlCategory);
    setOpenCategoriesComputedForId(analysisId);
    setOpenCategories(Array.from(defaults));
  }

  const activeSectionId = useActiveSection(SECTION_IDS);

  if (isLoading) return <ComparisonSkeleton />;

  if (isError || !data) {
    return (
      <Alert variant="destructive">
        <AlertDescription>比較結果の取得に失敗しました。しばらくしてからページを再読み込みしてください。</AlertDescription>
      </Alert>
    );
  }

  const result = data.data;

  function handleNavigate(id: string) {
    requestAnimationFrame(() => {
      document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }

  function handleOpenCategoriesChange(next: string[]) {
    setOpenCategories(next);
    const lastOpened = next.find((k) => !openCategories.includes(k));
    setUrlState({ category: lastOpened ?? next[next.length - 1] ?? null });
  }

  function handleFilterChange(next: ComparisonFilterValue) {
    setUrlState({ filter: next === DEFAULT_COMPARISON_FILTER ? null : next });
  }

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

      {result.ranking.length === 0 ? (
        <Alert>
          <AlertDescription>比較できるサイトの分析結果がありません。</AlertDescription>
        </Alert>
      ) : (
        <>
          <ComparisonStickyNav activeId={activeSectionId} onNavigate={handleNavigate} />

          <div id="summary" className="scroll-mt-14">
            <ComparisonSummary
              ranking={result.ranking}
              categories={result.categories}
              dataQuality={result.data_quality}
              externalSeo={result.external_seo}
            />
          </div>

          <div id="charts" className="scroll-mt-14">
            <ComparisonChartTabs ranking={result.ranking} categories={result.categories} />
          </div>

          <div id="categories" className="scroll-mt-14 space-y-3">
            <div className="flex items-center justify-between">
              <h2 className="text-base font-semibold tracking-tight">カテゴリ比較</h2>
              <ComparisonFilters value={filter} onChange={handleFilterChange} />
            </div>
            <CategoryComparisonAccordion
              ranking={result.ranking}
              categories={result.categories}
              metrics={result.metrics}
              filter={filter}
              openCategories={openCategories}
              onOpenCategoriesChange={handleOpenCategoriesChange}
            />
            <ExternalSeoComparison ranking={result.ranking} externalSeo={result.external_seo} />
          </div>

          <div id="strengths" className="scroll-mt-14">
            <StrengthWeaknessSummary
              ranking={result.ranking}
              strengths={result.strengths}
              weaknesses={result.weaknesses}
              metrics={result.metrics}
              categories={result.categories}
            />
          </div>

          <div id="recommendations" className="scroll-mt-14">
            <RecommendationPreview analysisId={analysisId} ranking={result.ranking} />
          </div>

          <div id="ai" className="scroll-mt-14">
            <AiAnalysisSummary ranking={result.ranking} />
          </div>
        </>
      )}
    </div>
  );
}

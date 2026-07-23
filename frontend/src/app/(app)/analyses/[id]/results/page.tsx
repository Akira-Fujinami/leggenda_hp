"use client";

import { Suspense, use, useMemo, useState } from "react";
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "@/components/ui/accordion";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { useAnalysisResults } from "@/features/analysis/hooks";
import { jobTypeLabel } from "@/features/analysis/job-labels";
import { AnalysisSummary } from "@/features/analysis/results/analysis-summary";
import { CategoryOverviewGrid } from "@/features/analysis/results/category-overview-grid";
import { ContentDetails } from "@/features/analysis/results/content-details";
import { ConversionDetails } from "@/features/analysis/results/conversion-details";
import { DataQualityNotice } from "@/features/analysis/results/data-quality-notice";
import { ExternalSeoDetails } from "@/features/analysis/results/external-seo-details";
import { FailedAnalysisItems } from "@/features/analysis/results/failed-analysis-items";
import { PerformanceDetails } from "@/features/analysis/results/performance-details";
import { PriorityRecommendations } from "@/features/analysis/results/priority-recommendations";
import { ResultsSectionNav } from "@/features/analysis/results/results-section-nav";
import { ResultsStickyHeader } from "@/features/analysis/results/results-sticky-header";
import { ScreenshotSection } from "@/features/analysis/results/screenshot-section";
import { ACCORDION_SECTION_IDS, RESULTS_SECTIONS, categoryKeyToSectionId } from "@/features/analysis/results/section-config";
import { SeoDetails } from "@/features/analysis/results/seo-details";
import { TechnologyDetails } from "@/features/analysis/results/technology-details";
import { useActiveSection } from "@/features/analysis/results/use-active-section";
import { useResultsUrlState } from "@/features/analysis/results/use-results-url-state";
import { WebsiteTabs } from "@/features/analysis/results/website-tabs";
import type { WebsiteAnalysisResult } from "@/types/analysis";

export default function AnalysisResultsPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const analysisId = Number(id);

  return (
    <Suspense fallback={<ResultsSkeleton />}>
      <AnalysisResultsContent analysisId={analysisId} />
    </Suspense>
  );
}

function ResultsSkeleton() {
  return (
    <div className="space-y-4">
      <Skeleton className="h-10 w-1/2" />
      <Skeleton className="h-64" />
    </div>
  );
}

/** カテゴリ内で最もカバー率×達成率が低い(=最も問題が多い)測定済みカテゴリのセクションidを返す。 */
function findLowestScoringSectionId(website: WebsiteAnalysisResult): string | undefined {
  const measured = website.score.category_scores.filter((c) => c.max_available_score > 0 && c.configured_max_score > 0);
  if (measured.length === 0) return undefined;

  const lowest = measured.reduce((worst, c) => (c.score / c.configured_max_score < worst.score / worst.configured_max_score ? c : worst));
  return categoryKeyToSectionId(lowest.key);
}

function AnalysisResultsContent({ analysisId }: { analysisId: number }) {
  const { data, isLoading, isError } = useAnalysisResults(analysisId);
  const { site, section: urlSection, setUrlState } = useResultsUrlState();

  const websites = data?.data.websites ?? [];
  const initialWebsiteId = site ? Number(site) : (websites[0]?.website_analysis_id ?? null);
  const [selectedWebsiteId, setSelectedWebsiteId] = useState<number | null>(initialWebsiteId);

  const selectedWebsite = websites.find((w) => w.website_analysis_id === selectedWebsiteId) ?? websites[0] ?? null;

  const [openSections, setOpenSections] = useState<string[]>([]);
  // サイト切り替え時に初期展開状態(最も問題の多いカテゴリ、およびURLのsection指定)を
  // 再計算する。cascading renderを避けるため、useEffect+setStateではなく
  // レンダー中に直接状態を調整する(Reactの推奨パターン)。
  const [openSectionsComputedForId, setOpenSectionsComputedForId] = useState<number | null>(null);
  if (selectedWebsite && openSectionsComputedForId !== selectedWebsite.website_analysis_id) {
    const defaults = new Set<string>();
    const lowest = findLowestScoringSectionId(selectedWebsite);
    if (lowest) defaults.add(lowest);
    if (urlSection && ACCORDION_SECTION_IDS.includes(urlSection)) defaults.add(urlSection);
    setOpenSectionsComputedForId(selectedWebsite.website_analysis_id);
    setOpenSections(Array.from(defaults));
  }

  const sectionIds = useMemo(() => {
    if (!selectedWebsite) return [];
    return RESULTS_SECTIONS.filter((s) => s.id !== "failed" || selectedWebsite.errors.length > 0).map((s) => s.id);
  }, [selectedWebsite]);

  const activeSectionId = useActiveSection(sectionIds);

  if (isLoading) return <ResultsSkeleton />;

  if (isError || !data || !selectedWebsite) {
    return (
      <Alert variant="destructive">
        <AlertDescription>結果の取得に失敗しました。しばらくしてからページを再読み込みしてください。</AlertDescription>
      </Alert>
    );
  }

  const analysis = data.data;

  function handleSelectWebsite(id: number) {
    setSelectedWebsiteId(id);
    setUrlState({ site: String(id) });
  }

  function handleNavigate(id: string) {
    if (ACCORDION_SECTION_IDS.includes(id)) {
      setOpenSections((prev) => (prev.includes(id) ? prev : [...prev, id]));
    }
    setUrlState({ section: id });
    // アコーディオンの展開(DOM挿入)を待ってからスクロールする。
    requestAnimationFrame(() => {
      document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }

  return (
    <div className="-mx-4 space-y-6 px-4 sm:mx-0 sm:px-0">
      <h1 className="sr-only">分析結果</h1>
      <ResultsStickyHeader
        analysisId={analysisId}
        websiteName={selectedWebsite.website_name}
        score={selectedWebsite.score.display_score}
        status={selectedWebsite.status}
      />

      {websites.length > 1 && (
        <div className="-mx-4 px-4 sm:mx-0 sm:px-0">
          <WebsiteTabs websites={websites} value={selectedWebsite.website_analysis_id} onValueChange={handleSelectWebsite} />
        </div>
      )}

      <ResultsSectionNav sectionIds={sectionIds} activeId={activeSectionId} onNavigate={handleNavigate} />

      {analysis.status === "partial" && (
        <Alert>
          <AlertDescription>
            <p>一部の分析項目を取得できませんでした。取得済みの結果を表示しています。</p>
            {selectedWebsite.status === "partial" && selectedWebsite.errors[0] && (
              <p className="mt-1 text-xs text-muted-foreground">{jobTypeLabel(selectedWebsite.errors[0].job_type)}に失敗したため、一部の分析項目が未取得です。</p>
            )}
          </AlertDescription>
        </Alert>
      )}

      {analysis.status === "failed" && websites.length > 0 && (
        <Alert variant="destructive">
          <AlertDescription>分析は失敗しましたが、取得できた範囲の結果を表示しています。</AlertDescription>
        </Alert>
      )}

      <div id="summary" className="scroll-mt-28 space-y-4">
        <DataQualityNotice score={selectedWebsite.score} htmlAnalysisSource={selectedWebsite.html_analysis_source} />
        <AnalysisSummary
          websiteName={selectedWebsite.website_name}
          score={selectedWebsite.score}
          recommendations={selectedWebsite.recommendations}
          metrics={selectedWebsite.metrics}
          generatedAt={analysis.completed_at}
        />
      </div>

      <div id="priority" className="scroll-mt-28">
        <PriorityRecommendations
          recommendations={selectedWebsite.recommendations}
          url={selectedWebsite.url}
          allRecommendationsHref={`/analyses/${analysisId}/comparison`}
        />
      </div>

      <div id="categories" className="scroll-mt-28">
        <CategoryOverviewGrid
          categories={selectedWebsite.score.category_scores}
          metrics={selectedWebsite.metrics}
          onViewDetails={handleNavigate}
        />
      </div>

      <Accordion multiple value={openSections} onValueChange={(v) => setOpenSections(v as string[])}>
        <AccordionItem value="seo" id="seo" className="scroll-mt-28 px-1">
          <AccordionTrigger className="text-base font-medium">SEO</AccordionTrigger>
          <AccordionContent>
            <SeoDetails metrics={selectedWebsite.metrics} seo={selectedWebsite.seo} />
          </AccordionContent>
        </AccordionItem>

        <AccordionItem value="content" id="content" className="scroll-mt-28 px-1">
          <AccordionTrigger className="text-base font-medium">コンテンツ</AccordionTrigger>
          <AccordionContent>
            <ContentDetails metrics={selectedWebsite.metrics} />
          </AccordionContent>
        </AccordionItem>

        <AccordionItem value="conversion" id="conversion" className="scroll-mt-28 px-1">
          <AccordionTrigger className="text-base font-medium">集客・CTA</AccordionTrigger>
          <AccordionContent>
            <ConversionDetails metrics={selectedWebsite.metrics} />
          </AccordionContent>
        </AccordionItem>

        <AccordionItem value="performance" id="performance" className="scroll-mt-28 px-1">
          <AccordionTrigger className="text-base font-medium">表示速度</AccordionTrigger>
          <AccordionContent>
            <PerformanceDetails metrics={selectedWebsite.metrics} />
          </AccordionContent>
        </AccordionItem>

        <AccordionItem value="technology" id="technology" className="scroll-mt-28 px-1">
          <AccordionTrigger className="text-base font-medium">技術</AccordionTrigger>
          <AccordionContent>
            <TechnologyDetails metrics={selectedWebsite.metrics} />
          </AccordionContent>
        </AccordionItem>

        <AccordionItem value="authority" id="authority" className="scroll-mt-28 px-1">
          <AccordionTrigger className="text-base font-medium">外部SEO</AccordionTrigger>
          <AccordionContent>
            <ExternalSeoDetails metrics={selectedWebsite.metrics} />
          </AccordionContent>
        </AccordionItem>

        <AccordionItem value="screenshots" id="screenshots" className="scroll-mt-28 px-1">
          <AccordionTrigger className="text-base font-medium">スクリーンショット</AccordionTrigger>
          <AccordionContent>
            <ScreenshotSection screenshots={selectedWebsite.screenshots} errors={selectedWebsite.errors} websiteName={selectedWebsite.website_name} />
          </AccordionContent>
        </AccordionItem>

        {selectedWebsite.errors.length > 0 && (
          <AccordionItem value="failed" id="failed" className="scroll-mt-28 px-1">
            <AccordionTrigger className="text-base font-medium">取得失敗</AccordionTrigger>
            <AccordionContent>
              <FailedAnalysisItems errors={selectedWebsite.errors} />
            </AccordionContent>
          </AccordionItem>
        )}
      </Accordion>
    </div>
  );
}

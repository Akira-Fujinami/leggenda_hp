"use client";

import { HelpCircle } from "lucide-react";
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "@/components/ui/accordion";
import { Badge } from "@/components/ui/badge";
import { isCategoryUnavailable } from "@/features/comparison/category-availability";
import { type ComparisonFilterValue } from "@/features/comparison/comparison-filters";
import { classifyComparisonMetric, type ComparisonRowState } from "@/features/comparison/comparison-metric-classification";
import { ComparisonMetricRow } from "@/features/comparison/comparison-metric-row";
import { GoodItemsCollapsible } from "@/features/analysis/results/good-items-collapsible";
import type { CategoryComparison, CategorySiteScore, MetricComparison, RankingEntry } from "@/types/comparison";

function categoryDiff(category: CategoryComparison): number {
  const available = category.sites.filter((s) => !isCategoryUnavailable(s));
  if (available.length < 2) return 0;
  const scores = available.map((s) => s.score);
  return Math.round((Math.max(...scores) - Math.min(...scores)) * 100) / 100;
}

function matchesFilter(state: ComparisonRowState, filter: ComparisonFilterValue): boolean {
  switch (filter) {
    case "differences":
      return state === "diff";
    case "improve":
      return state === "improve";
    case "unavailable":
      return state !== "good";
    case "all":
      return true;
  }
}

/**
 * 最も差が大きい、または問題(要改善+未取得)が多いカテゴリのkeyを1つ返す
 * (初期展開する1カテゴリを決めるための合成スコア)。
 */
export function findInitialOpenCategory(
  categories: CategoryComparison[],
  metrics: MetricComparison[],
): string | undefined {
  const metricsByCategory = groupMetricsByCategory(metrics);
  let bestKey: string | undefined;
  let bestScore = -Infinity;

  for (const category of categories) {
    const categoryMetrics = metricsByCategory.get(category.key) ?? [];
    const states = categoryMetrics.map(classifyComparisonMetric);
    const problemCount = states.filter((s) => s === "improve" || s === "unavailable").length;
    const normalizedDiff = category.configured_max_score > 0 ? categoryDiff(category) / category.configured_max_score : 0;
    const problemRatio = categoryMetrics.length > 0 ? problemCount / categoryMetrics.length : 0;
    const composite = normalizedDiff + problemRatio;

    if (composite > bestScore) {
      bestScore = composite;
      bestKey = category.key;
    }
  }

  return bestKey;
}

function groupMetricsByCategory(metrics: MetricComparison[]): Map<string, MetricComparison[]> {
  const map = new Map<string, MetricComparison[]>();
  for (const metric of metrics) {
    const list = map.get(metric.category_key) ?? [];
    list.push(metric);
    map.set(metric.category_key, list);
  }
  return map;
}

function CategoryScoreCell({ siteScore, configuredMaxScore }: { siteScore: CategorySiteScore | undefined; configuredMaxScore: number }) {
  if (isCategoryUnavailable(siteScore)) {
    return (
      <Badge variant="outline" className="gap-1">
        <HelpCircle className="size-3" />
        評価不可
      </Badge>
    );
  }
  return (
    <span>
      {siteScore!.score} / {configuredMaxScore}
    </span>
  );
}

export function CategoryComparisonAccordion({
  ranking,
  categories,
  metrics,
  filter,
  openCategories,
  onOpenCategoriesChange,
}: {
  ranking: RankingEntry[];
  categories: CategoryComparison[];
  metrics: MetricComparison[];
  filter: ComparisonFilterValue;
  openCategories: string[];
  onOpenCategoriesChange: (value: string[]) => void;
}) {
  const orderedSites = [...ranking].sort((a, b) => a.rank - b.rank);
  const metricsByCategory = groupMetricsByCategory(metrics);

  return (
    <Accordion multiple value={openCategories} onValueChange={(v) => onOpenCategoriesChange(v as string[])}>
      {categories.map((category) => {
        const categoryMetrics = metricsByCategory.get(category.key) ?? [];
        const classified = categoryMetrics.map((metric) => ({ metric, state: classifyComparisonMetric(metric) }));
        const improveCount = classified.filter((c) => c.state === "improve").length;
        const unavailableCount = classified.filter((c) => c.state === "unavailable").length;
        const diff = categoryDiff(category);

        const filtered = classified.filter((c) => matchesFilter(c.state, filter));
        const visible = filtered.filter((c) => c.state !== "good");
        const good = filtered.filter((c) => c.state === "good");

        return (
          <AccordionItem key={category.key} value={category.key} id={`category-${category.key}`} className="px-1">
            <AccordionTrigger className="text-left">
              <div className="flex flex-1 flex-wrap items-center justify-between gap-x-4 gap-y-1 pr-2">
                <span className="font-medium">{category.name}</span>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                  {orderedSites.map((site) => {
                    const siteScore = category.sites.find((s) => s.website_analysis_id === site.website_analysis_id);
                    return (
                      <span key={site.website_analysis_id} className="whitespace-nowrap">
                        {site.website_name ?? `サイト #${site.website_id}`}{" "}
                        <CategoryScoreCell siteScore={siteScore} configuredMaxScore={category.configured_max_score} />
                      </span>
                    );
                  })}
                  {diff > 0 && <span className="whitespace-nowrap">差 {diff}</span>}
                  <span className="whitespace-nowrap">要改善 {improveCount}件</span>
                  <span className="whitespace-nowrap">未取得 {unavailableCount}件</span>
                </div>
              </div>
            </AccordionTrigger>
            <AccordionContent>
              {visible.length === 0 && good.length === 0 && (
                <p className="text-sm text-muted-foreground">現在のフィルタ条件に一致する項目はありません。</p>
              )}
              {visible.map((c) => (
                <ComparisonMetricRow key={c.metric.key} metric={c.metric} sites={orderedSites} />
              ))}
              <GoodItemsCollapsible count={good.length} label="同等・良好な項目をすべて表示" contentClassName="mt-2">
                {good.map((c) => (
                  <ComparisonMetricRow key={c.metric.key} metric={c.metric} sites={orderedSites} />
                ))}
              </GoodItemsCollapsible>
            </AccordionContent>
          </AccordionItem>
        );
      })}
    </Accordion>
  );
}

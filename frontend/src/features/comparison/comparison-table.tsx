"use client";

import { Fragment, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { cn } from "@/lib/utils";
import type { CategoryComparison, MetricComparison, MetricSiteValue, RankingEntry } from "@/types/comparison";

const SOURCE_LABELS: Record<string, string> = {
  static_html: "HTML計測",
  http: "HTTP計測",
  lighthouse: "Lighthouse計測",
  analyzer: "技術検出",
  semrush: "Semrush",
  mock: "デモデータ",
  ai: "AI推定",
};

function formatValue(value: MetricSiteValue, unit: string | null): string {
  if (value.status !== "success" && value.status !== "not_applicable") return "-";
  if (value.value === null || value.value === undefined) return "-";
  if (typeof value.value === "boolean") return value.value ? "○" : "×";
  if (typeof value.value === "number") return unit ? `${value.value}${unit}` : String(value.value);
  return String(value.value);
}

function isMeasured(value: MetricSiteValue): boolean {
  return value.status === "success";
}

function bestSiteIds(metric: MetricComparison): Set<number> {
  const measured = metric.sites.filter((s) => isMeasured(s) && typeof s.value === "number");
  if (measured.length < 2) return new Set();
  const values = measured.map((s) => s.value as number);
  const best = metric.higher_is_better ? Math.max(...values) : Math.min(...values);
  return new Set(measured.filter((s) => s.value === best).map((s) => s.website_analysis_id));
}

function worstSiteIds(metric: MetricComparison): Set<number> {
  const measured = metric.sites.filter((s) => isMeasured(s) && typeof s.value === "number");
  if (measured.length < 2) return new Set();
  const values = measured.map((s) => s.value as number);
  const worst = metric.higher_is_better ? Math.min(...values) : Math.max(...values);
  return new Set(measured.filter((s) => s.value === worst).map((s) => s.website_analysis_id));
}

function MetricCell({ site, metric }: { site: MetricSiteValue; metric: MetricComparison }) {
  if (site.status === "error") {
    return (
      <span className="inline-flex items-center gap-1 text-destructive" title={site.error_message ?? "エラー"}>
        ⚠ エラー
      </span>
    );
  }

  if (site.status !== "success" && site.status !== "not_applicable") {
    return <span className="text-muted-foreground">未取得</span>;
  }

  const best = bestSiteIds(metric);
  const worst = worstSiteIds(metric);
  const isBest = best.has(site.website_analysis_id) && best.size < metric.sites.length;
  const isWorst = worst.has(site.website_analysis_id) && worst.size < metric.sites.length;

  return (
    <span
      className={cn(
        isBest && "font-semibold text-green-700 dark:text-green-400",
        isWorst && !isBest && "text-red-700 dark:text-red-400"
      )}
    >
      {formatValue(site, metric.unit)}
      {site.is_mock && (
        <Badge variant="outline" className="ml-1 align-middle">
          デモデータ
        </Badge>
      )}
    </span>
  );
}

export function ComparisonTable({
  ranking,
  categories,
  metrics,
}: {
  ranking: RankingEntry[];
  categories: CategoryComparison[];
  metrics: MetricComparison[];
}) {
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});
  const orderedSites = [...ranking].sort((a, b) => a.rank - b.rank);

  const metricsByCategory = new Map<string, MetricComparison[]>();
  for (const metric of metrics) {
    const list = metricsByCategory.get(metric.category_key) ?? [];
    list.push(metric);
    metricsByCategory.set(metric.category_key, list);
  }

  return (
    <div className="rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="sticky left-0 top-0 z-20 bg-background">項目</TableHead>
            {orderedSites.map((site) => (
              <TableHead key={site.website_analysis_id} className="sticky top-0 z-10 bg-background text-right">
                <div className="flex flex-col items-end gap-0.5">
                  <span>
                    {site.website_name ?? `サイト #${site.website_id}`}
                    {site.is_primary && (
                      <Badge variant="secondary" className="ml-1">
                        自社
                      </Badge>
                    )}
                  </span>
                </div>
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {categories.map((category) => {
            const isCollapsed = collapsed[category.key] ?? false;
            const categoryMetrics = metricsByCategory.get(category.key) ?? [];

            return (
              <Fragment key={category.key}>
                <TableRow className="bg-muted/40">
                  <TableCell className="sticky left-0 z-10 bg-muted/40 font-medium">
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-6 gap-1 px-1"
                      onClick={() => setCollapsed((prev) => ({ ...prev, [category.key]: !isCollapsed }))}
                    >
                      <span>{isCollapsed ? "▶" : "▼"}</span>
                      {category.name}
                    </Button>
                  </TableCell>
                  {orderedSites.map((site) => {
                    const siteScore = category.sites.find((s) => s.website_analysis_id === site.website_analysis_id);
                    return (
                      <TableCell key={site.website_analysis_id} className="text-right font-medium">
                        {siteScore ? `${siteScore.score} / ${category.configured_max_score}` : "-"}
                      </TableCell>
                    );
                  })}
                </TableRow>

                {!isCollapsed &&
                  categoryMetrics.map((metric) => (
                    <TableRow key={metric.key}>
                      <TableCell className="sticky left-0 z-10 bg-background pl-6 text-muted-foreground">
                        <div className="flex items-center gap-1.5">
                          <span>{metric.name}</span>
                          <Badge variant="outline" className="text-[10px]">
                            {SOURCE_LABELS[metric.source_type] ?? metric.source_type}
                          </Badge>
                        </div>
                      </TableCell>
                      {orderedSites.map((site) => {
                        const siteValue = metric.sites.find((s) => s.website_analysis_id === site.website_analysis_id);
                        return (
                          <TableCell key={site.website_analysis_id} className="text-right">
                            {siteValue ? <MetricCell site={siteValue} metric={metric} /> : "-"}
                          </TableCell>
                        );
                      })}
                    </TableRow>
                  ))}
              </Fragment>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
}

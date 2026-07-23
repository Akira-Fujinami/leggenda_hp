"use client";

import { useState } from "react";
import { ChevronDown, HelpCircle } from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  PolarAngleAxis,
  PolarGrid,
  PolarRadiusAxis,
  Radar,
  RadarChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { isCategoryUnavailable } from "@/features/comparison/category-availability";
import type { CategoryComparison, RankingEntry } from "@/types/comparison";

// shadcnの既定テーマは --chart-1 が oklch(0.87 0 0)(白背景でほぼ見えない薄灰色)のため、
// 濃い順(chart-5→chart-1)に並べ替えて使用する。2〜3サイト比較が主用途のため、
// 最初の系列ほどコントラストが確保されている必要がある。
const SERIES_COLORS = [
  "var(--color-chart-5)",
  "var(--color-chart-4)",
  "var(--color-chart-3)",
  "var(--color-chart-2)",
  "var(--color-chart-1)",
];

/**
 * カテゴリ別スコアのレーダーチャート。グラフ下の数値表はCollapsibleに格納し、
 * 既定では閉じておく(グラフだけで概要は伝わるため)。
 */
export function RadarCategoryChart({ ranking, categories }: { ranking: RankingEntry[]; categories: CategoryComparison[] }) {
  const orderedSites = [...ranking].sort((a, b) => a.rank - b.rank);
  const [focusedSiteId, setFocusedSiteId] = useState<number | "all">("all");
  const [tableOpen, setTableOpen] = useState(false);

  // 全サイトで評価不可(max_available_score<=0、Mock/未取得のみ)のカテゴリは、
  // 実測できていないのに0点として描画すると捏造した比較に見えるため、
  // レーダーチャートの軸自体から除外する(一覧表側では「評価不可」として明示する)。
  const comparableCategories = categories.filter((category) =>
    category.sites.some((s) => !isCategoryUnavailable(s)),
  );

  const radarData = comparableCategories.map((category) => {
    const point: Record<string, string | number> = { category: category.name };
    for (const site of orderedSites) {
      const siteScore = category.sites.find((s) => s.website_analysis_id === site.website_analysis_id);
      point[`site_${site.website_analysis_id}`] = isCategoryUnavailable(siteScore) ? 0 : siteScore!.score;
    }
    return point;
  });

  const visibleSites =
    focusedSiteId === "all" ? orderedSites : orderedSites.filter((s) => s.website_analysis_id === focusedSiteId);

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-end">
        <select
          className="rounded-md border bg-background px-2 py-1 text-xs sm:hidden"
          value={focusedSiteId === "all" ? "all" : String(focusedSiteId)}
          onChange={(e) => setFocusedSiteId(e.target.value === "all" ? "all" : Number(e.target.value))}
          aria-label="表示するサイトを選択"
        >
          <option value="all">すべて表示</option>
          {orderedSites.map((site) => (
            <option key={site.website_analysis_id} value={site.website_analysis_id}>
              {site.website_name ?? `サイト #${site.website_id}`}
            </option>
          ))}
        </select>
      </div>

      <div className="h-72 w-full">
        <ResponsiveContainer width="100%" height="100%">
          <RadarChart data={radarData}>
            <PolarGrid />
            <PolarAngleAxis dataKey="category" tick={{ fontSize: 11 }} />
            <PolarRadiusAxis angle={30} domain={[0, "auto"]} tick={{ fontSize: 10 }} />
            <Tooltip />
            <Legend />
            {visibleSites.map((site, index) => (
              <Radar
                key={site.website_analysis_id}
                name={site.website_name ?? `サイト #${site.website_id}`}
                dataKey={`site_${site.website_analysis_id}`}
                stroke={SERIES_COLORS[index % SERIES_COLORS.length]}
                fill={SERIES_COLORS[index % SERIES_COLORS.length]}
                fillOpacity={0.25}
              />
            ))}
          </RadarChart>
        </ResponsiveContainer>
      </div>

      <Collapsible open={tableOpen} onOpenChange={setTableOpen}>
        <CollapsibleTrigger render={<Button variant="ghost" size="sm" className="gap-1" />}>
          <ChevronDown className={`size-3.5 transition-transform ${tableOpen ? "rotate-180" : ""}`} />
          数値で表示
        </CollapsibleTrigger>
        <CollapsibleContent>
          <CategoryScoreTable categories={categories} sites={orderedSites} />
        </CollapsibleContent>
      </Collapsible>
    </div>
  );
}

/** 総合スコアの棒グラフ。数値表はCollapsibleで既定は閉じておく。 */
export function BarOverallChart({ ranking }: { ranking: RankingEntry[] }) {
  const orderedSites = [...ranking].sort((a, b) => a.rank - b.rank);
  const [tableOpen, setTableOpen] = useState(false);

  const barData = orderedSites.map((site) => ({
    name: site.website_name ?? `サイト #${site.website_id}`,
    score: site.display_score,
  }));

  return (
    <div className="space-y-3">
      <div className="h-72 w-full">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={barData}>
            <CartesianGrid strokeDasharray="3 3" />
            <XAxis dataKey="name" tick={{ fontSize: 11 }} />
            <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} />
            <Tooltip />
            <Bar dataKey="score" fill={SERIES_COLORS[0]} radius={[4, 4, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>

      <Collapsible open={tableOpen} onOpenChange={setTableOpen}>
        <CollapsibleTrigger render={<Button variant="ghost" size="sm" className="gap-1" />}>
          <ChevronDown className={`size-3.5 transition-transform ${tableOpen ? "rotate-180" : ""}`} />
          数値で表示
        </CollapsibleTrigger>
        <CollapsibleContent>
          <Table className="mt-2">
            <TableHeader>
              <TableRow>
                <TableHead>サイト</TableHead>
                <TableHead className="text-right">総合スコア</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {orderedSites.map((site) => (
                <TableRow key={site.website_analysis_id}>
                  <TableCell>{site.website_name ?? `サイト #${site.website_id}`}</TableCell>
                  <TableCell className="text-right">{site.display_score}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CollapsibleContent>
      </Collapsible>
    </div>
  );
}

function CategoryScoreTable({ categories, sites }: { categories: CategoryComparison[]; sites: RankingEntry[] }) {
  return (
    <Table className="mt-2">
      <TableHeader>
        <TableRow>
          <TableHead>カテゴリ</TableHead>
          {sites.map((site) => (
            <TableHead key={site.website_analysis_id} className="text-right">
              {site.website_name ?? `サイト #${site.website_id}`}
            </TableHead>
          ))}
        </TableRow>
      </TableHeader>
      <TableBody>
        {categories.map((category) => (
          <TableRow key={category.key}>
            <TableCell>{category.name}</TableCell>
            {sites.map((site) => {
              const score = category.sites.find((s) => s.website_analysis_id === site.website_analysis_id);
              return (
                <TableCell key={site.website_analysis_id} className="text-right">
                  {isCategoryUnavailable(score) ? (
                    <Badge variant="outline" className="gap-1">
                      <HelpCircle className="size-3" />
                      評価不可
                    </Badge>
                  ) : (
                    `${score!.score} / ${category.configured_max_score}`
                  )}
                </TableCell>
              );
            })}
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

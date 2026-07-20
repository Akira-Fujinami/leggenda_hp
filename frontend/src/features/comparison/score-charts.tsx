"use client";

import { useState } from "react";
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
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { CategoryComparison, RankingEntry } from "@/types/comparison";

const SERIES_COLORS = [
  "var(--color-chart-1)",
  "var(--color-chart-2)",
  "var(--color-chart-3)",
  "var(--color-chart-4)",
  "var(--color-chart-5)",
];

export function ScoreCharts({ ranking, categories }: { ranking: RankingEntry[]; categories: CategoryComparison[] }) {
  const orderedSites = [...ranking].sort((a, b) => a.rank - b.rank);
  const [focusedSiteId, setFocusedSiteId] = useState<number | "all">("all");

  const radarData = categories.map((category) => {
    const point: Record<string, string | number> = { category: category.name };
    for (const site of orderedSites) {
      const siteScore = category.sites.find((s) => s.website_analysis_id === site.website_analysis_id);
      point[`site_${site.website_analysis_id}`] = siteScore?.score ?? 0;
    }
    return point;
  });

  const barData = orderedSites.map((site) => ({
    name: site.website_name ?? `サイト #${site.website_id}`,
    score: site.display_score,
  }));

  const visibleSites =
    focusedSiteId === "all" ? orderedSites : orderedSites.filter((s) => s.website_analysis_id === focusedSiteId);

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Card>
        <CardHeader className="flex-row items-center justify-between space-y-0">
          <CardTitle className="text-base">カテゴリ別スコア(レーダーチャート)</CardTitle>
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
        </CardHeader>
        <CardContent>
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

          <CategoryScoreTable categories={categories} sites={orderedSites} />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">総合スコア(棒グラフ)</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-72 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={barData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} />
                <Tooltip />
                <Bar dataKey="score" fill="var(--color-chart-1)" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>

          <Table className="mt-4">
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
        </CardContent>
      </Card>
    </div>
  );
}

function CategoryScoreTable({ categories, sites }: { categories: CategoryComparison[]; sites: RankingEntry[] }) {
  return (
    <Table className="mt-4">
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
                  {score ? `${score.score} / ${category.configured_max_score}` : "-"}
                </TableCell>
              );
            })}
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

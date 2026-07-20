"use client";

import { useState } from "react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useAnalysisRecommendations } from "@/features/comparison/hooks";
import type { RankingEntry, RecommendationFilters, RecommendationItem } from "@/types/comparison";

const PRIORITY_LABELS: Record<RecommendationItem["priority"], string> = {
  critical: "緊急", high: "高", medium: "中", low: "低",
};
const PRIORITY_VARIANTS: Record<RecommendationItem["priority"], "destructive" | "default" | "secondary" | "outline"> = {
  critical: "destructive", high: "default", medium: "secondary", low: "outline",
};
const EFFORT_LABELS: Record<RecommendationItem["effort"], string> = {
  small: "小", medium: "中", large: "大",
};

export function RecommendationList({ analysisId, ranking }: { analysisId: number; ranking: RankingEntry[] }) {
  const [filters, setFilters] = useState<RecommendationFilters>({ sort: "default" });
  const { data, isLoading, isError } = useAnalysisRecommendations(analysisId, filters);

  const nameOf = (websiteAnalysisId: number) =>
    ranking.find((r) => r.website_analysis_id === websiteAnalysisId)?.website_name ?? `サイト #${websiteAnalysisId}`;

  return (
    <Card>
      <CardHeader>
        <CardTitle>改善提案</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex flex-wrap gap-2">
          <select
            className="rounded-md border bg-background px-2 py-1 text-sm"
            value={filters.website_analysis_id ?? ""}
            onChange={(e) =>
              setFilters((prev) => ({
                ...prev,
                website_analysis_id: e.target.value ? Number(e.target.value) : undefined,
              }))
            }
            aria-label="サイトで絞り込み"
          >
            <option value="">すべてのサイト</option>
            {ranking.map((site) => (
              <option key={site.website_analysis_id} value={site.website_analysis_id}>
                {nameOf(site.website_analysis_id)}
              </option>
            ))}
          </select>

          <select
            className="rounded-md border bg-background px-2 py-1 text-sm"
            value={filters.priority ?? ""}
            onChange={(e) =>
              setFilters((prev) => ({
                ...prev,
                priority: (e.target.value || undefined) as RecommendationFilters["priority"],
              }))
            }
            aria-label="優先度で絞り込み"
          >
            <option value="">すべての優先度</option>
            <option value="critical">緊急</option>
            <option value="high">高</option>
            <option value="medium">中</option>
            <option value="low">低</option>
          </select>

          <select
            className="rounded-md border bg-background px-2 py-1 text-sm"
            value={filters.effort ?? ""}
            onChange={(e) =>
              setFilters((prev) => ({ ...prev, effort: (e.target.value || undefined) as RecommendationFilters["effort"] }))
            }
            aria-label="工数で絞り込み"
          >
            <option value="">すべての工数</option>
            <option value="small">工数: 小</option>
            <option value="medium">工数: 中</option>
            <option value="large">工数: 大</option>
          </select>

          <select
            className="rounded-md border bg-background px-2 py-1 text-sm"
            value={filters.sort ?? "default"}
            onChange={(e) => setFilters((prev) => ({ ...prev, sort: e.target.value as RecommendationFilters["sort"] }))}
            aria-label="並び順"
          >
            <option value="default">おすすめ順</option>
            <option value="impact">効果順</option>
            <option value="effort">工数順</option>
            <option value="site">サイト順</option>
          </select>
        </div>

        {isLoading && <Skeleton className="h-32" />}

        {isError && (
          <Alert variant="destructive">
            <AlertDescription>改善提案の取得に失敗しました。</AlertDescription>
          </Alert>
        )}

        {data && data.data.length === 0 && (
          <p className="text-sm text-muted-foreground">条件に一致する改善提案はありません。</p>
        )}

        {data && data.data.length > 0 && (
          <ul className="space-y-3">
            {data.data.map((rec) => (
              <li key={rec.id} className="rounded-md border p-3">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={PRIORITY_VARIANTS[rec.priority]}>優先度: {PRIORITY_LABELS[rec.priority]}</Badge>
                  <Badge variant="outline">工数: {EFFORT_LABELS[rec.effort]}</Badge>
                  <span className="text-xs text-muted-foreground">{nameOf(rec.website_analysis_id)}</span>
                </div>
                <p className="mt-2 font-medium">{rec.title}</p>
                {rec.description && <p className="mt-1 text-sm text-muted-foreground">{rec.description}</p>}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Skeleton } from "@/components/ui/skeleton";
import { formatEvidence, formatMetricValueField } from "@/features/analysis/formatters/metric-evidence-formatter";
import { useAnalysisRecommendations } from "@/features/comparison/hooks";
import { RecommendationList } from "@/features/comparison/recommendation-list";
import type { RankingEntry, RecommendationItem } from "@/types/comparison";

const PRIORITY_LABELS: Record<RecommendationItem["priority"], string> = { critical: "緊急", high: "高", medium: "中", low: "低" };
const PRIORITY_VARIANTS: Record<RecommendationItem["priority"], "destructive" | "default" | "secondary" | "outline"> = {
  critical: "destructive",
  high: "default",
  medium: "secondary",
  low: "outline",
};
const EFFORT_LABELS: Record<RecommendationItem["effort"], string> = { small: "小", medium: "中", large: "大" };

const INITIAL_VISIBLE_COUNT = 5;

function RecommendationPreviewCard({ rec, siteName }: { rec: RecommendationItem; siteName: string }) {
  const [open, setOpen] = useState(false);
  const current = formatMetricValueField(rec.current_value, { metric_value_type: null, metric_unit: null });
  const evidence = formatEvidence(rec.evidence);
  const hasDetail = Boolean(rec.description || evidence || current);

  return (
    <div className="rounded-md border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs text-muted-foreground">{siteName}</span>
        <Badge variant={PRIORITY_VARIANTS[rec.priority]}>優先度: {PRIORITY_LABELS[rec.priority]}</Badge>
        <Badge variant="outline">工数: {EFFORT_LABELS[rec.effort]}</Badge>
      </div>
      <p className="mt-2 font-medium">{rec.title}</p>

      {hasDetail && (
        <Collapsible open={open} onOpenChange={setOpen} className="mt-1.5">
          <CollapsibleTrigger render={<Button variant="ghost" size="sm" className="h-6 gap-1 px-1.5 text-xs" />}>
            <ChevronDown className={`size-3 transition-transform ${open ? "rotate-180" : ""}`} />
            詳細を見る
          </CollapsibleTrigger>
          <CollapsibleContent className="space-y-1 pt-1 text-xs text-muted-foreground">
            {rec.description && <p>問題: {rec.description}</p>}
            {evidence && <p>根拠: {evidence}</p>}
            {current && <p>現在値: {current}</p>}
          </CollapsibleContent>
        </Collapsible>
      )}
    </div>
  );
}

export function RecommendationPreview({ analysisId, ranking }: { analysisId: number; ranking: RankingEntry[] }) {
  const { data, isLoading } = useAnalysisRecommendations(analysisId, {});
  const [showAll, setShowAll] = useState(false);

  const nameOf = (websiteAnalysisId: number) =>
    ranking.find((r) => r.website_analysis_id === websiteAnalysisId)?.website_name ?? `サイト #${websiteAnalysisId}`;

  if (showAll) {
    return <RecommendationList analysisId={analysisId} ranking={ranking} />;
  }

  const items = data?.data ?? [];
  const visible = items.slice(0, INITIAL_VISIBLE_COUNT);

  return (
    <Card>
      <CardHeader>
        <CardTitle>ルールベース改善提案</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {isLoading && <Skeleton className="h-24" />}
        {!isLoading && visible.length === 0 && <p className="text-sm text-muted-foreground">現時点で改善提案はありません。</p>}
        {visible.map((rec) => (
          <RecommendationPreviewCard key={rec.id} rec={rec} siteName={nameOf(rec.website_analysis_id)} />
        ))}
        {items.length > INITIAL_VISIBLE_COUNT && (
          <Button variant="outline" size="sm" onClick={() => setShowAll(true)}>
            すべての改善提案を見る({items.length}件)
          </Button>
        )}
      </CardContent>
    </Card>
  );
}

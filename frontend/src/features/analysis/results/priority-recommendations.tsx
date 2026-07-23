"use client";

import { useState } from "react";
import Link from "next/link";
import { ChevronDown } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { formatEvidence, formatMetricValueField } from "@/features/analysis/formatters/metric-evidence-formatter";
import type { ResultRecommendation } from "@/types/analysis";

const PRIORITY_LABELS: Record<ResultRecommendation["priority"], string> = { critical: "緊急", high: "高", medium: "中", low: "低" };
const PRIORITY_VARIANTS: Record<ResultRecommendation["priority"], "destructive" | "default" | "secondary" | "outline"> = {
  critical: "destructive",
  high: "default",
  medium: "secondary",
  low: "outline",
};
const IMPACT_LABELS: Record<ResultRecommendation["impact"], string> = { high: "大", medium: "中", low: "小" };
const EFFORT_LABELS: Record<ResultRecommendation["effort"], string> = { small: "小", medium: "中", large: "大" };

const INITIAL_VISIBLE_COUNT = 3;

function RecommendationCard({ rec, url }: { rec: ResultRecommendation; url: string | null }) {
  const [open, setOpen] = useState(false);
  const valueContext = { metric_value_type: rec.metric_value_type, metric_unit: rec.metric_unit };
  const current = formatMetricValueField(rec.current_value, valueContext);
  const recommended = formatMetricValueField(rec.recommended_value, valueContext);
  const evidence = formatEvidence(rec.evidence);
  const hasDetail = Boolean(rec.description || evidence || recommended || url);

  return (
    <div className="rounded-md border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <Badge variant={PRIORITY_VARIANTS[rec.priority]}>優先度: {PRIORITY_LABELS[rec.priority]}</Badge>
        <Badge variant="outline">想定効果: {IMPACT_LABELS[rec.impact]}</Badge>
        <Badge variant="outline">工数: {EFFORT_LABELS[rec.effort]}</Badge>
      </div>
      <p className="mt-2 font-medium">{rec.title}</p>
      {current && <p className="mt-1 text-xs text-muted-foreground">現在値: {current}</p>}

      {hasDetail && (
        <Collapsible open={open} onOpenChange={setOpen} className="mt-1.5">
          <CollapsibleTrigger render={<Button variant="ghost" size="sm" className="h-6 gap-1 px-1.5 text-xs" />}>
            <ChevronDown className={`size-3 transition-transform ${open ? "rotate-180" : ""}`} />
            詳細を見る
          </CollapsibleTrigger>
          <CollapsibleContent className="space-y-1 pt-1 text-xs text-muted-foreground">
            {rec.description && <p>問題: {rec.description}</p>}
            {evidence && <p>根拠: {evidence}</p>}
            {recommended && <p>推奨値: {recommended}</p>}
            {url && <p>対象URL: {url}</p>}
          </CollapsibleContent>
        </Collapsible>
      )}
    </div>
  );
}

export function PriorityRecommendations({
  recommendations,
  url,
  allRecommendationsHref,
}: {
  recommendations: ResultRecommendation[];
  url: string | null;
  allRecommendationsHref?: string;
}) {
  const top = recommendations.slice(0, INITIAL_VISIBLE_COUNT);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">優先改善項目</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {top.length === 0 ? (
          <p className="text-sm text-muted-foreground">現時点で改善提案はありません。</p>
        ) : (
          <>
            {top.map((rec) => (
              <RecommendationCard key={rec.id} rec={rec} url={url} />
            ))}
            {recommendations.length > INITIAL_VISIBLE_COUNT && allRecommendationsHref && (
              <Link href={allRecommendationsHref} className="inline-block text-sm text-primary underline underline-offset-4">
                すべての改善項目を見る({recommendations.length}件)
              </Link>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

"use client";

import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { classifyMetric } from "@/features/analysis/metric-evaluation";
import { MetricEvaluationCard } from "@/features/analysis/results/metric-evaluation-card";
import { metricsByCategory } from "@/features/analysis/results/metric-lookup";
import type { CategoryScore, MetricEvaluation } from "@/types/analysis";

export function CategoryScoreCard({ category, metrics }: { category: CategoryScore; metrics: MetricEvaluation[] }) {
  const [expanded, setExpanded] = useState(false);
  const categoryMetrics = metricsByCategory(metrics, category.key);
  const isUnavailable = category.max_available_score <= 0;

  const good = categoryMetrics.filter((m) => classifyMetric(m) === "good" || classifyMetric(m) === "info");
  const improve = categoryMetrics.filter((m) => classifyMetric(m) === "improve" || classifyMetric(m) === "review");
  const notMeasured = categoryMetrics.filter((m) => ["unavailable", "not_found", "failed"].includes(classifyMetric(m)));

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <CardTitle className="text-base">{category.name}</CardTitle>
        {isUnavailable ? (
          <Badge variant="outline">評価不可</Badge>
        ) : (
          <span className="text-sm text-muted-foreground">
            {category.score} / {category.configured_max_score}点(カバー率{Math.round(category.coverage_rate)}%)
          </span>
        )}
      </CardHeader>
      <CardContent className="space-y-3">
        {isUnavailable ? (
          <p className="text-sm text-muted-foreground">
            このカテゴリで採点対象となる指標を取得できなかったため、現在は採点できません。
          </p>
        ) : (
          <div className="grid gap-2 text-sm text-muted-foreground sm:grid-cols-3">
            <p>良かった項目: {good.length}件</p>
            <p>問題のあった項目: {improve.length}件</p>
            <p>未取得の項目: {notMeasured.length}件</p>
          </div>
        )}

        <Button variant="ghost" size="sm" onClick={() => setExpanded((v) => !v)}>
          {expanded ? "詳細を閉じる" : "詳細を開く"}
        </Button>

        {expanded && (
          <div className="grid gap-2 sm:grid-cols-2">
            {categoryMetrics.map((metric) => (
              <MetricEvaluationCard key={metric.key} metric={metric} />
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

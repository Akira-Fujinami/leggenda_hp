import { Badge } from "@/components/ui/badge";
import { classifyMetric, EVALUATION_BADGE_VARIANT, EVALUATION_LABELS, formatMetricValue } from "@/features/analysis/metric-evaluation";
import type { MetricEvaluation } from "@/types/analysis";

export function MetricEvaluationCard({
  metric,
  label,
  description,
  rangeLabel,
}: {
  metric: MetricEvaluation;
  label?: string;
  description?: string;
  rangeLabel?: string;
}) {
  const state = classifyMetric(metric);

  return (
    <div className="rounded-md border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium">{label ?? metric.name}</p>
        <Badge variant={EVALUATION_BADGE_VARIANT[state]}>{EVALUATION_LABELS[state]}</Badge>
      </div>
      <p className="mt-1 text-sm text-muted-foreground">
        現在値: {formatMetricValue(metric)}
        {rangeLabel && ` ・推奨範囲: ${rangeLabel}`}
      </p>
      {description && <p className="mt-1 text-xs text-muted-foreground">{description}</p>}
      {(state === "unavailable" || state === "failed") && metric.error_message && (
        <p className="mt-1 text-xs text-muted-foreground">{metric.error_message}</p>
      )}
    </div>
  );
}

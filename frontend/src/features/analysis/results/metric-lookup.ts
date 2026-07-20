import type { MetricEvaluation } from "@/types/analysis";

export function findMetric(metrics: MetricEvaluation[], key: string): MetricEvaluation | undefined {
  return metrics.find((m) => m.key === key);
}

export function metricsByCategory(metrics: MetricEvaluation[], categoryKey: string): MetricEvaluation[] {
  return metrics.filter((m) => m.category_key === categoryKey);
}

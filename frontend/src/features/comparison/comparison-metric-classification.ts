import type { MetricComparison, MetricSiteValue } from "@/types/comparison";

/**
 * 比較表示専用の分類(MetricResult/採点とは無関係の表示ヒューリスティック)。
 * サイト間の値をどのグループとして初期表示するかだけを決める。
 */
export type ComparisonRowState = "diff" | "improve" | "unavailable" | "good";

const RELATIVE_DIFF_THRESHOLD = 0.1; // 10%以上の相対差を「差が大きい」とみなす

function isMeasured(site: MetricSiteValue): boolean {
  return site.status === "success";
}

export function classifyComparisonMetric(metric: MetricComparison): ComparisonRowState {
  const measured = metric.sites.filter(isMeasured);
  if (measured.length < metric.sites.length) return "unavailable";
  if (measured.length === 0) return "unavailable";

  const first = measured[0].value;

  if (typeof first === "boolean") {
    const bad = measured.some((s) => (metric.higher_is_better ? s.value === false : s.value === true));
    if (bad) return "improve";
    const allSame = measured.every((s) => s.value === first);
    return allSame ? "good" : "diff";
  }

  if (typeof first === "number") {
    const values = measured.map((s) => s.value as number);
    const max = Math.max(...values);
    const min = Math.min(...values);
    if (max === min) return "good";
    const avg = values.reduce((a, b) => a + b, 0) / values.length;
    const relativeDiff = avg !== 0 ? (max - min) / Math.abs(avg) : max - min;
    return relativeDiff >= RELATIVE_DIFF_THRESHOLD ? "diff" : "good";
  }

  return "good";
}

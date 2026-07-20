import type { MetricEvaluation } from "@/types/analysis";

/**
 * 0点・未取得・失敗・対象外を明確に区別するための評価状態。
 * scoreがnull(=counts_toward_score=false)の項目を「0点」として
 * ratio計算しないことが最重要 ―― unavailable/error/not_applicable/
 * not_scoredはこの関数の早期リターンで弾かれ、良好〜要改善の判定には
 * 一切乗らない。
 */
export type EvaluationState = "good" | "review" | "improve" | "not_found" | "unavailable" | "not_applicable" | "failed" | "info";

export const EVALUATION_LABELS: Record<EvaluationState, string> = {
  good: "良好",
  review: "要確認",
  improve: "要改善",
  not_found: "検出されませんでした",
  unavailable: "データを取得できませんでした",
  not_applicable: "対象外",
  failed: "分析に失敗しました",
  info: "検出されました",
};

export const EVALUATION_BADGE_VARIANT: Record<EvaluationState, "default" | "secondary" | "outline" | "destructive"> = {
  good: "default",
  review: "secondary",
  improve: "destructive",
  not_found: "outline",
  unavailable: "outline",
  not_applicable: "outline",
  failed: "destructive",
  info: "outline",
};

export function classifyMetric(metric: MetricEvaluation): EvaluationState {
  if (metric.status === "error") return "failed";
  if (metric.status === "unavailable") return "unavailable";
  if (metric.status === "not_applicable") return "not_applicable";

  // 「技術の種類そのものへの優劣」をつけない情報項目(CMS/計測ツール検出等)。
  if (metric.scoring_type === "not_scored") {
    return metric.status === "not_found" ? "not_found" : "info";
  }

  // 採点対象外(exclude方針のnot_found等)、またはそもそも分母が無い場合は
  // 0点として扱わず、検出されなかった/評価不可のいずれかとして表示する。
  if (!metric.counts_toward_score || metric.max_score === null || metric.max_score <= 0) {
    return metric.status === "not_found" ? "not_found" : "unavailable";
  }

  const ratio = (metric.score ?? 0) / metric.max_score;

  if (ratio >= 0.8) return "good";
  if (ratio >= 0.5) return "review";

  return "improve";
}

export function formatMetricValue(metric: MetricEvaluation): string {
  const { value, unit } = metric;

  if (value === null || value === undefined) return "-";
  if (typeof value === "boolean") return value ? "あり" : "なし";
  if (typeof value === "number") return unit ? `${value}${unit}` : String(value);

  return String(value);
}

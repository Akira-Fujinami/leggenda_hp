import type { AnalysisScore } from "@/types/analysis";

export type ConfidenceBand = "high" | "ok" | "reference_only";

export const CONFIDENCE_BAND_LABELS: Record<ConfidenceBand, string> = {
  high: "高信頼",
  ok: "概ね参考可能",
  reference_only: "参考値",
};

/**
 * effective_confidence = coverage_rate(測定できた項目の割合) ×
 * measured_confidence_rate(測定済みデータ自体の信頼度) / 100。
 * 「取得できた指標の確からしさ」と「全体のうちどれだけ取得できたか」を
 * 別々に見せるのではなく、両方を掛け合わせた「総合評価の参考信頼度」を
 * 算出する。
 */
export function calculateEffectiveConfidence(score: Pick<AnalysisScore, "coverage_rate" | "confidence_rate">): number {
  return Math.round(((score.coverage_rate * score.confidence_rate) / 100) * 100) / 100;
}

export function classifyConfidenceBand(effectiveConfidence: number): ConfidenceBand {
  if (effectiveConfidence >= 85) return "high";
  if (effectiveConfidence >= 70) return "ok";
  return "reference_only";
}

import { formatNumberWithUnit } from "@/features/analysis/formatters/unit-labels";
import type { ResultRecommendation } from "@/types/analysis";

type ValueContext = Pick<ResultRecommendation, "metric_value_type" | "metric_unit">;

/**
 * Recommendation.current_value/recommended_value(内部形式は常に
 * {value: X}、MetricResult.normalized_valueと同じ形)を、ユーザー向けの
 * 文字列に整形する。raw JSONのキーをそのまま表示しない。
 */
export function formatMetricValueField(raw: unknown, context: ValueContext): string | null {
  if (raw === null || raw === undefined || typeof raw !== "object") {
    return null;
  }

  const record = raw as Record<string, unknown>;

  if (!("value" in record)) {
    return null;
  }

  const value = record.value;

  if (value === null || value === undefined) {
    return "未取得";
  }

  if (typeof value === "boolean") {
    return value ? "あり" : "検出されませんでした";
  }

  if (typeof value === "number") {
    if (context.metric_value_type === "percentage") {
      return `${(value * 100).toFixed(2)}%`;
    }

    return formatNumberWithUnit(value, context.metric_unit);
  }

  return String(value);
}

const KNOWN_EVIDENCE_KEY_LABELS: Record<string, string> = {
  count: "検出数",
  url: "URL",
  text: "リンクテキスト",
  confidence: "確信度",
  host: "ホスト",
  domain: "ドメイン",
  detected: "検出",
  provider: "Provider",
};

/**
 * Recommendation.evidence / MetricResult.evidence(構造化データ)を
 * ユーザー向けの短い説明文に整形する。raw JSONのキー名をそのまま
 * 出さないことを最優先とし、代表的な形(画像alt内訳・単純な件数)は
 * 専用の文章にする。それ以外は既知キーのみを日本語ラベルへ変換して
 * 列挙し、未知のキーは表示しない(内部実装の詳細を漏らさないため)。
 */
export function formatEvidence(evidence: Record<string, unknown> | null | undefined): string | null {
  if (!evidence || Object.keys(evidence).length === 0) {
    return null;
  }

  if (typeof evidence.total === "number" && typeof evidence.with_alt === "number") {
    const missing = typeof evidence.missing_alt === "number" ? evidence.missing_alt : null;
    return `画像${evidence.total}枚中、alt設定あり${evidence.with_alt}枚${missing !== null ? `・未設定${missing}枚` : ""}`;
  }

  if (typeof evidence.count === "number" && Object.keys(evidence).length === 1) {
    return `関連スクリプト検出数：${evidence.count}件`;
  }

  const parts = Object.entries(evidence)
    .filter(([key, value]) => key in KNOWN_EVIDENCE_KEY_LABELS && value !== null && value !== undefined && value !== "")
    .map(([key, value]) => `${KNOWN_EVIDENCE_KEY_LABELS[key]}: ${typeof value === "number" ? value : String(value)}`);

  return parts.length > 0 ? parts.join("、") : null;
}

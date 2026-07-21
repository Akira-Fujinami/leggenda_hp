import { Alert, AlertDescription } from "@/components/ui/alert";
import { CONFIDENCE_BAND_LABELS, calculateEffectiveConfidence, classifyConfidenceBand } from "@/features/analysis/effective-confidence";
import type { AnalysisScore, HtmlAnalysisSource } from "@/types/analysis";

export const COVERAGE_THRESHOLD = 70;

function htmlAnalysisSourceLine(htmlAnalysisSource?: HtmlAnalysisSource): { label: string; warning: string | null } | null {
  if (!htmlAnalysisSource || htmlAnalysisSource.source === null) return null;

  if (htmlAnalysisSource.source === "rendered") {
    return { label: "HTML解析元: レンダリング済みページ", warning: null };
  }

  // source === "static"
  if (htmlAnalysisSource.fallback_used) {
    return {
      label: "HTML解析元: 静的HTML",
      warning: "JavaScriptレンダリングに失敗したため、一部の動的要素(SNSリンク・価格付き商品カード等)を検出できていない可能性があります。",
    };
  }

  return { label: "HTML解析元: 静的HTML(レンダリング済みページの再解析待ち)", warning: null };
}

export function DataQualityNotice({ score, htmlAnalysisSource }: { score: AnalysisScore; htmlAnalysisSource?: HtmlAnalysisSource }) {
  const isReferenceOnly = score.coverage_rate < COVERAGE_THRESHOLD;
  const effectiveConfidence = calculateEffectiveConfidence(score);
  const confidenceBand = classifyConfidenceBand(effectiveConfidence);
  const scoredUnavailable = score.metric_summary.scored_unavailable;
  const informationalUnavailable = score.metric_summary.informational_unavailable;
  const sourceLine = htmlAnalysisSourceLine(htmlAnalysisSource);

  return (
    <div className="rounded-md border p-4">
      <p className="text-sm text-muted-foreground">{isReferenceOnly ? "参考スコア" : "総合スコア"}</p>
      <p className="text-2xl font-semibold">
        {score.display_score}
        <span className="text-sm font-normal text-muted-foreground"> / {score.configured_max_score}</span>
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        測定カバー率: {Math.round(score.coverage_rate)}% ・測定済みデータの信頼度: {Math.round(score.confidence_rate)}%
        {score.metric_summary.error > 0 && ` ・分析失敗: ${score.metric_summary.error}件`}
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        採点対象の未取得: {scoredUnavailable}件 ・参考情報の未取得: {informationalUnavailable}件
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        総合評価の参考信頼度: {effectiveConfidence}%({CONFIDENCE_BAND_LABELS[confidenceBand]})
      </p>
      {sourceLine && <p className="mt-1 text-xs text-muted-foreground">{sourceLine.label}</p>}

      {isReferenceOnly && (
        <Alert className="mt-3">
          <AlertDescription>
            測定カバー率が{Math.round(score.coverage_rate)}%のため、このスコアは参考値です。取得できなかった項目を除いた範囲での評価としてご確認ください。
          </AlertDescription>
        </Alert>
      )}

      {sourceLine?.warning && (
        <Alert className="mt-3">
          <AlertDescription>{sourceLine.warning}</AlertDescription>
        </Alert>
      )}
    </div>
  );
}

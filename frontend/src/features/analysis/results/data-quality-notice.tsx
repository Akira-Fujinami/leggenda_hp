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

/**
 * showPercentage=true時の見出し数値。available_score(実際に測れた範囲の満点)を
 * 分母にする ―― configured_max_score(設計上固定の満点)を分母にすると、4観点
 * 比較チャート(LeadPerspectiveComparison)の各バー(同じくmax_available_scoreを
 * 分母にしている)と数値が一致しなくなる(2026-08-03のユーザー指摘・実データ検証済み:
 * overall_score/available_scoreは4観点バーのmax_available_score加重平均と
 * 代数的に同一)。1件も測れていない(available_score<=0)場合は0%ではなくnullを
 * 返す ―― 0%は「測ったが全部だめだった」という意味になってしまうため
 * (4観点バーがmax_available_score<=0のときnullを返すのと同じ判断)。
 */
function percentageScore(score: AnalysisScore): number | null {
  if (score.available_score <= 0) return null;

  return Math.round((score.overall_score / score.available_score) * 100);
}

export function DataQualityNotice({
  score,
  htmlAnalysisSource,
  label = "総合スコア",
  referenceLabel = "参考スコア",
  showPercentage = false,
}: {
  score: AnalysisScore;
  htmlAnalysisSource?: HtmlAnalysisSource;
  // リード向け画面など、社内版の総合スコアとは別建ての値であることを
  // 見出しで明示したい場合に上書きする(既定値は社内版の内部ダッシュボードと同じ)。
  label?: string;
  referenceLabel?: string;
  // リード向け画面専用。4観点比較チャートのバーと同じ尺度(0〜100%)で
  // 見出しの数値を表示する(既定false=社内向けの点数表示のまま、
  // 2026-08-03のユーザー指摘への対応)。
  showPercentage?: boolean;
}) {
  const isReferenceOnly = score.coverage_rate < COVERAGE_THRESHOLD;
  const effectiveConfidence = calculateEffectiveConfidence(score);
  const confidenceBand = classifyConfidenceBand(effectiveConfidence);
  const scoredUnavailable = score.metric_summary.scored_unavailable;
  const informationalUnavailable = score.metric_summary.informational_unavailable;
  const sourceLine = htmlAnalysisSourceLine(htmlAnalysisSource);
  const percentage = showPercentage ? percentageScore(score) : null;

  return (
    <div className="rounded-md border p-4">
      <p className="text-sm text-muted-foreground">{isReferenceOnly ? referenceLabel : label}</p>
      {showPercentage ? (
        percentage === null ? (
          <p className="text-2xl font-semibold">評価できませんでした</p>
        ) : (
          <p className="text-2xl font-semibold">{percentage}%</p>
        )
      ) : (
        <p className="text-2xl font-semibold">
          {score.display_score}
          <span className="text-sm font-normal text-muted-foreground"> / {score.configured_max_score}</span>
        </p>
      )}
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

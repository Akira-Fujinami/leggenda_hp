import { Alert, AlertDescription } from "@/components/ui/alert";
import type { AnalysisScore } from "@/types/analysis";

const COVERAGE_THRESHOLD = 70;

export function DataQualityNotice({ score }: { score: AnalysisScore }) {
  const isReferenceOnly = score.coverage_rate < COVERAGE_THRESHOLD;

  return (
    <div className="rounded-md border p-4">
      <p className="text-sm text-muted-foreground">{isReferenceOnly ? "参考スコア" : "総合スコア"}</p>
      <p className="text-2xl font-semibold">
        {score.display_score}
        <span className="text-sm font-normal text-muted-foreground"> / {score.configured_max_score}</span>
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        測定カバー率: {Math.round(score.coverage_rate)}% ・データ信頼度: {Math.round(score.confidence_rate)}%
        {score.metric_summary.error > 0 && ` ・分析失敗: ${score.metric_summary.error}件`}
        {score.metric_summary.unavailable > 0 && ` ・未取得: ${score.metric_summary.unavailable}件`}
      </p>

      {isReferenceOnly && (
        <Alert className="mt-3">
          <AlertDescription>
            測定カバー率が{Math.round(score.coverage_rate)}%のため、このスコアは参考値です。取得できなかった項目を除いた範囲での評価としてご確認ください。
          </AlertDescription>
        </Alert>
      )}
    </div>
  );
}

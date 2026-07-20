import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { AnalysisScore, ResultRecommendation } from "@/types/analysis";

function evaluateOverall(score: AnalysisScore): string {
  if (score.coverage_rate < 70) return "測定できた範囲では判断が難しい状況です";
  if (score.display_score >= 80) return "全体的に良好な状態です";
  if (score.display_score >= 60) return "一定水準は満たしていますが、改善余地があります";

  return "改善が必要な項目が多い状態です";
}

export function AnalysisSummary({
  websiteName,
  score,
  recommendations,
  generatedAt,
}: {
  websiteName: string | null;
  score: AnalysisScore;
  recommendations: ResultRecommendation[];
  generatedAt: string | null;
}) {
  const measuredCategories = score.category_scores.filter((c) => c.max_available_score > 0);
  const strengths = measuredCategories.filter((c) => c.score / c.configured_max_score >= 0.8);
  const weaknesses = measuredCategories.filter((c) => c.score / c.configured_max_score < 0.5);
  const topPriority = recommendations[0] ?? null;
  const unavailableCount = score.metric_summary.unavailable + score.metric_summary.error;

  const summaryText = [
    `${websiteName ?? "このサイト"}は、${evaluateOverall(score)}。`,
    strengths.length > 0 ? `${strengths.map((c) => c.name).join("・")}は良好な水準です。` : null,
    weaknesses.length > 0 ? `一方で${weaknesses.map((c) => c.name).join("・")}に改善余地があります。` : null,
    unavailableCount > 0 ? `また、${unavailableCount}件の項目は今回取得できませんでした。` : null,
    score.coverage_rate < 70
      ? `現在の結果は測定カバー率${Math.round(score.coverage_rate)}%のため、参考値としてご確認ください。`
      : null,
  ]
    .filter(Boolean)
    .join("");

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">分析サマリー</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="text-sm">{summaryText}</p>

        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <p className="text-xs font-medium text-muted-foreground">主な強み</p>
            {strengths.length > 0 ? (
              <div className="mt-1 flex flex-wrap gap-1">
                {strengths.map((c) => (
                  <Badge key={c.key} variant="secondary">
                    {c.name}
                  </Badge>
                ))}
              </div>
            ) : (
              <p className="mt-1 text-sm text-muted-foreground">目立った強みは見つかりませんでした</p>
            )}
          </div>
          <div>
            <p className="text-xs font-medium text-muted-foreground">主な弱み</p>
            {weaknesses.length > 0 ? (
              <div className="mt-1 flex flex-wrap gap-1">
                {weaknesses.map((c) => (
                  <Badge key={c.key} variant="destructive">
                    {c.name}
                  </Badge>
                ))}
              </div>
            ) : (
              <p className="mt-1 text-sm text-muted-foreground">目立った弱みは見つかりませんでした</p>
            )}
          </div>
        </div>

        {topPriority && (
          <div>
            <p className="text-xs font-medium text-muted-foreground">最優先の改善候補</p>
            <p className="mt-1 text-sm">{topPriority.title}</p>
          </div>
        )}

        <div className="grid grid-cols-2 gap-2 text-xs text-muted-foreground sm:grid-cols-4">
          <p>測定カバー率: {Math.round(score.coverage_rate)}%</p>
          <p>データ信頼度: {Math.round(score.confidence_rate)}%</p>
          <p>未取得件数: {unavailableCount}件</p>
          <p>分析日時: {generatedAt ? new Date(generatedAt).toLocaleString("ja-JP") : "-"}</p>
        </div>
      </CardContent>
    </Card>
  );
}

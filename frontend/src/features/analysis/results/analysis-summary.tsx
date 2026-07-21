import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { AnalysisScore, CategoryScore, MetricEvaluation, ResultRecommendation } from "@/types/analysis";

const LIGHTHOUSE_METRIC_KEYS = ["lighthouse_performance", "lighthouse_accessibility", "lighthouse_best_practices", "lighthouse_seo_score", "fcp", "lcp", "cls", "speed_index", "tbt"];

function isLighthouseSingleRun(metrics: MetricEvaluation[]): boolean {
  return metrics.some((m) => {
    if (!LIGHTHOUSE_METRIC_KEYS.includes(m.key) || m.status !== "success") return false;
    const evidence = m.evidence as { metadata?: { run_count?: number } } | null;
    return evidence?.metadata?.run_count === 1;
  });
}

/**
 * 総合点の高低だけで断定せず、測定できた範囲・強み/弱みの中身・
 * Lighthouse単発計測の影響を踏まえた文章にする(「改善が必要な項目が
 * 多い状態です」のような単なる点数区分による断定は行わない)。
 */
function buildSummarySentences(
  websiteName: string | null,
  score: AnalysisScore,
  strengths: CategoryScore[],
  weaknesses: CategoryScore[],
  lighthouseSingleRun: boolean,
): string[] {
  const subject = websiteName ?? "このサイト";
  const sentences: string[] = [];

  if (score.coverage_rate < 70) {
    sentences.push(`${subject}は、測定できた範囲では判断が難しい状況です(測定カバー率${Math.round(score.coverage_rate)}%)。`);
  } else if (strengths.length > 0 || weaknesses.length > 0) {
    if (strengths.length > 0) {
      sentences.push(`測定できた範囲では、${strengths.map((c) => c.name).join("・")}は良好です。`);
    }
    if (weaknesses.length > 0) {
      sentences.push(`${weaknesses.map((c) => c.name).join("・")}には改善余地があります。`);
    }
  } else if (score.display_score >= 80) {
    sentences.push(`${subject}は、測定できた範囲では全体的に良好な状態です。`);
  } else if (score.display_score >= 60) {
    sentences.push(`${subject}は、一定水準は満たしていますが、改善余地があります。`);
  } else {
    sentences.push(`${subject}は、測定できた範囲では改善余地のある項目が複数あります。`);
  }

  if (lighthouseSingleRun) {
    sentences.push("なお、総合評価はLighthouseのローカル環境での単発計測結果の影響を受けています。参考値としてご確認ください。");
  }

  return sentences;
}

export function AnalysisSummary({
  websiteName,
  score,
  recommendations,
  metrics,
  generatedAt,
}: {
  websiteName: string | null;
  score: AnalysisScore;
  recommendations: ResultRecommendation[];
  metrics: MetricEvaluation[];
  generatedAt: string | null;
}) {
  const measuredCategories = score.category_scores.filter((c) => c.max_available_score > 0);
  const strengths = measuredCategories.filter((c) => c.score / c.configured_max_score >= 0.8);
  const weaknesses = measuredCategories.filter((c) => c.score / c.configured_max_score < 0.5);
  const topPriority = recommendations[0] ?? null;
  const scoredUnavailableCount = score.metric_summary.scored_unavailable;
  const lighthouseSingleRun = isLighthouseSingleRun(metrics);

  const summarySentences = buildSummarySentences(websiteName, score, strengths, weaknesses, lighthouseSingleRun);
  if (scoredUnavailableCount > 0) {
    summarySentences.push(`また、採点対象のうち${scoredUnavailableCount}件の項目は今回取得できませんでした。`);
  }
  const summaryText = summarySentences.join("");

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
          <p>未取得件数(採点対象): {scoredUnavailableCount}件</p>
          <p>分析日時: {generatedAt ? new Date(generatedAt).toLocaleString("ja-JP") : "-"}</p>
        </div>
      </CardContent>
    </Card>
  );
}

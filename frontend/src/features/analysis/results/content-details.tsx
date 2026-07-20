import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { MetricEvaluationCard } from "@/features/analysis/results/metric-evaluation-card";
import { findMetric } from "@/features/analysis/results/metric-lookup";
import type { MetricEvaluation } from "@/types/analysis";

export function ContentDetails({ metrics }: { metrics: MetricEvaluation[] }) {
  const wordCount = findMetric(metrics, "word_count_sufficient");
  const altCoverage = findMetric(metrics, "img_alt_coverage");
  const internalLinks = findMetric(metrics, "internal_link_sufficient");
  const externalLinks = findMetric(metrics, "external_link_present");
  const headingStructure = findMetric(metrics, "heading_structure_present");

  const altRaw = altCoverage?.raw_value as { total?: number; with_alt?: number; missing_alt?: number } | null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">コンテンツ分析</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 sm:grid-cols-2">
        {wordCount && <MetricEvaluationCard metric={wordCount} label="本文の文字数" />}
        {altCoverage && (
          <MetricEvaluationCard
            metric={altCoverage}
            label="画像alt充足率"
            description={
              altRaw
                ? `画像${altRaw.total ?? "-"}枚中、alt設定あり${altRaw.with_alt ?? "-"}枚・未設定${altRaw.missing_alt ?? "-"}枚`
                : undefined
            }
          />
        )}
        {internalLinks && <MetricEvaluationCard metric={internalLinks} label="内部リンク数" />}
        {externalLinks && <MetricEvaluationCard metric={externalLinks} label="外部リンク" />}
        {headingStructure && <MetricEvaluationCard metric={headingStructure} label="見出し構造" />}
      </CardContent>
    </Card>
  );
}

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { MetricEvaluationCard } from "@/features/analysis/results/metric-evaluation-card";
import { findMetric } from "@/features/analysis/results/metric-lookup";
import type { MetricEvaluation } from "@/types/analysis";

interface BusinessLinkRaw {
  url?: string | null;
  text?: string | null;
}

function businessLink(metric: MetricEvaluation | undefined): { url: string; text: string | null } | null {
  const raw = metric?.raw_value as BusinessLinkRaw | null;
  if (!raw?.url) return null;
  return { url: raw.url, text: raw.text ?? null };
}

export function ContentDetails({ metrics }: { metrics: MetricEvaluation[] }) {
  const wordCount = findMetric(metrics, "word_count_sufficient");
  const altCoverage = findMetric(metrics, "img_alt_coverage");
  const internalLinks = findMetric(metrics, "internal_link_sufficient");
  const externalLinks = findMetric(metrics, "external_link_present");
  const headingStructure = findMetric(metrics, "heading_structure_present");
  const pricing = findMetric(metrics, "pricing_info_link_present");
  const caseStudy = findMetric(metrics, "case_study_or_testimonial_link_present");
  const companyInfo = findMetric(metrics, "company_info_link_present");
  const privacyPolicy = findMetric(metrics, "privacy_policy_link_present");
  const faq = findMetric(metrics, "faq_link_present");

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
        {pricing && <MetricEvaluationCard metric={pricing} label="料金情報リンク" link={businessLink(pricing)} />}
        {caseStudy && <MetricEvaluationCard metric={caseStudy} label="導入事例・お客様の声" link={businessLink(caseStudy)} />}
        {companyInfo && <MetricEvaluationCard metric={companyInfo} label="会社概要リンク" link={businessLink(companyInfo)} />}
        {privacyPolicy && <MetricEvaluationCard metric={privacyPolicy} label="プライバシーポリシー" link={businessLink(privacyPolicy)} />}
        {faq && <MetricEvaluationCard metric={faq} label="FAQ/よくある質問" link={businessLink(faq)} />}
      </CardContent>
    </Card>
  );
}

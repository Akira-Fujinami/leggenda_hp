import type { ReactNode } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { classifyMetric, isGoodEvaluationState } from "@/features/analysis/metric-evaluation";
import { GoodItemsCollapsible } from "@/features/analysis/results/good-items-collapsible";
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

interface ContentRow {
  key: string;
  state: ReturnType<typeof classifyMetric>;
  node: ReactNode;
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
  const helpCenter = findMetric(metrics, "help_center_link_present");
  const priceCard = findMetric(metrics, "pricing_card_or_product_price_present");

  const altRaw = altCoverage?.raw_value as { total?: number; with_alt?: number; missing_alt?: number } | null;
  const priceCardRaw = priceCard?.raw_value as { count?: number; sample_text?: string | null } | null;

  const rows: ContentRow[] = [];
  if (wordCount) rows.push({ key: "word_count", state: classifyMetric(wordCount), node: <MetricEvaluationCard metric={wordCount} label="本文の文字数" /> });
  if (altCoverage) {
    rows.push({
      key: "alt_coverage",
      state: classifyMetric(altCoverage),
      node: (
        <MetricEvaluationCard
          metric={altCoverage}
          label="画像alt充足率"
          description={
            altRaw
              ? `画像${altRaw.total ?? "-"}枚中、alt設定あり${altRaw.with_alt ?? "-"}枚・未設定${altRaw.missing_alt ?? "-"}枚`
              : undefined
          }
        />
      ),
    });
  }
  if (internalLinks) rows.push({ key: "internal_links", state: classifyMetric(internalLinks), node: <MetricEvaluationCard metric={internalLinks} label="内部リンク数" /> });
  if (externalLinks) rows.push({ key: "external_links", state: classifyMetric(externalLinks), node: <MetricEvaluationCard metric={externalLinks} label="外部リンク" /> });
  if (headingStructure) {
    rows.push({ key: "heading_structure", state: classifyMetric(headingStructure), node: <MetricEvaluationCard metric={headingStructure} label="見出し構造" /> });
  }
  if (pricing) {
    rows.push({
      key: "pricing",
      state: classifyMetric(pricing),
      node: <MetricEvaluationCard metric={pricing} label="料金情報リンク" link={businessLink(pricing)} />,
    });
  }
  if (priceCard) {
    rows.push({
      key: "price_card",
      state: classifyMetric(priceCard),
      node: (
        <MetricEvaluationCard
          metric={priceCard}
          label="価格付き商品・プラン"
          description={
            priceCardRaw?.sample_text
              ? `例: ${priceCardRaw.sample_text}(検出数: ${priceCardRaw.count ?? "-"}件)`
              : "固定の料金ページが無くても、商品・プランカード上の価格表示を検出した場合はここに表示されます。"
          }
        />
      ),
    });
  }
  if (caseStudy) {
    rows.push({ key: "case_study", state: classifyMetric(caseStudy), node: <MetricEvaluationCard metric={caseStudy} label="導入事例・お客様の声" link={businessLink(caseStudy)} /> });
  }
  if (companyInfo) {
    rows.push({ key: "company_info", state: classifyMetric(companyInfo), node: <MetricEvaluationCard metric={companyInfo} label="会社概要リンク" link={businessLink(companyInfo)} /> });
  }
  if (privacyPolicy) {
    rows.push({ key: "privacy_policy", state: classifyMetric(privacyPolicy), node: <MetricEvaluationCard metric={privacyPolicy} label="プライバシーポリシー" link={businessLink(privacyPolicy)} /> });
  }
  if (faq) rows.push({ key: "faq", state: classifyMetric(faq), node: <MetricEvaluationCard metric={faq} label="FAQ/よくある質問" link={businessLink(faq)} /> });
  if (helpCenter) {
    rows.push({ key: "help_center", state: classifyMetric(helpCenter), node: <MetricEvaluationCard metric={helpCenter} label="ヘルプ・サポート導線" link={businessLink(helpCenter)} /> });
  }

  const visibleRows = rows.filter((r) => !isGoodEvaluationState(r.state));
  const goodRows = rows.filter((r) => isGoodEvaluationState(r.state));

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">コンテンツ分析</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 sm:grid-cols-2">
        {visibleRows.map((r) => (
          <div key={r.key}>{r.node}</div>
        ))}
        <GoodItemsCollapsible count={goodRows.length}>
          {goodRows.map((r) => (
            <div key={r.key}>{r.node}</div>
          ))}
        </GoodItemsCollapsible>
      </CardContent>
    </Card>
  );
}

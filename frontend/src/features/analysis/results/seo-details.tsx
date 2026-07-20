import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { classifyMetric, EVALUATION_BADGE_VARIANT, EVALUATION_LABELS } from "@/features/analysis/metric-evaluation";
import { MetricEvaluationCard } from "@/features/analysis/results/metric-evaluation-card";
import { findMetric } from "@/features/analysis/results/metric-lookup";
import type { AnalysisSeoSummary, MetricEvaluation } from "@/types/analysis";

function LengthCard({
  label,
  presenceMetric,
  lengthMetric,
  text,
}: {
  label: string;
  presenceMetric?: MetricEvaluation;
  lengthMetric?: MetricEvaluation;
  text: string | null;
}) {
  const metric = lengthMetric ?? presenceMetric;
  if (!metric) return null;

  const state = classifyMetric(metric);
  const rangeLabel =
    lengthMetric && (lengthMetric.min_value !== null || lengthMetric.max_value !== null)
      ? `${lengthMetric.min_value ?? "-"}〜${lengthMetric.max_value ?? "-"}文字`
      : undefined;

  return (
    <div className="rounded-md border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium">{label}</p>
        <Badge variant={EVALUATION_BADGE_VARIANT[state]}>{EVALUATION_LABELS[state]}</Badge>
      </div>
      <p className="mt-1 text-sm text-muted-foreground">
        現在値: {typeof lengthMetric?.value === "number" ? `${lengthMetric.value}文字` : presenceMetric?.value ? "設定あり" : "未設定"}
        {rangeLabel && ` ・推奨範囲: ${rangeLabel}`}
      </p>
      {text && <p className="mt-1 truncate text-xs text-muted-foreground">内容: {text}</p>}
    </div>
  );
}

interface H1RawValue {
  count?: number;
  primary_text?: string | null;
}

function H1Card({ metric }: { metric: MetricEvaluation }) {
  const raw = metric.raw_value as H1RawValue | null;
  const count = raw?.count;
  const primaryText = raw?.primary_text;

  return (
    <MetricEvaluationCard
      metric={metric}
      label="H1タグ"
      description={
        count !== undefined
          ? `H1: ${count}件${primaryText ? ` ・内容: ${primaryText}(ページの主題と一致しているか内容をご確認ください)` : ""}`
          : "ページの主題を表すH1見出しを1件だけ設定することが推奨されます。"
      }
    />
  );
}

export function SeoDetails({ metrics, seo }: { metrics: MetricEvaluation[]; seo: AnalysisSeoSummary | null }) {
  const canonical = findMetric(metrics, "canonical_present");
  const canonicalSelf = findMetric(metrics, "canonical_self_referencing");
  const robotsMeta = findMetric(metrics, "robots_meta_indexable");
  const ogp = findMetric(metrics, "ogp_present");
  const jsonLd = findMetric(metrics, "structured_data_present");
  const lang = findMetric(metrics, "lang_present");
  const viewport = findMetric(metrics, "viewport_present");
  const favicon = findMetric(metrics, "favicon_present");
  const robotsTxt = findMetric(metrics, "robots_fetched");
  const sitemapXml = findMetric(metrics, "sitemap_fetched");

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">SEO基本情報</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 sm:grid-cols-2">
        <LengthCard
          label="タイトル(title)"
          presenceMetric={findMetric(metrics, "title_present")}
          lengthMetric={findMetric(metrics, "title_length_optimal")}
          text={seo?.title ?? null}
        />
        <LengthCard
          label="meta description"
          presenceMetric={findMetric(metrics, "meta_description_present")}
          lengthMetric={findMetric(metrics, "meta_description_length_optimal")}
          text={seo?.meta_description ?? null}
        />
        {findMetric(metrics, "h1_single") && (
          <H1Card metric={findMetric(metrics, "h1_single")!} />
        )}
        {canonical && <MetricEvaluationCard metric={canonicalSelf ?? canonical} label="canonicalタグ" />}
        {robotsMeta && <MetricEvaluationCard metric={robotsMeta} label="robots meta(インデックス可否)" />}
        {ogp && <MetricEvaluationCard metric={ogp} label="OGP(SNSシェア表示)" />}
        {jsonLd && <MetricEvaluationCard metric={jsonLd} label="構造化データ(JSON-LD)" />}
        {lang && <MetricEvaluationCard metric={lang} label="lang属性" />}
        {viewport && <MetricEvaluationCard metric={viewport} label="viewport(モバイル表示設定)" />}
        {favicon && <MetricEvaluationCard metric={favicon} label="favicon" />}
        {robotsTxt && <MetricEvaluationCard metric={robotsTxt} label="robots.txt" />}
        {sitemapXml && <MetricEvaluationCard metric={sitemapXml} label="sitemap.xml" />}
      </CardContent>
    </Card>
  );
}

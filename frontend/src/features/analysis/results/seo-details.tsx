import type { ReactNode } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { classifyMetric, isGoodEvaluationState } from "@/features/analysis/metric-evaluation";
import { EvaluationBadge } from "@/features/analysis/results/evaluation-badge";
import { GoodItemsCollapsible } from "@/features/analysis/results/good-items-collapsible";
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
        <EvaluationBadge state={state} />
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
  valid_count?: number;
  visible_count?: number;
  primary_text?: string | null;
}

/**
 * H1タグは代表的な内部矛盾バグの発生源だったため、他のMetricのように
 * MetricEvaluationCard(汎用のformatMetricValueが素のboolean normalized_value
 * から「あり/なし」を導出する)には委譲しない。normalized_valueは
 * 「有効なH1がちょうど1件という採点基準を満たすか」という採点専用の
 * 意味であり(valid_count>=2でもfalseになる)、H1の有無表示にそのまま
 * 使うと「H1: 3件」と同時に「現在値: なし」が表示される矛盾を再現して
 * しまう。ここでは必ずraw_value.valid_countのみからH1の状態を導出する。
 */
function classifyH1Badge(validCount: number, totalCount: number): ReturnType<typeof classifyMetric> {
  if (validCount === 0) return "not_found";
  if (validCount === 1 && totalCount === validCount) return "good";
  return "review";
}

function h1Note(validCount: number): string {
  if (validCount === 0) return "ページの主題を表すH1見出しを設定することが推奨されます。";
  if (validCount === 1) return "代表H1の内容がページの主題と一致しているか確認してください。";
  return `主要なH1が${validCount}件検出されました。ページの主題を1つに絞ることを推奨します。`;
}

/** H1Card内で実際に表示される状態と一致させ、良好項目の折りたたみ判定にそのまま使う。 */
function classifyH1RowState(metric: MetricEvaluation): ReturnType<typeof classifyMetric> {
  if (metric.status === "error" || metric.status === "unavailable") return classifyMetric(metric);
  const raw = metric.raw_value as H1RawValue | null;
  const validCount = raw?.valid_count ?? raw?.count ?? 0;
  const totalCount = raw?.count ?? 0;
  return classifyH1Badge(validCount, totalCount);
}

function H1Card({ metric }: { metric: MetricEvaluation }) {
  const raw = metric.raw_value as H1RawValue | null;

  if (metric.status === "error" || metric.status === "unavailable") {
    const state = classifyMetric(metric);
    return (
      <div className="rounded-md border p-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm font-medium">H1タグ</p>
          <EvaluationBadge state={state} />
        </div>
        {metric.error_message && <p className="mt-1 text-xs text-muted-foreground">{metric.error_message}</p>}
      </div>
    );
  }

  // 旧形式のraw_value(valid_countフィールド導入前の既存Analysis)には
  // valid_countが存在しない。undefinedの場合はraw.countにフォールバック
  // することで、「H1タグは実在するのに0件と誤表示される」新たな後退を
  // 避ける(広告H1除外等、新ロジックでしか分からない情報までは補えないが、
  // 少なくとも「検出されませんでした」への誤表示は防ぐ)。
  const validCount = raw?.valid_count ?? raw?.count ?? 0;
  const totalCount = raw?.count ?? 0;
  const excludedCount = Math.max(0, totalCount - validCount);
  const badgeState = classifyH1Badge(validCount, totalCount);

  return (
    <div className="rounded-md border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium">H1タグ</p>
        <EvaluationBadge state={badgeState} />
      </div>
      <div className="mt-1 space-y-0.5 text-sm text-muted-foreground">
        <p>有効なH1: {validCount}件</p>
        <p>検出したH1: {totalCount}件</p>
        {raw?.primary_text && <p>代表H1: {raw.primary_text}</p>}
        {excludedCount > 0 && <p>広告・非主要見出し: {excludedCount}件</p>}
      </div>
      <p className="mt-1 text-xs text-muted-foreground">{h1Note(validCount)}</p>
    </div>
  );
}

interface SeoRow {
  key: string;
  state: ReturnType<typeof classifyMetric>;
  node: ReactNode;
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
  const h1 = findMetric(metrics, "h1_single");

  const rows: SeoRow[] = [];

  const titleMetric = findMetric(metrics, "title_length_optimal") ?? findMetric(metrics, "title_present");
  if (titleMetric) {
    rows.push({
      key: "title",
      state: classifyMetric(titleMetric),
      node: (
        <LengthCard
          label="タイトル(title)"
          presenceMetric={findMetric(metrics, "title_present")}
          lengthMetric={findMetric(metrics, "title_length_optimal")}
          text={seo?.title ?? null}
        />
      ),
    });
  }

  const metaMetric = findMetric(metrics, "meta_description_length_optimal") ?? findMetric(metrics, "meta_description_present");
  if (metaMetric) {
    rows.push({
      key: "meta_description",
      state: classifyMetric(metaMetric),
      node: (
        <LengthCard
          label="meta description"
          presenceMetric={findMetric(metrics, "meta_description_present")}
          lengthMetric={findMetric(metrics, "meta_description_length_optimal")}
          text={seo?.meta_description ?? null}
        />
      ),
    });
  }

  if (h1) {
    rows.push({ key: "h1", state: classifyH1RowState(h1), node: <H1Card metric={h1} /> });
  }

  const simpleRows: Array<[MetricEvaluation | undefined, string]> = [
    [canonical ? (canonicalSelf ?? canonical) : undefined, "canonicalタグ"],
    [robotsMeta, "robots meta(インデックス可否)"],
    [ogp, "OGP(SNSシェア表示)"],
    [jsonLd, "構造化データ(JSON-LD)"],
    [lang, "lang属性"],
    [viewport, "viewport(モバイル表示設定)"],
    [favicon, "favicon"],
    [robotsTxt, "robots.txt"],
    [sitemapXml, "sitemap.xml"],
  ];
  for (const [metric, label] of simpleRows) {
    if (!metric) continue;
    rows.push({ key: label, state: classifyMetric(metric), node: <MetricEvaluationCard metric={metric} label={label} /> });
  }

  const visibleRows = rows.filter((r) => !isGoodEvaluationState(r.state));
  const goodRows = rows.filter((r) => isGoodEvaluationState(r.state));

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">SEO基本情報</CardTitle>
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

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatMetricValue } from "@/features/analysis/metric-evaluation";
import { findMetric } from "@/features/analysis/results/metric-lookup";
import type { MetricEvaluation } from "@/types/analysis";

const DISPLAY_KEYS: Array<{ key: string; label: string }> = [
  { key: "authority_score", label: "Authority Score" },
  { key: "organic_traffic_estimate", label: "オーガニックトラフィック推定" },
  { key: "organic_keywords_count", label: "オーガニックキーワード数" },
  { key: "top10_keywords_count", label: "上位10位以内キーワード数" },
  { key: "top3_keywords_count", label: "上位3位以内キーワード数" },
  { key: "backlinks_count", label: "被リンク数" },
  { key: "referring_domains_count", label: "参照ドメイン数" },
  { key: "competitor_domains_count", label: "競合ドメイン数" },
  { key: "paid_search_present", label: "有料検索(リスティング広告)の有無" },
];

const UNAVAILABLE_REASON_LABELS: Record<string, string> = {
  SEMRUSH_NOT_CONFIGURED: "Semrush APIキーが設定されていないため取得できませんでした。",
  SEO_PROVIDER_INVALID: "外部SEOデータの設定が不正です。",
  SEMRUSH_AUTH_FAILED: "Semrush APIの認証に失敗しました。",
  SEMRUSH_RATE_LIMITED: "Semrush APIのレート制限に達しました。",
  SEMRUSH_QUOTA_EXCEEDED: "Semrush APIの利用可能ユニットが不足しています。",
  SEMRUSH_DAILY_LIMIT_REACHED: "本日の外部SEO API利用上限に達しています。",
  SEMRUSH_UNAVAILABLE: "Semrush APIに接続できませんでした。",
  SEMRUSH_METRIC_UNAVAILABLE: "この指標は現在のSemrush契約プランでは取得できません。",
};

function deriveDataState(authorityMetrics: MetricEvaluation[]): "real" | "mock" | "unavailable" {
  if (authorityMetrics.some((m) => m.status === "success")) return "real";
  if (authorityMetrics.some((m) => m.status === "not_applicable")) return "mock";

  return "unavailable";
}

export function ExternalSeoDetails({ metrics }: { metrics: MetricEvaluation[] }) {
  const authorityMetrics = DISPLAY_KEYS.map(({ key }) => findMetric(metrics, key)).filter((m): m is MetricEvaluation => m !== undefined);
  const dataState = deriveDataState(authorityMetrics);
  const firstUnavailable = authorityMetrics.find((m) => m.status === "unavailable");
  const providerMetric = authorityMetrics.find((m) => m.source_type);

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <CardTitle className="text-base">外部SEO・ドメイン評価</CardTitle>
        <Badge variant={dataState === "real" ? "secondary" : "outline"}>
          {dataState === "real" ? "実データ" : dataState === "mock" ? "デモデータ" : "未取得"}
        </Badge>
      </CardHeader>
      <CardContent className="space-y-3">
        {providerMetric?.source_type && (
          <p className="text-xs text-muted-foreground">
            provider: {providerMetric.source_type}
            {providerMetric.measured_at && ` ・取得日時: ${new Date(providerMetric.measured_at).toLocaleString("ja-JP")}`}
          </p>
        )}

        {dataState === "unavailable" ? (
          <p className="text-sm text-muted-foreground">
            {firstUnavailable?.error_code ? UNAVAILABLE_REASON_LABELS[firstUnavailable.error_code] ?? firstUnavailable.error_message : "外部SEOデータを取得できていません。"}
          </p>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            {DISPLAY_KEYS.map(({ key, label }) => {
              const metric = findMetric(metrics, key);
              if (!metric || metric.status === "unavailable") return null;

              return (
                <div key={key} className="rounded-md border p-3">
                  <p className="text-xs text-muted-foreground">{label}</p>
                  <p className="mt-1 text-sm font-medium">{formatMetricValue(metric)}</p>
                </div>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { findMetric } from "@/features/analysis/results/metric-lookup";
import type { MetricEvaluation } from "@/types/analysis";

const LIGHTHOUSE_METRIC_KEYS = ["lighthouse_performance", "lighthouse_accessibility", "lighthouse_best_practices", "lighthouse_seo_score", "fcp", "lcp", "cls", "speed_index", "tbt"];

const INFO_METRIC_KEYS = ["lighthouse_request_count", "lighthouse_transfer_size"];

const METRIC_LABELS: Record<string, string> = {
  lighthouse_performance: "Performance",
  lighthouse_accessibility: "Accessibility",
  lighthouse_best_practices: "Best Practices",
  lighthouse_seo_score: "SEO",
  fcp: "First Contentful Paint (FCP)",
  lcp: "Largest Contentful Paint (LCP)",
  cls: "Cumulative Layout Shift (CLS)",
  speed_index: "Speed Index",
  tbt: "Total Blocking Time (TBT)",
  lighthouse_request_count: "リクエスト数",
  lighthouse_transfer_size: "転送量",
};

function formatInfoValue(metric: MetricEvaluation): string {
  if (metric.value === null || metric.value === undefined) return "-";
  if (metric.key === "lighthouse_transfer_size" && typeof metric.value === "number") {
    return `${Math.round((metric.value / 1024) * 10) / 10} KB`;
  }
  return typeof metric.value === "number" && metric.unit ? `${metric.value}${metric.unit}` : String(metric.value);
}

interface LighthouseEvidence {
  metadata?: { run_count?: number } | null;
}

export function PerformanceDetails({ metrics }: { metrics: MetricEvaluation[] }) {
  const performance = findMetric(metrics, "lighthouse_performance");
  const succeeded = performance?.status === "success";
  const lighthouseMetrics = LIGHTHOUSE_METRIC_KEYS.map((key) => findMetric(metrics, key)).filter((m): m is MetricEvaluation => m !== undefined);
  const infoMetrics = INFO_METRIC_KEYS.map((key) => findMetric(metrics, key)).filter(
    (m): m is MetricEvaluation => m !== undefined && m.value !== null
  );
  const evidence = performance?.evidence as LighthouseEvidence | null;
  const runCount = evidence?.metadata?.run_count;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">表示速度・アクセシビリティ(Lighthouse)</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {succeeded ? (
          <>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {lighthouseMetrics.map((metric) => (
                <div key={metric.key} className="rounded-md border p-3 text-center">
                  <p className="text-xs text-muted-foreground">{METRIC_LABELS[metric.key] ?? metric.name}</p>
                  <p className="mt-1 text-lg font-semibold">
                    {metric.value ?? "-"}
                    {metric.unit && metric.value !== null ? metric.unit : ""}
                  </p>
                </div>
              ))}
            </div>
            {runCount === 1 && (
              <Alert>
                <AlertDescription className="text-xs text-muted-foreground">
                  この値はローカル環境での単発計測(1回のみの実行)です。外部広告・ネットワーク状況・Cookie表示等の影響を受けるため、確定的な実ユーザー評価とはせず、再計測を推奨します。
                </AlertDescription>
              </Alert>
            )}
          </>
        ) : (
          <Alert variant="destructive">
            <AlertDescription>
              <p className="font-medium">Lighthouse計測に失敗したため、表示速度・アクセシビリティスコアは評価できませんでした。</p>
              {performance?.error_message && <p className="mt-1 text-sm">{performance.error_message}</p>}
              <p className="mt-1 text-xs text-muted-foreground">
                再分析することでLighthouse計測を再取得できる可能性があります。この失敗は表示速度カテゴリの採点のみに影響し、他のカテゴリ(SEO・コンテンツ等)の評価には影響しません。
              </p>
            </AlertDescription>
          </Alert>
        )}
        {infoMetrics.length > 0 && (
          <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
            {infoMetrics.map((metric) => (
              <span key={metric.key}>
                {METRIC_LABELS[metric.key] ?? metric.name}: {formatInfoValue(metric)}
              </span>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

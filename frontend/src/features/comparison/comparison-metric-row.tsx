import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import type { MetricComparison, MetricSiteValue, RankingEntry } from "@/types/comparison";

const SOURCE_LABELS: Record<string, string> = {
  static_html: "HTML計測",
  http: "HTTP計測",
  lighthouse: "Lighthouse計測",
  analyzer: "技術検出",
  semrush: "Semrush",
  mock: "デモデータ",
  ai: "AI推定",
};

export function formatMetricSiteValue(value: MetricSiteValue, unit: string | null): string {
  if (value.status !== "success" && value.status !== "not_applicable") return "-";
  if (value.value === null || value.value === undefined) return "-";
  if (typeof value.value === "boolean") return value.value ? "○" : "×";
  if (typeof value.value === "number") return unit ? `${value.value}${unit}` : String(value.value);
  return String(value.value);
}

function isMeasured(value: MetricSiteValue): boolean {
  return value.status === "success";
}

export function bestSiteIds(metric: MetricComparison): Set<number> {
  const measured = metric.sites.filter((s) => isMeasured(s) && typeof s.value === "number");
  if (measured.length < 2) return new Set();
  const values = measured.map((s) => s.value as number);
  const best = metric.higher_is_better ? Math.max(...values) : Math.min(...values);
  return new Set(measured.filter((s) => s.value === best).map((s) => s.website_analysis_id));
}

export function worstSiteIds(metric: MetricComparison): Set<number> {
  const measured = metric.sites.filter((s) => isMeasured(s) && typeof s.value === "number");
  if (measured.length < 2) return new Set();
  const values = measured.map((s) => s.value as number);
  const worst = metric.higher_is_better ? Math.min(...values) : Math.max(...values);
  return new Set(measured.filter((s) => s.value === worst).map((s) => s.website_analysis_id));
}

function judgmentText(metric: MetricComparison, sites: RankingEntry[]): string | null {
  const measured = metric.sites.filter((s) => isMeasured(s) && typeof s.value === "number");
  if (measured.length < 2) return null;

  const best = bestSiteIds(metric);
  const worst = worstSiteIds(metric);
  if (best.size !== 1 || worst.size !== 1) return null;
  const [bestId] = [...best];
  const [worstId] = [...worst];
  if (bestId === worstId) return null;

  const bestValue = measured.find((s) => s.website_analysis_id === bestId)!.value as number;
  const worstValue = measured.find((s) => s.website_analysis_id === worstId)!.value as number;
  const bestName = sites.find((s) => s.website_analysis_id === bestId)?.website_name ?? `サイト #${bestId}`;
  const diff = Math.round(Math.abs(bestValue - worstValue) * 100) / 100;
  const unit = metric.unit ?? "";

  return `${bestName}が${diff}${unit}良好です`;
}

function ValueDisplay({ site, metric }: { site: MetricSiteValue; metric: MetricComparison }) {
  if (site.status === "error") {
    return (
      <span className="inline-flex items-center gap-1 text-destructive" title={site.error_message ?? "エラー"}>
        ⚠ エラー
      </span>
    );
  }

  if (site.status !== "success" && site.status !== "not_applicable") {
    return <span className="text-muted-foreground">未取得</span>;
  }

  const best = bestSiteIds(metric);
  const worst = worstSiteIds(metric);
  const isBest = best.has(site.website_analysis_id) && best.size < metric.sites.length;
  const isWorst = worst.has(site.website_analysis_id) && worst.size < metric.sites.length;

  return (
    <span
      className={cn(
        isBest && "font-semibold text-green-700 dark:text-green-400",
        isWorst && !isBest && "text-red-700 dark:text-red-400",
      )}
    >
      {formatMetricSiteValue(site, metric.unit)}
      {site.is_mock && (
        <Badge variant="outline" className="ml-1 align-middle">
          デモデータ
        </Badge>
      )}
    </span>
  );
}

/**
 * 1Metricの比較行。デスクトップはサイト列を横並びのグリッドで表示し、
 * モバイルではメトリック名→各サイトの値→ベスト/ワーストの自然文判定、という
 * カード形式に切り替える(横長テーブルにしない)。
 */
export function ComparisonMetricRow({ metric, sites }: { metric: MetricComparison; sites: RankingEntry[] }) {
  const judgment = judgmentText(metric, sites);

  return (
    <div className="border-b py-2 last:border-b-0">
      {/* デスクトップ: グリッド行 */}
      <div
        className="hidden items-center gap-2 sm:grid"
        style={{ gridTemplateColumns: `minmax(0,2fr) repeat(${sites.length}, minmax(0,1fr))` }}
      >
        <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
          <span>{metric.name}</span>
          <Badge variant="outline" className="text-[10px]">
            {SOURCE_LABELS[metric.source_type] ?? metric.source_type}
          </Badge>
        </div>
        {sites.map((site) => {
          const siteValue = metric.sites.find((s) => s.website_analysis_id === site.website_analysis_id);
          return (
            <div key={site.website_analysis_id} className="text-right text-sm">
              {siteValue ? <ValueDisplay site={siteValue} metric={metric} /> : "-"}
            </div>
          );
        })}
      </div>

      {/* モバイル: カード形式 */}
      <div className="space-y-1.5 rounded-md border p-3 sm:hidden">
        <div className="flex items-center gap-1.5">
          <p className="text-sm font-medium">{metric.name}</p>
          <Badge variant="outline" className="text-[10px]">
            {SOURCE_LABELS[metric.source_type] ?? metric.source_type}
          </Badge>
        </div>
        <dl className="space-y-1">
          {sites.map((site) => {
            const siteValue = metric.sites.find((s) => s.website_analysis_id === site.website_analysis_id);
            return (
              <div key={site.website_analysis_id} className="flex items-center justify-between text-sm">
                <dt className="text-muted-foreground">{site.website_name ?? `サイト #${site.website_id}`}</dt>
                <dd>{siteValue ? <ValueDisplay site={siteValue} metric={metric} /> : "-"}</dd>
              </div>
            );
          })}
        </dl>
        {judgment && (
          <p className="border-t pt-1.5 text-xs text-muted-foreground">
            <span className="font-medium">判定: </span>
            {judgment}
          </p>
        )}
      </div>
    </div>
  );
}

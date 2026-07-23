import { classifyMetric, formatMetricValue } from "@/features/analysis/metric-evaluation";
import { EvaluationBadge } from "@/features/analysis/results/evaluation-badge";
import type { MetricEvaluation } from "@/types/analysis";

// 分析対象サイトのHTMLから抽出したhrefをそのままリンク化する前に、
// http/https以外のスキーム(javascript:等)を弾く(多重の防御。抽出処理側
// (HtmlSeoAnalyzer)でも既に除外しているが、表示側でも安全に倒す)。
function safeHref(url: string): string | null {
  try {
    const resolved = new URL(url, "https://example.invalid/");
    return resolved.protocol === "http:" || resolved.protocol === "https:" ? url : null;
  } catch {
    return null;
  }
}

export function MetricEvaluationCard({
  metric,
  label,
  description,
  rangeLabel,
  link,
}: {
  metric: MetricEvaluation;
  label?: string;
  description?: string;
  rangeLabel?: string;
  link?: { url: string; text: string | null } | null;
}) {
  const state = classifyMetric(metric);
  const href = link ? safeHref(link.url) : null;

  return (
    <div className="rounded-md border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium">{label ?? metric.name}</p>
        <EvaluationBadge state={state} />
      </div>
      <p className="mt-1 text-sm text-muted-foreground">
        現在値: {formatMetricValue(metric)}
        {rangeLabel && ` ・推奨範囲: ${rangeLabel}`}
      </p>
      {description && <p className="mt-1 text-xs text-muted-foreground">{description}</p>}
      {href && (
        <p className="mt-1 truncate text-xs">
          <a href={href} target="_blank" rel="noopener noreferrer" className="text-primary underline">
            {link?.text || href}
          </a>
        </p>
      )}
      {(state === "unavailable" || state === "failed") && metric.error_message && (
        <p className="mt-1 text-xs text-muted-foreground">{metric.error_message}</p>
      )}
    </div>
  );
}

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { classifyMetric, isGoodEvaluationState } from "@/features/analysis/metric-evaluation";
import { EVALUATION_ICONS, EvaluationBadge } from "@/features/analysis/results/evaluation-badge";
import { findMetric } from "@/features/analysis/results/metric-lookup";
import type { MetricEvaluation } from "@/types/analysis";

const INFO_KEYS: Array<{ key: string; label: string }> = [
  { key: "ga_detected", label: "Google Analytics" },
  { key: "gtm_detected", label: "Google Tag Manager" },
  { key: "clarity_detected", label: "Microsoft Clarity" },
  { key: "meta_pixel_detected", label: "Meta Pixel" },
  { key: "recaptcha_detected", label: "reCAPTCHA" },
  { key: "cdn_detected", label: "CDN" },
];

function TechRow({
  label,
  metric,
  valueLabel,
  description,
}: {
  label: string;
  metric?: MetricEvaluation;
  valueLabel?: string;
  description?: string;
}) {
  if (!metric) return null;
  const state = classifyMetric(metric);

  return (
    <div className="rounded-md border p-2 text-sm">
      <div className="flex items-center justify-between gap-2">
        <span>{label}</span>
        <div className="flex items-center gap-2">
          {valueLabel && <span className="text-muted-foreground">{valueLabel}</span>}
          <EvaluationBadge state={state} />
        </div>
      </div>
      {description && state !== "good" && <p className="mt-1 text-xs text-muted-foreground">{description}</p>}
    </div>
  );
}

function TechBadge({ label, metric }: { label: string; metric: MetricEvaluation }) {
  const state = classifyMetric(metric);
  const Icon = EVALUATION_ICONS[state];
  return (
    <Badge variant={isGoodEvaluationState(state) ? "secondary" : "outline"} className="gap-1">
      <Icon className="size-3" />
      {label}
    </Badge>
  );
}

export function TechnologyDetails({ metrics }: { metrics: MetricEvaluation[] }) {
  const cms = findMetric(metrics, "cms_detected");
  const analytics = findMetric(metrics, "analytics_configured");
  const infoMetrics = INFO_KEYS.map(({ key }) => findMetric(metrics, key));

  const techMetrics = [cms, analytics, ...infoMetrics].filter((m): m is MetricEvaluation => m !== undefined);
  const allFailed = techMetrics.length > 0 && techMetrics.every((m) => m.status === "error" || m.status === "unavailable");
  const firstErrorMessage = techMetrics.find((m) => m.error_message)?.error_message;

  const badgeMetrics = INFO_KEYS.map(({ key, label }) => ({ label, metric: findMetric(metrics, key) })).filter(
    (x): x is { label: string; metric: MetricEvaluation } => x.metric !== undefined,
  );
  const detected = badgeMetrics.filter(({ metric }) => isGoodEvaluationState(classifyMetric(metric)));
  const notDetected = badgeMetrics.filter(({ metric }) => !isGoodEvaluationState(classifyMetric(metric)));

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">技術・計測環境</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {allFailed ? (
          <Alert variant="destructive">
            <AlertDescription>
              <p className="font-medium">技術・計測環境の検出に失敗しました。CMS、計測タグ、CDN等を判定できませんでした。</p>
              {firstErrorMessage && <p className="mt-1 text-sm">{firstErrorMessage}</p>}
              <p className="mt-1 text-xs text-muted-foreground">再分析することで再取得できる可能性があります。</p>
            </AlertDescription>
          </Alert>
        ) : techMetrics.length === 0 ? (
          <p className="text-sm text-muted-foreground">技術・計測環境のデータがありません。</p>
        ) : (
          <>
            <TechRow label="CMS / フレームワーク" metric={cms} valueLabel={typeof cms?.value === "string" ? cms.value : undefined} />
            <TechRow
              label="一般的なアクセス解析タグの検出"
              metric={analytics}
              description="Google Analytics/Google Tag Manager等の一般的なタグを検出できませんでした。独自計測や同意後読み込みを利用している場合、実際には計測が行われている可能性があります。"
            />
            {detected.length > 0 && (
              <div>
                <p className="flex items-center gap-1 text-xs font-medium text-muted-foreground">検出済み</p>
                <div className="mt-1 flex flex-wrap gap-1.5">
                  {detected.map(({ label, metric }) => (
                    <TechBadge key={label} label={label} metric={metric} />
                  ))}
                </div>
              </div>
            )}
            {notDetected.length > 0 && (
              <div>
                <p className="text-xs font-medium text-muted-foreground">未検出</p>
                <div className="mt-1 flex flex-wrap gap-1.5">
                  {notDetected.map(({ label, metric }) => (
                    <TechBadge key={label} label={label} metric={metric} />
                  ))}
                </div>
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

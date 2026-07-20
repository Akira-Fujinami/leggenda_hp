import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { classifyMetric, EVALUATION_BADGE_VARIANT, EVALUATION_LABELS } from "@/features/analysis/metric-evaluation";
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
          <Badge variant={EVALUATION_BADGE_VARIANT[state]}>{EVALUATION_LABELS[state]}</Badge>
        </div>
      </div>
      {description && state !== "good" && <p className="mt-1 text-xs text-muted-foreground">{description}</p>}
    </div>
  );
}

export function TechnologyDetails({ metrics }: { metrics: MetricEvaluation[] }) {
  const cms = findMetric(metrics, "cms_detected");
  const analytics = findMetric(metrics, "analytics_configured");

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">技術・計測環境</CardTitle>
      </CardHeader>
      <CardContent className="space-y-2">
        <TechRow label="CMS / フレームワーク" metric={cms} valueLabel={typeof cms?.value === "string" ? cms.value : undefined} />
        <TechRow
          label="一般的なアクセス解析タグの検出"
          metric={analytics}
          description="Google Analytics/Google Tag Manager等の一般的なタグを検出できませんでした。独自計測や同意後読み込みを利用している場合、実際には計測が行われている可能性があります。"
        />
        {INFO_KEYS.map(({ key, label }) => (
          <TechRow key={key} label={label} metric={findMetric(metrics, key)} />
        ))}
      </CardContent>
    </Card>
  );
}

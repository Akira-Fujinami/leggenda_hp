import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { MetricEvaluationCard } from "@/features/analysis/results/metric-evaluation-card";
import { findMetric } from "@/features/analysis/results/metric-lookup";
import type { MetricEvaluation } from "@/types/analysis";

export function ConversionDetails({ metrics }: { metrics: MetricEvaluation[] }) {
  const form = findMetric(metrics, "form_present");
  const telOrMail = findMetric(metrics, "tel_or_mailto_present");
  const contact = findMetric(metrics, "contact_cta_present");
  const reservation = findMetric(metrics, "reservation_cta_present");
  const documentRequest = findMetric(metrics, "document_request_cta_present");
  const sns = findMetric(metrics, "sns_link_present");
  const ctaCount = findMetric(metrics, "cta_count_sufficient");

  const formRaw = form?.raw_value as { form_count?: number; input_count?: number } | null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">集客・コンバージョン導線</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 sm:grid-cols-2">
        {form && (
          <MetricEvaluationCard
            metric={form}
            label="問い合わせフォーム"
            description={formRaw ? `フォーム${formRaw.form_count ?? 0}件・入力項目${formRaw.input_count ?? 0}個` : undefined}
          />
        )}
        {telOrMail && <MetricEvaluationCard metric={telOrMail} label="電話・メール導線" />}
        {contact && <MetricEvaluationCard metric={contact} label="問い合わせ導線" />}
        {reservation && <MetricEvaluationCard metric={reservation} label="予約導線" />}
        {documentRequest && <MetricEvaluationCard metric={documentRequest} label="資料請求導線" />}
        {sns && <MetricEvaluationCard metric={sns} label="SNSリンク" />}
        {ctaCount && <MetricEvaluationCard metric={ctaCount} label="CTA数(合計)" />}
      </CardContent>
    </Card>
  );
}

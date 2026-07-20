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
  const fixedCta = findMetric(metrics, "fixed_cta_present");
  const formBurden = findMetric(metrics, "form_input_burden");
  const externalReservation = findMetric(metrics, "external_reservation_service_detected");
  const recruit = findMetric(metrics, "recruit_link_present");

  const formRaw = form?.raw_value as { form_count?: number; input_count?: number } | null;
  const fixedCtaRaw = fixedCta?.raw_value as { text?: string | null; href?: string | null; position?: string | null } | null;
  const burdenRaw = formBurden?.raw_value as { total_field_count?: number; tier?: string | null } | null;
  const burdenTierLabel: Record<string, string> = { small: "少ない", medium: "普通", large: "多い" };

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
        {fixedCta && (
          <MetricEvaluationCard
            metric={fixedCta}
            label="固定表示CTA(常時追従)"
            description={fixedCtaRaw?.position ? `position: ${fixedCtaRaw.position}` : undefined}
            link={fixedCtaRaw?.href ? { url: fixedCtaRaw.href, text: fixedCtaRaw.text ?? null } : null}
          />
        )}
        {formBurden && (
          <MetricEvaluationCard
            metric={formBurden}
            label="フォーム入力負担"
            description={
              burdenRaw?.tier
                ? `入力項目合計${burdenRaw.total_field_count ?? "-"}個・負担: ${burdenTierLabel[burdenRaw.tier] ?? burdenRaw.tier}`
                : undefined
            }
          />
        )}
        {externalReservation && <MetricEvaluationCard metric={externalReservation} label="外部予約サービス利用" />}
        {recruit && <MetricEvaluationCard metric={recruit} label="採用情報リンク" />}
      </CardContent>
    </Card>
  );
}

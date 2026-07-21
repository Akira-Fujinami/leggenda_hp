import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { MetricEvaluationCard } from "@/features/analysis/results/metric-evaluation-card";
import { findMetric } from "@/features/analysis/results/metric-lookup";
import type { MetricEvaluation } from "@/types/analysis";

const BURDEN_TIER_LABEL: Record<string, string> = { small: "少ない", medium: "普通", large: "多い" };

const SNS_PLATFORM_LABELS: Record<string, string> = {
  facebook: "Facebook",
  instagram: "Instagram",
  x: "X",
  line: "LINE",
  youtube: "YouTube",
  tiktok: "TikTok",
  linkedin: "LinkedIn",
  pinterest: "Pinterest",
};

interface SnsPlatformRaw {
  platform: string;
  url: string;
  label?: string;
  source?: string;
  confidence?: number;
}

const REPRESENTATIVE_FORM_REASON_LABEL: Record<string, string> = {
  form_attributes: "問い合わせ・相談を示すフォーム属性から判定",
  nearby_heading: "直前の見出し(問い合わせ・相談等)から判定",
  field_names: "メール・件名等の入力項目名から判定",
  largest_search_form_fallback: "他に該当が無いため、検索フォームの中で最大のものを暫定的に採用",
  largest_form_fallback: "他に該当が無いため、最も入力項目数が多いフォームを暫定的に採用",
};

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
  const pageFormCount = findMetric(metrics, "page_form_count");
  const pageInputCount = findMetric(metrics, "page_input_count");
  const representativeFieldCount = findMetric(metrics, "representative_form_field_count");
  const externalReservation = findMetric(metrics, "external_reservation_service_detected");
  const recruit = findMetric(metrics, "recruit_link_present");
  const chatbot = findMetric(metrics, "chatbot_detected");

  const fixedCtaRaw = fixedCta?.raw_value as { text?: string | null; href?: string | null; position?: string | null } | null;
  const snsRaw = sns?.raw_value as { platforms?: SnsPlatformRaw[] } | null;
  const snsPlatformNames = snsRaw?.platforms?.length
    ? snsRaw.platforms.map((p) => SNS_PLATFORM_LABELS[p.platform] ?? p.platform).join("、")
    : null;
  const burdenRaw = formBurden?.raw_value as { tier?: string | null; representative_form_reason?: string | null } | null;
  const tierLabel = burdenRaw?.tier ? (BURDEN_TIER_LABEL[burdenRaw.tier] ?? burdenRaw.tier) : null;
  const reasonLabel = burdenRaw?.representative_form_reason
    ? REPRESENTATIVE_FORM_REASON_LABEL[burdenRaw.representative_form_reason]
    : null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">集客・コンバージョン導線</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 sm:grid-cols-2">
        {form && <MetricEvaluationCard metric={form} label="問い合わせフォームの有無" />}
        {telOrMail && <MetricEvaluationCard metric={telOrMail} label="電話・メール導線" />}
        {contact && <MetricEvaluationCard metric={contact} label="問い合わせ導線" />}
        {reservation && <MetricEvaluationCard metric={reservation} label="予約導線" />}
        {documentRequest && <MetricEvaluationCard metric={documentRequest} label="資料請求導線" />}
        {sns && (
          <div className="space-y-1">
            <MetricEvaluationCard metric={sns} label="SNSリンク" description={snsPlatformNames ?? undefined} />
            {snsRaw?.platforms && snsRaw.platforms.length > 0 && (
              <details className="rounded-md border p-2 text-xs text-muted-foreground">
                <summary className="cursor-pointer">SNSリンクのURLを表示({snsRaw.platforms.length}件)</summary>
                <ul className="mt-1 space-y-0.5">
                  {snsRaw.platforms.map((p) => (
                    <li key={p.platform} className="truncate">
                      {SNS_PLATFORM_LABELS[p.platform] ?? p.platform}: {p.url}
                    </li>
                  ))}
                </ul>
              </details>
            )}
          </div>
        )}
        {chatbot && <MetricEvaluationCard metric={chatbot} label="チャットサポート" />}
        {ctaCount && <MetricEvaluationCard metric={ctaCount} label="CTA数(合計)" />}
        {fixedCta && (
          <MetricEvaluationCard
            metric={fixedCta}
            label="固定表示CTA(常時追従)"
            description={fixedCtaRaw?.position ? `position: ${fixedCtaRaw.position}` : undefined}
            link={fixedCtaRaw?.href ? { url: fixedCtaRaw.href, text: fixedCtaRaw.text ?? null } : null}
          />
        )}
        {/* ページ全体のフォーム数・入力項目総数と、代表フォーム自体の項目数・
            必須項目数・入力負担は、意味が異なるため明確に分離して表示する。 */}
        {pageFormCount && <MetricEvaluationCard metric={pageFormCount} label="ページ内フォーム数" />}
        {pageInputCount && <MetricEvaluationCard metric={pageInputCount} label="ページ内入力項目総数" />}
        {representativeFieldCount && (
          <MetricEvaluationCard
            metric={representativeFieldCount}
            label="代表フォームの入力項目数"
            description={reasonLabel ?? undefined}
          />
        )}
        {formBurden && (
          <MetricEvaluationCard
            metric={formBurden}
            label="代表フォームの必須項目数・入力負担"
            description={tierLabel ? `入力負担: ${tierLabel}` : undefined}
          />
        )}
        {externalReservation && <MetricEvaluationCard metric={externalReservation} label="外部予約サービス利用" />}
        {recruit && <MetricEvaluationCard metric={recruit} label="採用情報リンク" />}
      </CardContent>
    </Card>
  );
}

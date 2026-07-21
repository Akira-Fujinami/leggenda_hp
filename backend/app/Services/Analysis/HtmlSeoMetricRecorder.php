<?php

namespace App\Services\Analysis;

use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * HtmlSeoAnalyzerの解析結果をMetricResultへ記録する共通処理。
 * AnalyzeHtmlSeoJob(静的HTMLによる一次解析)とReanalyzeRenderedHtmlJob
 * (レンダリング済みHTMLによる二次解析)の両方から呼ばれるため、
 * どちらのjobにも属さないプレーンサービスとして切り出している
 * (状態を持たない ―― $websiteAnalysisId/$sourceはインスタンスプロパティに
 * せず、全メソッドへ明示的に引数で渡す)。
 *
 * 検出ロジック自体(何を検出するか)はHtmlSeoAnalyzer側の責務であり、
 * このクラスは「検出結果をどのMetricDefinitionへどう記録するか」のみを扱う。
 */
class HtmlSeoMetricRecorder
{
    use RecordsMetricResults;

    /**
     * このクラスが担当する全MetricDefinitionキー(HTMLが取得できなかった
     * 場合にまとめてunavailable/errorにするため)。
     */
    public const ALL_KEYS = [
        'title_present', 'title_length_optimal', 'meta_description_present', 'meta_description_length_optimal',
        'h1_single', 'canonical_present', 'canonical_self_referencing', 'robots_meta_indexable',
        'viewport_present', 'lang_present', 'favicon_present', 'structured_data_present', 'ogp_present',
        'word_count_sufficient', 'img_alt_coverage', 'internal_link_sufficient', 'heading_structure_present',
        'external_link_present', 'pricing_info_link_present', 'case_study_or_testimonial_link_present',
        'company_info_link_present', 'privacy_policy_link_present', 'faq_link_present', 'help_center_link_present',
        'pricing_card_or_product_price_present',
        'a11y_lang_present', 'a11y_form_label_present', 'a11y_button_name_present', 'a11y_heading_order_ok',
        'form_present', 'tel_or_mailto_present', 'contact_cta_present', 'reservation_cta_present',
        'document_request_cta_present', 'sns_link_present', 'chatbot_detected', 'cta_count_sufficient',
        'form_input_burden', 'external_reservation_service_detected', 'recruit_link_present',
        'page_form_count', 'page_input_count', 'representative_form_field_count',
    ];

    /**
     * @param  array<string, mixed>  $result  HtmlSeoAnalyzer::analyze()の戻り値
     * @param  string  $source  'static'|'rendered'
     */
    public function recordAll(int $websiteAnalysisId, array $result, int $pageId, string $source): void
    {
        $this->recordTechnicalSeo($websiteAnalysisId, $result, $pageId, $source);
        $this->recordContent($websiteAnalysisId, $result, $pageId, $source);
        $this->recordAccessibility($websiteAnalysisId, $result, $pageId, $source);
        $this->recordConversion($websiteAnalysisId, $result, $pageId, $source);
    }

    public function recordAllUnavailable(int $websiteAnalysisId): void
    {
        foreach (self::ALL_KEYS as $key) {
            $this->recordMetric($websiteAnalysisId, $key, MetricResultStatus::Unavailable);
        }
    }

    public function recordAllError(int $websiteAnalysisId, string $message): void
    {
        foreach (self::ALL_KEYS as $key) {
            $this->recordMetric($websiteAnalysisId, $key, MetricResultStatus::Error, errorMessage: $message);
        }
    }

    private function recordTechnicalSeo(int $websiteAnalysisId, array $result, int $pageId, string $source): void
    {
        // head/bodyが実質的に空(bot拒否ページ・取得失敗のプレースホルダー等)の
        // 場合、h1/viewportの「無し」は「設置していない」(not_found)ではなく
        // 「そもそも判定材料が無い」(unavailable)として扱う。
        $pageUnavailable = (bool) ($result['page_structure']['body_is_effectively_empty'] ?? false);

        $this->recordSeoMetric($websiteAnalysisId, 'title_present', MetricResultStatus::Success, normalizedValue: $result['title']['present'], rawValue: $result['title'], analysisPageId: $pageId, source: $source);

        $titleLength = $result['title']['length'];
        $this->recordSeoMetric($websiteAnalysisId, 'title_length_optimal', $titleLength === null ? MetricResultStatus::NotFound : MetricResultStatus::Success, normalizedValue: $titleLength, rawValue: $result['title'], analysisPageId: $pageId, source: $source);

        $this->recordSeoMetric($websiteAnalysisId, 'meta_description_present', MetricResultStatus::Success, normalizedValue: $result['meta_description']['present'], rawValue: $result['meta_description'], analysisPageId: $pageId, source: $source);

        $descLength = $result['meta_description']['length'];
        $this->recordSeoMetric($websiteAnalysisId, 'meta_description_length_optimal', $descLength === null ? MetricResultStatus::NotFound : MetricResultStatus::Success, normalizedValue: $descLength, rawValue: $result['meta_description'], analysisPageId: $pageId, source: $source);

        // status(success/not_found/unavailable)はvalid_count(有効なH1が
        // 1件以上あるか)で決める。normalized_value(=valid_count===1)は
        // 「H1が存在するか」ではなく「有効なH1がちょうど1件という採点基準を
        // 満たすか」という採点専用の意味であり、valid_count>=2でもstatusは
        // Successのまま、normalized_valueだけがfalseになる。Frontend側は
        // H1の有無判定にnormalized_valueを使わず、raw_value.valid_countを
        // 参照すること。
        $h1ValidCount = $result['h1']['valid_count'];
        $h1Status = $pageUnavailable
            ? MetricResultStatus::Unavailable
            : ($h1ValidCount === 0 ? MetricResultStatus::NotFound : MetricResultStatus::Success);
        $this->recordSeoMetric(
            $websiteAnalysisId, 'h1_single', $h1Status,
            normalizedValue: $h1ValidCount === 1,
            rawValue: $result['h1'] + ['html_source' => $result['html_source'] ?? null],
            evidence: ['valid_count' => $h1ValidCount, 'visible_count' => $result['h1']['visible_count'], 'raw_count' => $result['h1']['count']],
            analysisPageId: $pageId, source: $source,
        );

        $this->recordSeoMetric($websiteAnalysisId, 'canonical_present', MetricResultStatus::Success, normalizedValue: $result['canonical']['present'], rawValue: $result['canonical'], analysisPageId: $pageId, source: $source);

        $canonicalStatus = $result['canonical']['present'] ? MetricResultStatus::Success : MetricResultStatus::NotFound;
        $this->recordSeoMetric($websiteAnalysisId, 'canonical_self_referencing', $canonicalStatus, normalizedValue: (bool) ($result['canonical']['is_self_referencing'] ?? false), rawValue: $result['canonical'], analysisPageId: $pageId, source: $source);

        $this->recordSeoMetric($websiteAnalysisId, 'robots_meta_indexable', MetricResultStatus::Success, normalizedValue: $result['robots_meta']['index'], rawValue: $result['robots_meta'], analysisPageId: $pageId, source: $source);

        $viewportStatus = $pageUnavailable ? MetricResultStatus::Unavailable : MetricResultStatus::Success;
        $this->recordSeoMetric($websiteAnalysisId, 'viewport_present', $viewportStatus, normalizedValue: $result['content']['viewport_present'], rawValue: $result['content'] + ['html_source' => $result['html_source'] ?? null], analysisPageId: $pageId, source: $source);

        $this->recordSeoMetric($websiteAnalysisId, 'lang_present', MetricResultStatus::Success, normalizedValue: $result['content']['lang'] !== null, rawValue: ['lang' => $result['content']['lang']], analysisPageId: $pageId, source: $source);

        $this->recordSeoMetric($websiteAnalysisId, 'favicon_present', MetricResultStatus::Success, normalizedValue: $result['content']['favicon_present'], rawValue: $result['content'], analysisPageId: $pageId, source: $source);

        $structuredDataPresent = $result['structured_data']['count'] > 0;
        $this->recordSeoMetric($websiteAnalysisId, 'structured_data_present', $structuredDataPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $structuredDataPresent, rawValue: $result['structured_data'], analysisPageId: $pageId, source: $source);

        $ogpPresent = ($result['ogp']['title'] ?? null) !== null;
        $this->recordSeoMetric($websiteAnalysisId, 'ogp_present', $ogpPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $ogpPresent, rawValue: $result['ogp'], analysisPageId: $pageId, source: $source);
    }

    private function recordContent(int $websiteAnalysisId, array $result, int $pageId, string $source): void
    {
        $wordCount = $result['content']['word_count'];
        $this->recordSeoMetric($websiteAnalysisId, 'word_count_sufficient', MetricResultStatus::Success, normalizedValue: $wordCount, rawValue: ['word_count' => $wordCount], analysisPageId: $pageId, source: $source);

        $altCoverage = $result['images']['alt_coverage'];
        $this->recordSeoMetric($websiteAnalysisId, 'img_alt_coverage', MetricResultStatus::Success, normalizedValue: $altCoverage ?? 1.0, rawValue: $result['images'], analysisPageId: $pageId, source: $source);

        $this->recordSeoMetric($websiteAnalysisId, 'internal_link_sufficient', MetricResultStatus::Success, normalizedValue: $result['links']['internal'], rawValue: $result['links'], analysisPageId: $pageId, source: $source);

        $pageUnavailable = (bool) ($result['page_structure']['body_is_effectively_empty'] ?? false);
        $headingPresent = $result['h1']['valid_count'] > 0;
        $headingStatus = $pageUnavailable ? MetricResultStatus::Unavailable : ($headingPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound);
        $this->recordSeoMetric($websiteAnalysisId, 'heading_structure_present', $headingStatus, normalizedValue: $headingPresent, rawValue: $result['h1'], analysisPageId: $pageId, source: $source);

        $externalPresent = $result['links']['external'] > 0;
        $this->recordSeoMetric($websiteAnalysisId, 'external_link_present', $externalPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $externalPresent, rawValue: $result['links'], analysisPageId: $pageId, source: $source);

        $this->recordBusinessLink($websiteAnalysisId, $result, 'pricing', 'pricing_info_link_present', $pageId, $source);
        $this->recordBusinessLink($websiteAnalysisId, $result, 'case_study', 'case_study_or_testimonial_link_present', $pageId, $source);
        $this->recordBusinessLink($websiteAnalysisId, $result, 'company_info', 'company_info_link_present', $pageId, $source);
        $this->recordBusinessLink($websiteAnalysisId, $result, 'privacy_policy', 'privacy_policy_link_present', $pageId, $source);
        $this->recordBusinessLink($websiteAnalysisId, $result, 'faq', 'faq_link_present', $pageId, $source);
        $this->recordBusinessLink($websiteAnalysisId, $result, 'help_center', 'help_center_link_present', $pageId, $source);

        $priceCards = $result['product_price_cards'];
        $this->recordSeoMetric(
            $websiteAnalysisId, 'pricing_card_or_product_price_present',
            $priceCards['present'] ? MetricResultStatus::Success : MetricResultStatus::NotFound,
            normalizedValue: $priceCards['present'], rawValue: $priceCards, analysisPageId: $pageId,
            confidence: $priceCards['confidence'] ?? 1.0, source: $source,
        );
    }

    private function recordBusinessLink(int $websiteAnalysisId, array $result, string $businessLinkKey, string $metricKey, int $pageId, string $source): void
    {
        $link = $result['business_links'][$businessLinkKey] ?? ['present' => false];
        $present = (bool) $link['present'];
        $confidence = $present ? (float) ($link['confidence'] ?? 0.65) : 1.0;

        $this->recordSeoMetric(
            $websiteAnalysisId, $metricKey,
            $present ? MetricResultStatus::Success : MetricResultStatus::NotFound,
            normalizedValue: $present, rawValue: $link, analysisPageId: $pageId, confidence: $confidence, source: $source,
        );
    }

    private function recordAccessibility(int $websiteAnalysisId, array $result, int $pageId, string $source): void
    {
        $this->recordSeoMetric($websiteAnalysisId, 'a11y_lang_present', MetricResultStatus::Success, normalizedValue: $result['content']['lang'] !== null, rawValue: ['lang' => $result['content']['lang']], analysisPageId: $pageId, source: $source);

        $a11y = $result['accessibility'];

        $this->recordSeoMetric(
            $websiteAnalysisId, 'a11y_form_label_present',
            $a11y['form_label_present'] === null ? MetricResultStatus::NotApplicable : MetricResultStatus::Success,
            normalizedValue: $a11y['form_label_present'] ?? false, rawValue: $a11y, analysisPageId: $pageId, source: $source,
        );

        $this->recordSeoMetric(
            $websiteAnalysisId, 'a11y_button_name_present',
            $a11y['button_name_present'] === null ? MetricResultStatus::NotApplicable : MetricResultStatus::Success,
            normalizedValue: $a11y['button_name_present'] ?? false, rawValue: $a11y, analysisPageId: $pageId, source: $source,
        );

        $this->recordSeoMetric($websiteAnalysisId, 'a11y_heading_order_ok', MetricResultStatus::Success, normalizedValue: $a11y['heading_order_ok'], rawValue: $a11y, analysisPageId: $pageId, source: $source);
    }

    private function recordConversion(int $websiteAnalysisId, array $result, int $pageId, string $source): void
    {
        $forms = $result['forms'];
        $links = $result['links'];

        $formPresent = $forms['form_count'] > 0;
        $this->recordSeoMetric($websiteAnalysisId, 'form_present', $formPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $formPresent, rawValue: $forms, analysisPageId: $pageId, source: $source);

        $telOrMail = $links['tel'] > 0 || $links['mailto'] > 0;
        $this->recordSeoMetric($websiteAnalysisId, 'tel_or_mailto_present', $telOrMail ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $telOrMail, rawValue: $links, analysisPageId: $pageId, source: $source);

        $contactCta = $links['contact_like'] > 0 || $forms['contact_like'];
        $this->recordSeoMetric($websiteAnalysisId, 'contact_cta_present', $contactCta ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $contactCta, rawValue: ['links' => $links, 'forms' => $forms], analysisPageId: $pageId, source: $source);

        $this->recordSeoMetric($websiteAnalysisId, 'reservation_cta_present', $forms['reservation_like'] ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $forms['reservation_like'], rawValue: $forms, analysisPageId: $pageId, source: $source);

        $this->recordSeoMetric($websiteAnalysisId, 'document_request_cta_present', $forms['document_request_like'] ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $forms['document_request_like'], rawValue: $forms, analysisPageId: $pageId, source: $source);

        $sns = $result['sns_links'];
        $this->recordSeoMetric($websiteAnalysisId, 'sns_link_present', $sns['detected'] ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $sns['detected'], rawValue: $sns, analysisPageId: $pageId, source: $source);

        $chatbot = $result['chatbot'];
        $this->recordSeoMetric($websiteAnalysisId, 'chatbot_detected', $chatbot['detected'] ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $chatbot['detected'], rawValue: $chatbot, analysisPageId: $pageId, source: $source);

        $ctaCount = $links['contact_like'] + $links['tel'] + $links['mailto'] + ($forms['reservation_like'] ? 1 : 0) + ($forms['document_request_like'] ? 1 : 0);
        $this->recordSeoMetric($websiteAnalysisId, 'cta_count_sufficient', MetricResultStatus::Success, normalizedValue: $ctaCount, rawValue: ['cta_count' => $ctaCount], analysisPageId: $pageId, source: $source);

        $burden = $result['form_burden'];
        $burdenStatus = $burden['form_found'] ? MetricResultStatus::Success : MetricResultStatus::NotFound;
        $this->recordSeoMetric($websiteAnalysisId, 'form_input_burden', $burdenStatus, normalizedValue: $burden['required_field_count'], rawValue: $burden, analysisPageId: $pageId, source: $source);

        // ページ全体のフォーム数・入力項目総数・代表フォーム自体の項目数は、
        // 「フォーム入力負担」(=代表フォームの必須項目数のみ)と混同されないよう
        // 別のMetricとして記録する。
        $this->recordSeoMetric($websiteAnalysisId, 'page_form_count', MetricResultStatus::Success, normalizedValue: $forms['form_count'], rawValue: $burden, analysisPageId: $pageId, source: $source);
        $this->recordSeoMetric($websiteAnalysisId, 'page_input_count', MetricResultStatus::Success, normalizedValue: $burden['page_total_field_count'], rawValue: $burden, analysisPageId: $pageId, source: $source);
        $this->recordSeoMetric($websiteAnalysisId, 'representative_form_field_count', $burdenStatus, normalizedValue: $burden['total_field_count'], rawValue: $burden, analysisPageId: $pageId, source: $source);

        $reservationService = $result['third_party_reservation'];
        $reservationDetected = $reservationService['detected'];
        $this->recordSeoMetric($websiteAnalysisId, 'external_reservation_service_detected', $reservationDetected ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $reservationDetected, rawValue: $reservationService, analysisPageId: $pageId, source: $source);

        $this->recordBusinessLink($websiteAnalysisId, $result, 'recruit', 'recruit_link_present', $pageId, $source);
    }

    /**
     * recordMetric()への薄いラッパー。source='rendered'での書き込み時に、
     * 直前まで存在していたstatic結果と値が変わっていれば、evidenceへ
     * changed_after_render/previous_static_valueを付与し、どのMetricが
     * レンダリング後に変化したか監査できるようにする(生HTML全文は含めない)。
     *
     * @param  array<string, mixed>|null  $rawValue
     * @param  array<string, mixed>|null  $evidence
     */
    private function recordSeoMetric(
        int $websiteAnalysisId,
        string $key,
        MetricResultStatus $status,
        mixed $normalizedValue,
        ?array $rawValue,
        int $analysisPageId,
        string $source,
        ?array $evidence = null,
        float $confidence = 1.0,
    ): void {
        $finalEvidence = ($evidence ?? []) + ['source' => $source];

        if ($source === 'rendered') {
            $definition = MetricDefinition::query()->where('key', $key)->where('is_active', true)->first();
            $existing = $definition === null ? null : MetricResult::query()
                ->where('website_analysis_id', $websiteAnalysisId)
                ->where('metric_definition_id', $definition->id)
                ->first();

            if ($existing !== null && $existing->source === 'static') {
                $newNormalized = $normalizedValue !== null ? ['value' => $normalizedValue] : null;
                if (json_encode($existing->normalized_value) !== json_encode($newNormalized)) {
                    $finalEvidence['changed_after_render'] = true;
                    $finalEvidence['previous_static_value'] = $existing->normalized_value['value'] ?? null;
                }
            }
        }

        $this->recordMetric(
            $websiteAnalysisId, $key, $status,
            normalizedValue: $normalizedValue, rawValue: $rawValue, evidence: $finalEvidence,
            analysisPageId: $analysisPageId, confidence: $confidence, source: $source,
        );
    }
}

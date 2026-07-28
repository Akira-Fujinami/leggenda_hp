<?php

namespace App\Services\Analysis;

use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;

/**
 * 採用ページ向けにHtmlSeoAnalyzer::analyze()の結果をrecruit_*の
 * MetricDefinitionへ記録する。検出ロジック自体はHtmlSeoAnalyzerを
 * そのまま再利用し(新たな検出ロジックは書かない)、このクラスは
 * 「どのDB行(recruit_*)へ記録するか」のみを担う ―― HtmlSeoMetricRecorder
 * (トップページ向け、title_present等の既存キーへ記録)とは記録先が
 * 異なるため、既存クラスを流用せず薄い専用クラスとして分離している。
 *
 * 採用ページには「レンダリング済みHTMLによる再解析」を行わない
 * (静的HTML取得のみ、FetchRecruitPageJob参照)ため、
 * HtmlSeoMetricRecorderのようなstatic/rendered優先度制御は不要で、
 * source引数を渡さず常に上書きするRecordsMetricResults::recordMetric()を
 * そのまま使う。
 */
class RecruitPageMetricRecorder
{
    use RecordsMetricResults;

    public const ALL_KEYS = [
        'recruit_title_present', 'recruit_meta_description_present', 'recruit_h1_single',
        'recruit_heading_structure_present', 'recruit_word_count_sufficient', 'recruit_internal_link_sufficient',
        'recruit_faq_link_present', 'recruit_company_info_link_present', 'recruit_contact_cta_present',
        'recruit_tel_or_mailto_present', 'recruit_form_present', 'recruit_viewport_present',
        'recruit_a11y_lang_present', 'recruit_a11y_form_label_present', 'recruit_a11y_button_name_present',
        'recruit_a11y_heading_order_ok',
    ];

    /**
     * @param  array<string, mixed>  $result  HtmlSeoAnalyzer::analyze()の戻り値
     */
    public function recordAll(int $websiteAnalysisId, array $result, int $pageId): void
    {
        $pageUnavailable = (bool) ($result['page_structure']['body_is_effectively_empty'] ?? false);

        $this->recordMetric($websiteAnalysisId, 'recruit_title_present', MetricResultStatus::Success, normalizedValue: $result['title']['present'], rawValue: $result['title'], analysisPageId: $pageId);

        $this->recordMetric($websiteAnalysisId, 'recruit_meta_description_present', MetricResultStatus::Success, normalizedValue: $result['meta_description']['present'], rawValue: $result['meta_description'], analysisPageId: $pageId);

        $h1ValidCount = $result['h1']['valid_count'];
        $h1Status = $pageUnavailable ? MetricResultStatus::Unavailable : ($h1ValidCount === 0 ? MetricResultStatus::NotFound : MetricResultStatus::Success);
        $this->recordMetric($websiteAnalysisId, 'recruit_h1_single', $h1Status, normalizedValue: $h1ValidCount === 1, rawValue: $result['h1'], analysisPageId: $pageId);

        $headingPresent = $result['h1']['valid_count'] > 0;
        $headingStatus = $pageUnavailable ? MetricResultStatus::Unavailable : ($headingPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound);
        $this->recordMetric($websiteAnalysisId, 'recruit_heading_structure_present', $headingStatus, normalizedValue: $headingPresent, rawValue: $result['h1'], analysisPageId: $pageId);

        $wordCount = $result['content']['word_count'];
        $this->recordMetric($websiteAnalysisId, 'recruit_word_count_sufficient', MetricResultStatus::Success, normalizedValue: $wordCount, rawValue: ['word_count' => $wordCount], analysisPageId: $pageId);

        $this->recordMetric($websiteAnalysisId, 'recruit_internal_link_sufficient', MetricResultStatus::Success, normalizedValue: $result['links']['internal'], rawValue: $result['links'], analysisPageId: $pageId);

        $this->recordBusinessLink($websiteAnalysisId, $result, 'faq', 'recruit_faq_link_present', $pageId);
        $this->recordBusinessLink($websiteAnalysisId, $result, 'company_info', 'recruit_company_info_link_present', $pageId);

        $forms = $result['forms'];
        $links = $result['links'];

        $formPresent = $forms['form_count'] > 0;
        $this->recordMetric($websiteAnalysisId, 'recruit_form_present', $formPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $formPresent, rawValue: $forms, analysisPageId: $pageId);

        $telOrMail = $links['tel'] > 0 || $links['mailto'] > 0;
        $this->recordMetric($websiteAnalysisId, 'recruit_tel_or_mailto_present', $telOrMail ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $telOrMail, rawValue: $links, analysisPageId: $pageId);

        $contactCta = $links['contact_like'] > 0 || $forms['contact_like'];
        $this->recordMetric($websiteAnalysisId, 'recruit_contact_cta_present', $contactCta ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $contactCta, rawValue: ['links' => $links, 'forms' => $forms], analysisPageId: $pageId);

        $viewportStatus = $pageUnavailable ? MetricResultStatus::Unavailable : MetricResultStatus::Success;
        $this->recordMetric($websiteAnalysisId, 'recruit_viewport_present', $viewportStatus, normalizedValue: $result['content']['viewport_present'], rawValue: $result['content'], analysisPageId: $pageId);

        $this->recordMetric($websiteAnalysisId, 'recruit_a11y_lang_present', MetricResultStatus::Success, normalizedValue: $result['content']['lang'] !== null, rawValue: ['lang' => $result['content']['lang']], analysisPageId: $pageId);

        $a11y = $result['accessibility'];
        $this->recordMetric(
            $websiteAnalysisId, 'recruit_a11y_form_label_present',
            $a11y['form_label_present'] === null ? MetricResultStatus::NotApplicable : MetricResultStatus::Success,
            normalizedValue: $a11y['form_label_present'] ?? false, rawValue: $a11y, analysisPageId: $pageId,
        );
        $this->recordMetric(
            $websiteAnalysisId, 'recruit_a11y_button_name_present',
            $a11y['button_name_present'] === null ? MetricResultStatus::NotApplicable : MetricResultStatus::Success,
            normalizedValue: $a11y['button_name_present'] ?? false, rawValue: $a11y, analysisPageId: $pageId,
        );
        $this->recordMetric($websiteAnalysisId, 'recruit_a11y_heading_order_ok', MetricResultStatus::Success, normalizedValue: $a11y['heading_order_ok'], rawValue: $a11y, analysisPageId: $pageId);
    }

    public function recordAllUnavailable(int $websiteAnalysisId): void
    {
        foreach (self::ALL_KEYS as $key) {
            $this->recordMetric($websiteAnalysisId, $key, MetricResultStatus::Unavailable);
        }
    }

    private function recordBusinessLink(int $websiteAnalysisId, array $result, string $businessLinkKey, string $metricKey, int $pageId): void
    {
        $link = $result['business_links'][$businessLinkKey] ?? ['present' => false];
        $present = (bool) $link['present'];
        $confidence = $present ? (float) ($link['confidence'] ?? 0.65) : 1.0;

        $this->recordMetric($websiteAnalysisId, $metricKey, $present ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $present, rawValue: $link, analysisPageId: $pageId, confidence: $confidence);
    }
}

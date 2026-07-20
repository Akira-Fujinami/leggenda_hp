<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\HtmlSeoAnalyzer;
use Illuminate\Support\Facades\Storage;

/**
 * FetchStaticPageJobが保存した生HTMLを解析し、technical_seo/content/
 * accessibility/conversionカテゴリのMetricResultを記録する。
 *
 * 依存関係: FetchStaticPageJobの成否に関わらず(finally句で)必ず起動される。
 * HTMLが取得できていない場合は「失敗」ではなく「測定不能」として全指標を
 * unavailableで記録し、正常終了する(取得できなかった原因はFetchStaticPageJob
 * 側のAnalysisJobに既に記録されているため、ここで重複してエラー扱いにはしない)。
 */
class AnalyzeHtmlSeoJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 1;

    public $timeout = 20;

    /**
     * このJobが担当する全MetricDefinitionキー(HTMLが取得できなかった場合に
     * まとめてunavailableにするため)。
     */
    private const ALL_KEYS = [
        'title_present', 'title_length_optimal', 'meta_description_present', 'meta_description_length_optimal',
        'h1_single', 'canonical_present', 'canonical_self_referencing', 'robots_meta_indexable',
        'viewport_present', 'lang_present', 'favicon_present', 'structured_data_present', 'ogp_present',
        'word_count_sufficient', 'img_alt_coverage', 'internal_link_sufficient', 'heading_structure_present',
        'external_link_present', 'pricing_info_link_present', 'case_study_or_testimonial_link_present',
        'company_info_link_present', 'privacy_policy_link_present', 'faq_link_present',
        'a11y_lang_present', 'a11y_form_label_present', 'a11y_button_name_present', 'a11y_heading_order_ok',
        'form_present', 'tel_or_mailto_present', 'contact_cta_present', 'reservation_cta_present',
        'document_request_cta_present', 'sns_link_present', 'cta_count_sufficient',
        'form_input_burden', 'external_reservation_service_detected', 'recruit_link_present',
        'page_form_count', 'page_input_count', 'representative_form_field_count',
    ];

    public function jobType(): JobType
    {
        return JobType::AnalyzeHtmlSeo;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $page = AnalysisPage::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->where('page_type', PageType::Homepage)
            ->first();

        $disk = Storage::disk('analysis');

        // レンダリング後HTML(JS実行後)が既に利用可能ならそちらを優先する
        // (H1/viewport等をJSで注入するSPA的なサイトでは、静的HTMLだけでは
        // 実際には存在する要素を「無し」と誤判定しかねないため)。
        // AnalyzeHtmlSeoJobはFetchStaticPageJobの完了直後に起動されるため、
        // RenderPageJob(別途並行実行)がまだ完了していないことも多く、
        // その場合は従来通り静的HTMLにフォールバックする。
        $htmlSource = null;
        $htmlPath = null;
        if ($page?->rendered_html_path !== null && $disk->exists($page->rendered_html_path)) {
            $htmlPath = $page->rendered_html_path;
            $htmlSource = 'rendered';
        } elseif ($page?->raw_html_path !== null && $disk->exists($page->raw_html_path)) {
            $htmlPath = $page->raw_html_path;
            $htmlSource = 'static';
        }

        if ($htmlPath === null) {
            $this->recordAllUnavailable();

            return;
        }

        $html = $disk->get($htmlPath);
        $pageUrl = $page->final_url ?? $page->url;

        $result = app(HtmlSeoAnalyzer::class)->analyze($html, $pageUrl);
        $result['html_source'] = $htmlSource;

        $page->update([
            'title' => $result['title']['text'],
            'meta_description' => $result['meta_description']['text'],
            'h1_count' => $result['h1']['count'],
            'word_count' => $result['content']['word_count'],
        ]);

        $this->recordTechnicalSeo($result, $pageUrl, $page->id);
        $this->recordContent($result, $page->id);
        $this->recordAccessibility($result, $page->id);
        $this->recordConversion($result, $page->id);
    }

    private function recordTechnicalSeo(array $result, string $pageUrl, int $pageId): void
    {
        // head/bodyが実質的に空(bot拒否ページ・取得失敗のプレースホルダー等)の
        // 場合、h1/viewportの「無し」は「設置していない」(not_found)ではなく
        // 「そもそも判定材料が無い」(unavailable)として扱う。
        $pageUnavailable = (bool) ($result['page_structure']['body_is_effectively_empty'] ?? false);

        $this->recordMetric($this->websiteAnalysisId, 'title_present', MetricResultStatus::Success, normalizedValue: $result['title']['present'], rawValue: $result['title'], analysisPageId: $pageId);

        $titleLength = $result['title']['length'];
        $this->recordMetric($this->websiteAnalysisId, 'title_length_optimal', $titleLength === null ? MetricResultStatus::NotFound : MetricResultStatus::Success, normalizedValue: $titleLength, rawValue: $result['title'], analysisPageId: $pageId);

        $this->recordMetric($this->websiteAnalysisId, 'meta_description_present', MetricResultStatus::Success, normalizedValue: $result['meta_description']['present'], rawValue: $result['meta_description'], analysisPageId: $pageId);

        $descLength = $result['meta_description']['length'];
        $this->recordMetric($this->websiteAnalysisId, 'meta_description_length_optimal', $descLength === null ? MetricResultStatus::NotFound : MetricResultStatus::Success, normalizedValue: $descLength, rawValue: $result['meta_description'], analysisPageId: $pageId);

        $h1Status = $pageUnavailable ? MetricResultStatus::Unavailable : MetricResultStatus::Success;
        $this->recordMetric($this->websiteAnalysisId, 'h1_single', $h1Status, normalizedValue: $result['h1']['count'] === 1, rawValue: $result['h1'] + ['html_source' => $result['html_source'] ?? null], analysisPageId: $pageId);

        $this->recordMetric($this->websiteAnalysisId, 'canonical_present', MetricResultStatus::Success, normalizedValue: $result['canonical']['present'], rawValue: $result['canonical'], analysisPageId: $pageId);

        $canonicalStatus = $result['canonical']['present'] ? MetricResultStatus::Success : MetricResultStatus::NotFound;
        $this->recordMetric($this->websiteAnalysisId, 'canonical_self_referencing', $canonicalStatus, normalizedValue: (bool) ($result['canonical']['is_self_referencing'] ?? false), rawValue: $result['canonical'], analysisPageId: $pageId);

        $this->recordMetric($this->websiteAnalysisId, 'robots_meta_indexable', MetricResultStatus::Success, normalizedValue: $result['robots_meta']['index'], rawValue: $result['robots_meta'], analysisPageId: $pageId);

        $viewportStatus = $pageUnavailable ? MetricResultStatus::Unavailable : MetricResultStatus::Success;
        $this->recordMetric($this->websiteAnalysisId, 'viewport_present', $viewportStatus, normalizedValue: $result['content']['viewport_present'], rawValue: $result['content'] + ['html_source' => $result['html_source'] ?? null], analysisPageId: $pageId);

        $this->recordMetric($this->websiteAnalysisId, 'lang_present', MetricResultStatus::Success, normalizedValue: $result['content']['lang'] !== null, rawValue: ['lang' => $result['content']['lang']], analysisPageId: $pageId);

        $this->recordMetric($this->websiteAnalysisId, 'favicon_present', MetricResultStatus::Success, normalizedValue: $result['content']['favicon_present'], rawValue: $result['content'], analysisPageId: $pageId);

        $structuredDataPresent = $result['structured_data']['count'] > 0;
        $this->recordMetric($this->websiteAnalysisId, 'structured_data_present', $structuredDataPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $structuredDataPresent, rawValue: $result['structured_data'], analysisPageId: $pageId);

        $ogpPresent = ($result['ogp']['title'] ?? null) !== null;
        $this->recordMetric($this->websiteAnalysisId, 'ogp_present', $ogpPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $ogpPresent, rawValue: $result['ogp'], analysisPageId: $pageId);
    }

    private function recordContent(array $result, int $pageId): void
    {
        $wordCount = $result['content']['word_count'];
        $this->recordMetric($this->websiteAnalysisId, 'word_count_sufficient', MetricResultStatus::Success, normalizedValue: $wordCount, rawValue: ['word_count' => $wordCount], analysisPageId: $pageId);

        $altCoverage = $result['images']['alt_coverage'];
        $this->recordMetric($this->websiteAnalysisId, 'img_alt_coverage', MetricResultStatus::Success, normalizedValue: $altCoverage ?? 1.0, rawValue: $result['images'], analysisPageId: $pageId);

        $this->recordMetric($this->websiteAnalysisId, 'internal_link_sufficient', MetricResultStatus::Success, normalizedValue: $result['links']['internal'], rawValue: $result['links'], analysisPageId: $pageId);

        $pageUnavailable = (bool) ($result['page_structure']['body_is_effectively_empty'] ?? false);
        $headingPresent = $result['h1']['count'] > 0;
        $headingStatus = $pageUnavailable ? MetricResultStatus::Unavailable : ($headingPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound);
        $this->recordMetric($this->websiteAnalysisId, 'heading_structure_present', $headingStatus, normalizedValue: $headingPresent, rawValue: $result['h1'], analysisPageId: $pageId);

        $externalPresent = $result['links']['external'] > 0;
        $this->recordMetric($this->websiteAnalysisId, 'external_link_present', $externalPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $externalPresent, rawValue: $result['links'], analysisPageId: $pageId);

        $this->recordBusinessLink($result, 'pricing', 'pricing_info_link_present', $pageId);
        $this->recordBusinessLink($result, 'case_study', 'case_study_or_testimonial_link_present', $pageId);
        $this->recordBusinessLink($result, 'company_info', 'company_info_link_present', $pageId);
        $this->recordBusinessLink($result, 'privacy_policy', 'privacy_policy_link_present', $pageId);
        $this->recordBusinessLink($result, 'faq', 'faq_link_present', $pageId);
    }

    private function recordBusinessLink(array $result, string $businessLinkKey, string $metricKey, int $pageId): void
    {
        $link = $result['business_links'][$businessLinkKey] ?? ['present' => false];
        $present = (bool) $link['present'];
        $confidence = $present ? (float) ($link['confidence'] ?? 0.65) : 1.0;

        $this->recordMetric(
            $this->websiteAnalysisId, $metricKey,
            $present ? MetricResultStatus::Success : MetricResultStatus::NotFound,
            normalizedValue: $present, rawValue: $link, analysisPageId: $pageId, confidence: $confidence,
        );
    }

    private function recordAccessibility(array $result, int $pageId): void
    {
        $this->recordMetric($this->websiteAnalysisId, 'a11y_lang_present', MetricResultStatus::Success, normalizedValue: $result['content']['lang'] !== null, rawValue: ['lang' => $result['content']['lang']], analysisPageId: $pageId);

        $a11y = $result['accessibility'];

        $this->recordMetric(
            $this->websiteAnalysisId, 'a11y_form_label_present',
            $a11y['form_label_present'] === null ? MetricResultStatus::NotApplicable : MetricResultStatus::Success,
            normalizedValue: $a11y['form_label_present'] ?? false, rawValue: $a11y, analysisPageId: $pageId,
        );

        $this->recordMetric(
            $this->websiteAnalysisId, 'a11y_button_name_present',
            $a11y['button_name_present'] === null ? MetricResultStatus::NotApplicable : MetricResultStatus::Success,
            normalizedValue: $a11y['button_name_present'] ?? false, rawValue: $a11y, analysisPageId: $pageId,
        );

        $this->recordMetric($this->websiteAnalysisId, 'a11y_heading_order_ok', MetricResultStatus::Success, normalizedValue: $a11y['heading_order_ok'], rawValue: $a11y, analysisPageId: $pageId);
    }

    private function recordConversion(array $result, int $pageId): void
    {
        $forms = $result['forms'];
        $links = $result['links'];

        $formPresent = $forms['form_count'] > 0;
        $this->recordMetric($this->websiteAnalysisId, 'form_present', $formPresent ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $formPresent, rawValue: $forms, analysisPageId: $pageId);

        $telOrMail = $links['tel'] > 0 || $links['mailto'] > 0;
        $this->recordMetric($this->websiteAnalysisId, 'tel_or_mailto_present', $telOrMail ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $telOrMail, rawValue: $links, analysisPageId: $pageId);

        $contactCta = $links['contact_like'] > 0 || $forms['contact_like'];
        $this->recordMetric($this->websiteAnalysisId, 'contact_cta_present', $contactCta ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $contactCta, rawValue: ['links' => $links, 'forms' => $forms], analysisPageId: $pageId);

        $this->recordMetric($this->websiteAnalysisId, 'reservation_cta_present', $forms['reservation_like'] ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $forms['reservation_like'], rawValue: $forms, analysisPageId: $pageId);

        $this->recordMetric($this->websiteAnalysisId, 'document_request_cta_present', $forms['document_request_like'] ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $forms['document_request_like'], rawValue: $forms, analysisPageId: $pageId);

        $sns = $result['sns_links'];
        $this->recordMetric($this->websiteAnalysisId, 'sns_link_present', $sns['detected'] ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $sns['detected'], rawValue: $sns, analysisPageId: $pageId);

        $ctaCount = $links['contact_like'] + $links['tel'] + $links['mailto'] + ($forms['reservation_like'] ? 1 : 0) + ($forms['document_request_like'] ? 1 : 0);
        $this->recordMetric($this->websiteAnalysisId, 'cta_count_sufficient', MetricResultStatus::Success, normalizedValue: $ctaCount, rawValue: ['cta_count' => $ctaCount], analysisPageId: $pageId);

        $burden = $result['form_burden'];
        $burdenStatus = $burden['form_found'] ? MetricResultStatus::Success : MetricResultStatus::NotFound;
        $this->recordMetric($this->websiteAnalysisId, 'form_input_burden', $burdenStatus, normalizedValue: $burden['required_field_count'], rawValue: $burden, analysisPageId: $pageId);

        // ページ全体のフォーム数・入力項目総数・代表フォーム自体の項目数は、
        // 「フォーム入力負担」(=代表フォームの必須項目数のみ)と混同されないよう
        // 別のMetricとして記録する。
        $this->recordMetric($this->websiteAnalysisId, 'page_form_count', MetricResultStatus::Success, normalizedValue: $forms['form_count'], rawValue: $burden, analysisPageId: $pageId);
        $this->recordMetric($this->websiteAnalysisId, 'page_input_count', MetricResultStatus::Success, normalizedValue: $burden['page_total_field_count'], rawValue: $burden, analysisPageId: $pageId);
        $this->recordMetric($this->websiteAnalysisId, 'representative_form_field_count', $burdenStatus, normalizedValue: $burden['total_field_count'], rawValue: $burden, analysisPageId: $pageId);

        $reservationService = $result['third_party_reservation'];
        $reservationDetected = $reservationService['detected'];
        $this->recordMetric($this->websiteAnalysisId, 'external_reservation_service_detected', $reservationDetected ? MetricResultStatus::Success : MetricResultStatus::NotFound, normalizedValue: $reservationDetected, rawValue: $reservationService, analysisPageId: $pageId);

        $this->recordBusinessLink($result, 'recruit', 'recruit_link_present', $pageId);
    }

    private function recordAllUnavailable(): void
    {
        foreach (self::ALL_KEYS as $key) {
            $this->recordMetric($this->websiteAnalysisId, $key, MetricResultStatus::Unavailable);
        }
    }
}

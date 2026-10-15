<?php

namespace App\Services\SiteFirstImpression;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\PageHtmlResolver;
use App\Services\CorporateTop\CorporateTopStore;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CX-1: 第一印象の材料(AIを使わない・通信しない)。自社の2ページ ―― コーポレートTOP(依頼CRで取得して保存したHTML。
 * 決まらなかった会社はコーポレートTOPの分を作らず、採用TOPだけ)と採用TOP(自社の採用ページ=起点。描画済みのHTML、
 * 無ければ静的なHTML)―― の構造を、PageStructureExtractorで取り出す。
 *
 * 材料を作れない(どちらのページのHTMLも読めない)ときは status=skipped(AIは呼ばない)。
 * 企業名の一覧(自社の言い換えを含む)と、同じ比較の他社の名前は、サーバー側の確認(自社の名前だけを出す)に使う。
 */
class SiteFirstImpressionMaterialBuilder
{
    public function __construct(
        private readonly PageStructureExtractor $extractor,
        private readonly CorporateTopStore $corporateTop,
        private readonly PageHtmlResolver $htmlResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(WebsiteAnalysis $websiteAnalysis): array
    {
        $websiteAnalysis->loadMissing(['website', 'analysis.project.leadCompany', 'analysis.websiteAnalyses.website']);
        $analysisId = (int) $websiteAnalysis->analysis_id;
        $websiteAnalysisId = (int) $websiteAnalysis->id;
        $analysis = $websiteAnalysis->analysis;

        $companyName = (string) ($analysis?->project?->leadCompany?->company_name ?? $websiteAnalysis->website?->name ?? '');
        $recruitUrl = (string) ($websiteAnalysis->website?->normalized_url ?? $websiteAnalysis->website?->url ?? '');
        $labels = (array) config('site_first_impression.page_labels');
        $prefixes = (array) config('site_first_impression.id_prefixes');

        $pages = [];

        // コーポレートTOP(依頼CR。決まらなかった会社は、この分を作らない)
        $meta = $this->corporateTop->readMeta($analysisId, $websiteAnalysisId);
        $corporateHtml = ($meta['status'] ?? null) === 'found' ? $this->corporateTop->readHtml($analysisId, $websiteAnalysisId) : null;
        if ($corporateHtml !== null) {
            $url = (string) ($meta['url'] ?? '');
            $pages['corporate'] = [
                'page' => 'corporate',
                'label' => (string) $labels['corporate'],
                'url' => $url,
                'structure' => $this->extractor->extract($corporateHtml, $url, (string) $prefixes['corporate'], $recruitUrl !== '' ? $recruitUrl : null),
            ];
        }

        // 採用TOP(自社の採用ページ=起点)
        $recruitPage = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysisId)->where('page_type', PageType::Recruit)->first();
        $resolved = $this->htmlResolver->resolve($recruitPage);
        $recruitHtml = $resolved !== null ? (string) Storage::disk('analysis')->get($resolved['path']) : '';
        if ($recruitHtml !== '') {
            $pages['recruit'] = [
                'page' => 'recruit',
                'label' => (string) $labels['recruit'],
                'url' => $recruitUrl,
                'structure' => $this->extractor->extract($recruitHtml, $recruitUrl, (string) $prefixes['recruit']),
            ];
        }

        $selfNames = $this->variants($companyName);
        if (isset($websiteAnalysis->website->name)) {
            array_push($selfNames, ...$this->variants((string) $websiteAnalysis->website->name));
        }
        $otherNames = [];
        foreach ($analysis?->websiteAnalyses ?? [] as $other) {
            if ((int) $other->id !== $websiteAnalysisId && $other->website?->name !== null) {
                array_push($otherNames, ...$this->variants((string) $other->website->name));
            }
        }

        if ($pages === []) {
            return ['status' => 'skipped', 'reason' => 'no_page', 'analysis_id' => $analysisId, 'website_analysis_id' => $websiteAnalysisId, 'company_name' => $companyName, 'self_names' => [], 'other_names' => [], 'pages' => [], 'elements' => [], 'numbers' => []];
        }

        $elements = [];
        $numbers = [];
        foreach ($pages as $page) {
            $elements += $page['structure']['elements'];
            array_push($numbers, ...$page['structure']['numbers']);
        }

        return [
            'status' => 'ok',
            'reason' => null,
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysisId,
            'company_name' => $companyName,
            'self_names' => array_values(array_unique($selfNames)),
            // 自社の名前と同じ文字を含む他社の名前は、確認の対象から外す(自社の名前を誤って落とさないため)。
            'other_names' => array_values(array_unique(array_filter($otherNames, fn (string $n) => ! in_array($n, $selfNames, true)))),
            'pages' => $pages,
            'elements' => $elements,
            'numbers' => array_values(array_unique($numbers)),
        ];
    }

    /**
     * プロンプトに載せる部分(確認用の要素の一覧・数字は含めない)。
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public function visible(array $material): array
    {
        $pages = [];
        foreach ((array) $material['pages'] as $key => $page) {
            $pages[$key] = ['label' => $page['label'], 'url' => $page['url'], 'structure' => array_diff_key($page['structure'], ['elements' => 1, 'numbers' => 1, 'prefix' => 1])];
        }

        return ['company_name' => $material['company_name'], 'pages' => $pages];
    }

    /**
     * 会社名と、法人格を除いた言い換え(2文字以上のものだけ)。
     *
     * @return list<string>
     */
    public function variants(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }
        $stripped = $name;
        foreach ((array) config('site_first_impression.company_legal_forms', []) as $form) {
            $stripped = str_replace((string) $form, '', $stripped);
        }
        $stripped = trim((string) preg_replace('/\s+/u', '', $stripped));

        return array_values(array_unique(array_filter([$name, $stripped], fn (string $v) => mb_strlen($v) >= 2)));
    }
}

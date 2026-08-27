<?php

namespace Tests\Feature\Report;

use App\Support\Report\MultiSiteReportViewModel;
use Tests\TestCase;

/**
 * 依頼AC-2/AC-3/AC-4: 多社比較レポート(reports.admin-comparison-pdf)の
 * 文面・ページ順を検証する。LeadPdfViewTestと同じ方針(dompdfへ変換する前の
 * Bladeテンプレート自体をHTML文字列としてレンダリングする)。
 */
class AdminComparisonPdfViewTest extends TestCase
{
    private function render(MultiSiteReportViewModel $viewModel): string
    {
        return view('reports.admin-comparison-pdf', [
            'viewModel' => $viewModel,
            'ipaexGothicFontPath' => 'file:///dev/null',
            'brandWheelFrameworkImageBase64' => base64_encode((string) file_get_contents(resource_path('images/brand-wheel-framework.png'))),
            'leggendaLogoImageBase64' => base64_encode((string) file_get_contents(resource_path('images/leggenda-logo.png'))),
        ])->render();
    }

    /**
     * @param  list<array{name: string, url: string}>  $competitors
     */
    private function viewModel(array $overrides = [], array $competitors = []): MultiSiteReportViewModel
    {
        $competitors = $competitors !== [] ? $competitors : [
            ['name' => 'サイボウズ', 'url' => 'https://cybozu.example.com'],
            ['name' => 'DeNA', 'url' => 'https://dena.example.com'],
            ['name' => 'ZOZO', 'url' => 'https://zozo.example.com'],
        ];

        $defaults = [
            'selfCompanyDisplayName' => '株式会社サンプル',
            'generatedAtLabel' => '2026年8月27日',
            'selfWebsiteUrl' => 'https://example.com',
            'competitors' => $competitors,
            'competitorCount' => count($competitors),
            'majorityThreshold' => intdiv(count($competitors), 2) + 1,
            'selfReadable' => true,
            'selfTotalMatched' => 8,
            'selfTotalMax' => 24,
            'brandWheelRadarPngCombined' => null,
            'missingFromSelf' => [],
            'selfStrengths' => [],
            'comparisonTable' => [],
            'selfEvidenceByAxis' => [],
            'hasQuoteTranslations' => false,
        ];

        $merged = array_merge($defaults, $overrides);

        return new MultiSiteReportViewModel(...$merged);
    }

    public function test_cover_page_shows_self_and_competitor_company_names_and_date(): void
    {
        $html = $this->render($this->viewModel());

        $this->assertStringContainsString('株式会社サンプル', $html);
        $this->assertStringContainsString('サイボウズ', $html);
        $this->assertStringContainsString('DeNA', $html);
        $this->assertStringContainsString('ZOZO', $html);
        $this->assertStringContainsString('2026年8月27日', $html);
    }

    /**
     * 依頼AC-2最重要: 「競合1」等の記号表記は不採用 ―― 実際の社名を列見出しに
     * 使う。
     */
    public function test_comparison_table_headers_show_actual_company_names_not_symbolic_labels(): void
    {
        $comparisonTable = [
            ['axis_name' => '活動的魅力', 'group' => 'company_appeal', 'sub_name' => 'パーパス', 'self_matched' => true, 'competitor_matched' => [true, false, true]],
        ];

        $html = $this->render($this->viewModel(['comparisonTable' => $comparisonTable]));

        $this->assertStringContainsString('サイボウズ', $html);
        $this->assertStringContainsString('DeNA', $html);
        $this->assertStringContainsString('ZOZO', $html);
        // 依頼AB時点の既定値(社名未入力時のフォールバック)だった記号表記が
        // 列見出しに残っていないこと。「競合N社中M社」等の件数表記(正当な
        // 文言)まで否定しないよう、旧フォールバック固有の文字列で判定する。
        $this->assertStringNotContainsString('競合サイト1', $html);
        $this->assertStringNotContainsString('競合サイト2', $html);
        $this->assertStringNotContainsString('競合サイト3', $html);
    }

    /**
     * 依頼AC-2: 列の並び順はdisplay_order順(=$viewModel->competitorsの
     * 並び順)をそのまま使う。
     */
    public function test_comparison_table_column_order_follows_the_competitors_array_order(): void
    {
        $html = $this->render($this->viewModel());

        $cyPos = strpos($html, 'サイボウズ');
        $denaPos = strpos($html, 'DeNA');
        $zozoPos = strpos($html, 'ZOZO');

        $this->assertNotFalse($cyPos);
        $this->assertNotFalse($denaPos);
        $this->assertNotFalse($zozoPos);
        $this->assertLessThan($denaPos, $cyPos);
        $this->assertLessThan($zozoPos, $denaPos);
    }

    /**
     * 依頼AC-3最重要: 競合引用には必ず社名を添える(「どの会社の記述か
     * 分からない引用は商談で使えない」という依頼者指摘への対応)。
     */
    public function test_missing_from_self_shows_representative_company_name_with_the_quote(): void
    {
        $missingFromSelf = [[
            'axis_name' => '活動的魅力',
            'sub_name' => 'パーパス',
            'definition' => '会社が何を目指しているかの記述。',
            'recommendation' => '御社が何のために存在するかを書いてください。',
            'competitor_matched_count' => 2,
            'representative_company_name' => 'サイボウズ',
            'quote' => 'チームワークあふれる社会を創る。',
            'quote_translation' => null,
        ]];

        $html = $this->render($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertStringContainsString('サイボウズの記述より', $html);
        $this->assertStringContainsString('チームワークあふれる社会を創る。', $html);
    }

    public function test_non_japanese_quote_shows_the_translation_line(): void
    {
        $missingFromSelf = [[
            'axis_name' => '活動的魅力',
            'sub_name' => 'パーパス',
            'definition' => '定義文。',
            'recommendation' => '',
            'competitor_matched_count' => 2,
            'representative_company_name' => 'DeNA',
            'quote' => 'Delight and Impact the World.',
            'quote_translation' => '世界に喜びとインパクトを。',
        ]];

        $html = $this->render($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertStringContainsString('Delight and Impact the World.', $html);
        $this->assertStringContainsString('日本語訳: 世界に喜びとインパクトを。', $html);
    }

    /**
     * 依頼AC-3: 引用は必ずHTMLエスケープを経由する(Bladeの{{ }}を使う)。
     */
    public function test_quotes_and_company_names_are_html_escaped(): void
    {
        $missingFromSelf = [[
            'axis_name' => '活動的魅力',
            'sub_name' => 'パーパス',
            'definition' => '定義文。',
            'recommendation' => '',
            'competitor_matched_count' => 2,
            'representative_company_name' => 'A&B<script>',
            'quote' => '<script>alert(1)</script> & "quoted"',
            'quote_translation' => null,
        ]];

        $html = $this->render($this->viewModel(['missingFromSelf' => $missingFromSelf]));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    /**
     * 依頼AC-4最重要: 結論(④自社に足りない項目・⑤自社の強み)を、根拠である
     * 対比表より先に置く。
     */
    public function test_missing_from_self_and_self_strengths_appear_before_the_comparison_table(): void
    {
        $missingFromSelf = [[
            'axis_name' => '活動的魅力', 'sub_name' => 'パーパス', 'definition' => '定義。', 'recommendation' => '',
            'competitor_matched_count' => 2, 'representative_company_name' => 'サイボウズ', 'quote' => '引用文。', 'quote_translation' => null,
        ]];
        $selfStrengths = [[
            'axis_name' => '資産的魅力', 'sub_name' => '知名度・評判', 'definition' => '定義。', 'competitor_matched_count' => 0,
        ]];
        $comparisonTable = [
            ['axis_name' => '活動的魅力', 'group' => 'company_appeal', 'sub_name' => 'パーパス', 'self_matched' => false, 'competitor_matched' => [true, true, false]],
        ];

        $html = $this->render($this->viewModel([
            'missingFromSelf' => $missingFromSelf,
            'selfStrengths' => $selfStrengths,
            'comparisonTable' => $comparisonTable,
        ]));

        $missingHeadingPos = strpos($html, '自社に足りない項目');
        $strengthsHeadingPos = strpos($html, '自社の強み');
        $tableHeadingPos = strpos($html, '24項目の対比表');

        $this->assertNotFalse($missingHeadingPos);
        $this->assertNotFalse($strengthsHeadingPos);
        $this->assertNotFalse($tableHeadingPos);
        $this->assertLessThan($tableHeadingPos, $missingHeadingPos);
        $this->assertLessThan($tableHeadingPos, $strengthsHeadingPos);
    }

    /**
     * 依頼X(0件挙げます、のダングリング文言)と同じ種類の事故を繰り返さない
     * ―― 「自社に足りない項目」が0件でもページが破綻せず、自然な文言になる。
     */
    public function test_missing_from_self_page_shows_a_graceful_message_when_empty(): void
    {
        $html = $this->render($this->viewModel(['missingFromSelf' => []]));

        $this->assertStringContainsString('自社に不足している項目は見つかりませんでした', $html);
        $this->assertStringNotContainsString('0件挙げます', $html);
    }

    public function test_self_strengths_page_shows_a_graceful_message_when_empty(): void
    {
        $html = $this->render($this->viewModel(['selfStrengths' => []]));

        $this->assertStringContainsString('自社だけの強みとして際立つ項目は見つかりませんでした', $html);
        $this->assertStringNotContainsString('0件挙げます', $html);
    }

    public function test_self_evidence_page_is_omitted_when_empty(): void
    {
        $html = $this->render($this->viewModel(['selfEvidenceByAxis' => []]));

        $this->assertStringNotContainsString('自社の「○」と判定した根拠', $html);
    }

    public function test_self_evidence_page_shows_translation_when_present(): void
    {
        $selfEvidenceByAxis = [[
            'axis_name' => '活動的魅力',
            'items' => [[
                'sub_name' => 'パーパス',
                'evidence' => 'We build a better society.',
                'evidence_translation' => 'より良い社会を築く。',
            ]],
        ]];

        $html = $this->render($this->viewModel(['selfEvidenceByAxis' => $selfEvidenceByAxis]));

        $this->assertStringContainsString('自社の「○」と判定した根拠', $html);
        $this->assertStringContainsString('We build a better society.', $html);
        $this->assertStringContainsString('日本語訳: より良い社会を築く。', $html);
    }
}

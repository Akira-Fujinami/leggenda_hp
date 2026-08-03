<?php

namespace Tests\Unit\BrandWheel;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandWheelAnalysisInputFactoryTest extends TestCase
{
    use RefreshDatabase;

    private BrandWheelAnalysisInputFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
        Storage::fake('analysis');
        $this->factory = app(BrandWheelAnalysisInputFactory::class);
    }

    private function putHtmlPage(WebsiteAnalysis $websiteAnalysis, PageType $pageType, string $html, ?string $title = null): AnalysisPage
    {
        $filename = $pageType === PageType::Recruit ? 'recruit.html' : 'homepage.html';
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, $filename);
        Storage::disk('analysis')->put($path, $html);

        return AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => $pageType,
            'http_status' => 200,
            'raw_html_path' => $path,
            'title' => $title,
            'fetched_at' => now(),
        ]);
    }

    public function test_it_builds_input_from_recruit_and_homepage_html_without_leaking_raw_html(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $recruitHtml = '<html><head><title>採用情報</title><script>evil()</script></head>'
            .'<body><h1>採用情報</h1><h2>働き方</h2><p>私たちは挑戦を続けます。</p></body></html>';
        $homepageHtml = '<html><head><title>Example</title><style>.x{color:red}</style></head>'
            .'<body><header><nav><a href="/energy">エネルギー事業</a><a href="/mobility">モビリティ事業</a></nav></header>'
            .'<h1>Example</h1><p>会社概要のご案内です。</p>'
            .'<footer><a href="/healthcare">ヘルスケア事業</a></footer></body></html>';

        $this->putHtmlPage($websiteAnalysis, PageType::Recruit, $recruitHtml, '採用情報 | Example');
        $this->putHtmlPage($websiteAnalysis, PageType::Homepage, $homepageHtml, 'Example');

        $input = $this->factory->build($websiteAnalysis->fresh());

        $this->assertSame('採用情報 | Example', $input->recruitPageTitle);
        $this->assertStringContainsString('私たちは挑戦を続けます', $input->recruitPageBodyText);
        $this->assertSame([['level' => 1, 'text' => '採用情報'], ['level' => 2, 'text' => '働き方']], $input->recruitPageHeadings);

        $this->assertSame('Example', $input->homepageTitle);
        $this->assertStringContainsString('会社概要のご案内です', $input->homepageBodyText);

        $this->assertSame(['エネルギー事業', 'モビリティ事業', 'ヘルスケア事業'], $input->businessLinkLabels);
        $this->assertFalse($input->inputTruncated);

        $json = json_encode($input->toArray());
        $this->assertStringNotContainsString('<html>', $json);
        $this->assertStringNotContainsString('<script>', $json);
        $this->assertStringNotContainsString('evil()', $json);
        $this->assertStringNotContainsString('<style>', $json);
        $this->assertStringNotContainsString('<h1>', $json);
        $this->assertStringNotContainsString('<a href', $json);
        $this->assertStringNotContainsString('/energy', $json);
    }

    public function test_it_does_not_reference_any_lead_model_or_lead_pii_field(): void
    {
        // リードPIIがAIへ渡る経路自体が存在しないことを、依存関係の欠如として
        // 構造的に検証する(LeadSession等Lead系モデルへの参照が無いこと)。
        // コード中の説明コメントには設計意図としてLeadSessionの名が出てくるため、
        // `use`インポート文のみを対象に実コード上の依存有無を検証する。
        $factoryImports = $this->importedClassNames('Services/BrandWheel/BrandWheelAnalysisInputFactory.php');
        $dtoImports = $this->importedClassNames('Services/BrandWheel/Data/BrandWheelAnalysisInput.php');

        foreach ([...$factoryImports, ...$dtoImports] as $importedClass) {
            $this->assertStringNotContainsStringIgnoringCase('lead', $importedClass);
        }

        $reflection = new \ReflectionClass(BrandWheelAnalysisInputFactory::class);
        $constructor = $reflection->getConstructor();
        $dependencyClassNames = array_map(
            fn (\ReflectionParameter $p) => $p->getType()?->getName(),
            $constructor?->getParameters() ?? [],
        );

        foreach ($dependencyClassNames as $className) {
            $this->assertStringNotContainsStringIgnoringCase('lead', (string) $className);
        }
    }

    /**
     * @return list<string>
     */
    private function importedClassNames(string $relativeAppPath): array
    {
        $source = file_get_contents(app_path($relativeAppPath));
        preg_match_all('/^use\s+([^;]+);/m', $source, $matches);

        return $matches[1];
    }

    public function test_it_truncates_when_input_exceeds_ai_max_input_tokens_and_keeps_recruit_body_first(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $recruitBody = str_repeat('採', 100);
        $homepageBody = str_repeat('会', 200);

        $recruitHtml = '<html><body><p>'.$recruitBody.'</p></body></html>';
        $homepageHtml = '<html><body><p>'.$homepageBody.'</p></body></html>';

        $this->putHtmlPage($websiteAnalysis, PageType::Recruit, $recruitHtml);
        $this->putHtmlPage($websiteAnalysis, PageType::Homepage, $homepageHtml);

        // maxChars = 50 * 3 = 150。採用ページ本文(100文字)は全て残り、
        // 残り50文字分だけトップページ本文が残るはず。
        config(['services.ai.max_input_tokens' => 50]);

        $input = $this->factory->build($websiteAnalysis->fresh());

        $this->assertTrue($input->inputTruncated);
        $this->assertSame($recruitBody, $input->recruitPageBodyText);
        $this->assertSame(str_repeat('会', 50), $input->homepageBodyText);
    }

    /**
     * 2026-08-03: AI_MAX_INPUT_TOKENS未設定時の既定値が「無制限」ではなく
     * 6000tokenになったことを、config()を一切上書きせず(=実運用の既定値の
     * まま)確認する。目安: 6000token≒18,000文字(文字数≒token数×3の概算)。
     */
    public function test_default_ai_max_input_tokens_is_not_unlimited_and_truncates_oversized_input(): void
    {
        $this->assertSame(6000, config('services.ai.max_input_tokens'), 'AI_MAX_INPUT_TOKENS should default to 6000 when unset, not unlimited');

        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        // 18,000文字(6000token相当)を明確に超える本文。
        $recruitHtml = '<html><body><p>'.str_repeat('採用ページの本文です。', 2000).'</p></body></html>';
        $homepageHtml = '<html><body><p>'.str_repeat('トップページの本文です。', 2000).'</p></body></html>';

        $this->putHtmlPage($websiteAnalysis, PageType::Recruit, $recruitHtml);
        $this->putHtmlPage($websiteAnalysis, PageType::Homepage, $homepageHtml);

        $input = $this->factory->build($websiteAnalysis->fresh());

        $this->assertTrue($input->inputTruncated);
        // 採用ページ本文を優先して残す(既存の切り詰め処理の方針)。
        $this->assertGreaterThan(0, mb_strlen($input->recruitPageBodyText));
    }

    public function test_it_does_not_throw_when_recruit_page_is_missing(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $this->putHtmlPage($websiteAnalysis, PageType::Homepage, '<html><body><h1>Example</h1><p>会社の紹介文です。</p></body></html>', 'Example');

        $input = $this->factory->build($websiteAnalysis->fresh());

        $this->assertNull($input->recruitPageTitle);
        $this->assertSame('', $input->recruitPageBodyText);
        $this->assertSame([], $input->recruitPageHeadings);
        $this->assertSame('Example', $input->homepageTitle);
        $this->assertStringContainsString('会社の紹介文です', $input->homepageBodyText);
    }

    public function test_it_does_not_throw_and_logs_a_known_error_when_the_stored_html_file_is_missing(): void
    {
        // AnalysisPage行はあるが、Storage上のファイル実体が無い(欠損)ケース。
        // 「採用ページが元々検出されなかった」場合と違い、これは運用上検知
        // されるべき障害(Renderのディスク共有問題等)であるためLog::errorで
        // 記録する(2026-07-29の指摘によりLog::warningから格上げ)。
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $missingPath = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'recruit.html');
        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com/careers',
            'page_type' => PageType::Recruit,
            'http_status' => 200,
            'raw_html_path' => $missingPath,
            'fetched_at' => now(),
        ]);
        // 意図的にStorageへは何も書き込まない(ファイル欠損を再現する)。

        \Illuminate\Support\Facades\Log::spy();

        $input = $this->factory->build($websiteAnalysis->fresh());

        $this->assertSame('', $input->recruitPageBodyText);
        $this->assertSame([], $input->recruitPageHeadings);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $message === 'Brand wheel analysis: stored raw HTML file is missing'
                && $context['website_analysis_id'] === $websiteAnalysis->id)
            ->once();
    }
}

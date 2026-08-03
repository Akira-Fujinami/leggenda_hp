<?php

namespace Tests\Unit\Services\Analysis;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\PageHtmlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AnalyzeHtmlSeoJob/DetectTechnologyJob/BrandWheelAnalysisInputFactoryが
 * 共有する優先順位ロジック(2026-08-04に3箇所目の重複回避のため切り出し)。
 * レンダリング後HTML(JS実行後)が読める場合はそちらを優先し、無ければ
 * 静的HTMLへフォールバックする。
 */
class PageHtmlResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function resolver(): PageHtmlResolver
    {
        return new PageHtmlResolver;
    }

    private function makePage(?string $rawHtml, ?string $renderedHtml): AnalysisPage
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $rawPath = null;
        if ($rawHtml !== null) {
            $rawPath = "raw-{$websiteAnalysis->id}.html";
            Storage::disk('analysis')->put($rawPath, $rawHtml);
        }

        $renderedPath = null;
        if ($renderedHtml !== null) {
            $renderedPath = "rendered-{$websiteAnalysis->id}.html";
            Storage::disk('analysis')->put($renderedPath, $renderedHtml);
        }

        return AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'raw_html_path' => $rawPath,
            'rendered_html_path' => $renderedPath,
            'fetched_at' => now(),
        ]);
    }

    public function test_prefers_rendered_html_when_both_are_available(): void
    {
        $page = $this->makePage('<html>static</html>', '<html>rendered</html>');

        $resolved = $this->resolver()->resolve($page);

        $this->assertSame('rendered-'.$page->website_analysis_id.'.html', $resolved['path']);
        $this->assertSame(PageHtmlResolver::SOURCE_RENDERED, $resolved['source']);
    }

    public function test_falls_back_to_static_html_when_rendered_is_not_yet_available(): void
    {
        // RenderPageJobは別ジョブとして並行実行されるため、まだ完了していない
        // (rendered_html_pathがnull)ことは正常系。
        $page = $this->makePage('<html>static</html>', null);

        $resolved = $this->resolver()->resolve($page);

        $this->assertSame('raw-'.$page->website_analysis_id.'.html', $resolved['path']);
        $this->assertSame(PageHtmlResolver::SOURCE_STATIC, $resolved['source']);
    }

    public function test_falls_back_to_static_html_when_rendered_path_is_set_but_the_file_is_missing_on_disk(): void
    {
        $page = $this->makePage('<html>static</html>', null);
        $page->update(['rendered_html_path' => 'rendered-missing.html']);

        $resolved = $this->resolver()->resolve($page->fresh());

        $this->assertSame('raw-'.$page->website_analysis_id.'.html', $resolved['path']);
        $this->assertSame(PageHtmlResolver::SOURCE_STATIC, $resolved['source']);
    }

    public function test_returns_null_when_neither_html_is_readable(): void
    {
        $page = $this->makePage(null, null);

        $this->assertNull($this->resolver()->resolve($page));
    }

    public function test_returns_null_for_a_null_page(): void
    {
        $this->assertNull($this->resolver()->resolve(null));
    }
}

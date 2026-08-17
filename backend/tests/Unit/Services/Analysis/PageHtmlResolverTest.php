<?php

namespace Tests\Unit\Services\Analysis;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\PageHtmlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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

    /**
     * 2026-08-19追加: analysis_id=45/website_analysis_id=93の障害調査用。
     * パスがDBに記録されているのに実ファイルが見つからない(=呼び出し元が
     * unreadable相当として扱う)ケースだけを対象にwarningログを残すことを
     * 確認する。「パス自体が未記録」(採用ページが元々無い等の正常系、上の
     * test_returns_null_when_neither_html_is_readable)ではログを出さない
     * ことも合わせて確認済み。
     */
    public function test_logs_a_warning_when_recorded_paths_exist_but_no_file_is_readable_on_disk(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('PageHtmlResolver: recorded HTML path(s) exist in DB but no file is readable on disk', \Mockery::on(function (array $context) {
                return $context['raw_html_path'] === 'raw-missing.html'
                    && $context['rendered_html_path'] === 'rendered-missing.html'
                    && $context['raw_exists'] === false
                    && $context['rendered_exists'] === false
                    && array_key_exists('hostname', $context)
                    && array_key_exists('analysis_disk_root', $context)
                    && array_key_exists('website_analysis_id', $context)
                    && array_key_exists('page_type', $context);
            }));

        $page = $this->makePage(null, null);
        $page->update(['raw_html_path' => 'raw-missing.html', 'rendered_html_path' => 'rendered-missing.html']);

        $this->assertNull($this->resolver()->resolve($page->fresh()));
    }

    public function test_does_not_log_a_warning_when_neither_path_was_ever_recorded(): void
    {
        Log::shouldReceive('warning')->never();

        $page = $this->makePage(null, null);

        $this->resolver()->resolve($page);
    }
}

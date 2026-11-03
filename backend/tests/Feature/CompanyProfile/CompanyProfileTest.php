<?php

namespace Tests\Feature\CompanyProfile;

use App\Enums\AnalysisStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Jobs\Analysis\FinalizeAnalysisJob;
use App\Jobs\GenerateCompanyProfileJob;
use App\Models\Analysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\SafeHttpFetcher;
use App\Services\Analysis\SafeUrlValidator;
use App\Services\CompanyProfile\AnthropicCompanyProfileProvider;
use App\Services\CompanyProfile\CompanyDomain;
use App\Services\CompanyProfile\CompanyProfileDispatcher;
use App\Services\CompanyProfile\CompanyProfileProvider;
use App\Services\CompanyProfile\CompanyProfileService;
use App\Services\CompanyProfile\CompanyProfileSourceFetcher;
use App\Services\CompanyProfile\CompanyProfileStore;
use App\Services\CompanyProfile\CompanyProfileVerifier;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use App\Services\Report\CompanyProfileSlideGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼DK: 「私たちが理解している、{社名}という会社」。AIの下書き(Web検索つき)を、サーバーが出典を取りに行って確かめる。
 * 本文・会社名はすべて人工のもの。AIも出典の取りに行きも偽物を使う(外部と通信しない)。
 */
class CompanyProfileTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const OWN = 'https://www.self.example.com/company/';

    private const PRESS = 'https://press.example.org/news/1';

    /** @var array<string, array{title: string, text: string, final_url?: string}> 出典のURL => ページ */
    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config([
            'company_profile.enabled' => true,
            'company_profile.model' => 'test-model',
            'top_message_panel.enabled' => false,
            'strength_weakness_sentence.enabled' => false,
            'strength_weakness_insight.enabled' => false,
        ]);
        $this->pages = [
            self::OWN => ['title' => '会社概要 | 例社', 'text' => "私たちは、社員全員が つくり手 であることを大切にしています。\n1892年の創業以来、年間1万件以上のプロジェクトを手がけています。"],
            self::PRESS => ['title' => '受賞のお知らせ | 例の賞', 'text' => '例空間デザイン賞2025で、例社の「アースマート」が金賞を受賞しました。'],
        ];
        $test = $this;
        $this->app->instance(CompanyProfileSourceFetcher::class, new class($test) extends CompanyProfileSourceFetcher
        {
            public int $calls = 0;

            public function __construct(private readonly CompanyProfileTest $test)
            {
                parent::__construct(new SafeHttpFetcher(new SafeUrlValidator), new HtmlSeoAnalyzer);
            }

            public function fetch(string $url): ?array
            {
                $this->calls++;
                $page = $this->test->page($url);

                return $page === null ? null : ['title' => $page['title'], 'text' => $page['text'], 'final_url' => $page['final_url'] ?? $url];
            }
        });
    }

    /** @return ?array{title: string, text: string, final_url?: string} */
    public function page(string $url): ?array
    {
        return $this->pages[$url] ?? null;
    }

    /** @return array<string, mixed> */
    private function material(array $sentences = []): array
    {
        return [
            'company_name' => '例社',
            'site_url' => 'https://recruit.self.example.com/',
            'own_domains' => ['example.com'],
            'message_title' => '代表メッセージ',
            'message_url' => 'https://recruit.self.example.com/message/',
            'speaker' => null,
            'sentences' => $sentences ?: [['id' => 'm-1', 'text' => '空間創造によって、人々に「歓び」を届けることが私たちの使命です。'], ['id' => 'm-2', 'text' => '創業から130年、私たちは挑戦を続けてきました。']],
        ];
    }

    /** @return array<string, mixed> */
    private function goodDraft(): array
    {
        return [
            'headline' => ['text' => '空間創造によって、人々に「歓び」を届ける', 'sentence_id' => 'm-1', 'source_url' => null, 'quote' => null],
            'lead' => '創業から130年、空間で歓びを届けてきた会社',
            'pillars' => [[
                'title' => '130年つくり続けてきた会社',
                'value' => ['text' => '社員全員がつくり手であること', 'source_url' => self::OWN, 'quote' => '社員全員が つくり手 であることを大切にしています'],
                'achievement' => ['text' => '1892年創業。年間1万件以上のプロジェクト', 'source_url' => self::OWN, 'quote' => '1892年の創業以来、年間1万件以上のプロジェクトを手がけています'],
                'recognition' => ['text' => '例空間デザイン賞2025で「アースマート」が金賞', 'source_url' => self::PRESS, 'quote' => '例空間デザイン賞2025で、例社の「アースマート」が金賞を受賞しました'],
            ]],
        ];
    }

    private function verify(array $draft, ?array $material = null): array
    {
        return app(CompanyProfileVerifier::class)->verify($draft, $material ?? $this->material());
    }

    // ------------------------------------------------------------------ 確認

    public function test_a_draft_whose_quotes_are_on_the_source_pages_passes_with_the_sources(): void
    {
        $result = $this->verify($this->goodDraft());

        $profile = $result['profile'];
        $this->assertSame([], $result['rejected']);
        $this->assertSame(['text' => '空間創造によって、人々に「歓び」を届ける', 'from' => 'message'], $profile['headline']);
        $this->assertSame('創業から130年、空間で歓びを届けてきた会社', $profile['lead']);
        $this->assertSame('130年つくり続けてきた会社', $profile['pillars'][0]['title']);
        $this->assertSame('例空間デザイン賞2025で「アースマート」が金賞', $profile['pillars'][0]['recognition']['text']);
        $this->assertSame(['https://recruit.self.example.com/message/', self::OWN, self::PRESS], array_column($profile['sources'], 'url'));
        $this->assertArrayNotHasKey('quote', $profile['pillars'][0]['value'], '原文の一節はページに出さない');
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function badFacts(): array
    {
        return [
            'quote is not on the page' => ['achievement', ['text' => '1892年創業', 'source_url' => self::OWN, 'quote' => '1892年に創業した老舗です'], 'fact_quote_not_found'],
            'a number is not in the quote' => ['achievement', ['text' => '1893年創業', 'source_url' => self::OWN, 'quote' => '1892年の創業以来、年間1万件以上のプロジェクト'], 'fact_number_not_in_quote'],
            'a name is not in the quote' => ['recognition', ['text' => '「別の賞」で金賞', 'source_url' => self::PRESS, 'quote' => '例空間デザイン賞2025で、例社の「アースマート」が金賞'], 'fact_name_not_in_quote'],
            'the source cannot be fetched' => ['recognition', ['text' => '金賞を受賞', 'source_url' => 'https://press.example.org/missing', 'quote' => '金賞を受賞しました。とても嬉しい'], 'fact_source_unavailable'],
            'the value is not on the own site' => ['value', ['text' => '例空間デザイン賞2025で金賞', 'source_url' => self::PRESS, 'quote' => '例空間デザイン賞2025で、例社の「アースマート」が金賞'], 'fact_not_own_site'],
            'not a web page url' => ['value', ['text' => '社員全員がつくり手', 'source_url' => 'ftp://www.self.example.com/a', 'quote' => '社員全員が つくり手 であること'], 'fact_shape'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fact
     */
    #[DataProvider('badFacts')]
    public function test_a_fact_that_cannot_be_confirmed_is_left_empty_with_the_reason(string $key, array $fact, string $reason): void
    {
        $draft = $this->goodDraft();
        $draft['pillars'][0][$key] = $fact;

        $result = $this->verify($draft);

        $this->assertNull($result['profile']['pillars'][0][$key]);
        $this->assertSame([$reason => 1], $result['rejected']);
    }

    public function test_a_value_on_another_host_of_the_same_company_domain_is_own_site(): void
    {
        $this->pages['https://recruit.self.example.com/about/'] = ['title' => '採用', 'text' => '社員全員が つくり手 であることを大切にしています。'];
        $draft = $this->goodDraft();
        $draft['pillars'][0]['value']['source_url'] = 'https://recruit.self.example.com/about/';

        $this->assertNotNull($this->verify($draft)['profile']['pillars'][0]['value']);
        $this->assertSame('example.co.jp', (new CompanyDomain)->registrable('recruit.example.co.jp'));
        $this->assertSame('example.com', (new CompanyDomain)->registrable('www.example.com'));
    }

    public function test_the_headline_must_be_part_of_a_message_sentence_or_of_a_quote_on_the_own_site(): void
    {
        $draft = $this->goodDraft();
        $draft['headline'] = ['text' => '空間の力で、人々に感動を届ける', 'sentence_id' => 'm-1'];
        $result = $this->verify($draft);
        $this->assertNull($result['profile']['headline']);
        $this->assertSame(1, $result['rejected']['headline_not_in_sentence']);

        $draft['headline'] = ['text' => '社員全員が つくり手 であること', 'sentence_id' => null, 'source_url' => self::OWN, 'quote' => '社員全員が つくり手 であることを大切にしています'];
        $this->assertSame(['text' => '社員全員が つくり手 であること', 'from' => 'site'], $this->verify($draft)['profile']['headline']);
    }

    public function test_numbers_in_the_lead_and_the_title_must_be_in_the_message_or_the_quotes(): void
    {
        $draft = $this->goodDraft();
        $draft['lead'] = '創業から150年の会社';
        $draft['pillars'][0]['title'] = '200年つくり続けてきた会社';

        $result = $this->verify($draft);

        $this->assertNull($result['profile']['lead']);
        $this->assertSame([], $result['profile']['pillars'], '柱の句の数字が確かめられない柱は出さない');
        $this->assertSame(['pillar_title_number' => 1, 'lead_number' => 1], $result['rejected']);
    }

    public function test_a_pillar_without_any_confirmed_fact_is_dropped_and_nothing_confirmed_means_no_page(): void
    {
        $draft = $this->goodDraft();
        $draft['headline'] = null;
        $draft['pillars'][0]['value'] = null;
        $draft['pillars'][0]['achievement'] = null;
        $draft['pillars'][0]['recognition']['quote'] = '原文にない一節を写しました';

        $result = $this->verify($draft);

        $this->assertNull($result['profile']);
        $this->assertSame(1, $result['rejected']['pillar_no_fact']);
    }

    // ------------------------------------------------------------------ AIを呼ぶ口

    public function test_the_request_has_the_web_search_tool_and_no_temperature_and_continues_after_pause_turn(): void
    {
        config(['services.anthropic.api_key' => 'test-key', 'company_profile.web_search_max_uses' => 5]);
        $json = json_encode($this->goodDraft(), JSON_UNESCAPED_UNICODE);
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['content' => [['type' => 'server_tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => ['query' => 'q']]], 'stop_reason' => 'pause_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'server_tool_use' => ['web_search_requests' => 2]]])
            ->push(['content' => [['type' => 'text', 'text' => '調べた結果です。'], ['type' => 'text', 'text' => $json]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 20, 'output_tokens' => 30, 'server_tool_use' => ['web_search_requests' => 1]]]);

        $outcome = (new AnthropicCompanyProfileProvider)->draft($this->material());

        $this->assertSame('130年つくり続けてきた会社', $outcome['decoded']['pillars'][0]['title']);
        $this->assertSame(3, $outcome['web_searches']);
        $this->assertSame(30, $outcome['usage_input_tokens']);
        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        /** @var Request $first */
        $first = $requests[0][0];
        $this->assertSame([['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]], $first['tools']);
        $this->assertArrayNotHasKey('temperature', $first->data());
        $this->assertSame('test-model', $first['model']);
        $this->assertSame('assistant', $requests[1][0]['messages'][1]['role'], '続きは、そこまでの応答を渡して頼む');
    }

    public function test_the_prompt_has_the_rules_and_the_sentences_and_no_example_text(): void
    {
        $prompt = (new AnthropicCompanyProfileProvider)->buildPrompt($this->material());

        $this->assertStringContainsString('m-1: 空間創造によって', $prompt);
        $this->assertStringContainsString('PDF', $prompt);
        $this->assertStringContainsString('quote', $prompt);
        $this->assertStringNotContainsString('例:', $prompt);
        $this->assertStringNotContainsString('例：', $prompt);
    }

    public function test_without_the_model_or_the_key_no_request_is_sent(): void
    {
        Http::fake();
        config(['company_profile.model' => '', 'services.anthropic.api_key' => 'k']);
        try {
            (new AnthropicCompanyProfileProvider)->draft($this->material());
            $this->fail('例外になるはず');
        } catch (ComparisonCommentaryException $e) {
            $this->assertSame('MODEL_NOT_CONFIGURED', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 保存・ジョブ・待ち

    private function fakeProvider(array $draft): object
    {
        $fake = new class($draft) implements CompanyProfileProvider
        {
            public int $calls = 0;

            public function __construct(private readonly array $draft) {}

            public function model(): ?string
            {
                return 'test-model';
            }

            public function draft(array $material): array
            {
                $this->calls++;

                return ['decoded' => $this->draft, 'web_searches' => 2, 'usage_input_tokens' => 1, 'usage_output_tokens' => 1];
            }
        };
        $this->app->instance(CompanyProfileProvider::class, $fake);

        return $fake;
    }

    private function runningComparison(): Analysis
    {
        $self = $this->makeComparisonWebsiteAnalysis(true, 0, 'self');
        $analysis = Analysis::query()->findOrFail($self->analysis_id);
        $analysis->update(['status' => AnalysisStatus::Running]);
        $self->update(['status' => WebsiteAnalysisStatus::Completed]);
        $other = $this->makeComparisonWebsiteAnalysis(false, 1, 'rival', $analysis);
        $other->update(['status' => WebsiteAnalysisStatus::Completed]);

        return $analysis->fresh();
    }

    public function test_the_default_of_enabled_is_false_and_the_model_has_no_default(): void
    {
        $config = require base_path('config/company_profile.php');
        $this->assertFalse($config['enabled']);
        $this->assertSame('', $config['model']);
    }

    public function test_the_results_are_stored_once_per_comparison_and_the_log_has_only_counts(): void
    {
        Log::spy();
        // この比較にはメッセージのページが無いので、メッセージの文に頼らない下書き。
        $draft = $this->goodDraft();
        $draft['headline'] = null;
        $draft['lead'] = null;
        $draft['pillars'][0]['title'] = '長くつくり続けてきた会社';
        $fake = $this->fakeProvider($draft);
        $analysis = $this->runningComparison();

        $result = app(CompanyProfileService::class)->generate($analysis);
        app(CompanyProfileService::class)->generate($analysis);

        $this->assertSame(2, $fake->calls, '1社1回、ファイルがあれば呼び直さない');
        $this->assertCount(2, $result['companies']);
        Storage::disk('analysis')->assertExists("analyses/{$analysis->id}/company_profile.json");
        $self = $analysis->websiteAnalyses()->whereHas('website', fn ($q) => $q->where('is_primary', true))->first();
        $this->assertSame('長くつくり続けてきた会社', app(CompanyProfileStore::class)->profileFor($analysis->id, $self->id)['pillars'][0]['title']);
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            $encoded = (string) json_encode($context, JSON_UNESCAPED_UNICODE);

            return $message === 'Company profile saved' && ! str_contains($encoded, 'つくり') && ! str_contains($encoded, 'self');
        })->once();
    }

    public function test_when_disabled_nothing_is_dispatched_and_the_comparison_is_not_held(): void
    {
        config(['company_profile.enabled' => false]);
        Queue::fake();
        $analysis = $this->runningComparison();

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateCompanyProfileJob::class);
        Queue::assertPushed(FinalizeAnalysisJob::class);
    }

    public function test_when_enabled_the_comparison_waits_for_the_job_and_finishes_after_it_even_if_it_fails(): void
    {
        Queue::fake();
        $analysis = $this->runningComparison();

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        Queue::assertPushed(GenerateCompanyProfileJob::class, 1);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);

        (new GenerateCompanyProfileJob($analysis->id))->failed(new \RuntimeException('x'));

        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
        $this->assertSame('RuntimeException', app(CompanyProfileStore::class)->read($analysis->id)['job_failed']);
        $this->assertFalse(app(CompanyProfileDispatcher::class)->holdFinalization($analysis->fresh()));
    }

    // ------------------------------------------------------------------ スライド

    private function slideXml(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cp');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    public function test_the_slide_has_the_title_the_headline_the_pillars_the_labels_and_the_sources_and_the_question_only_for_the_own_company(): void
    {
        $draft = $this->goodDraft();
        $draft['pillars'][0]['recognition'] = null;
        $profile = $this->verify($draft)['profile'];
        $generator = app(CompanyProfileSlideGenerator::class);

        $self = $this->slideXml($generator->generate(['company_name' => '例社', 'is_self' => true, 'profile' => $profile]));
        foreach (['私たちが理解している、例社という会社', '御社のトップメッセージ', '歓び', '130年つくり続けてきた会社', '大切にしていること', '実績', '外からの評価',
            '1892年創業。年間1万件以上のプロジェクト', (string) config('company_profile.fact_missing'), (string) config('company_profile.question_lead'), '出典：代表メッセージ／会社概要 | 例社'] as $text) {
            $this->assertStringContainsString($text, $self, $text);
        }

        $rival = $this->slideXml($generator->generate(['company_name' => '競合社', 'is_self' => false, 'profile' => $profile]));
        $this->assertStringNotContainsString((string) config('company_profile.question_lead'), $rival);
        $this->assertStringNotContainsString('御社', $rival);
        $this->assertStringContainsString((string) config('company_profile.band_label_competitor'), $rival);
    }

    public function test_the_page_builder_reads_the_stored_results_in_the_order_of_self_then_competitors(): void
    {
        $draft = $this->goodDraft();
        $draft['headline'] = null;
        $draft['lead'] = null;
        $draft['pillars'][0]['title'] = '長くつくり続けてきた会社';
        $this->fakeProvider($draft);
        $analysis = $this->runningComparison();
        app(CompanyProfileService::class)->generate($analysis);
        $viewModel = app(\App\Services\Report\MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $rows = app(\App\Services\CompanyProfile\CompanyProfilePageBuilder::class)->build($analysis->fresh(), $viewModel);

        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['page']['is_self']);
        $this->assertFalse($rows[1]['page']['is_self']);
        $this->assertSame('長くつくり続けてきた会社', $rows[1]['page']['profile']['pillars'][0]['title']);

        config(['company_profile.enabled' => false]);
        $this->assertNull(app(\App\Services\CompanyProfile\CompanyProfilePageBuilder::class)->build($analysis->fresh(), $viewModel)[0]['page'], '無効のときは入れない');
    }
}

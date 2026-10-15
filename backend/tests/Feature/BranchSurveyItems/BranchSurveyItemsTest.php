<?php

namespace Tests\Feature\BranchSurveyItems;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Jobs\FetchCorporateTopJob;
use App\Jobs\GenerateBranchSurveyItemsJob;
use App\Models\AnalysisJob;
use App\Models\LeadCompany;
use App\Models\WebsiteAnalysis;
use App\Services\Admin\LeadCompanyDeletionService;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\BranchSurveyItems\BranchSurveyItemsDispatcher;
use App\Services\BranchSurveyItems\BranchSurveyItemsException;
use App\Services\BranchSurveyItems\BranchSurveyItemsPresenter;
use App\Services\BranchSurveyItems\BranchSurveyItemsProvider;
use App\Services\BranchSurveyItems\BranchSurveyItemsProviderFactory;
use App\Services\BranchSurveyItems\BranchSurveyItemsService;
use App\Services\BranchSurveyItems\BranchSurveyItemsStore;
use App\Services\BranchSurveyItems\BranchSurveyItemsVerifier;
use App\Services\CorporateTop\CorporateTopDispatcher;
use App\Services\CorporateTop\CorporateTopService;
use App\Services\CorporateTop\CorporateTopStore;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CT-2: 階層図の枝ごと・TOPページの、調査14項目の判定(AI)。本文はすべて人工のもの。AIは呼ばず、
 * 入力の本文に応じた固定の出力を返す偽物を使う。
 */
class BranchSurveyItemsTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const BASE = 'https://self.example.com/recruit';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['branch_survey_items.enabled' => true, 'admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 0]);
    }

    /** 採用トップ + 新卒(2ページ)・中途(1ページ)の2つの枝。 */
    private function seedSite(WebsiteAnalysis $wa): void
    {
        $this->addHomepage($wa, self::BASE.'/', $this->htmlPage('採用トップ', "採用トップの本文です。私たちの理念は挑戦です。"), PageType::Recruit);
        $this->addCrawledPage($wa, self::BASE.'/fresh/', '新卒採用 | 例社', $this->htmlPage('新卒採用 | 例社', "新卒採用の本文です。\n入社後は半年間の研修を受けます。\n初任給は30万円です。"));
        $this->addCrawledPage($wa, self::BASE.'/fresh/interview', '社員インタビュー | 例社', $this->htmlPage('社員インタビュー | 例社', "先輩社員の体験談です。\n私は営業として働いています。"));
        $this->addCrawledPage($wa, self::BASE.'/career/', '中途採用 | 例社', $this->htmlPage('中途採用 | 例社', "中途採用の本文です。\n住宅手当を支給します。"));
    }

    /**
     * 入力の本文に含まれる語に応じた出力を返す偽物。
     *
     * @param  array<string, list<array{key: string, evidence: string}>>|\Closure  $byKeyword  語 => 判定した項目
     */
    private function fakeProvider(array|\Closure $byKeyword): object
    {
        $provider = new class($byKeyword) implements BranchSurveyItemsProvider
        {
            public int $calls = 0;

            /** @var list<string> */
            public array $inputs = [];

            public function __construct(public readonly array|\Closure $byKeyword) {}

            public function name(): string
            {
                return 'fake';
            }

            public function model(): ?string
            {
                return 'fake-model';
            }

            public function promptVersion(): string
            {
                return 'v1';
            }

            public function analyze(string $text): array
            {
                $this->calls++;
                $this->inputs[] = $text;
                if ($this->byKeyword instanceof \Closure) {
                    return ['decoded' => ($this->byKeyword)($text), 'usage_input_tokens' => 10, 'usage_output_tokens' => 5];
                }
                $items = [];
                foreach ($this->byKeyword as $word => $found) {
                    if (str_contains($text, $word)) {
                        foreach ($found as $row) {
                            $items[] = ['key' => $row['key'], 'found' => true, 'evidence' => $row['evidence']];
                        }
                    }
                }

                return ['decoded' => ['items' => $items], 'usage_input_tokens' => 10, 'usage_output_tokens' => 5];
            }
        };

        app()->instance(BranchSurveyItemsProviderFactory::class, new class($provider) extends BranchSurveyItemsProviderFactory
        {
            public function __construct(private readonly BranchSurveyItemsProvider $fake) {}

            public function make(): BranchSurveyItemsProvider
            {
                return $this->fake;
            }
        });

        return $provider;
    }

    private function standardOutput(): array
    {
        return [
            '新卒採用の本文' => [
                ['key' => 'training', 'evidence' => '入社後は半年間の研修を受けます。'],
                ['key' => 'salary_evaluation', 'evidence' => '初任給は30万円です。'],
            ],
            '先輩社員の体験談' => [['key' => 'employee_interview', 'evidence' => '私は営業として働いています。']],
            '中途採用の本文' => [['key' => 'benefits', 'evidence' => '住宅手当を支給します。']],
            '私たちの理念は挑戦' => [['key' => 'vision_philosophy', 'evidence' => '私たちの理念は挑戦です。']],
        ];
    }

    private function names(array $keys): array
    {
        return array_map(fn (string $k) => config("brand_wheel_candidate_survey.options.{$k}.name"), $keys);
    }

    // ------------------------------------------------------------------ 判定の流れ

    public function test_each_branch_and_the_top_page_are_judged_once_and_the_result_is_stored(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $fake = $this->fakeProvider($this->standardOutput());

        $result = app(BranchSurveyItemsService::class)->generate($wa);

        $this->assertSame(3, $fake->calls, 'TOP + 枝2つ');
        Storage::disk('analysis')->assertExists("analyses/{$wa->analysis_id}/websites/{$wa->id}/branch_survey_items.json");
        $this->assertSame('ok', $result['top']['status']);
        $this->assertSame(['vision_philosophy'], array_column($result['top']['items'], 'key'));
        $byName = array_column($result['branches'], null, 'name');
        $this->assertCount(2, $result['branches']);
        $fresh = $result['branches'][0];
        $this->assertSame('ok', $fresh['status']);
        // 枝の2ページぶんの本文から判定する(2ページ目は社員の体験談)。調査の割合の高い順
        // (社員インタビュー 19.4% → 給与体系や評価制度 16.4% → 研修制度 7.4%)。
        $this->assertSame(['employee_interview', 'salary_evaluation', 'training'], array_column($fresh['items'], 'key'));
        $this->assertSame(['benefits'], array_column($result['branches'][1]['items'], 'key'));
        $this->assertNotEmpty($byName);
    }

    public function test_an_item_whose_evidence_is_not_in_the_branch_text_is_treated_as_false_and_counted(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $this->fakeProvider([
            '新卒採用の本文' => [
                ['key' => 'training', 'evidence' => '入社後は半年間の研修を受けます。'],
                ['key' => 'career_path', 'evidence' => '3年で管理職に昇進できます。'],   // 本文に無い
                ['key' => 'benefits', 'evidence' => '住宅手当を支給します。'],             // 別の枝の本文
            ],
        ]);

        $result = app(BranchSurveyItemsService::class)->generate($wa);

        $fresh = $result['branches'][0];
        $this->assertSame(['training'], array_column($fresh['items'], 'key'));
        $this->assertSame(2, $fresh['discarded']);
    }

    public function test_the_check_ignores_only_whitespace_and_full_half_width_differences(): void
    {
        $verifier = new BranchSurveyItemsVerifier;
        $text = "初任給は３０万円です。\n研修 制度あり";
        $out = $verifier->verify(['items' => [
            ['key' => 'salary_evaluation', 'found' => true, 'evidence' => '初任給は30万円です。'],   // 全角半角の違いだけ
            ['key' => 'training', 'found' => true, 'evidence' => '研修制度あり'],                       // 空白の違いだけ
            ['key' => 'benefits', 'found' => true, 'evidence' => '研修制度があります'],               // 言い換え
            ['key' => 'culture', 'found' => true, 'evidence' => ''],                                      // 空の根拠
            ['key' => 'job_content', 'found' => false, 'evidence' => '初任給は30万円です。'],       // found=false
            ['key' => 'unknown_key', 'found' => true, 'evidence' => '初任給は30万円です。'],         // 調査に無い項目
        ]], $text);

        $this->assertSame(['salary_evaluation', 'training'], array_column($out['items'], 'key'));
        $this->assertSame(2, $out['discarded']);
    }

    public function test_other_menus_of_the_corporate_top_are_never_judged(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        // コーポレートTOP(保存済み)に、サービス・メンバー・ニュースなどのメニューが並んでいる。
        app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, [
            'status' => 'found', 'url' => 'https://self.example.com/', 'recruit_label' => '採用情報', 'recruit_url' => self::BASE.'/',
        ], '<html><body><nav><a href="/service">サービス</a><a href="/member">メンバー</a><a href="/news">ニュース</a><a href="/recruit/">採用情報</a></nav><main><p>コーポレートTOPの本文です。</p></main></body></html>');
        $fake = $this->fakeProvider($this->standardOutput());

        $result = app(BranchSurveyItemsService::class)->generate($wa);

        $this->assertSame(3, $fake->calls, '他のメニューのぶんは呼ばない(TOP + 枝2つだけ)');
        $this->assertCount(2, $result['branches']);
        foreach ($fake->inputs as $input) {
            $this->assertStringNotContainsString('サービス', $input);
        }
    }

    public function test_the_input_is_limited_and_taken_page_by_page(): void
    {
        config(['branch_survey_items.input_max_chars' => 60]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $fake = $this->fakeProvider(fn () => ['items' => []]);

        app(BranchSurveyItemsService::class)->generate($wa);

        foreach ($fake->inputs as $input) {
            $this->assertLessThanOrEqual(60, mb_strlen($input));
        }
        // 新卒の枝は2ページ。1ページが予算を独占せず、両方のページから段落が入る。
        $fresh = array_values(array_filter($fake->inputs, fn (string $t) => str_contains($t, '新卒採用の本文') || str_contains($t, '先輩社員')));
        $this->assertNotEmpty($fresh);
    }

    public function test_when_the_result_file_exists_the_ai_is_not_called_again(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $fake = $this->fakeProvider($this->standardOutput());

        app(BranchSurveyItemsService::class)->generate($wa);
        app(BranchSurveyItemsService::class)->generate($wa);

        $this->assertSame(3, $fake->calls);
    }

    public function test_a_failing_branch_is_marked_failed_and_the_others_continue(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $this->fakeProvider(function () {
            static $n = 0;
            $n++;
            if ($n === 2) {
                throw new BranchSurveyItemsException('AI_TIMEOUT', 'timeout');
            }

            return ['items' => []];
        });

        $result = app(BranchSurveyItemsService::class)->generate($wa);

        $this->assertSame('ok', $result['top']['status']);
        $this->assertSame('failed', $result['branches'][0]['status']);
        $this->assertSame('AI_TIMEOUT', $result['branches'][0]['reason']);
        $this->assertSame('ok', $result['branches'][1]['status']);
    }

    public function test_an_auth_failure_stops_the_remaining_calls(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $fake = $this->fakeProvider(fn () => throw new BranchSurveyItemsException('AI_AUTH_FAILED', 'auth'));

        $result = app(BranchSurveyItemsService::class)->generate($wa);

        $this->assertSame(1, $fake->calls);
        $this->assertSame(['failed', 'failed'], array_column($result['branches'], 'status'));
    }

    // ------------------------------------------------------------------ 実行の流れ

    public function test_a_failing_job_leaves_a_failure_record_and_the_comparison_still_finishes(): void
    {
        Queue::fake();
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        app()->instance(BranchSurveyItemsService::class, new class extends BranchSurveyItemsService
        {
            public function __construct() {}

            public function generate(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
            {
                throw new \RuntimeException('想定外');
            }

            public function recordFailure(WebsiteAnalysis $websiteAnalysis, string $reason): void
            {
                app(BranchSurveyItemsStore::class)->write((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id, ['status' => 'done', 'top' => null, 'branches' => [], 'job_failed' => $reason]);
            }
        });
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($wa->fresh());
        AnalysisJob::query()->where('website_analysis_id', $wa->id)->update(['status' => AnalysisJobStatus::Completed->value, 'completed_at' => now()]);

        // 待っている間は完了させない。
        app(AnalysisPipeline::class)->maybeFinalizeWebsiteAnalysis($wa->id);
        Queue::assertNotPushed(\App\Jobs\Analysis\FinalizeWebsiteAnalysisJob::class);

        (new GenerateBranchSurveyItemsJob($wa->id))->handle(app(BranchSurveyItemsService::class), app(BranchSurveyItemsDispatcher::class));

        $this->assertTrue(app(BranchSurveyItemsStore::class)->exists($wa->analysis_id, $wa->id));
        Queue::assertPushed(\App\Jobs\Analysis\FinalizeWebsiteAnalysisJob::class);
    }

    public function test_a_failed_job_shows_every_branch_as_not_judgeable_in_the_slide(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        app(BranchSurveyItemsStore::class)->write($wa->analysis_id, $wa->id, ['status' => 'done', 'top' => null, 'branches' => [], 'job_failed' => 'x']);

        $tree = app(BranchSurveyItemsPresenter::class)->apply(
            app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa),
            app(BranchSurveyItemsStore::class)->read($wa->analysis_id, $wa->id),
        );

        $this->assertSame(['failed', 'failed'], array_column($tree['survey']['branches'], 'state'));
        $this->assertStringContainsString((string) config('branch_survey_items.failed_text'), $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree)));
    }

    public function test_the_wait_ends_after_the_configured_minutes_even_if_the_job_never_comes(): void
    {
        Queue::fake();
        config(['branch_survey_items.wait_max_minutes' => 10]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($wa->fresh());
        AnalysisJob::query()->where('website_analysis_id', $wa->id)->update(['status' => AnalysisJobStatus::Completed->value, 'completed_at' => now()]);
        AnalysisJob::query()->where('website_analysis_id', $wa->id)->where('job_type', JobType::GenerateBrandWheelAnalysis)->update(['completed_at' => now()->subMinutes(11)]);

        app(AnalysisPipeline::class)->maybeFinalizeWebsiteAnalysis($wa->id);

        Queue::assertPushed(\App\Jobs\Analysis\FinalizeWebsiteAnalysisJob::class);
    }

    public function test_only_the_primary_site_waits_and_is_judged(): void
    {
        Queue::fake();
        $self = $this->makeComparisonWebsiteAnalysis();
        $competitor = $this->makeComparisonWebsiteAnalysis(false, 1, 'rival', $self->analysis);
        app(BranchSurveyItemsDispatcher::class)->dispatchFor($self->analysis_id, $competitor->id);
        Queue::assertNotPushed(GenerateBranchSurveyItemsJob::class);

        app(BranchSurveyItemsDispatcher::class)->dispatchFor($self->analysis_id, $self->id);
        Queue::assertPushed(GenerateBranchSurveyItemsJob::class, 1);
        Queue::assertPushedOn('ai', GenerateBranchSurveyItemsJob::class);
    }

    public function test_the_job_is_started_after_the_corporate_top_fetch_finishes(): void
    {
        Queue::fake();
        $wa = $this->makeComparisonWebsiteAnalysis();

        // 取得ジョブを出す時点では、判定のジョブはまだ出さない。
        app(CorporateTopDispatcher::class)->dispatchFor($wa->analysis_id, $wa->id);
        Queue::assertPushed(FetchCorporateTopJob::class, 1);
        Queue::assertNotPushed(GenerateBranchSurveyItemsJob::class);

        // 取得が終わった(失敗でも)あとに出す。
        app()->instance(CorporateTopService::class, new class extends CorporateTopService
        {
            public function __construct() {}

            public function run(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
            {
                throw new \RuntimeException('取得に失敗');
            }
        });
        (new FetchCorporateTopJob($wa->id))->handle(app(CorporateTopService::class));
        Queue::assertPushed(GenerateBranchSurveyItemsJob::class, 1);
    }

    public function test_when_the_corporate_top_is_disabled_the_job_is_started_directly(): void
    {
        Queue::fake();
        config(['admin_comparison_pptx.corporate_top_enabled' => false]);
        $wa = $this->makeComparisonWebsiteAnalysis();

        app(CorporateTopDispatcher::class)->dispatchFor($wa->analysis_id, $wa->id);

        Queue::assertNotPushed(FetchCorporateTopJob::class);
        Queue::assertPushed(GenerateBranchSurveyItemsJob::class, 1);
    }

    public function test_when_disabled_nothing_is_dispatched_nothing_is_waited_for_and_no_ai_is_called(): void
    {
        Queue::fake();
        config(['branch_survey_items.enabled' => false, 'admin_comparison_pptx.corporate_top_enabled' => false]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $fake = $this->fakeProvider($this->standardOutput());

        app(CorporateTopDispatcher::class)->dispatchFor($wa->analysis_id, $wa->id);
        Queue::assertNotPushed(GenerateBranchSurveyItemsJob::class);

        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($wa->fresh());
        AnalysisJob::query()->where('website_analysis_id', $wa->id)->update(['status' => AnalysisJobStatus::Completed->value, 'completed_at' => now()]);
        app(AnalysisPipeline::class)->maybeFinalizeWebsiteAnalysis($wa->id);
        Queue::assertPushed(\App\Jobs\Analysis\FinalizeWebsiteAnalysisJob::class);

        $this->assertSame(0, $fake->calls);
    }

    public function test_the_default_of_enabled_is_false(): void
    {
        $config = require base_path('config/branch_survey_items.php');
        $this->assertFalse($config['enabled']);
    }

    public function test_the_result_file_is_deleted_together_with_the_company_data(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $this->fakeProvider($this->standardOutput());
        app(BranchSurveyItemsService::class)->generate($wa);
        $path = "analyses/{$wa->analysis_id}/websites/{$wa->id}/branch_survey_items.json";
        Storage::disk('analysis')->assertExists($path);

        $company = LeadCompany::query()->findOrFail($wa->analysis->project->lead_company_id);
        app(LeadCompanyDeletionService::class)->destroy($company, $company->company_name);

        Storage::disk('analysis')->assertMissing($path);
    }

    public function test_building_the_slide_from_the_stored_file_never_calls_the_ai_or_the_network(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $this->fakeProvider($this->standardOutput());
        app(BranchSurveyItemsService::class)->generate($wa);

        $fake = $this->fakeProvider(fn () => throw new \RuntimeException('呼ばれてはいけない'));
        Http::preventStrayRequests();
        Http::fake();

        $tree = app(BranchSurveyItemsPresenter::class)->apply(
            app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa),
            app(BranchSurveyItemsStore::class)->read($wa->analysis_id, $wa->id),
        );
        app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree);

        $this->assertSame(0, $fake->calls);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 見せ方

    /** @return array<string, mixed> */
    private function treeWith(array $cells, array $names = ['新卒採用', '中途採用', 'インターン', '面白採用']): array
    {
        $branches = [];
        foreach ($cells as $i => $cell) {
            $branches[] = ['name' => $names[$i] ?? "枝{$i}", 'path' => '/recruit/b'.$i, 'page_count' => 1, 'pages' => ['ページ名X'.$i], 'other_page_count' => 0, 'url' => null];
        }
        $stored = [];
        foreach ($branches as $i => $branch) {
            $stored[] = ['key' => $branch['path'], 'name' => $branch['name']] + $cells[$i];
        }

        return app(BranchSurveyItemsPresenter::class)->apply($this->baseTree($branches), ['status' => 'done', 'top' => null, 'branches' => $stored]);
    }

    /** @return array<string, mixed> */
    private function baseTree(array $branches): array
    {
        return [
            'mode' => 'url', 'origin_url' => 'https://self.example.com/recruit/',
            'top' => ['url' => 'https://self.example.com/recruit/', 'title' => null, 'headings' => [], 'menu_item_count' => 0],
            'branches' => $branches, 'other_branch_count' => 0, 'total_fetched_pages' => 5, 'pages_within_origin' => 5, 'outside_origin_count' => 0,
            'second_level_source' => ['links' => 0, 'url' => 0], 'menu_source' => null, 'origin_widened' => false, 'unplaced_page_count' => 0,
            'first_level_labels' => [], 'corporate' => null, 'corporate_missing' => false, 'recruit_path' => '',
        ];
    }

    private function ok(array $keys): array
    {
        return ['status' => 'ok', 'items' => array_map(fn (string $k) => ['key' => $k, 'evidence' => 'x'], $keys), 'discarded' => 0, 'reason' => null];
    }

    private function failedCell(): array
    {
        return ['status' => 'failed', 'items' => [], 'discarded' => 0, 'reason' => 'AI_TIMEOUT'];
    }

    public function test_items_are_shown_by_survey_ratio_up_to_the_limit_and_the_rest_as_other_count(): void
    {
        // job_content 24.6 / career_path 23.8 / employee_interview 19.4 / business_service 17.0 / work_environment 17.0 / salary 16.4
        $tree = $this->treeWith([$this->ok(['salary_evaluation', 'work_environment', 'career_path', 'job_content', 'employee_interview', 'business_service'])]);

        $cell = $tree['survey']['branches'][0];
        $this->assertSame($this->names(['job_content', 'career_path', 'employee_interview', 'business_service']), $cell['items']);
        $this->assertSame(2, $cell['more']);
        $this->assertSame(6, $cell['count']);

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));
        $this->assertStringContainsString('ほか2件', $xml);
        $this->assertStringContainsString('実現できるキャリアパス', $xml);
        $this->assertStringNotContainsString('ページ名X0', $xml, 'ページの名前は出さない');
    }

    public function test_the_limit_of_shown_items_comes_from_the_config(): void
    {
        config(['branch_survey_items.items_per_branch_max' => 2]);
        $tree = $this->treeWith([$this->ok(['job_content', 'career_path', 'employee_interview'])]);

        $this->assertCount(2, $tree['survey']['branches'][0]['items']);
        $this->assertSame(1, $tree['survey']['branches'][0]['more']);
    }

    public function test_branch_states_are_filled_white_or_failed_in_the_slide(): void
    {
        $tree = $this->treeWith([$this->ok(['training']), $this->ok([]), $this->failedCell()]);

        $this->assertSame(['ok', 'ok', 'failed'], array_column($tree['survey']['branches'], 'state'));
        $this->assertSame([1, 0, 0], array_column($tree['survey']['branches'], 'count'));

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));
        $level = strtoupper((string) config('admin_comparison_pptx.site_hierarchy_level_fill.2'));
        $failedFill = strtoupper((string) config('admin_comparison_pptx.branch_survey_failed_fill'));
        // 階層の色(第2階層)は、列の見出しの細い帯にも使う(1か所)。枝の箱は情報がある枝の1つだけ(0件は白)。
        $this->assertSame(2, substr_count(strtoupper($xml), $level), '情報がある枝だけが塗りつぶし(0件は白)');
        $this->assertSame(1, substr_count(strtoupper($xml), $failedFill), '判定できなかった枝は白にせず別の色');
        $this->assertSame(1, substr_count($xml, (string) config('branch_survey_items.failed_text')));
        $this->assertStringContainsString((string) config('branch_survey_items.footnote'), $xml);
    }

    public function test_the_headline_appears_only_when_one_branch_has_at_least_double_the_others(): void
    {
        $tree = $this->treeWith([
            $this->ok(['job_content', 'career_path', 'training', 'benefits']),
            $this->ok(['training', 'benefits']),
            $this->ok(['culture']),
        ]);
        $this->assertSame('新卒採用に情報が集まっており、中途採用・インターンのページで確認できた情報は少なめです。', $tree['survey']['headline']);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));
        $this->assertStringContainsString($tree['survey']['headline'], $xml);

        // 2倍に届かない枝がある(4件に対して3件)。
        $tree = $this->treeWith([$this->ok(['job_content', 'career_path', 'training', 'benefits']), $this->ok(['culture', 'training', 'benefits'])]);
        $this->assertNull($tree['survey']['headline']);

        // 同数。
        $tree = $this->treeWith([$this->ok(['job_content']), $this->ok(['culture'])]);
        $this->assertNull($tree['survey']['headline']);

        // 枝が1つ。
        $tree = $this->treeWith([$this->ok(['job_content', 'career_path'])]);
        $this->assertNull($tree['survey']['headline']);

        // 全部0件。
        $tree = $this->treeWith([$this->ok([]), $this->ok([])]);
        $this->assertNull($tree['survey']['headline']);
    }

    public function test_the_headline_exactly_double_counts_and_groups_more_than_four_other_branches(): void
    {
        $cells = [$this->ok(['job_content', 'career_path']), $this->ok(['culture']), $this->ok(['training']), $this->ok(['benefits']), $this->ok(['company_event']), $this->ok(['employee_interview'])];
        $tree = $this->treeWith($cells, ['A', 'B', 'C', 'D', 'E', 'F']);

        $this->assertSame('Aに情報が集まっており、B・C・D・Eほかのページで確認できた情報は少なめです。', $tree['survey']['headline']);
    }

    public function test_the_headline_is_not_made_when_a_branch_could_not_be_judged(): void
    {
        $tree = $this->treeWith([$this->ok(['job_content', 'career_path', 'training', 'benefits']), $this->failedCell()]);

        $this->assertNull($tree['survey']['headline']);
    }

    public function test_the_headline_text_contains_no_evaluating_or_guessing_words(): void
    {
        $config = require base_path('config/branch_survey_items.php');
        foreach (['懸念', '届いていない', 'ありません', '発信していない', '可能性'] as $word) {
            $this->assertStringNotContainsString($word, $config['skew_template'].$config['footnote'].$config['failed_text']);
        }
    }

    public function test_without_a_stored_result_the_tree_and_the_slide_are_unchanged(): void
    {
        $tree = $this->baseTree([['name' => '新卒採用', 'path' => '/recruit/fresh', 'page_count' => 1, 'pages' => ['ページ名Y'], 'other_page_count' => 0, 'url' => null]]);

        $this->assertSame($tree, app(BranchSurveyItemsPresenter::class)->apply($tree, null));
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));
        $this->assertStringContainsString('ページ名Y', $xml);
        $this->assertStringNotContainsString((string) config('branch_survey_items.footnote'), $xml);
    }

    public function test_the_top_page_items_are_listed_under_the_top_box(): void
    {
        $tree = $this->baseTree([['name' => '新卒採用', 'path' => '/recruit/fresh', 'page_count' => 1, 'pages' => [], 'other_page_count' => 0, 'url' => null]]);
        $tree = app(BranchSurveyItemsPresenter::class)->apply($tree, [
            'status' => 'done',
            'top' => ['label' => 'x'] + $this->ok(['executive_interview', 'vision_philosophy']),
            'branches' => [['key' => '/recruit/fresh', 'name' => '新卒採用'] + $this->ok(['training'])],
        ]);

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));
        $this->assertStringContainsString('代表・経営層のインタビュー', $xml);
        $this->assertStringContainsString('企業のビジョンや理念', $xml);
    }

    private function slideXml(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sx');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    public function test_the_measure_command_never_calls_the_ai_beyond_the_approved_number_of_calls(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedSite($wa);
        $fake = $this->fakeProvider($this->standardOutput());   // この構成の呼び出しは TOP + 枝2つ = 3回

        // 既定(0)は何も呼ばない。
        $this->artisan('branch-survey:measure', ['--ids' => "self:{$wa->id}", '--live' => true])->assertFailed();
        $this->assertSame(0, $fake->calls);

        // 必要な3回に足りない承認では、1回も呼ばずに止まる。
        $this->artisan('branch-survey:measure', ['--ids' => "self:{$wa->id}", '--live' => true, '--max-ai-calls' => 2])->assertFailed();
        $this->assertSame(0, $fake->calls);

        // --repeat は同じ入力でもう1回ずつ呼ぶので、3回の承認では足りない。
        $this->artisan('branch-survey:measure', ['--ids' => "self:{$wa->id}", '--live' => true, '--repeat' => true, '--max-ai-calls' => 3])->assertFailed();
        $this->assertSame(0, $fake->calls);

        // 承認された回数ぶんだけは呼ぶ。
        $this->artisan('branch-survey:measure', ['--ids' => "self:{$wa->id}", '--live' => true, '--max-ai-calls' => 3])->assertSuccessful();
        $this->assertSame(3, $fake->calls);
    }
}

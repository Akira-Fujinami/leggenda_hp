<?php

namespace Tests\Unit\BrandWheel;

use App\Jobs\GenerateBrandWheelImprovementSuggestionJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelImprovementSuggestionDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 改善提案(page6)AIの生成タイミング判定。BrandWheelCompletionNotifierと同じ
 * 「side-effectとしてJobから呼ばれる」パターンだが、判定対象は「Analysisに
 * 紐づく全BrandWheelAnalysisResult(自社・競合)が終端状態に達したか」。
 */
class BrandWheelImprovementSuggestionDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalysisWithWebsites(bool $withCompetitor = true): Analysis
    {
        $project = Project::factory()->create();
        $selfWebsite = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();

        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        if ($withCompetitor) {
            $competitorWebsite = Website::factory()->for($project)->create(['is_primary' => false]);
            WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);
        }

        return $analysis;
    }

    public function test_does_not_dispatch_while_any_side_is_still_pending(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa, $competitorWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        // 依頼AJ-1(2026-08-28)訂正: 旧コメントは「診断実行時のfan-outで
        // status='pending'の行が先に作られている」としていたが誤り ――
        // AnalysisPipeline::dispatchWebsiteFanOut()はBrandWheelAnalysisResult
        // 行を作らない(FetchStaticPageJob等の起動のみ)。実際は各
        // WebsiteAnalysisのRenderPageJob終端(dispatchBrandWheelAnalysisAfterCrawl())
        // で個別に作られるため、ここでは「既に行が作られたうえでまだ
        // pendingの状態」を明示的にfactoryで再現している(行自体が
        // 「まだ作られていない」ケースは下のtest_does_not_dispatch_when_
        // the_competitor_brand_wheel_analysis_result_row_does_not_exist_yetを
        // 参照 ―― 依頼AJで実際に本番analysis_id=109を壊した原因はこちら)。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $competitorWa->id, 'status' => 'pending', 'axes' => null,
        ]);

        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        Queue::assertNothingPushed();
        $this->assertSame(0, BrandWheelImprovementSuggestion::count());
    }

    // ------------------------------------------------------------------
    // 依頼AJ-1(2026-08-28、本番analysis_id=109): 「保留中(非終端)の行が
    // 無いこと」は「必要な行が全部そろっていること」を意味しない ――
    // BrandWheelAnalysisResultは各WebsiteAnalysisの独立したパイプライン
    // (RenderPageJob終端)で個別に作られるため、自社の判定が先に終端に
    // 達し、競合の行がまだ「作られてすらいない」状態がありうる。存在しない
    // 行はwhereNotIn(status,...)->exists()の対象にならず、従来は誤って
    // 「保留中は無い」=生成OKと判定していた。
    // ------------------------------------------------------------------

    /**
     * 本番analysis_id=109で実際に起きた状態の再現: 自社は終端(success)、
     * 競合のBrandWheelAnalysisResult行は「まだ作られてすらいない」
     * (WebsiteAnalysis行自体は存在する ―― 競合サイトの指定・クロール自体は
     * 行われているが、そのRenderPageJobがまだ終端に達していない)。
     */
    public function test_does_not_dispatch_when_the_competitor_brand_wheel_analysis_result_row_does_not_exist_yet(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        // 競合側のBrandWheelAnalysisResultは意図的に作らない(行自体が
        // 存在しない状態を再現する ―― これが本番analysis_id=109の実際の状態)。

        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        Queue::assertNothingPushed();
        $this->assertSame(0, BrandWheelImprovementSuggestion::count());
    }

    /**
     * 競合の行が作られ、終端に達した時点で生成が1回だけ走ること
     * (上のテストで待たされた状態から、実際に競合の行が作られて終端に
     * 達するところまでを1つの流れとして確認する)。
     */
    public function test_dispatches_once_the_competitor_row_is_created_and_reaches_a_terminal_status_after_being_absent(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa, $competitorWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        // 競合の行がまだ無い時点では生成が始まらない。
        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);
        $this->assertSame(0, BrandWheelImprovementSuggestion::count());

        // 競合のRenderPageJobが終端に達し、行が作られる(依頼AJ-1修正後の
        // 正しい呼び出し順序)。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $competitorWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'relationship', 'matched_sub_elements' => [['key' => 'colleagues', 'evidence' => 'y']]]],
        ]);
        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        $this->assertSame(1, BrandWheelImprovementSuggestion::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateBrandWheelImprovementSuggestionJob::class, 1);
    }

    /**
     * 自社・競合が同時に終端に達した場合(=両方の行が既に存在し、両方とも
     * 終端状態)も、生成が1回だけ走ること(冪等性)。
     */
    public function test_dispatches_exactly_once_when_both_sides_reach_terminal_simultaneously(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa, $competitorWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $competitorWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'relationship', 'matched_sub_elements' => [['key' => 'colleagues', 'evidence' => 'y']]]],
        ]);

        $dispatcher = app(BrandWheelImprovementSuggestionDispatcher::class);
        // 自社側・競合側それぞれのcascadeProgress()から、ほぼ同時に
        // 呼ばれる状況を模す。
        $dispatcher->dispatchIfReady($analysis->id);
        $dispatcher->dispatchIfReady($analysis->id);

        $this->assertSame(1, BrandWheelImprovementSuggestion::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateBrandWheelImprovementSuggestionJob::class, 1);
    }

    /**
     * 管理者起点の多社比較(自社1件+競合3〜5件)でも、全件そろうまで
     * 待つこと。
     */
    public function test_waits_for_all_rows_in_a_multi_site_comparison_before_dispatching(): void
    {
        Queue::fake();

        $project = Project::factory()->create();
        $selfWebsite = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();
        $selfWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        $competitorWas = [];
        foreach (range(1, 4) as $i) {
            $competitorWebsite = Website::factory()->for($project)->create(['is_primary' => false]);
            $competitorWas[] = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);
        }

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        // 4件の競合のうち3件だけ行が作られた状態(4件目はまだ作られてすら
        // いない) ―― まだ生成が始まらないこと。
        foreach (array_slice($competitorWas, 0, 3) as $wa) {
            BrandWheelAnalysisResult::factory()->create([
                'analysis_id' => $analysis->id, 'website_analysis_id' => $wa->id, 'status' => 'success',
                'axes' => [['axis_key' => 'relationship', 'matched_sub_elements' => [['key' => 'colleagues', 'evidence' => 'y']]]],
            ]);
        }
        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);
        $this->assertSame(0, BrandWheelImprovementSuggestion::count());

        // 5件目(実際には4件目の競合)の行が作られ、終端に達したら生成される。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $competitorWas[3]->id, 'status' => 'success',
            'axes' => [['axis_key' => 'relationship', 'matched_sub_elements' => [['key' => 'atmosphere', 'evidence' => 'z']]]],
        ]);
        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        $this->assertSame(1, BrandWheelImprovementSuggestion::where('analysis_id', $analysis->id)->count());
    }

    // ------------------------------------------------------------------
    // 依頼AK-1(2026-08-28): brand_wheel_analysis_resultsは同一
    // website_analysis_idに複数行を持ちうる(--force再実行での比較用、
    // 一意制約は意図的に無い)。依頼AJ-1のガードを「行数」で数えると、
    // 自社だけ--forceで2行になった場合に「2行(自社)+0行(競合)=診断の
    // サイト数(2)」が一致してしまい、競合の行が1件も無いままガードを
    // 通過してしまう。distinctなwebsite_analysis_id数で数えることで塞ぐ。
    // ------------------------------------------------------------------

    /**
     * AK-1の再現: 自社がRunBrandWheelAnalysisCommand(--force)で2回目の
     * 判定を受け、brand_wheel_analysis_resultsに2行(いずれもwebsite_
     * analysis_idは自社の1件のみ)持つ状態。競合の行は1件も無い ――
     * 修正前は「2行 >= 診断のサイト数(2)」でガードを通過してしまっていた。
     */
    public function test_does_not_dispatch_when_self_has_two_result_rows_from_a_forced_rerun_but_competitor_has_none(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa] = $analysis->websiteAnalyses()->get();

        // --forceによる1回目の判定(古い行、既に終端)。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x1']]]],
        ]);
        // --forceによる2回目の判定(新しい行、これも終端)。
        // RunBrandWheelAnalysisCommand:82は同じanalysis_id/website_analysis_idで
        // 新しい行を作る(一意制約が無いため)。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x2']]]],
        ]);
        // 競合のBrandWheelAnalysisResultは1件も無い。

        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        Queue::assertNothingPushed();
        $this->assertSame(0, BrandWheelImprovementSuggestion::count());
    }

    /**
     * 自社2行(--force再実行分)・競合1行がすべて終端のとき、従来どおり
     * 1回だけ生成されること。
     */
    public function test_dispatches_once_when_self_has_two_result_rows_and_competitor_has_one_all_terminal(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa, $competitorWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x1']]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x2']]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $competitorWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'relationship', 'matched_sub_elements' => [['key' => 'colleagues', 'evidence' => 'y']]]],
        ]);

        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        $this->assertSame(1, BrandWheelImprovementSuggestion::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateBrandWheelImprovementSuggestionJob::class, 1);
    }

    public function test_dispatches_once_both_sides_reach_a_terminal_status(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa, $competitorWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $competitorWa->id, 'status' => 'error',
        ]);

        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        $this->assertSame(1, BrandWheelImprovementSuggestion::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateBrandWheelImprovementSuggestionJob::class);
    }

    public function test_does_not_dispatch_when_self_is_not_readable(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites(withCompetitor: false);
        $selfWa = $analysis->websiteAnalyses()->first();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'insufficient_input', 'axes' => null,
        ]);

        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        Queue::assertNothingPushed();
        $this->assertSame(0, BrandWheelImprovementSuggestion::count());
    }

    public function test_is_idempotent_when_called_more_than_once(): void
    {
        Queue::fake();
        $analysis = $this->makeAnalysisWithWebsites(withCompetitor: false);
        $selfWa = $analysis->websiteAnalyses()->first();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $dispatcher = app(BrandWheelImprovementSuggestionDispatcher::class);
        $dispatcher->dispatchIfReady($analysis->id);
        $dispatcher->dispatchIfReady($analysis->id);

        $this->assertSame(1, BrandWheelImprovementSuggestion::where('analysis_id', $analysis->id)->count());
        Queue::assertPushed(GenerateBrandWheelImprovementSuggestionJob::class, 1);
    }

    /**
     * 依頼AJ-1(2026-08-28)のエンドツーエンド確認: 競合の行が揃うまで
     * dispatchIfReady()を待たせたうえで、実際にJobを実行(QUEUE_CONNECTION
     * =syncのため同期実行)し、本番analysis_id=109で壊れていた
     * focus_items_reason_sub_namesが、競合ありの診断で空にならないこと
     * (=競合ゼロの状態でJobが走ることがもう無いこと)を確認する。
     */
    public function test_generated_suggestion_has_non_empty_focus_items_reason_sub_names_when_competitor_is_present(): void
    {
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa, $competitorWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'a'], ['key' => 'business_expansion', 'evidence' => 'b'],
                    ['key' => 'project_initiative', 'evidence' => 'c'], ['key' => 'social_contribution', 'evidence' => 'd'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'e'], ['key' => 'competitiveness', 'evidence' => 'f'],
                ]],
            ],
        ]);

        // 依頼AJ-1修正前は、この時点(競合の行がまだ無い)でも
        // dispatchIfReady()が誤って生成を始めていた。
        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);
        $this->assertSame(0, BrandWheelImprovementSuggestion::count());

        // comparison_sufficiency_threshold(既定6)以上にする ―― 閾値未満だと
        // hasSufficientCompetitor=falseとなりimprovementFocus自体がnullに
        // なる(依頼AJ-1とは別の、既存の閾値ガード)。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $competitorWa->id, 'status' => 'success',
            'axes' => [
                ['axis_key' => 'personality', 'matched_sub_elements' => [
                    ['key' => 'leadership', 'evidence' => '経営陣についての競合の抜粋'], ['key' => 'org_structure', 'evidence' => 'g2'],
                    ['key' => 'company_character', 'evidence' => 'g3'], ['key' => 'core_values', 'evidence' => 'g4'],
                ]],
                ['axis_key' => 'relationship', 'matched_sub_elements' => [
                    ['key' => 'colleagues', 'evidence' => 'g5'], ['key' => 'atmosphere', 'evidence' => 'g6'],
                ]],
            ],
        ]);
        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysis->id);

        $suggestion = BrandWheelImprovementSuggestion::where('analysis_id', $analysis->id)->first();
        $this->assertNotNull($suggestion);
        $this->assertSame('success', $suggestion->status);
        $this->assertNotEmpty($suggestion->focus_items_reason_sub_names);
    }

    // ------------------------------------------------------------------
    // 依頼AK-2(2026-08-28): dispatchIfReady()の早期returnは何度でも無言で
    // 起こりうる(正常な待機のため、要件によりログを出さない)。しかし
    // あるサイトのBrandWheelAnalysisResultが最後まで作られなければ、
    // dispatchIfReady()は二度と呼ばれず、改善提案は永久に生成されない
    // まま沈黙する。logIfSuggestionMissingAfterAnalysisCompletion()は
    // Analysis全体が終端に達した時点(FinalizeAnalysisJob、1診断につき
    // 1回)で呼ばれ、この状態を検知してログを1件出す(挙動は変えない)。
    // ------------------------------------------------------------------

    /**
     * 診断完了時点で改善提案が無く、かつBrandWheelAnalysisResultの行が
     * 実際に欠けている場合(依頼AJ/AKが直した「行が作られない」不具合の
     * 症状そのもの)、構造化ログが1件出ること。本文・APIキー・顧客情報
     * (URL・会社名)は含めない。
     */
    public function test_logs_a_warning_when_the_suggestion_is_missing_and_a_brand_wheel_result_row_is_missing(): void
    {
        $analysis = $this->makeAnalysisWithWebsites();
        [$selfWa, $competitorWa] = $analysis->websiteAnalyses()->get();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        // 競合のBrandWheelAnalysisResultは意図的に作らない(欠けている状態)。

        Log::spy();

        app(BrandWheelImprovementSuggestionDispatcher::class)->logIfSuggestionMissingAfterAnalysisCompletion($analysis->id);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($analysis, $competitorWa) {
                $encoded = json_encode($context, JSON_UNESCAPED_UNICODE);

                // 依頼AL-1: 本文自体から「行が欠けている」異常であることが
                // 分かること(正常系のinfoログと同じ本文にしない)。
                return str_contains($message, 'a BrandWheelAnalysisResult row is missing')
                    && $context['analysis_id'] === $analysis->id
                    && $context['website_analysis_count'] === 2
                    && $context['brand_wheel_result_distinct_website_analysis_count'] === 1
                    && $context['missing_website_analysis_ids'] === [$competitorWa->id]
                    // 本文・APIキー・顧客情報(URL・会社名)を含まないこと。
                    && ! str_contains($encoded, 'http')
                    && ! str_contains($encoded, 'test-key');
            })
            ->once();
        Log::shouldNotHaveReceived('info');
    }

    /**
     * 一致チェック(依頼AI-3)と同様、提案が既に存在する場合はログを
     * 出さないこと。
     */
    public function test_does_not_log_when_a_suggestion_already_exists(): void
    {
        $analysis = $this->makeAnalysisWithWebsites(withCompetitor: false);
        $selfWa = $analysis->websiteAnalyses()->first();

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'success']);

        Log::spy();

        app(BrandWheelImprovementSuggestionDispatcher::class)->logIfSuggestionMissingAfterAnalysisCompletion($analysis->id);

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    }

    /**
     * 依頼AL-1(2026-08-28): 全てのBrandWheelAnalysisResultの行がそろって
     * いるにもかかわらず提案が無い場合(例: 自社が読み取れず意図的に
     * 生成しなかった)は、想定済みの正常な結末(依頼X、レポートのSkipped
     * 状態として既にDB・管理画面から見える)であり珍しくないため、
     * warningではなくinfoに落とす ―― 正常系のたびにwarningが鳴ると
     * 無視されるようになり、本当に見たい「行が欠けている」異常側まで
     * 一緒に見過ごされるため(依頼者指摘)。missing_website_analysis_ids
     * は引き続き空になる。
     */
    public function test_logs_at_info_level_when_all_rows_exist_but_no_suggestion_was_generated(): void
    {
        $analysis = $this->makeAnalysisWithWebsites(withCompetitor: false);
        $selfWa = $analysis->websiteAnalyses()->first();

        // 自社が読み取れない(insufficient_input)ため、意図的に生成しない
        // ケース ―― 行自体はそろっている。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'insufficient_input', 'axes' => null,
        ]);

        Log::spy();

        app(BrandWheelImprovementSuggestionDispatcher::class)->logIfSuggestionMissingAfterAnalysisCompletion($analysis->id);

        Log::shouldNotHaveReceived('warning');
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $context['website_analysis_count'] === 1
                    && $context['brand_wheel_result_distinct_website_analysis_count'] === 1
                    && $context['missing_website_analysis_ids'] === [];
            })
            ->once();
    }
}

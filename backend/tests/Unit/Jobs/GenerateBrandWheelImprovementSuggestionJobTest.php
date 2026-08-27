<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateBrandWheelImprovementSuggestionJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelComparisonSufficiency;
use App\Services\BrandWheel\BrandWheelEvidenceLookupBuilder;
use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelImprovementSuggestionInputFactory;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BRAND_WHEEL_AI_PROVIDERは既定でmock(phpunit.xmlのALLOW_MOCK_PROVIDERS=true)
 * のため、ここでは実際のOpenAI呼び出しは行わない ―― Provider解決・入力組み立て・
 * 結果の永続化という配線が正しいことを検証する(プロンプトの実際の判定品質は
 * OpenAiBrandWheelImprovementSuggestionProviderTest/実PDF確認で別途検証する)。
 */
class GenerateBrandWheelImprovementSuggestionJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * config('brand_wheel.axes')の実キーを使い、指定件数分のmatched_sub_elements
     * を機械的に組み立てる(2026-08-25追加 ―― comparison_sufficiency_threshold
     * (既定6)をまたぐフィクスチャを、件数だけ指定して作れるようにするため)。
     *
     * @return list<array{axis_key: string, matched_sub_elements: list<array{key: string, evidence: string}>}>
     */
    private function axesWithMatchedCount(int $count): array
    {
        $subKeysByAxis = [
            'will_activity' => ['purpose', 'business_expansion', 'project_initiative', 'social_contribution'],
            'asset' => ['brand_recognition', 'competitiveness', 'scale_influence', 'office_facility'],
            'personality' => ['leadership', 'org_structure', 'company_character', 'core_values'],
            'relationship' => ['colleagues', 'atmosphere', 'physical_freedom', 'mental_freedom'],
            'emotional_benefit' => ['pride', 'talkable', 'satisfaction', 'superiority'],
            'financial_benefit' => ['salary_level', 'benefits', 'growth_opportunity', 'employment_stability'],
        ];

        $remaining = $count;
        $axes = [];
        foreach ($subKeysByAxis as $axisKey => $subKeys) {
            if ($remaining <= 0) {
                break;
            }
            $take = min(4, $remaining);
            $axes[] = [
                'axis_key' => $axisKey,
                'matched_sub_elements' => array_map(
                    fn (string $subKey) => ['key' => $subKey, 'evidence' => "{$axisKey}-{$subKey}の抜粋"],
                    array_slice($subKeys, 0, $take),
                ),
            ];
            $remaining -= $take;
        }

        return $axes;
    }

    private function makeSuggestion(bool $withCompetitor = true, int $selfMatchedCount = 6, int $competitorMatchedCount = 6): BrandWheelImprovementSuggestion
    {
        $project = Project::factory()->create();
        $selfWebsite = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();
        $selfWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => $this->axesWithMatchedCount($selfMatchedCount),
        ]);

        if ($withCompetitor) {
            $competitorWebsite = Website::factory()->for($project)->create(['is_primary' => false]);
            $competitorWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);
            BrandWheelAnalysisResult::factory()->create([
                'analysis_id' => $analysis->id,
                'website_analysis_id' => $competitorWa->id,
                'status' => 'success',
                'axes' => $this->axesWithMatchedCount($competitorMatchedCount),
            ]);
        }

        return BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending', 'one_point' => null, 'recommendation' => null]);
    }

    private function handle(GenerateBrandWheelImprovementSuggestionJob $job): void
    {
        $job->handle(
            app(BrandWheelLeadResponseComposer::class),
            app(BrandWheelSubElementComparisonComposer::class),
            app(BrandWheelImprovementSuggestionInputFactory::class),
            app(BrandWheelEvidenceLookupBuilder::class),
            app(BrandWheelComparisonSufficiency::class),
            app(BrandWheelImprovementFocusComposer::class),
        );
    }

    public function test_generates_a_suggestion_using_the_mock_provider(): void
    {
        $suggestion = $this->makeSuggestion();

        $this->handle(new GenerateBrandWheelImprovementSuggestionJob($suggestion->id));

        $suggestion->refresh();

        $this->assertSame('success', $suggestion->status);
        $this->assertSame('mock', $suggestion->provider);
        $this->assertTrue($suggestion->is_mock);
        $this->assertNotNull($suggestion->one_point);
        $this->assertNotNull($suggestion->generated_at);
    }

    public function test_marks_as_error_when_self_is_not_readable(): void
    {
        $project = Project::factory()->create();
        $selfWebsite = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();
        $selfWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $selfWa->id, 'status' => 'insufficient_input', 'axes' => null,
        ]);
        $suggestion = BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);

        $this->handle(new GenerateBrandWheelImprovementSuggestionJob($suggestion->id));

        $suggestion->refresh();

        $this->assertSame('error', $suggestion->status);
        $this->assertSame('SELF_NOT_READABLE', $suggestion->error_code);
    }

    public function test_returns_early_when_the_suggestion_record_no_longer_exists(): void
    {
        // find()がnullを返すケース(レコード削除済み等)でも例外を投げない。
        $this->handle(new GenerateBrandWheelImprovementSuggestionJob(999999));

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // 2026-08-25追加: 診断レポートを商談で使える状態にする(修正1・2)。
    // ------------------------------------------------------------------

    /**
     * 修正2: 自社の合計matched件数が閾値未満(既定6)のときはAIを呼ばず、
     * one_pointにconfig('brand_wheel.one_point_messages.insufficient_content')
     * の定型文のみを保存する。他のフィールド(reason/recommended_contents/
     * mid_term_action等)はnull/空配列のまま。
     */
    public function test_skips_the_ai_call_and_uses_the_deterministic_one_point_when_self_is_below_the_sufficiency_threshold(): void
    {
        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 4, competitorMatchedCount: 6);

        $this->handle(new GenerateBrandWheelImprovementSuggestionJob($suggestion->id));

        $suggestion->refresh();

        $this->assertSame('success', $suggestion->status);
        $this->assertNull($suggestion->provider);
        $this->assertFalse($suggestion->is_mock);
        $this->assertSame((string) config('brand_wheel.one_point_messages.insufficient_content'), $suggestion->one_point);
        $this->assertNull($suggestion->recommendation);
        $this->assertNull($suggestion->reason);
        $this->assertSame([], $suggestion->recommended_contents);
        $this->assertNull($suggestion->mid_term_action);
        $this->assertNull($suggestion->focus_items_reason);
        $this->assertSame([], $suggestion->focus_items_reason_sub_names);
    }

    /**
     * 修正1: 競合の合計matched件数が閾値未満のときは、AIへ競合データを
     * 渡さない(hasCompetitor=falseとして扱う)。プロバイダのプロンプトが
     * 「比較サイトのデータはありません」という自社単独モードに切り替わり、
     * 生成結果(input_hash算出に使われるBrandWheelImprovementSuggestionInput)が
     * 競合データを含まないことを確認する。
     */
    public function test_does_not_pass_competitor_data_to_the_ai_when_competitor_is_below_the_sufficiency_threshold(): void
    {
        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 1);

        // 実際に渡されたhasCompetitorの値を検証するため、InputFactoryの
        // 実装をそのまま使いつつ引数だけ横取りする(anonymous subclass)。
        $capturingFactory = new class extends BrandWheelImprovementSuggestionInputFactory
        {
            public ?bool $lastHasCompetitor = null;

            public function build(
                array $comparisonItems,
                array $selfEvidenceByAxisAndSubKey,
                array $competitorEvidenceByAxisAndSubKey,
                array $groupTotals,
                bool $hasCompetitor,
                ?string $selfKeyMessage = null,
                ?string $selfPositiveImpression = null,
                ?string $selfCoreValueEvidence = null,
                array $focusItemsForReason = [],
            ): \App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionInput {
                $this->lastHasCompetitor = $hasCompetitor;

                return parent::build(
                    $comparisonItems, $selfEvidenceByAxisAndSubKey, $competitorEvidenceByAxisAndSubKey,
                    $groupTotals, $hasCompetitor, $selfKeyMessage, $selfPositiveImpression, $selfCoreValueEvidence,
                    $focusItemsForReason,
                );
            }
        };

        $job = new GenerateBrandWheelImprovementSuggestionJob($suggestion->id);
        $job->handle(
            app(BrandWheelLeadResponseComposer::class),
            app(BrandWheelSubElementComparisonComposer::class),
            $capturingFactory,
            app(BrandWheelEvidenceLookupBuilder::class),
            app(BrandWheelComparisonSufficiency::class),
            app(BrandWheelImprovementFocusComposer::class),
        );

        $suggestion->refresh();

        $this->assertSame('success', $suggestion->status);
        // 競合が閾値未満(1件)のため、hasCompetitor=falseとしてAIへ渡される
        // (competitorReadable自体はtrueでも)。BrandWheelImprovementSuggestion
        // InputFactory::build()はhasCompetitor=falseのとき競合関連の配列
        // (competitorMatchedItems/competitorUnmatchedItems/mutuallyUnmatchedItems/
        // groupTotals)を一切含めない(同クラスのbuild()実装で確認済み)ため、
        // ここではAIへ渡された$hasCompetitorの値そのものを検証すれば十分。
        $this->assertFalse($capturingFactory->lastHasCompetitor);
        $this->assertNotNull($suggestion->one_point);
    }

    /**
     * 依頼AF-2(2026-08-27): 改善提案ページに実際に表示されるカードの項目
     * (BrandWheelImprovementFocusComposer::compose()が選ぶ、competitor_matched
     * && !self_matchedの項目)を、AIへ渡す前にこのJob自身が計算し、
     * focus_items_reason_sub_namesとしてDBに保存することを確認する。
     * MockBrandWheelImprovementSuggestionProviderはfocusItemsForReasonが
     * 空でない場合のみfocus_items_reasonを埋めるため、両方が連動して
     * 正しく保存されることも合わせて確認する。
     */
    public function test_persists_the_focus_items_reason_and_matching_sub_names_when_the_focus_composer_selects_items(): void
    {
        $project = Project::factory()->create();
        $selfWebsite = Website::factory()->for($project)->create(['is_primary' => true]);
        $competitorWebsite = Website::factory()->for($project)->create(['is_primary' => false]);
        $analysis = Analysis::factory()->for($project)->create();
        $selfWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);
        $competitorWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);

        // 自社はwill_activity/assetの6項目、競合はpersonality/relationshipの
        // 6項目(自社と重複しない) ―― competitor_matched && !self_matchedの
        // 候補を確実に作る。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
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
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'personality', 'matched_sub_elements' => [
                    ['key' => 'leadership', 'evidence' => '組織構造についての競合の抜粋'], ['key' => 'org_structure', 'evidence' => '組織構造についての競合の抜粋'],
                    ['key' => 'company_character', 'evidence' => 'g'], ['key' => 'core_values', 'evidence' => 'h'],
                ]],
                ['axis_key' => 'relationship', 'matched_sub_elements' => [
                    ['key' => 'colleagues', 'evidence' => 'i'], ['key' => 'atmosphere', 'evidence' => 'j'],
                ]],
            ],
        ]);
        $suggestion = BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);

        $this->handle(new GenerateBrandWheelImprovementSuggestionJob($suggestion->id));

        $suggestion->refresh();

        $this->assertSame('success', $suggestion->status);
        $this->assertNotEmpty($suggestion->focus_items_reason_sub_names);
        $this->assertNotNull($suggestion->focus_items_reason);
        // モックの理由文はfocusItemsForReasonが空でないときのみ埋まる
        // (MockBrandWheelImprovementSuggestionProvider参照)。
        $this->assertStringContainsString('モックプロバイダのため理由はありません', $suggestion->focus_items_reason);
    }

    /**
     * 依頼AH-3(2026-08-28): ①(競合にあり自社に無い項目)が3件に満たない
     * ケースでは、②(競合にも自社にも無い項目)が補われた状態で
     * focus_items_reason_sub_namesが保存されること ―― 生成時点の選定
     * (このJob)と表示時の選定(ReportViewModelBuilder)が同じ
     * BrandWheelImprovementFocusComposer::compose()を呼ぶため、AH-1の
     * 新しい選定結果(①+②)がそのまま一致チェックの対象になることを確認する。
     */
    public function test_persists_breakout_items_in_the_matching_sub_names_when_catch_up_alone_is_insufficient(): void
    {
        $project = Project::factory()->create();
        $selfWebsite = Website::factory()->for($project)->create(['is_primary' => true]);
        $competitorWebsite = Website::factory()->for($project)->create(['is_primary' => false]);
        $analysis = Analysis::factory()->for($project)->create();
        $selfWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);
        $competitorWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);

        // 自社はwill_activity全4件+asset.scale_influence/office_facility
        // (合計6件、閾値ちょうど)。競合はwill_activity全4件(自社と重複、
        // ①にならない)+asset.scale_influence(自社と重複)+personality.
        // leadership(自社に無い、①候補はこれ1件だけ)の合計6件(閾値以上、
        // AIへ競合データが渡る条件を満たす)。asset.brand_recognition/
        // competitiveness・personalityの残り3項目・relationship等は自社・
        // 競合とも未充足のため②候補として豊富に残る。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'a'], ['key' => 'business_expansion', 'evidence' => 'b'],
                    ['key' => 'project_initiative', 'evidence' => 'c'], ['key' => 'social_contribution', 'evidence' => 'd'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'scale_influence', 'evidence' => 'e'], ['key' => 'office_facility', 'evidence' => 'f'],
                ]],
            ],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'g'], ['key' => 'business_expansion', 'evidence' => 'h'],
                    ['key' => 'project_initiative', 'evidence' => 'i'], ['key' => 'social_contribution', 'evidence' => 'j'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'scale_influence', 'evidence' => 'k'],
                ]],
                ['axis_key' => 'personality', 'matched_sub_elements' => [
                    ['key' => 'leadership', 'evidence' => '経営陣についての競合の抜粋'],
                ]],
            ],
        ]);
        $suggestion = BrandWheelImprovementSuggestion::factory()->create(['analysis_id' => $analysis->id, 'status' => 'pending']);

        $this->handle(new GenerateBrandWheelImprovementSuggestionJob($suggestion->id));

        $suggestion->refresh();

        $this->assertSame('success', $suggestion->status);
        // ①(リーダーシップ)1件 + ②(知名度・評判/競争力・独自性)2件で
        // 合計3件になる(compose()のAH-1挙動)。
        $this->assertSame(['リーダーシップ', '知名度・評判', '競争力・独自性'], $suggestion->focus_items_reason_sub_names);
    }

    /**
     * 自社・競合とも閾値以上のときは、従来どおりAIが呼ばれ結果が保存される
     * (回帰防止)。
     */
    public function test_calls_the_ai_normally_when_both_sides_meet_the_sufficiency_threshold(): void
    {
        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 6);

        $this->handle(new GenerateBrandWheelImprovementSuggestionJob($suggestion->id));

        $suggestion->refresh();

        $this->assertSame('success', $suggestion->status);
        $this->assertSame('mock', $suggestion->provider);
        $this->assertTrue($suggestion->is_mock);
        $this->assertNotNull($suggestion->one_point);
    }

    // ------------------------------------------------------------------
    // 依頼U: GenerateBrandWheelAnalysisJobTestと同じ方針
    // (job-level $tries/$backoff/retryUntil/ログ)。この改善提案Jobには
    // website_analysis_idカラムが無いため、ログのそのフィールドは常にnull
    // になる点だけが差分(GenerateBrandWheelAnalysisJob.php docblock参照)。
    // ------------------------------------------------------------------

    public function test_retry_backoff_increases_with_each_attempt_and_repeats_the_last_value(): void
    {
        config([
            'services.brand_wheel_ai.provider' => 'openai',
            'services.openai.api_key' => 'test-key',
            'services.brand_wheel_ai.job_tries' => 5,
            'services.brand_wheel_ai.job_backoff_seconds' => [30, 90, 180],
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429)]);

        foreach ([1 => 30, 2 => 90, 3 => 180, 4 => 180] as $attempt => $expectedDelay) {
            $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 6);

            $job = (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->withFakeQueueInteractions();
            $job->job->attempts = $attempt;
            $this->handle($job);

            $job->assertReleased($expectedDelay);
            $this->assertSame('pending', $suggestion->fresh()->status, "attempt={$attempt}");
        }
    }

    public function test_retry_after_header_takes_priority_over_configured_backoff(): void
    {
        config([
            'services.brand_wheel_ai.provider' => 'openai',
            'services.openai.api_key' => 'test-key',
            'services.brand_wheel_ai.job_tries' => 5,
            'services.brand_wheel_ai.job_backoff_seconds' => [30, 90, 180],
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '45'])]);

        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 6);

        $job = (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->withFakeQueueInteractions();
        $this->handle($job);

        $job->assertReleased(45);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRetryAfterHeaderValuesProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'non_numeric' => ['abc'];
        yield 'negative' => ['-5'];
        yield 'http_date' => ['Wed, 21 Oct 2015 07:28:00 GMT'];
    }

    #[DataProvider('invalidRetryAfterHeaderValuesProvider')]
    public function test_retry_after_header_falls_back_to_backoff_when_not_a_positive_integer(string $headerValue): void
    {
        config([
            'services.brand_wheel_ai.provider' => 'openai',
            'services.openai.api_key' => 'test-key',
            'services.brand_wheel_ai.job_tries' => 5,
            'services.brand_wheel_ai.job_backoff_seconds' => [30, 90, 180],
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => $headerValue])]);

        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 6);

        $job = (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->withFakeQueueInteractions();
        $this->handle($job);

        $job->assertReleased(30);
    }

    public function test_retry_after_header_is_capped_at_the_configured_maximum(): void
    {
        config([
            'services.brand_wheel_ai.provider' => 'openai',
            'services.openai.api_key' => 'test-key',
            'services.brand_wheel_ai.job_tries' => 5,
            'services.brand_wheel_ai.job_retry_after_max_seconds' => 180,
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '100000'])]);

        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 6);

        $job = (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->withFakeQueueInteractions();
        $this->handle($job);

        $job->assertReleased(180);
    }

    public function test_retry_after_cap_is_driven_by_config(): void
    {
        config([
            'services.brand_wheel_ai.provider' => 'openai',
            'services.openai.api_key' => 'test-key',
            'services.brand_wheel_ai.job_tries' => 5,
            'services.brand_wheel_ai.job_retry_after_max_seconds' => 50,
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '100000'])]);

        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 6);

        $job = (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->withFakeQueueInteractions();
        $this->handle($job);

        $job->assertReleased(50);
    }

    public function test_finalizes_as_error_once_tries_are_exhausted_even_for_a_retryable_error(): void
    {
        config([
            'services.brand_wheel_ai.provider' => 'openai',
            'services.openai.api_key' => 'test-key',
            'services.brand_wheel_ai.job_tries' => 2,
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429)]);

        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 6);

        $job = (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $this->handle($job);

        $job->assertNotReleased();
        $this->assertSame('error', $suggestion->fresh()->status);
        $this->assertSame('AI_RATE_LIMITED', $suggestion->fresh()->error_code);
    }

    public function test_tries_and_backoff_are_driven_by_config(): void
    {
        config([
            'services.brand_wheel_ai.job_tries' => 7,
            'services.brand_wheel_ai.job_backoff_seconds' => [10, 20],
        ]);

        $job = new GenerateBrandWheelImprovementSuggestionJob(1);

        $this->assertSame(7, $job->tries);
        $this->assertSame([10, 20], $job->backoff);
    }

    public function test_retry_until_reflects_the_configured_minutes(): void
    {
        config(['services.brand_wheel_ai.job_retry_until_minutes' => 12]);

        $job = new GenerateBrandWheelImprovementSuggestionJob(1);
        $now = now();

        $this->assertEqualsWithDelta(
            $now->clone()->addMinutes(12)->getTimestamp(),
            $job->retryUntil()->getTimestamp(),
            2,
        );
        $this->assertLessThan(
            (int) config('lead.stale_analysis_after_minutes') * 60,
            $job->retryUntil()->getTimestamp() - $now->getTimestamp(),
        );
    }

    public function test_unique_for_covers_the_full_retry_until_window(): void
    {
        config(['services.brand_wheel_ai.job_retry_until_minutes' => 10, 'services.brand_wheel_ai.timeout' => 60]);

        $job = new GenerateBrandWheelImprovementSuggestionJob(1);

        $this->assertGreaterThanOrEqual(10 * 60 + $job->timeout, $job->uniqueFor);
    }

    public function test_unique_for_follows_configured_retry_until_minutes(): void
    {
        config(['services.brand_wheel_ai.job_retry_until_minutes' => 20, 'services.brand_wheel_ai.timeout' => 60]);

        $job = new GenerateBrandWheelImprovementSuggestionJob(1);

        $this->assertSame(20 * 60 + $job->timeout, $job->uniqueFor);
    }

    public function test_unique_for_still_exceeds_timeout_even_with_a_tiny_retry_until_window(): void
    {
        config(['services.brand_wheel_ai.job_retry_until_minutes' => 0, 'services.brand_wheel_ai.timeout' => 60]);

        $job = new GenerateBrandWheelImprovementSuggestionJob(1);

        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
        $this->assertSame($job->timeout * 3, $job->uniqueFor);
    }

    public function test_logs_a_structured_line_when_a_retry_is_scheduled_without_leaking_body_or_api_key(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429)]);

        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 6);

        Log::spy();

        $job = (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->withFakeQueueInteractions();
        $this->handle($job);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($suggestion) {
                $encoded = json_encode($context);

                return $message === 'Brand wheel AI call hit a retryable failure; scheduling a retry'
                    && $context['analysis_id'] === $suggestion->analysis_id
                    && $context['website_analysis_id'] === null
                    && $context['attempt'] === 1
                    && $context['max_tries'] === (int) config('services.brand_wheel_ai.job_tries')
                    && $context['error_code'] === 'AI_RATE_LIMITED'
                    && $context['wait_seconds'] === 30
                    && $context['wait_source'] === 'backoff'
                    && ! str_contains($encoded, 'test-key')
                    && ! str_contains(mb_strtolower($encoded), 'レート制限に達しました');
            })
            ->once();
    }

    public function test_logs_a_distinct_warning_when_retries_are_exhausted(): void
    {
        config([
            'services.brand_wheel_ai.provider' => 'openai',
            'services.openai.api_key' => 'test-key',
            'services.brand_wheel_ai.job_tries' => 2,
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429)]);

        $suggestion = $this->makeSuggestion(withCompetitor: true, selfMatchedCount: 6, competitorMatchedCount: 6);

        Log::spy();

        $job = (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $this->handle($job);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($suggestion) {
                $encoded = json_encode($context);

                return $message === 'Brand wheel AI call failed after exhausting all retries'
                    && $context['analysis_id'] === $suggestion->analysis_id
                    && $context['website_analysis_id'] === null
                    && $context['attempt'] === 2
                    && $context['max_tries'] === 2
                    && $context['error_code'] === 'AI_RATE_LIMITED'
                    && ! str_contains($encoded, 'test-key');
            })
            ->once();
    }

    // ------------------------------------------------------------------
    // 依頼V-2: failed()(retryUntil()の期限切れ等、handle()を経由しない
    // キュー基盤直の失敗経路)でも、固定文字列ではなく例外の種類に応じた
    // error_codeを記録し、試行回数入りのログを出す。
    // ------------------------------------------------------------------

    public function test_failed_classifies_max_attempts_exceeded_instead_of_a_fixed_string(): void
    {
        $suggestion = $this->makeSuggestion();

        $exception = new \Illuminate\Queue\MaxAttemptsExceededException('too many attempts');

        (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->failed($exception);

        $suggestion->refresh();

        $this->assertSame('error', $suggestion->status);
        $this->assertSame(\App\Enums\AnalysisErrorCode::MaxAttemptsExceeded->value, $suggestion->error_code);
        $this->assertSame('too many attempts', $suggestion->error_message);
    }

    public function test_failed_still_records_error_for_an_unclassified_exception(): void
    {
        $suggestion = $this->makeSuggestion();

        (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->failed(new \RuntimeException('boom'));

        $suggestion->refresh();

        $this->assertSame('error', $suggestion->status);
        $this->assertSame(\App\Enums\AnalysisErrorCode::UnknownError->value, $suggestion->error_code);
    }

    public function test_failed_logs_the_attempt_count_without_leaking_body_or_api_key(): void
    {
        $suggestion = $this->makeSuggestion();

        Log::spy();

        $job = (new GenerateBrandWheelImprovementSuggestionJob($suggestion->id))->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->failed(new \Illuminate\Queue\MaxAttemptsExceededException('too many attempts'));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($suggestion, $job) {
                $encoded = json_encode($context);

                return $message === 'Brand wheel AI job failed via the queue-level failed() handler'
                    && $context['analysis_id'] === $suggestion->analysis_id
                    && $context['website_analysis_id'] === null
                    && $context['attempt'] === 3
                    && $context['max_tries'] === $job->tries
                    && $context['error_code'] === \App\Enums\AnalysisErrorCode::MaxAttemptsExceeded->value
                    && ! str_contains($encoded, 'test-key')
                    && ! str_contains($encoded, 'too many attempts');
            })
            ->once();
    }
}

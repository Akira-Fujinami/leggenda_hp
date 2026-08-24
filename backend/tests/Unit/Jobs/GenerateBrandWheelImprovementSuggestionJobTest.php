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
use App\Services\BrandWheel\BrandWheelImprovementSuggestionInputFactory;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ): \App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionInput {
                $this->lastHasCompetitor = $hasCompetitor;

                return parent::build(
                    $comparisonItems, $selfEvidenceByAxisAndSubKey, $competitorEvidenceByAxisAndSubKey,
                    $groupTotals, $hasCompetitor, $selfKeyMessage, $selfPositiveImpression, $selfCoreValueEvidence,
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
}

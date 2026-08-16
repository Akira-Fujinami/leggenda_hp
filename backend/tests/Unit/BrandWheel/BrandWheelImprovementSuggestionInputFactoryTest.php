<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelImprovementSuggestionInputFactory;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use Tests\TestCase;

/**
 * 改善提案AIへ渡す入力DTOの組み立てを検証する。実行難易度タグ
 * (execution_difficulty)は企業ごとにAIへ判定させない静的な事実
 * (config('brand_wheel.axes.*.sub_element_execution_difficulty'))のため、
 * ここでPHP側の組み立てが正しいことを確認しておくことが、改善提案AIの
 * 出力品質(Quick Win判定の土台)を担保する最初の防波堤になる。
 */
class BrandWheelImprovementSuggestionInputFactoryTest extends TestCase
{
    private function factory(): BrandWheelImprovementSuggestionInputFactory
    {
        return new BrandWheelImprovementSuggestionInputFactory;
    }

    private function comparisonComposer(): BrandWheelSubElementComparisonComposer
    {
        return new BrandWheelSubElementComparisonComposer;
    }

    public function test_matched_items_are_split_from_unmatched_items_with_evidence(): void
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_sub_elements' => [
                ['key' => 'purpose', 'name' => 'パーパス'],
            ], 'label_only_sub_elements' => []],
        ];
        $comparisonItems = $this->comparisonComposer()->compose($selfAxes, []);

        $input = $this->factory()->build(
            $comparisonItems,
            selfEvidenceByAxisAndSubKey: ['will_activity' => ['purpose' => 'サイトに実在する記述']],
            competitorEvidenceByAxisAndSubKey: [],
            groupTotals: [],
            hasCompetitor: false,
        );

        $purposeMatched = collect($input->selfMatchedItems)->firstWhere('sub_name', 'パーパス');
        $this->assertNotNull($purposeMatched);
        $this->assertSame('サイトに実在する記述', $purposeMatched['evidence']);

        // 残り23項目は自社未該当として扱われる。
        $this->assertCount(23, $input->selfUnmatchedItems);
        $this->assertFalse($input->hasCompetitor);
        $this->assertSame([], $input->competitorMatchedItems);
    }

    /**
     * 「会社との距離」(relationship軸)は依頼者の例示どおり実行難易度'high'
     * (社員インタビュー等が必要になる可能性)、「仕事内容」等(will_activity軸)は
     * 'low'(既存の社内資料で整理できる可能性)がタグ付けされる。
     */
    public function test_unmatched_items_are_tagged_with_the_configured_execution_difficulty(): void
    {
        $comparisonItems = $this->comparisonComposer()->compose([], []);

        $input = $this->factory()->build($comparisonItems, [], [], [], hasCompetitor: false);

        $colleagues = collect($input->selfUnmatchedItems)->firstWhere('sub_name', '同僚・先輩像');
        $this->assertSame('high', $colleagues['execution_difficulty']);
        $this->assertNotEmpty($colleagues['execution_note']);

        $purpose = collect($input->selfUnmatchedItems)->firstWhere('sub_name', 'パーパス');
        $this->assertSame('low', $purpose['execution_difficulty']);
    }

    public function test_competitor_items_are_empty_when_there_is_no_competitor(): void
    {
        $comparisonItems = $this->comparisonComposer()->compose([], []);

        $input = $this->factory()->build($comparisonItems, [], [], [['group' => 'x']], hasCompetitor: false);

        $this->assertSame([], $input->competitorMatchedItems);
        $this->assertSame([], $input->competitorUnmatchedItems);
        // hasCompetitor=falseのときgroupTotalsも渡さない(呼び出し側の責務だが、
        // Input DTO自体もhasCompetitorに追従してgroupTotalsを保持しないことを
        // 確認する ―― BrandWheelImprovementSuggestionInputFactory::build()は
        // 引数をそのまま保持せず$hasCompetitorで切り替える設計のため)。
        $this->assertSame([], $input->groupTotals);
    }

    public function test_competitor_items_are_populated_when_a_competitor_exists(): void
    {
        $selfAxes = [];
        $competitorAxes = [
            ['key' => 'relationship', 'group' => 'company_distance', 'name' => '就業環境', 'matched_sub_elements' => [
                ['key' => 'colleagues', 'name' => '同僚・先輩像'],
            ], 'label_only_sub_elements' => []],
        ];
        $comparisonItems = $this->comparisonComposer()->compose($selfAxes, $competitorAxes);

        $input = $this->factory()->build(
            $comparisonItems,
            [],
            competitorEvidenceByAxisAndSubKey: ['relationship' => ['colleagues' => '競合サイトの抜粋']],
            groupTotals: [['group' => 'company_distance', 'verdict' => 'competitor_advantage']],
            hasCompetitor: true,
        );

        $colleaguesMatched = collect($input->competitorMatchedItems)->firstWhere('sub_name', '同僚・先輩像');
        $this->assertNotNull($colleaguesMatched);
        $this->assertSame('競合サイトの抜粋', $colleaguesMatched['evidence']);
        $this->assertCount(23, $input->competitorUnmatchedItems);
        $this->assertNotEmpty($input->groupTotals);
    }
}

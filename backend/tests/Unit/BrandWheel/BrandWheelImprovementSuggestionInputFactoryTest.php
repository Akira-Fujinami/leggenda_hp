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

    /**
     * 2026-08-19追加: 「中長期の差別化ポイント」の候補プール
     * (mutuallyUnmatchedItems、自社・競合とも未充足の項目)は、AIに
     * self_unmatched_items×competitor_unmatched_itemsを自分で突き合わせ
     * させるのではなく、PHP側で決定的に事前計算する(依頼者指摘 ――
     * 断定/捏造リスクを下げるため、判定できる事実はPHPで計算する既存方針)。
     */
    public function test_mutually_unmatched_items_are_the_intersection_of_self_and_competitor_unmatched_items(): void
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_sub_elements' => [
                ['key' => 'purpose', 'name' => 'パーパス'],
            ], 'label_only_sub_elements' => []],
        ];
        // 競合はwill_activity軸の中でpurposeのみ該当あり(=自社と同じ項目は
        // 互いに充足)、relationship軸はどちらも完全に未充足のまま。
        $competitorAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_sub_elements' => [
                ['key' => 'purpose', 'name' => 'パーパス'],
            ], 'label_only_sub_elements' => []],
        ];
        $comparisonItems = $this->comparisonComposer()->compose($selfAxes, $competitorAxes);

        $input = $this->factory()->build($comparisonItems, [], [], [], hasCompetitor: true);

        // purpose(自社・競合とも該当)はmutually_unmatched_itemsに含まれない。
        $this->assertNull(collect($input->mutuallyUnmatchedItems)->firstWhere('sub_name', 'パーパス'));

        // colleagues(同僚・先輩像、relationship軸)は自社・競合とも未充足のため
        // mutually_unmatched_itemsに含まれ、実行難易度タグも付与される。
        $colleagues = collect($input->mutuallyUnmatchedItems)->firstWhere('sub_name', '同僚・先輩像');
        $this->assertNotNull($colleagues);
        $this->assertSame('high', $colleagues['execution_difficulty']);

        // 24項目中、両者が該当したpurpose 1件を除いた23件がmutually_unmatched。
        $this->assertCount(23, $input->mutuallyUnmatchedItems);
    }

    public function test_mutually_unmatched_items_are_empty_when_there_is_no_competitor(): void
    {
        $comparisonItems = $this->comparisonComposer()->compose([], []);

        $input = $this->factory()->build($comparisonItems, [], [], [], hasCompetitor: false);

        $this->assertSame([], $input->mutuallyUnmatchedItems);
    }

    /**
     * 2026-08-20追加: 差別化テーマ選定に「自社の既存ブランド文脈」を
     * 考慮させるための自社強みデータ(依頼者指摘 ―― mutually_unmatched_items
     * だけでは「両社とも書いていない」という消極的な理由でしか選べない
     * ため)。selfConfirmedItemNamesは自社matched項目の名前一覧、
     * selfCategoryScoresは軸(6軸)ごとの件数({軸名: 件数}、0件の軸も含む)。
     */
    public function test_self_confirmed_items_and_category_scores_are_aggregated_from_the_comparison(): void
    {
        $selfAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_sub_elements' => [
                ['key' => 'purpose', 'name' => 'パーパス'],
                ['key' => 'business_expansion', 'name' => '展開事業・商品'],
            ], 'label_only_sub_elements' => []],
            ['key' => 'financial_benefit', 'group' => 'job_appeal', 'name' => '金銭的便益', 'matched_sub_elements' => [
                ['key' => 'growth_opportunity', 'name' => '成長機会'],
            ], 'label_only_sub_elements' => []],
        ];
        $comparisonItems = $this->comparisonComposer()->compose($selfAxes, []);

        $input = $this->factory()->build($comparisonItems, [], [], [], hasCompetitor: false);

        $this->assertEqualsCanonicalizing(['パーパス', '展開事業・商品', '成長機会'], $input->selfConfirmedItemNames);
        $this->assertSame(2, $input->selfCategoryScores['活動的魅力']);
        $this->assertSame(1, $input->selfCategoryScores['金銭的便益']);
        // 該当0件の軸も必ずキーとして含まれる(AIが「確認していない」ことを
        // 「渡されていない」と混同しないよう、0を明示する)。
        $this->assertSame(0, $input->selfCategoryScores['資産的魅力']);
        $this->assertSame(0, $input->selfCategoryScores['就業環境']);
    }

    /**
     * key_message/positive_impression/core_value_evidenceは、いずれも
     * BrandWheelLeadResponseComposer/BrandWheelAnalysisResultが既に検証済みの
     * 値をそのまま受け取り、素通しする(このFactoryが新たに何かを生成しない
     * ことの確認)。
     */
    public function test_self_key_message_positive_impression_and_core_value_are_passed_through(): void
    {
        $comparisonItems = $this->comparisonComposer()->compose([], []);

        $input = $this->factory()->build(
            $comparisonItems, [], [], [], hasCompetitor: false,
            selfKeyMessage: '社会のあらゆる「お金の課題」を解決し、より良い世界を創る',
            selfPositiveImpression: '社会的な意義を重視している印象です。',
            selfCoreValueEvidence: 'すべての人の可能性を最大化する',
        );

        $this->assertSame('社会のあらゆる「お金の課題」を解決し、より良い世界を創る', $input->selfKeyMessage);
        $this->assertSame('社会的な意義を重視している印象です。', $input->selfPositiveImpression);
        $this->assertSame('すべての人の可能性を最大化する', $input->selfCoreValueEvidence);
    }

    public function test_self_key_message_is_truncated_for_the_prompt_when_extremely_long(): void
    {
        $comparisonItems = $this->comparisonComposer()->compose([], []);
        $longMessage = str_repeat('あ', 200);

        $input = $this->factory()->build(
            $comparisonItems, [], [], [], hasCompetitor: false,
            selfKeyMessage: $longMessage,
        );

        $this->assertLessThanOrEqual(151, mb_strlen($input->selfKeyMessage));
        $this->assertStringEndsWith('…', $input->selfKeyMessage);
    }

    public function test_self_brand_context_fields_default_to_empty_or_null_when_not_provided(): void
    {
        $comparisonItems = $this->comparisonComposer()->compose([], []);

        $input = $this->factory()->build($comparisonItems, [], [], [], hasCompetitor: false);

        $this->assertNull($input->selfKeyMessage);
        $this->assertNull($input->selfPositiveImpression);
        $this->assertNull($input->selfCoreValueEvidence);
    }

    /**
     * Case E(依頼者指定): 競合との差を埋めるQuick Win(gap_closing、
     * competitorMatchedItemsから選ばれる)と、中長期の差別化ポイント
     * (differentiation_opportunities、mutuallyUnmatchedItemsから選ばれる)が、
     * 同じ下位要素になることは構造的に無い ―― ある項目が
     * competitor_matched(=競合に該当あり)であれば、その時点で
     * mutually_unmatched(=競合も未充足)の定義を満たせないため。
     * AIの選択そのものではなく、この2つの候補プール自体が互いに排他的で
     * あることをPHP側で決定的に保証する。
     */
    public function test_competitor_matched_items_and_mutually_unmatched_items_never_overlap(): void
    {
        $selfAxes = [];
        $competitorAxes = [
            ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'matched_sub_elements' => [
                ['key' => 'purpose', 'name' => 'パーパス'],
                ['key' => 'project_initiative', 'name' => 'PJ・新たな取組'],
            ], 'label_only_sub_elements' => []],
        ];
        $comparisonItems = $this->comparisonComposer()->compose($selfAxes, $competitorAxes);

        $input = $this->factory()->build($comparisonItems, [], [], [], hasCompetitor: true);

        $competitorMatchedNames = collect($input->competitorMatchedItems)->pluck('sub_name')->all();
        $mutuallyUnmatchedNames = collect($input->mutuallyUnmatchedItems)->pluck('sub_name')->all();

        $this->assertNotEmpty($competitorMatchedNames);
        $this->assertNotEmpty($mutuallyUnmatchedNames);
        $this->assertEmpty(array_intersect($competitorMatchedNames, $mutuallyUnmatchedNames));
    }
}

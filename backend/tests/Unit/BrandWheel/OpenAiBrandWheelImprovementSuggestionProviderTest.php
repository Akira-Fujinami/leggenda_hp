<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelImprovementSuggestionResponseParser;
use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionInput;
use App\Services\BrandWheel\OpenAiBrandWheelImprovementSuggestionProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiBrandWheelImprovementSuggestionProviderTest extends TestCase
{
    private function makeInput(bool $hasCompetitor = true): BrandWheelImprovementSuggestionInput
    {
        return new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [['axis_name' => '活動的魅力', 'sub_name' => 'パーパス', 'evidence' => '地域社会への貢献']],
            selfUnmatchedItems: [['axis_name' => '就業環境', 'sub_name' => '同僚・先輩像', 'state' => 'none', 'execution_difficulty' => 'high', 'execution_note' => '社員インタビュー等が必要になる可能性があります。']],
            competitorMatchedItems: $hasCompetitor ? [['axis_name' => '就業環境', 'sub_name' => '同僚・先輩像', 'evidence' => '先輩社員の紹介']] : [],
            competitorUnmatchedItems: [],
            mutuallyUnmatchedItems: [],
            groupTotals: $hasCompetitor ? [['group' => 'company_distance', 'label' => '会社との距離', 'self_count' => 0, 'competitor_count' => 2, 'max_count' => 8, 'verdict' => 'competitor_advantage']] : [],
            hasCompetitor: $hasCompetitor,
        );
    }

    private function fakeSuccessfulResponse(array $body): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($body)]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ], 200),
        ]);
    }

    public function test_model_and_prompt_version_are_available_before_calling_analyze(): void
    {
        config(['services.openai.model' => 'gpt-test-model']);

        $provider = new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser);

        $this->assertSame('gpt-test-model', $provider->model());
        $this->assertSame(OpenAiBrandWheelImprovementSuggestionProvider::PROMPT_VERSION, $provider->promptVersion());
    }

    public function test_uses_strict_json_schema_response_format(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($this->makeInput());

        Http::assertSent(function ($request) {
            $responseFormat = $request->data()['response_format'] ?? [];

            return ($responseFormat['type'] ?? null) === 'json_schema'
                && ($responseFormat['json_schema']['strict'] ?? null) === true;
        });
    }

    /**
     * 依頼者指定の禁止語(config('brand_wheel.forbidden_phrases'))と、
     * 「一般論を禁止する」旨の指示がプロンプトに含まれることを確認する。
     */
    public function test_prompt_includes_the_forbidden_phrases_and_bans_generic_advice(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        config(['brand_wheel.forbidden_phrases' => ['独自禁止語テストX']]);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($this->makeInput());

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '独自禁止語テストX')
                && str_contains($content, '情報が不足しているため追加しましょう。')
                && str_contains($content, 'Quick Win');
        });
    }

    public function test_analyze_returns_the_parsed_result_and_usage(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse([
            'one_point' => 'まずは仕事の魅力から着手しましょう。',
            'recommendation' => 'まずは仕事の魅力に関する情報を拡充することを推奨します。',
            'focus_sub_element_keys' => ['purpose'],
        ]);

        $outcome = (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($this->makeInput());

        $this->assertSame('まずは仕事の魅力から着手しましょう。', $outcome->result->onePoint);
        $this->assertSame(100, $outcome->usageInputTokens);
        $this->assertSame(50, $outcome->usageOutputTokens);
    }

    /**
     * 2026-08-19追加: 実際の生成例で「既存の社内資料を活用することで迅速に
     * 対応可能です」等、サイト分析だけでは分からない社内事情を断定する
     * 表現が残っていた指摘への対応。禁止する断定表現の対象(社内資料の
     * 有無・工数・担当部署・社内調整の難易度・インタビュー要否・応募数への
     * 効果)と、推奨する条件付き表現がプロンプトに明示されていることを確認する。
     */
    public function test_prompt_lists_the_specific_internal_facts_that_must_not_be_asserted(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($this->makeInput());

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '社内資料・素材の有無')
                && str_contains($content, '対応できる担当部署')
                && str_contains($content, '社内調整の難易度')
                && str_contains($content, '社員インタビュー等の取材が必須かどうか')
                && str_contains($content, '応募数・採用数への効果')
                && str_contains($content, '〜できる場合')
                && str_contains($content, '既存の社内資料を活用することで迅速に対応可能です');
        });
    }

    /**
     * 2026-08-19追加: 「中長期の差別化ポイント」(mid_term_action)の候補は
     * AIが自分でself_unmatched_items×competitor_unmatched_itemsを突き合わ
     * せるのではなく、PHP側で事前計算したmutually_unmatched_itemsから選ぶ
     * よう指示されていること、また1テーマ(関連項目は最大2件)にまとめる
     * よう指示されていることを確認する。
     */
    public function test_prompt_instructs_choosing_the_differentiation_theme_from_mutually_unmatched_items(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [],
            selfUnmatchedItems: [],
            competitorMatchedItems: [],
            competitorUnmatchedItems: [],
            mutuallyUnmatchedItems: [
                ['axis_name' => '就業環境', 'sub_name' => '同僚・先輩像', 'execution_difficulty' => 'high'],
                ['axis_name' => '就業環境', 'sub_name' => '職場の雰囲気', 'execution_difficulty' => 'high'],
                ['axis_name' => '経営スタイル', 'sub_name' => 'リーダーシップ', 'execution_difficulty' => 'medium'],
            ],
            groupTotals: [],
            hasCompetitor: true,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'mutually_unmatched_items')
                && str_contains($content, '1テーマ(関連項目は最大2件)')
                && str_contains($content, '同僚・先輩像')
                && str_contains($content, '職場の雰囲気')
                && str_contains($content, 'リーダーシップ');
        });
    }

    // ------------------------------------------------------------------
    // 2026-08-18: 依頼者指定の5シナリオ(A〜E)の検証。実際のAI判断そのものは
    // 外部APIへのライブ呼び出しが必要なため、この開発コンテナのネットワーク
    // 制約(api.openai.comへのTLS到達不可を確認済み)の下では決定的に
    // テストできない。その代わり、AIが正しく判断するための「土台」となる
    // グラウンディング事実(実行難易度・自社/競合の一致状況・グループ優劣)が
    // 各シナリオでプロンプトに正しく渡っていることを確認する ―― これが
    // このアーキテクチャで唯一決定的に検証可能な境界(事実の計算はPHP、
    // 事実の上での判断のみAIに委ねる設計)。AIの実際の判断出力例は、
    // 本番相当のネットワーク環境でtinker等から`analyze()`を呼び出して
    // 別途確認すること。
    // ------------------------------------------------------------------

    /**
     * シナリオA: 自社だけ明確に情報量が少なく、かつ競合にはあり自社には
     * 無い低難度項目が複数ある状態。ギャップ埋め候補の材料(低難度・
     * 競合エビデンスあり)がプロンプトに含まれることを確認する。
     */
    public function test_scenario_a_prompt_includes_low_difficulty_gap_closing_candidates(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [['axis_name' => '活動的魅力', 'sub_name' => 'パーパス', 'evidence' => '理念の記述']],
            selfUnmatchedItems: [
                ['axis_name' => '活動的魅力', 'sub_name' => 'PJ・新たな取組', 'state' => 'none', 'execution_difficulty' => 'low', 'execution_note' => '既存の社内情報を活用できる場合、比較的着手しやすい項目です。'],
                ['axis_name' => '資産的魅力', 'sub_name' => '競争力・独自性', 'state' => 'none', 'execution_difficulty' => 'low', 'execution_note' => '既存の社内情報を活用できる場合、比較的着手しやすい項目です。'],
            ],
            competitorMatchedItems: [
                ['axis_name' => '活動的魅力', 'sub_name' => 'PJ・新たな取組', 'evidence' => '新規事業の立ち上げを紹介'],
                ['axis_name' => '資産的魅力', 'sub_name' => '競争力・独自性', 'evidence' => '独自技術の特許保有に言及'],
            ],
            competitorUnmatchedItems: [],
            mutuallyUnmatchedItems: [],
            groupTotals: [['group' => 'company_appeal', 'label' => '会社の魅力', 'self_count' => 1, 'competitor_count' => 3, 'max_count' => 8, 'verdict' => 'competitor_advantage']],
            hasCompetitor: true,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'PJ・新たな取組')
                && str_contains($content, '新規事業の立ち上げを紹介')
                && str_contains($content, 'low');
        });
    }

    /**
     * シナリオB: 自社・競合とも同じ領域(就業環境等)で情報が薄い状態。
     * 「自社・競合とも手薄」な事実が判別できるよう、双方のunmatched項目が
     * プロンプトに含まれることを確認する(差別化提案の材料)。
     */
    public function test_scenario_b_prompt_includes_items_unmatched_on_both_sides_for_differentiation(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [['axis_name' => '活動的魅力', 'sub_name' => 'パーパス', 'evidence' => '理念の記述']],
            selfUnmatchedItems: [
                ['axis_name' => '就業環境', 'sub_name' => '職場の雰囲気', 'state' => 'none', 'execution_difficulty' => 'high', 'execution_note' => '社員インタビュー等が必要になる可能性があります。'],
            ],
            competitorMatchedItems: [],
            competitorUnmatchedItems: [
                ['axis_name' => '就業環境', 'sub_name' => '職場の雰囲気', 'state' => 'none'],
            ],
            mutuallyUnmatchedItems: [
                ['axis_name' => '就業環境', 'sub_name' => '職場の雰囲気', 'execution_difficulty' => 'high'],
            ],
            groupTotals: [['group' => 'company_distance', 'label' => '会社との距離', 'self_count' => 0, 'competitor_count' => 0, 'max_count' => 8, 'verdict' => 'even']],
            hasCompetitor: true,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '差別化')
                && str_contains($content, 'mutually_unmatched_items')
                && str_contains($content, '職場の雰囲気');
        });
    }

    /**
     * シナリオC: 最大ギャップ領域が高難度(会社との距離)である一方、
     * 低難度のギャップ項目も別にある状態。両方の難易度がプロンプトに
     * 含まれ、AIがQuick Winを判断できる材料が揃っていることを確認する。
     */
    public function test_scenario_c_prompt_includes_both_the_high_difficulty_biggest_gap_and_a_low_difficulty_alternative(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [],
            selfUnmatchedItems: [
                ['axis_name' => '就業環境', 'sub_name' => '同僚・先輩像', 'state' => 'none', 'execution_difficulty' => 'high', 'execution_note' => '社員インタビュー等が必要になる可能性があります。'],
                ['axis_name' => '経営スタイル', 'sub_name' => '組織構造', 'state' => 'none', 'execution_difficulty' => 'low', 'execution_note' => '既存の社内情報を活用できる場合、比較的着手しやすい項目です。'],
            ],
            competitorMatchedItems: [
                ['axis_name' => '就業環境', 'sub_name' => '同僚・先輩像', 'evidence' => '入社3年目社員のインタビュー'],
                ['axis_name' => '経営スタイル', 'sub_name' => '組織構造', 'evidence' => '部門構成図の掲載'],
            ],
            competitorUnmatchedItems: [],
            mutuallyUnmatchedItems: [],
            groupTotals: [
                ['group' => 'company_distance', 'label' => '会社との距離', 'self_count' => 0, 'competitor_count' => 6, 'max_count' => 8, 'verdict' => 'competitor_advantage'],
            ],
            hasCompetitor: true,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '同僚・先輩像') && str_contains($content, 'high')
                && str_contains($content, '組織構造') && str_contains($content, 'low')
                && str_contains($content, 'Quick Win');
        });
    }

    /**
     * シナリオD: 自社が全グループで競合に対して優位な状態。不要な
     * 「追いつき」提案を避けるための判断材料として、自社優位(self_advantage)
     * のverdictがプロンプトに含まれることを確認する。
     */
    public function test_scenario_d_prompt_includes_self_advantage_verdicts_to_avoid_unnecessary_catch_up(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [
                ['axis_name' => '活動的魅力', 'sub_name' => 'パーパス', 'evidence' => '理念の記述'],
                ['axis_name' => '就業環境', 'sub_name' => '同僚・先輩像', 'evidence' => '社員インタビュー多数'],
            ],
            selfUnmatchedItems: [
                ['axis_name' => '資産的魅力', 'sub_name' => 'オフィス・施設', 'state' => 'none', 'execution_difficulty' => 'medium', 'execution_note' => '写真撮影や新規紹介文の作成が必要になる場合があります。'],
            ],
            competitorMatchedItems: [],
            competitorUnmatchedItems: [
                ['axis_name' => '活動的魅力', 'sub_name' => 'パーパス', 'state' => 'none'],
                ['axis_name' => '就業環境', 'sub_name' => '同僚・先輩像', 'state' => 'none'],
            ],
            mutuallyUnmatchedItems: [],
            groupTotals: [
                ['group' => 'company_appeal', 'label' => '会社の魅力', 'self_count' => 5, 'competitor_count' => 1, 'max_count' => 8, 'verdict' => 'self_advantage'],
                ['group' => 'company_distance', 'label' => '会社との距離', 'self_count' => 2, 'competitor_count' => 0, 'max_count' => 8, 'verdict' => 'self_advantage'],
            ],
            hasCompetitor: true,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'self_advantage');
        });
    }

    /**
     * シナリオE: 自社・競合の件数がほぼ同数(even)の状態。差別化または
     * 記述の具体性向上の判断材料として、evenのverdictと、双方とも簡潔な
     * 記述にとどまっているevidenceがプロンプトに含まれることを確認する。
     */
    public function test_scenario_e_prompt_includes_parity_verdicts_and_thin_evidence_on_both_sides(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [
                ['axis_name' => '就業環境', 'sub_name' => '職場の雰囲気', 'evidence' => '「風通しの良い職場です」という一文のみ'],
            ],
            selfUnmatchedItems: [
                ['axis_name' => '経営スタイル', 'sub_name' => '会社の性格', 'state' => 'none', 'execution_difficulty' => 'high', 'execution_note' => '社員インタビュー等が必要になる可能性があります。'],
            ],
            competitorMatchedItems: [
                ['axis_name' => '就業環境', 'sub_name' => '同僚・先輩像', 'evidence' => '「先輩が優しいです」という一文のみ'],
            ],
            competitorUnmatchedItems: [
                ['axis_name' => '経営スタイル', 'sub_name' => '会社の性格', 'state' => 'none'],
            ],
            mutuallyUnmatchedItems: [
                ['axis_name' => '経営スタイル', 'sub_name' => '会社の性格', 'execution_difficulty' => 'high'],
            ],
            groupTotals: [
                ['group' => 'company_distance', 'label' => '会社との距離', 'self_count' => 1, 'competitor_count' => 1, 'max_count' => 8, 'verdict' => 'even'],
            ],
            hasCompetitor: true,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'even')
                && str_contains($content, '風通しの良い職場です')
                && str_contains($content, '先輩が優しいです');
        });
    }
}

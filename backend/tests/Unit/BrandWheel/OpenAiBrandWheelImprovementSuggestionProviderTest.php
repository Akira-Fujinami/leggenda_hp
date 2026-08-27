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
            selfConfirmedItemNames: ['パーパス'],
            selfCategoryScores: ['活動的魅力' => 1, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: '地域社会に貢献するという理念を掲げています。',
            selfPositiveImpression: '地域貢献への姿勢が伝わる印象です。',
            selfCoreValueEvidence: null,
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
        // 開発環境の.envがBRAND_WHEEL_AI_MODEL(2026-08-24追加、既定gpt-4o)を
        // 設定している場合でも、このテストはopenai.modelへのフォールバックを
        // 検証する意図のため、明示的にnullへ戻す(2026-08-24修正)。
        config(['services.brand_wheel_ai.model' => null]);

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

    /**
     * 依頼Z-1(2026-08-27): OpenAiBrandWheelAnalysisProviderのv9と同時対応。
     * このProviderの出力フィールドはすべて生成文(引用フィールドは無い)ため、
     * 出力を日本語に固定する指示が含まれることだけを確認する。
     */
    public function test_prompt_instructs_japanese_output(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($this->makeInput());

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '必ず日本語で書いてください')
                && str_contains($content, 'one_point')
                && str_contains($content, 'reason');
        });
    }

    public function test_prompt_version_is_v9(): void
    {
        $this->assertSame('v9', OpenAiBrandWheelImprovementSuggestionProvider::PROMPT_VERSION);
    }

    /**
     * 依頼Q(2026-08-25、v6): v5は断定禁止の推奨表現として「〜可能性が
     * あります」「〜かもしれません」の2つをほぼ唯一の語尾として提示して
     * おり、実際の生成文で同じ語尾が1つの提言内で3回連続する事例が
     * あった(レポート35)。断定禁止そのものの指示は維持したまま、
     * 語尾のバリエーションを増やし「同じ語尾を繰り返さない」ことを
     * 明示的に指示していることを確認する。
     */
    public function test_prompt_instructs_varying_the_hedging_phrase_instead_of_repeating_it(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($this->makeInput());

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '同じ語尾を1つの提言内で繰り返さない')
                && str_contains($content, '〜と考えられます')
                && str_contains($content, '〜と見込まれます')
                // 断定してよいという意味ではない、という留保も維持されていること。
                && str_contains($content, '断定を避けるという趣旨自体は変わりません');
        });
    }

    /**
     * 依頼Q-2(2026-08-25、v7): 改善提案ページのカード(自社単独モード)を
     * focus_sub_element_keysから直接組み立てるようになったため、
     * self_unmatched_itemsのみを対象とし、既に○の項目のキーを含めない
     * ことを明示的に指示していることを確認する。
     */
    public function test_prompt_restricts_focus_sub_element_keys_to_self_unmatched_items(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($this->makeInput());

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '必ずself_unmatched_items(自社に無い項目)の中から選んでください')
                && str_contains($content, 'self_matched_items')
                && str_contains($content, 'キーは含めないでください');
        });
    }

    /**
     * 依頼S(2026-08-26、v8): v7のJSON Schema例
     * `"mid_term_action": "string または null"` はnullをクォートで囲んだ
     * 記法だったため、モデルが文字列"null"(4文字)を返し、レポートに
     * 「null」がそのまま印字される事故が実物レポート37で発生した。
     * プロンプトが「文字列"null"ではなくJSONのnullそのものを返す」ことを
     * 明示していることを確認する。旧クォート記法(`"string または null"`)は
     * もう含まれないこと。
     */
    public function test_prompt_clarifies_that_mid_term_action_null_must_be_json_null_not_the_string_null(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($this->makeInput());

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '文字列"null"ではなく、JSONのnullそのものを返すこと')
                && ! str_contains($content, '"string または null"');
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
            selfConfirmedItemNames: ['パーパス'],
            selfCategoryScores: ['活動的魅力' => 1, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: '社会のあらゆる課題を解決し、より良い世界を創る。',
            selfPositiveImpression: '社会的意義のある仕事に取り組んでいる印象です。',
            selfCoreValueEvidence: null,
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

    /**
     * 2026-08-20追加: 依頼者指摘「mutually_unmatched_itemsだけから選ぶと、
     * "両社とも書いていない"という消極的な理由だけで選ばれてしまう」への
     * 対応。差別化テーマの判断材料として、自社で確認済みの項目名・軸別
     * スコア・キーメッセージ・ポジティブな印象がプロンプトに含まれ、
     * 「自社の既存ブランドとの接続」を評価するよう明示的に指示されている
     * ことを確認する。
     */
    public function test_prompt_includes_self_brand_context_for_differentiation_theme_selection(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [['axis_name' => '活動的魅力', 'sub_name' => 'パーパス', 'evidence' => '社会のお金の課題を解決する']],
            selfUnmatchedItems: [],
            competitorMatchedItems: [],
            competitorUnmatchedItems: [],
            mutuallyUnmatchedItems: [
                ['axis_name' => '活動的魅力', 'sub_name' => '社会貢献活動', 'execution_difficulty' => 'medium'],
                ['axis_name' => '資産的魅力', 'sub_name' => '知名度・評判', 'execution_difficulty' => 'low'],
            ],
            groupTotals: [],
            hasCompetitor: true,
            selfConfirmedItemNames: ['パーパス', '展開事業・商品', '重視する価値', '福利厚生', '成長機会'],
            selfCategoryScores: ['活動的魅力' => 2, '資産的魅力' => 0, '経営スタイル' => 1, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 2],
            selfKeyMessage: '社会のあらゆる「お金の課題」を解決し、より良い世界を創る',
            selfPositiveImpression: '社会的な意義を重視している印象です。',
            selfCoreValueEvidence: null,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'self_confirmed_items')
                && str_contains($content, 'self_category_scores')
                && str_contains($content, 'self_key_message')
                && str_contains($content, 'self_positive_impression')
                && str_contains($content, '社会のあらゆる「お金の課題」を解決し、より良い世界を創る')
                && str_contains($content, 'Brand Fit')
                && str_contains($content, '自社がすでに持っている');
        });
    }

    // ------------------------------------------------------------------
    // 2026-08-20: 依頼者指定の差別化テーマ選定ロジック改善に伴うテストケース
    // A〜E。実際のAIの最終選択そのものはライブAPI呼び出しが必要なため
    // (このコンテナはapi.openai.comへのTLS到達がブロックされている)、
    // 「AIが正しく選べるための材料(自社の既存ブランド文脈)が各ケースで
    // プロンプトに正しく渡っているか」を検証する。
    // ------------------------------------------------------------------

    /**
     * Case A: 自社の強み(パーパス・展開事業)と、mutually_unmatched_itemsの
     * 「社会貢献活動」が接続しうる状態。self_key_messageと候補の両方が
     * プロンプトに含まれ、AIが接続を評価できる材料が揃っていることを確認する。
     */
    public function test_case_a_prompt_includes_self_purpose_context_alongside_the_socially_connected_candidate(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [
                ['axis_name' => '活動的魅力', 'sub_name' => 'パーパス', 'evidence' => '社会のお金の課題を解決する'],
                ['axis_name' => '活動的魅力', 'sub_name' => '展開事業・商品', 'evidence' => '複数の金融サービスを展開'],
            ],
            selfUnmatchedItems: [],
            competitorMatchedItems: [],
            competitorUnmatchedItems: [],
            mutuallyUnmatchedItems: [
                ['axis_name' => '活動的魅力', 'sub_name' => '社会貢献活動', 'execution_difficulty' => 'medium'],
                ['axis_name' => '就業環境', 'sub_name' => '職場の雰囲気', 'execution_difficulty' => 'high'],
                ['axis_name' => '金銭的便益', 'sub_name' => '給与水準', 'execution_difficulty' => 'low'],
            ],
            groupTotals: [],
            hasCompetitor: true,
            selfConfirmedItemNames: ['パーパス', '展開事業・商品'],
            selfCategoryScores: ['活動的魅力' => 2, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: '社会のあらゆる「お金の課題」を解決し、より良い世界を創る',
            selfPositiveImpression: '社会的な意義を重視している印象です。',
            selfCoreValueEvidence: null,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '社会貢献活動')
                && str_contains($content, '社会のあらゆる「お金の課題」を解決し、より良い世界を創る')
                && str_contains($content, 'パーパス')
                && str_contains($content, 'Brand Fit');
        });
    }

    /**
     * Case B: 自社の強みが金銭的便益文脈(福利厚生・成長機会)、
     * mutually_unmatched_itemsに雇用の安定性(同じ金銭的便益軸)を含む状態。
     * self_category_scoresで金銭的便益が確認できていることと、候補の
     * 雇用の安定性が同じ文脈にあることをAIが評価できる材料が揃っている
     * ことを確認する。
     */
    public function test_case_b_prompt_includes_self_financial_benefit_context_alongside_a_related_candidate(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [
                ['axis_name' => '金銭的便益', 'sub_name' => '福利厚生', 'evidence' => '各種手当を紹介'],
                ['axis_name' => '金銭的便益', 'sub_name' => '成長機会', 'evidence' => '資格取得支援制度'],
            ],
            selfUnmatchedItems: [],
            competitorMatchedItems: [],
            competitorUnmatchedItems: [],
            mutuallyUnmatchedItems: [
                ['axis_name' => '金銭的便益', 'sub_name' => '雇用の安定性', 'execution_difficulty' => 'low'],
                ['axis_name' => '活動的魅力', 'sub_name' => '社会貢献活動', 'execution_difficulty' => 'medium'],
                ['axis_name' => '資産的魅力', 'sub_name' => 'オフィス・施設', 'execution_difficulty' => 'medium'],
            ],
            groupTotals: [],
            hasCompetitor: true,
            selfConfirmedItemNames: ['福利厚生', '成長機会'],
            selfCategoryScores: ['活動的魅力' => 0, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 2],
            selfKeyMessage: null,
            selfPositiveImpression: '成長機会を提供している印象です。',
            selfCoreValueEvidence: null,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '雇用の安定性')
                && str_contains($content, '福利厚生')
                && str_contains($content, '成長機会')
                && str_contains($content, '"金銭的便益":2');
        });
    }

    /**
     * Case C: 自社の強みが「重視する価値」(経営スタイル軸)、
     * mutually_unmatched_itemsに会社の性格・リーダーシップ(同じ経営スタイル軸)を
     * 含む状態。経営スタイル軸との接続を評価できる材料が揃っていることを確認する。
     */
    public function test_case_c_prompt_includes_self_management_style_context_alongside_related_candidates(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [
                ['axis_name' => '経営スタイル', 'sub_name' => '重視する価値', 'evidence' => '誠実さを重んじる文化'],
            ],
            selfUnmatchedItems: [],
            competitorMatchedItems: [],
            competitorUnmatchedItems: [],
            mutuallyUnmatchedItems: [
                ['axis_name' => '経営スタイル', 'sub_name' => '会社の性格', 'execution_difficulty' => 'medium'],
                ['axis_name' => '経営スタイル', 'sub_name' => 'リーダーシップ', 'execution_difficulty' => 'medium'],
                ['axis_name' => '就業環境', 'sub_name' => '職場の雰囲気', 'execution_difficulty' => 'high'],
            ],
            groupTotals: [],
            hasCompetitor: true,
            selfConfirmedItemNames: ['重視する価値'],
            selfCategoryScores: ['活動的魅力' => 0, '資産的魅力' => 0, '経営スタイル' => 1, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: null,
            selfPositiveImpression: null,
            selfCoreValueEvidence: null,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '重視する価値')
                && str_contains($content, '会社の性格')
                && str_contains($content, 'リーダーシップ')
                && str_contains($content, '"経営スタイル":1');
        });
    }

    /**
     * Case D: mutually_unmatched_itemsは存在するが、自社の強みが全く無い
     * (selfConfirmedItemNames/selfCategoryScoresが空)状態。無理にテーマを
     * 選ばずnullを許容する指示がプロンプトに含まれていることを確認する
     * (「とにかく何か1つ出す」仕様にしないこと、依頼者指定)。
     */
    public function test_case_d_prompt_allows_null_mid_term_action_when_self_has_no_confirmed_strengths(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $this->fakeSuccessfulResponse(['one_point' => null, 'recommendation' => null, 'focus_sub_element_keys' => []]);

        $input = new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: [],
            selfUnmatchedItems: [],
            competitorMatchedItems: [],
            competitorUnmatchedItems: [],
            mutuallyUnmatchedItems: [
                ['axis_name' => '活動的魅力', 'sub_name' => '社会貢献活動', 'execution_difficulty' => 'medium'],
                ['axis_name' => '資産的魅力', 'sub_name' => '知名度・評判', 'execution_difficulty' => 'low'],
            ],
            groupTotals: [],
            hasCompetitor: true,
            selfConfirmedItemNames: [],
            selfCategoryScores: ['活動的魅力' => 0, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: null,
            selfPositiveImpression: null,
            selfCoreValueEvidence: null,
        );

        (new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser))->analyze($input);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '無理にテーマを作らず')
                && str_contains($content, 'mid_term_actionをnullにしてください')
                && str_contains($content, '関連性が低い候補しかない')
                && str_contains($content, 'とにかく何か1つ出す');
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
            selfConfirmedItemNames: ['パーパス'],
            selfCategoryScores: ['活動的魅力' => 1, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: '理念を体現する事業を展開しています。',
            selfPositiveImpression: '理念への一貫性が伝わる印象です。',
            selfCoreValueEvidence: null,
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
            selfConfirmedItemNames: ['パーパス'],
            selfCategoryScores: ['活動的魅力' => 1, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: '理念を体現する事業を展開しています。',
            selfPositiveImpression: '理念への一貫性が伝わる印象です。',
            selfCoreValueEvidence: null,
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
            selfConfirmedItemNames: [],
            selfCategoryScores: ['活動的魅力' => 0, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 0, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: null,
            selfPositiveImpression: null,
            selfCoreValueEvidence: null,
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
            selfConfirmedItemNames: ['パーパス', '同僚・先輩像'],
            selfCategoryScores: ['活動的魅力' => 1, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 1, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: '理念を体現する事業を展開しています。',
            selfPositiveImpression: '理念への一貫性が伝わる印象です。',
            selfCoreValueEvidence: null,
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
            selfConfirmedItemNames: ['職場の雰囲気'],
            selfCategoryScores: ['活動的魅力' => 0, '資産的魅力' => 0, '経営スタイル' => 0, '就業環境' => 1, '情緒的便益' => 0, '金銭的便益' => 0],
            selfKeyMessage: null,
            selfPositiveImpression: '風通しの良さが伝わる印象です。',
            selfCoreValueEvidence: null,
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

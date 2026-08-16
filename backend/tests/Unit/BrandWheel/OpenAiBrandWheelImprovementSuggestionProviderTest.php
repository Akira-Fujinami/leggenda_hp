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
}

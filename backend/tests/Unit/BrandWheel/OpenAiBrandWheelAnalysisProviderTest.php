<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelAnalysisResponseParser;
use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use App\Services\BrandWheel\OpenAiBrandWheelAnalysisProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiBrandWheelAnalysisProviderTest extends TestCase
{
    private function makeInput(): BrandWheelAnalysisInput
    {
        return new BrandWheelAnalysisInput(
            websiteAnalysisId: 1,
            recruitPageTitle: null,
            recruitPageBodyText: '採用情報の本文です。',
            recruitPageHeadings: [],
            homepageTitle: null,
            homepageBodyText: '会社概要の本文です。',
            homepageHeadings: [],
            businessLinkLabels: [],
            inputTruncated: false,
            sourcePages: ['recruit_page' => 'read', 'home_page' => 'read'],
        );
    }

    /**
     * config('brand_wheel.axes')の24キー全部をmatched:falseで埋めた
     * sub_elements。guardAgainstIncompleteSchema()(欠落7個以上でAI_
     * INCOMPLETE_SCHEMA)に引っかからないよう、AIモックの応答には常に
     * 24キー全部を含める。
     *
     * @return array<string, array{matched: bool, evidence: null}>
     */
    private function completeSubElements(): array
    {
        $keys = [];
        foreach ((array) config('brand_wheel.axes', []) as $axis) {
            $keys = array_merge($keys, array_keys((array) $axis['sub_elements']));
        }

        return array_fill_keys($keys, ['matched' => false, 'evidence' => null]);
    }

    /**
     * config('brand_wheel.forbidden_phrases')がプロンプトへハードコードでは
     * なくconfig経由で渡され、生成段階から使わせないよう指示されていることを
     * 確認する(2026-07-30の指摘 ―― テストは最後の防波堤、生成段階での
     * 抑制を優先する)。
     */
    public function test_prompt_sent_to_openai_includes_the_configured_forbidden_phrases(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        config(['brand_wheel.forbidden_phrases' => ['独自禁止語テストA', '独自禁止語テストB']]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'sub_elements' => $this->completeSubElements(),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => [],
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        $provider = new OpenAiBrandWheelAnalysisProvider(new BrandWheelAnalysisResponseParser);
        $provider->analyze($this->makeInput());

        Http::assertSent(function ($request) {
            $body = $request->data();
            $content = $body['messages'][0]['content'] ?? '';

            return str_contains($content, '独自禁止語テストA') && str_contains($content, '独自禁止語テストB');
        });
    }

    public function test_model_and_prompt_version_are_available_before_calling_analyze(): void
    {
        config(['services.openai.model' => 'gpt-test-model']);

        $provider = new OpenAiBrandWheelAnalysisProvider(new BrandWheelAnalysisResponseParser);

        $this->assertSame('gpt-test-model', $provider->model());
        $this->assertSame(OpenAiBrandWheelAnalysisProvider::PROMPT_VERSION, $provider->promptVersion());
    }

    /**
     * 2026-08-04: モデル比較測定用のBRAND_WHEEL_AI_MODEL上書き
     * (services.brand_wheel_ai.model)。未設定時は既存のservices.openai.model
     * (共有設定)にフォールバックすることも合わせて確認する。
     */
    public function test_model_can_be_overridden_independently_of_the_shared_openai_model(): void
    {
        config(['services.openai.model' => 'gpt-4o-mini']);
        config(['services.brand_wheel_ai.model' => 'gpt-4o']);

        $provider = new OpenAiBrandWheelAnalysisProvider(new BrandWheelAnalysisResponseParser);

        $this->assertSame('gpt-4o', $provider->model());
    }

    public function test_model_falls_back_to_the_shared_openai_model_when_not_overridden(): void
    {
        config(['services.openai.model' => 'gpt-4o-mini']);
        config(['services.brand_wheel_ai.model' => null]);

        $provider = new OpenAiBrandWheelAnalysisProvider(new BrandWheelAnalysisResponseParser);

        $this->assertSame('gpt-4o-mini', $provider->model());
    }

    /**
     * 判定システムのため、揺らぎを避けるべくtemperatureは既定で最小値(0.0)を
     * 使う。既存のOpenAiAnalysisProvider(スコアリング要約用、0.2固定)とは
     * 異なりconfig駆動にすること自体も、この試験で担保する(2026-07-30の指摘)。
     */
    public function test_defaults_to_the_minimum_temperature_and_is_configurable(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'sub_elements' => $this->completeSubElements(),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => [],
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        (new OpenAiBrandWheelAnalysisProvider(new BrandWheelAnalysisResponseParser))->analyze($this->makeInput());

        Http::assertSent(fn ($request) => ($request->data()['temperature'] ?? null) === 0.0);
    }

    /**
     * 2026-08-05: response_formatをjson_object(緩いJSONモード)から
     * json_schema(strict:true)へ変更した。24個も必須キーがある状態で
     * json_objectのままだと時々キーが欠けるため、OpenAI側にスキーマ準拠を
     * 保証させる。sub_elements.requiredに24キー全部が含まれることを確認する。
     */
    public function test_response_format_uses_strict_json_schema_with_all_24_sub_element_keys_required(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'sub_elements' => $this->completeSubElements(),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => [],
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        (new OpenAiBrandWheelAnalysisProvider(new BrandWheelAnalysisResponseParser))->analyze($this->makeInput());

        $expectedKeys = array_keys($this->completeSubElements());

        Http::assertSent(function ($request) use ($expectedKeys) {
            $responseFormat = $request->data()['response_format'] ?? [];

            if (($responseFormat['type'] ?? null) !== 'json_schema') {
                return false;
            }

            $jsonSchema = $responseFormat['json_schema'] ?? [];

            if (($jsonSchema['strict'] ?? null) !== true) {
                return false;
            }

            $subElementsRequired = $jsonSchema['schema']['properties']['sub_elements']['required'] ?? [];

            return count($subElementsRequired) === 24
                && $expectedKeys === array_values(array_intersect($expectedKeys, $subElementsRequired));
        });
    }

    /**
     * 2026-08-08(v7〜): impressionのJSON Schemaが['type' => ['string','null']]
     * から['type' => 'array', 'items' => ['type' => 'string']]へ変更された
     * (1〜3文の地の文から2〜4件の短いフレーズ列挙への変更に伴う)。
     * impressionはrequiredのまま(nullは許容せず、空配列で表現する)。
     */
    public function test_response_format_schema_defines_impression_as_an_array_of_strings(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'sub_elements' => $this->completeSubElements(),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => [],
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        (new OpenAiBrandWheelAnalysisProvider(new BrandWheelAnalysisResponseParser))->analyze($this->makeInput());

        Http::assertSent(function ($request) {
            $schema = $request->data()['response_format']['json_schema']['schema'] ?? [];
            $impressionSchema = $schema['properties']['impression'] ?? [];
            $required = $schema['required'] ?? [];

            return $impressionSchema === ['type' => 'array', 'items' => ['type' => 'string']]
                && in_array('impression', $required, true);
        });
    }
}

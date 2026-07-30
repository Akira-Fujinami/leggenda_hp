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
                    'axes' => [], 'core_value' => ['readable' => false], 'quality_notes' => [], 'cautions' => [],
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
                    'axes' => [], 'core_value' => ['readable' => false], 'quality_notes' => [], 'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        (new OpenAiBrandWheelAnalysisProvider(new BrandWheelAnalysisResponseParser))->analyze($this->makeInput());

        Http::assertSent(fn ($request) => ($request->data()['temperature'] ?? null) === 0.0);
    }
}

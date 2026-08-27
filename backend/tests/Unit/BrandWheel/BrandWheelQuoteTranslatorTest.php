<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelQuoteTranslator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 依頼AA(2026-08-27): レポート内の引用(evidence/competitor_evidence)に
 * 日本語訳を併記するための、1レポート1回のバッチ翻訳。
 */
class BrandWheelQuoteTranslatorTest extends TestCase
{
    public function test_is_japanese_detects_hiragana_katakana_and_kanji(): void
    {
        $this->assertTrue(BrandWheelQuoteTranslator::isJapanese('だれもが自由に経営できる社会へ。'));
        $this->assertTrue(BrandWheelQuoteTranslator::isJapanese('カタカナのみのテキスト'));
        $this->assertTrue(BrandWheelQuoteTranslator::isJapanese('漢字'));
    }

    public function test_is_japanese_returns_false_for_text_without_japanese_characters(): void
    {
        $this->assertFalse(BrandWheelQuoteTranslator::isJapanese('We contribute to building a better society.'));
        $this->assertFalse(BrandWheelQuoteTranslator::isJapanese('123 ABC !?'));
    }

    /**
     * 依頼AA-2の必須要件: 引用が1件も無い(=翻訳対象が空)場合、AI呼び出しが
     * 一切発生しないこと。
     */
    public function test_translate_makes_no_http_call_when_given_an_empty_list(): void
    {
        Http::fake();

        $result = (new BrandWheelQuoteTranslator)->translate([]);

        $this->assertSame([], $result);
        Http::assertNothingSent();
    }

    /**
     * mockプロバイダ(既定、開発/テスト環境でALLOW_MOCK_PROVIDERS=trueのとき)
     * は、HTTP呼び出しを行わず決定的な訳を返す。
     */
    public function test_translate_uses_the_mock_provider_without_an_http_call(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        Http::fake();

        $result = (new BrandWheelQuoteTranslator)->translate(['Hello world.']);

        $this->assertArrayHasKey('Hello world.', $result);
        $this->assertStringContainsString('Hello world.', $result['Hello world.']);
        Http::assertNothingSent();
    }

    /**
     * 依頼Jのガード(MockProviderGuard)が効くこと ―― ALLOW_MOCK_PROVIDERSが
     * 明示されていない場合、mockへ黙って落ちず、翻訳無し(空配列)として
     * 扱われる。警告ログが出ることも確認する。
     */
    public function test_translate_skips_and_logs_when_mock_is_not_explicitly_allowed(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => false]);
        Log::spy();

        $result = (new BrandWheelQuoteTranslator)->translate(['Hello world.']);

        $this->assertSame([], $result);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'mock provider not allowed'))
            ->once();
    }

    public function test_translate_returns_the_translation_map_via_openai(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'translations' => ['こんにちは世界。', '良い一日を。'],
                ])]]],
            ], 200),
        ]);

        $result = (new BrandWheelQuoteTranslator)->translate(['Hello world.', 'Have a nice day.']);

        $this->assertSame([
            'Hello world.' => 'こんにちは世界。',
            'Have a nice day.' => '良い一日を。',
        ], $result);
    }

    /**
     * 依頼AA-3の必須要件: 引用テキスト以外(会社名・担当者名等のリードの
     * 個人情報)を送らないこと。実際に送られたリクエストボディを検証する。
     */
    public function test_translate_sends_only_the_quote_text_to_openai(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['translations' => ['訳文。']])]]],
            ], 200),
        ]);

        (new BrandWheelQuoteTranslator)->translate(['Some quote text.']);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'Some quote text.')
                && ! str_contains($content, '株式会社')
                && ! str_contains($content, '@');
        });
    }

    /**
     * 依頼AA-3の必須要件(最重要): 翻訳呼び出しが失敗しても例外を投げず、
     * 空配列(=原文のみで表示)を返すこと。ログには引用本文・APIキーを
     * 含めないこと。
     */
    public function test_translate_returns_empty_and_logs_without_leaking_quote_or_api_key_when_the_call_fails(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'secret-api-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'boom'], 500)]);
        Log::spy();

        $result = (new BrandWheelQuoteTranslator)->translate(['This must not leak into logs.']);

        $this->assertSame([], $result);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) {
                $encoded = $message.json_encode($context);

                return str_contains($message, 'translation failed')
                    && ! str_contains($encoded, 'This must not leak into logs.')
                    && ! str_contains($encoded, 'secret-api-key');
            })
            ->once();
    }

    public function test_translate_returns_empty_when_the_response_count_does_not_match(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['translations' => ['訳文が1件だけ。']])]]],
            ], 200),
        ]);

        $result = (new BrandWheelQuoteTranslator)->translate(['Quote one.', 'Quote two.']);

        $this->assertSame([], $result);
    }

    public function test_translate_returns_empty_when_the_response_is_not_valid_json(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'not json']]],
            ], 200),
        ]);

        $result = (new BrandWheelQuoteTranslator)->translate(['Quote one.']);

        $this->assertSame([], $result);
    }
}

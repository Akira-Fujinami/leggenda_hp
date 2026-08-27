<?php

namespace App\Services\BrandWheel;

use App\Support\MockProviderGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 依頼AA(2026-08-27): レポート内の「原文からの引用」
 * (sub_elements.*.evidence・competitor_evidence)のうち、日本語でないものに
 * 日本語訳を併記するための、1レポート1回のバッチ翻訳。
 *
 * 【原文は書き換えない】この訳はあくまで補助表示であり、原文(evidence自体)は
 * 一切変更・削除しない ―― 呼び出し側(ReportViewModelBuilder)は原文と訳を
 * 別々のフィールドに保持し、Blade/WordReportGeneratorは両方を出す。
 *
 * 【1レポート1回】呼び出し側が全引用をまとめて1回のtranslate()呼び出しに
 * する(引用ごとに呼ばない、5件同時実行時の呼び出し数増加を避ける、
 * 依頼者指定)。
 *
 * 【失敗してもレポートを止めない】translate()は例外を投げない。呼び出しが
 * 失敗・タイムアウト・レート制限に当たった場合は空配列を返すだけで、
 * 呼び出し側はこれを「訳無し、原文のみで続行」として扱う(訳が付かないのは
 * 劣化だが、レポートが出ないことは事故である、依頼者指定)。
 *
 * 【mockへの黙示的フォールバックを禁止】既存のBrandWheelAnalysisProviderFactory
 * 等と同じ設定キー(services.brand_wheel_ai.provider)・同じガード
 * (MockProviderGuard、production常時拒否・それ以外もALLOW_MOCK_PROVIDERS
 * の明示が必要)を再利用する。ガードに拒否された場合も、上の「失敗しても
 * レポートを止めない」を優先して例外は投げず翻訳無しで続行するが、警告ログは
 * 必ず残す(無言でmockへ落ちたのではないことを記録に残す)。
 *
 * 【個人情報を送らない】translate()に渡すのは引用テキストの配列のみ
 * (リードの会社名・担当者名等は一切含まれない、呼び出し側の責務)。
 */
class BrandWheelQuoteTranslator
{
    /**
     * ひらがな・カタカナ・漢字のいずれも含まない場合に「日本語でない」と
     * 判定する(依頼者指定の単純な判定でよい、という方針どおり)。
     */
    public static function isJapanese(string $text): bool
    {
        return (bool) preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text);
    }

    /**
     * @param  list<string>  $quotes  翻訳したい引用(重複除去済み、日本語で
     *         ないと判定済みのものだけを渡すこと)。空配列ならAI呼び出しを
     *         行わずに空配列を返す(=日本語のみのレポートでAI呼び出しが
     *         発生しないことは、ここで構造的に保証される)。
     * @return array<string, string>  引用文字列 => 日本語訳。呼び出しが
     *         失敗した場合は空配列(全件、原文のみで表示)。
     */
    public function translate(array $quotes): array
    {
        if ($quotes === []) {
            return [];
        }

        try {
            return $this->translateViaProvider(array_values($quotes));
        } catch (Throwable $e) {
            // 依頼AA: 引用本文・APIキーは一切含めない(件数のみ)。
            Log::warning('Brand wheel quote translation failed; report will show the original text only', [
                'exception_class' => get_class($e),
                'quote_count' => count($quotes),
            ]);

            return [];
        }
    }

    /**
     * @param  list<string>  $quotes
     * @return array<string, string>
     */
    private function translateViaProvider(array $quotes): array
    {
        $provider = (string) config('services.brand_wheel_ai.provider', 'mock');

        if ($provider === 'mock') {
            $rejection = MockProviderGuard::rejectionReason();

            if ($rejection !== null) {
                Log::warning('Brand wheel quote translation skipped: mock provider not allowed here', [
                    'rejection_reason' => $rejection,
                ]);

                return [];
            }

            return $this->mockTranslate($quotes);
        }

        if ($provider === 'openai') {
            return $this->openAiTranslate($quotes);
        }

        Log::warning('Brand wheel quote translation skipped: unknown provider', ['provider' => $provider]);

        return [];
    }

    /**
     * @param  list<string>  $quotes
     * @return array<string, string>
     */
    private function mockTranslate(array $quotes): array
    {
        $map = [];
        foreach ($quotes as $quote) {
            $map[$quote] = "[MOCK訳]{$quote}";
        }

        return $map;
    }

    /**
     * @param  list<string>  $quotes
     * @return array<string, string>
     */
    private function openAiTranslate(array $quotes): array
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured');
        }

        $model = (string) (config('services.brand_wheel_ai.model') ?: config('services.openai.model', 'gpt-4o-mini'));
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('services.brand_wheel_ai.timeout', 60);

        $response = Http::withToken($apiKey)
            ->baseUrl($baseUrl)
            ->timeout($timeout)
            ->post('/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $this->buildPrompt($quotes)],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'quote_translations',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'translations' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['translations'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'max_tokens' => 2000,
                'temperature' => 0.0,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("OpenAI quote translation call failed (HTTP {$response->status()})");
        }

        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;

        if (! is_array($decoded) || ! is_array($decoded['translations'] ?? null)) {
            throw new RuntimeException('OpenAI quote translation response was not valid JSON');
        }

        $translations = array_values($decoded['translations']);

        // 依頼AA: 部分的に信用する(順不同・件数不一致を推測で補う)実装は
        // しない ―― 件数が一致しない場合は全体を失敗として扱い、
        // 呼び出し元のtranslate()が空配列にフォールバックする。
        if (count($translations) !== count($quotes)) {
            throw new RuntimeException('OpenAI quote translation response count did not match the request');
        }

        $map = [];
        foreach ($quotes as $i => $quote) {
            $translated = $translations[$i];

            if (! is_string($translated) || trim($translated) === '') {
                throw new RuntimeException('OpenAI quote translation response contained an empty item');
            }

            $map[$quote] = $translated;
        }

        return $map;
    }

    /**
     * @param  list<string>  $quotes
     */
    private function buildPrompt(array $quotes): string
    {
        $numbered = implode("\n", array_map(
            fn (int $i, string $quote) => ($i + 1).". {$quote}",
            array_keys($quotes),
            $quotes,
        ));

        return <<<PROMPT
以下は採用サイトからの引用です。要約・評価・言い換えは行わず、それぞれを
日本語へ翻訳するだけの作業です。入力と同じ件数・同じ順序の配列で返して
ください(1件につき1つの訳)。すでに日本語の項目があれば、そのまま(意味を
変えずに)返してください。

{$numbered}
PROMPT;
    }
}

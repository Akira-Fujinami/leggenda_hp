<?php

namespace App\Services\StrengthWeaknessSentence;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 依頼CV-2・CW-1: 機械が選んだ「1項目・1社」の根拠の文(全文)から、強み・弱みそれぞれの「引用する一節」を1回の呼び出しで
 * まとめて選ぶAI呼び出し(プロンプト strength_weakness_sentence v3)。文は書かせない(文はサーバーが組み立てる)。
 *
 * 呼び出し先(プロバイダ・モデル・温度)は既存のconfig('services.brand_wheel_ai')を使う(温度は0)。
 * 判定プロンプト(v9)・改善提案プロンプト(v14)・依頼CUのプロンプトとは別の、独立したプロンプト。版は
 * config('strength_weakness_sentence.prompt_version')。プロンプト本文を変えたら上げる。
 *
 * AIがプロンプトを守らなくてもよいように、引用は必ずStrengthWeaknessSentenceVerifierが材料と突き合わせる
 * (プロンプトは確認の代わりではない)。
 */
class OpenAiStrengthWeaknessSentenceProvider implements StrengthWeaknessSentenceProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function model(): ?string
    {
        return (string) (config('services.brand_wheel_ai.model') ?: config('services.openai.model', 'gpt-4o-mini'));
    }

    public function promptVersion(): string
    {
        return (string) config('strength_weakness_sentence.prompt_version', 'v1');
    }

    public function write(array $material): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new ComparisonCommentaryException('OPENAI_NOT_CONFIGURED', 'OPENAI_API_KEYが設定されていません。');
        }

        $response = $this->request($apiKey, $this->model(), $this->buildPrompt($material));

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new ComparisonCommentaryException('AI_INVALID_RESPONSE', 'AIから有効な応答が返されませんでした。');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new ComparisonCommentaryException('AI_INVALID_JSON', 'AIの応答をJSONとして解釈できませんでした。');
        }

        $usage = $response['usage'] ?? [];

        return [
            'decoded' => $decoded,
            'usage_input_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            'usage_output_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $material  StrengthWeaknessSentenceMaterialBuilder::visible()の戻り値
     */
    public function buildPrompt(array $material): string
    {
        $quoteMin = (int) config('strength_weakness_sentence.quote_min_chars', 10);
        $quoteMax = (int) config('strength_weakness_sentence.quote_max_chars', 35);
        $forbidden = implode('」「', (array) config('comparison_commentary.forbidden_words', []));
        $feelings = implode('」「', (array) config('strength_weakness_sentence.quote_feeling_words', []));
        $endings = implode('」「', (array) config('strength_weakness_sentence.quote_bad_endings', []));
        $selfLabel = (string) config('comparison_commentary.material_labels.self', '自社');

        $sections = '';
        if ($material['strength'] !== null) {
            $s = $material['strength'];
            $sections .= <<<SECTION
【強み】(strength_quote を選ぶ)
- 項目: {$s['name']}(領域: {$s['axis']})
- 項目の定義: {$s['definition']}
- {$selfLabel}のサイトの根拠の文(抜き出し):
{$s['evidence']}

SECTION;
        }
        if ($material['weakness'] !== null) {
            $w = $material['weakness'];
            $sections .= <<<SECTION
【弱み】(weakness_quote を選ぶ)
- 項目: {$w['name']}(領域: {$w['axis']})
- 項目の定義: {$w['definition']}
- {$w['company']}のサイトの根拠の文(抜き出し):
{$w['evidence']}

SECTION;
        }

        $schema = json_encode($this->buildResponseSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
あなたは、企業の採用サイトの文章から、比較資料に載せる「引用する一節」を選ぶアシスタントです。
下の「材料」の根拠の文から、強み・弱みそれぞれについて、引用する一節(quote)を1つだけ選んでください。文は書かないでください(文はこちらで組み立てます)。材料が無い側は null にしてください。

{$sections}
選び方:
- その項目(定義を示してある)に当てはまる、制度・取り組み・数字・事実を表す部分を選ぶ。
- 選ばないもの:
  - 会社名だけの部分
  - 個人の感想・心情を表す部分(「{$feelings}」を含む部分)
  - 文の途中で切れる部分(「{$endings}」で終わる部分)
- 根拠の文から一字一句そのまま抜き出す(言い換え・要約・つなぎ合わせ・字句の変更をしない)。長さは{$quoteMin}〜{$quoteMax}字。かぎ括弧(「」『』)を含めない。
- 次の言葉を含まない部分を選ぶ: 「{$forbidden}」。

引用の良い例・悪い例(形だけの例。材料とは無関係):
- 良い例: 「入社3年目までに全員が海外研修に参加できる」「月間の平均残業時間は15時間」
- 悪い例: 「A社では」(会社名だけ)、「新しい制度を使ってみたいと感じた」(個人の感想)、「新しい制度を導入するよう」(途中で切れている)

出力は、次のJSON Schemaに従うJSONオブジェクトだけにしてください(説明文や前後のテキストは不要):
{$schema}

--- ここから材料 ---
{$sections}--- ここまで材料 ---
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'strength_quote' => ['type' => ['string', 'null']],
                'weakness_quote' => ['type' => ['string', 'null']],
            ],
            'required' => ['strength_quote', 'weakness_quote'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $apiKey, string $model, string $prompt): array
    {
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('strength_weakness_sentence.timeout', 90);
        $maxRetries = (int) config('services.brand_wheel_ai.max_retries', 1);
        $maxOutputTokens = (int) config('strength_weakness_sentence.max_output_tokens', 800);
        $temperature = (float) config('services.brand_wheel_ai.temperature', 0.0);

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $response = Http::withToken($apiKey)
                    ->baseUrl($baseUrl)
                    ->timeout($timeout)
                    ->post('/chat/completions', [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'response_format' => [
                            'type' => 'json_schema',
                            'json_schema' => [
                                'name' => 'strength_weakness_sentence',
                                'strict' => true,
                                'schema' => $this->buildResponseSchema(),
                            ],
                        ],
                        'max_tokens' => $maxOutputTokens,
                        'temperature' => $temperature,
                    ]);
            } catch (ConnectionException $e) {
                $lastException = $e;

                continue;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new ComparisonCommentaryException('AI_AUTH_FAILED', 'OpenAI APIの認証に失敗しました。');
            }

            if ($response->status() === 429) {
                throw new ComparisonCommentaryException('AI_RATE_LIMITED', 'OpenAI APIのレート制限に達しました。');
            }

            if ($response->status() === 408 || $response->status() === 504) {
                if ($attempt <= $maxRetries) {
                    continue;
                }

                throw new ComparisonCommentaryException('AI_TIMEOUT', 'OpenAI APIの呼び出しがタイムアウトしました。');
            }

            if (! $response->successful()) {
                throw new ComparisonCommentaryException('AI_REQUEST_FAILED', 'OpenAI APIの呼び出しに失敗しました(HTTP '.$response->status().')。');
            }

            return $response->json() ?? [];
        }

        throw new ComparisonCommentaryException('AI_UNAVAILABLE', 'OpenAI APIに接続できませんでした。', $lastException);
    }
}

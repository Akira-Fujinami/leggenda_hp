<?php

namespace App\Services\TopMessagePanel;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 依頼CZ-2: メッセージの文(IDつき)と制度の候補(ID・名前・抜粋)から、核の一文・本文の文・キーワード・キーワードごとの制度を
 * 「選ぶ」AI呼び出し(プロンプト top_message_panel v1、1社1回)。文章は書かせない。プロンプトに文の例は書かない(選び方の観点だけ)。
 *
 * 呼び出し先(プロバイダ・モデル・温度)は既存のconfig('services.brand_wheel_ai')を使う(温度は0)。
 * 判定プロンプト(v9)・改善提案プロンプト(v14)・他の依頼のプロンプトとは別の、独立したプロンプト。版は
 * config('top_message_panel.prompt_version')。プロンプト本文を変えたら上げる。
 *
 * AIがプロンプトを守らなくてもよいように、選んだものは必ずTopMessagePanelVerifierが材料と突き合わせる
 * (プロンプトは確認の代わりではない)。
 */
class OpenAiTopMessagePanelProvider implements TopMessagePanelProvider
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
        return (string) config('top_message_panel.prompt_version', 'v1');
    }

    public function select(array $material): array
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
     * @param  array{company_name: string, sentences: list<array{id: string, text: string}>, programs: list<array{id: string, name: string, excerpt: string}>}  $material
     */
    public function buildPrompt(array $material): string
    {
        $bodyMax = (int) config('top_message_panel.body_max');
        $kwMin = (int) config('top_message_panel.keyword_min_chars');
        $kwMax = (int) config('top_message_panel.keyword_max_chars');
        $keywordsMin = (int) config('top_message_panel.keywords_min');
        $keywordsMax = (int) config('top_message_panel.keywords_max');
        $perKeyword = (int) config('top_message_panel.programs_per_keyword_max');
        $sumMin = (int) config('top_message_panel.summary_min_chars');
        $sumMax = (int) config('top_message_panel.summary_max_chars');
        $forbidden = implode('」「', (array) config('comparison_commentary.forbidden_words', []));

        $sentences = '';
        foreach ($material['sentences'] as $sentence) {
            $sentences .= "{$sentence['id']}: {$sentence['text']}\n";
        }
        $programs = '';
        foreach ($material['programs'] as $program) {
            $programs .= "{$program['id']} | {$program['name']} | {$program['excerpt']}\n";
        }

        $schema = json_encode($this->buildResponseSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
あなたは、企業の採用サイトの文章から、比較資料のページに載せるものを「選ぶ」アシスタントです。
下の「材料」にある、{$material['company_name']}のトップメッセージの文(IDつき)と、人事制度などの候補(IDつき)から、ページに載せるものを選んでください。文章は書かないでください(言い換え・要約・新しい文は不要です)。選ぶだけです。

選ぶもの:
- core_sentence_id: 会社が何を信じ、何を目指しているかを最もよく表す1文のID。
- body_sentence_ids: 核の一文を補う文のID。1〜{$bodyMax}文。核の一文は含めない。
- keywords: {$keywordsMin}〜{$keywordsMax}個。メッセージの中で繰り返し出てくる考え・大事にしていることを表す語句を選ぶ。
  - phrase: その考えを表す語句を、メッセージの文から一字一句そのまま抜き出す({$kwMin}〜{$kwMax}字。言い換えない)。
  - source_sentence_id: その語句を抜き出した文のID。
  - programs: そのキーワードを具体化している制度を0〜{$perKeyword}件。関係が弱いものは入れない。1つの制度は1つのキーワードにだけ入れる。
    - program_id: 制度のID。
    - summary: その制度の抜粋から一字一句そのまま抜き出した一節({$sumMin}〜{$sumMax}字)。何をする制度か、数字など、中身が分かる部分を選ぶ。文の途中で切れる部分は選ばない。
- 次の言葉を含む語句は選ばない: 「{$forbidden}」。
- 材料に無い文・語句・制度は選ばない。

出力は、次のJSON Schemaに従うJSONオブジェクトだけにしてください(説明文や前後のテキストは不要):
{$schema}

--- ここから材料 ---
【メッセージの文】(ID: 文)
{$sentences}
【制度の候補】(ID | 制度名 | 抜粋)
{$programs}--- ここまで材料 ---
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
                'core_sentence_id' => ['type' => ['string', 'null']],
                'body_sentence_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                'keywords' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'phrase' => ['type' => 'string'],
                            'source_sentence_id' => ['type' => 'string'],
                            'programs' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'program_id' => ['type' => 'string'],
                                        'summary' => ['type' => 'string'],
                                    ],
                                    'required' => ['program_id', 'summary'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['phrase', 'source_sentence_id', 'programs'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['core_sentence_id', 'body_sentence_ids', 'keywords'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $apiKey, string $model, string $prompt): array
    {
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('top_message_panel.timeout', 90);
        $maxRetries = (int) config('services.brand_wheel_ai.max_retries', 1);
        $maxOutputTokens = (int) config('top_message_panel.max_output_tokens', 1500);
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
                                'name' => 'top_message_panel',
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

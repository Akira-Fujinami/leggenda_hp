<?php

namespace App\Services\BranchSurveyItems;

use App\Services\Report\CandidateSurveyCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 依頼CT: 枝の本文に、求職者調査の14項目(定義つき)のそれぞれが書かれているかを判定するAI呼び出し。
 *
 * 呼び出し先(プロバイダ・モデル・温度)は既存のconfig('services.brand_wheel_ai')を使う(温度は0)。
 * 判定プロンプト(v9)・改善提案プロンプト(v14)とは別の、独立したプロンプト。版は
 * config('branch_survey_items.prompt_version')。プロンプト本文を変えたら上げる。
 *
 * AIが根拠を守らなくてもよいように、出力は必ずBranchSurveyItemsVerifierが入力の本文と突き合わせる
 * (プロンプトは確認の代わりではない)。
 */
class OpenAiBranchSurveyItemsProvider implements BranchSurveyItemsProvider
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
        return (string) config('branch_survey_items.prompt_version', 'v1');
    }

    public function analyze(string $text): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new BranchSurveyItemsException('OPENAI_NOT_CONFIGURED', 'OPENAI_API_KEYが設定されていません。');
        }

        $response = $this->request($apiKey, $this->model(), $this->buildPrompt($text));

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new BranchSurveyItemsException('AI_INVALID_RESPONSE', 'AIから有効な応答が返されませんでした。');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new BranchSurveyItemsException('AI_INVALID_JSON', 'AIの応答をJSONとして解釈できませんでした。');
        }

        $usage = $response['usage'] ?? [];

        return [
            'decoded' => $decoded,
            'usage_input_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            'usage_output_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        ];
    }

    public function buildPrompt(string $text): string
    {
        $evidenceMax = (int) config('branch_survey_items.evidence_max_chars', 80);
        $definitions = (array) config('branch_survey_items.definitions', []);

        $itemLines = '';
        foreach ((new CandidateSurveyCatalog)->options() as $option) {
            $itemLines .= "- key: {$option['key']} / 名前: {$option['name']} / 定義: ".($definitions[$option['key']] ?? '')."\n";
        }

        $schema = json_encode($this->buildResponseSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
あなたは、企業の採用サイトの文章を整理するアシスタントです。
下に示す「本文」だけを根拠に、次の14項目それぞれについて、その情報がこの本文の中に書かれているかを判定してください。

14項目(keyと定義):
{$itemLines}
必ず守ること(守れないものは found を false にする):
- 本文に書かれていることだけで判定する。推測・補完・一般論・評価の言葉で判定しない。
- found を true にするのは、その情報が、この本文の中で主題として(見出しや段落としてまとまって)書かれているときだけ。言葉が一度出てくるだけ、他の話題の中で触れているだけのときは false にする。
- found を true にしたら、evidence には、その主題の見出し、または段落の書き出しを、本文から一字一句そのまま抜き出して書く({$evidenceMax}文字以内)。
- evidence は、言い換え・要約・つなぎ合わせ・表記の変更(送り仮名・記号・全角半角)をしない。本文に無い語を足さない。途中を省略しない(続けて書かれている部分だけを抜き出す)。
- 本文に書かれていない項目は found を false にし、evidence を空文字にする。
- 14項目すべてについて、1つずつ出力する。

出力は、次のJSON Schemaに従うJSONオブジェクトだけにしてください(説明文や前後のテキストは不要):
{$schema}

--- ここから本文 ---
{$text}
--- ここまで本文 ---
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
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'enum' => array_keys((array) config('brand_wheel_candidate_survey.options', []))],
                            'found' => ['type' => 'boolean'],
                            'evidence' => ['type' => 'string'],
                        ],
                        'required' => ['key', 'found', 'evidence'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $apiKey, string $model, string $prompt): array
    {
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('branch_survey_items.timeout', 90);
        $maxRetries = (int) config('services.brand_wheel_ai.max_retries', 1);
        $maxOutputTokens = (int) config('branch_survey_items.max_output_tokens', 3000);
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
                                'name' => 'branch_survey_items',
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
                throw new BranchSurveyItemsException('AI_AUTH_FAILED', 'OpenAI APIの認証に失敗しました。');
            }

            if ($response->status() === 429) {
                throw new BranchSurveyItemsException('AI_RATE_LIMITED', 'OpenAI APIのレート制限に達しました。');
            }

            if ($response->status() === 408 || $response->status() === 504) {
                if ($attempt <= $maxRetries) {
                    continue;
                }

                throw new BranchSurveyItemsException('AI_TIMEOUT', 'OpenAI APIの呼び出しがタイムアウトしました。');
            }

            if (! $response->successful()) {
                throw new BranchSurveyItemsException('AI_REQUEST_FAILED', 'OpenAI APIの呼び出しに失敗しました(HTTP '.$response->status().')。');
            }

            return $response->json() ?? [];
        }

        throw new BranchSurveyItemsException('AI_UNAVAILABLE', 'OpenAI APIに接続できませんでした。', $lastException);
    }
}

<?php

namespace App\Services\TopMessageInsight;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 依頼CQ-3: トップメッセージのキーワードと、関連する制度を取り出すAI呼び出し。
 *
 * 呼び出し先(プロバイダ・モデル・温度)は既存のconfig('services.brand_wheel_ai')
 * を使う。温度は0(config('services.brand_wheel_ai.temperature')の既定どおり)。
 * 判定プロンプト(v9)・改善提案プロンプト(v14)とは別の、独立したプロンプト。
 * 版はconfig('top_message_insight.prompt_version')。プロンプト本文を変えたら上げる。
 *
 * プロンプトの要点: 本文に無いことを書かない/quoteとevidenceは本文からそのまま抜き出す/
 * 数字はevidenceに含まれるものだけ。AIが守らなくてもよいように、出力は必ず
 * TopMessageInsightVerifierが原文と突き合わせる(プロンプトは確認の代わりではない)。
 */
class OpenAiTopMessageInsightProvider implements TopMessageInsightProvider
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
        return (string) config('top_message_insight.prompt_version', 'v1');
    }

    public function analyze(TopMessagePage $messagePage, array $programPages): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new TopMessageInsightException('OPENAI_NOT_CONFIGURED', 'OPENAI_API_KEYが設定されていません。');
        }

        $response = $this->request($apiKey, $this->model(), $this->buildPrompt($messagePage, $programPages));

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new TopMessageInsightException('AI_INVALID_RESPONSE', 'AIから有効な応答が返されませんでした。');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new TopMessageInsightException('AI_INVALID_JSON', 'AIの応答をJSONとして解釈できませんでした。');
        }

        $usage = $response['usage'] ?? [];

        return [
            'decoded' => $decoded,
            'usage_input_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            'usage_output_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        ];
    }

    /**
     * @param  list<TopMessagePage>  $programPages
     */
    public function buildPrompt(TopMessagePage $messagePage, array $programPages): string
    {
        $quoteMax = (int) config('top_message_insight.quote_max_chars', 60);
        $keywordsMax = (int) config('top_message_insight.keywords_max', 4);
        $programsMax = (int) config('top_message_insight.programs_per_keyword_max', 3);
        $detailMax = (int) config('top_message_insight.detail_max_chars', 30);
        $evidenceMax = (int) config('top_message_insight.evidence_prompt_max_chars', 100);
        $categories = implode('／', (array) config('top_message_insight.program_categories', []));

        $pages = $this->renderPage('メッセージのページ', $messagePage);
        foreach ($programPages as $i => $page) {
            $pages .= "\n".$this->renderPage('制度のページ'.($i + 1), $page);
        }

        $schema = json_encode($this->buildResponseSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
あなたは、企業の採用サイトの文章を整理するアシスタントです。
下に示す「メッセージのページ」と「制度のページ」の本文だけを根拠に、次の3つを取り出してください。

1. quote: メッセージのページの本文から、代表者(経営者)のメッセージとして象徴的な一文を、そのまま抜き出す({$quoteMax}文字以内)。
2. keyword: そのメッセージの中に実際に出てくる言葉(短い語句)を最大{$keywordsMax}つ。
3. programs: 各keywordに関連すると思われる制度を、最大{$programsMax}つ。制度は、制度のページに書かれているものだけを選ぶ。

必ず守ること(守れないものは出力しない):
- 本文に無いことを書かない。推測・補完・一般論・評価の言葉を書かない。
- quoteとevidenceは、本文から一字一句そのまま抜き出す。言い換え・要約・つなぎ合わせ・表記の変更(送り仮名・記号・全角半角)をしない。
- keywordは、メッセージのページの本文に、その表記のまま出てくる言葉にする。
- nameは、制度の名前を、evidenceの中に書いてある表記のままそっくり写す(縮めない・言い換えない・補わない)。
- evidenceには、必ずその制度の名前(name)そのものを含める。見出しに名前があり、説明が別の行にあるときは、名前の行から説明の行までを、続けて一つのevidenceとして抜き出す。
- categoryは、次のどれか1つをそのまま書く: {$categories}
- 制度とは、社員に対する人事の制度・仕組み(研修・育成、評価・キャリア、配属・異動、働き方・休暇、福利厚生・手当、交流・組織づくり)だけ。会社の製品・サービス・事業・採用の手続き・求人は、制度として出力しない。
- メッセージは、代表者(社長・CEOなど)本人の言葉であるときだけ使う。社員・採用担当などの言葉であれば、quoteを空文字にする。
- detailは{$detailMax}文字以内の短い説明。数字は、evidenceに書いてあるものだけを使う。数字を変えたり、計算したり、丸めたりしない。説明に使える記述が無ければ空文字にする。
- source_urlは、その制度が書いてあるページのURLを、下に示した「制度のページ」のURLのとおりに書く。「メッセージのページ」のURLは使わない(メッセージのページに書かれた制度は出力しない)。
- evidenceは、source_urlのページの本文から、その制度が書かれている部分を、一字一句そのまま抜き出す({$evidenceMax}文字以内)。
- 関連する制度が本文に見つからないkeywordは出力しない。無理にkeywordや制度を増やさない。同じ制度を複数のkeywordに重ねて出さない。
- 条件に合うものが無ければ、keywordsを空の配列にする。quoteにふさわしい一文が無ければ、quoteを空文字にする。

出力は、次のJSON Schemaに従うJSONオブジェクトだけにしてください(説明文や前後のテキストは不要):
{$schema}

--- ここから本文 ---
{$pages}
--- ここまで本文 ---
PROMPT;
    }

    private function renderPage(string $label, TopMessagePage $page): string
    {
        $title = $page->title !== null && $page->title !== '' ? $page->title : '(なし)';

        return "## {$label}\nURL: {$page->url}\nタイトル: {$title}\n本文:\n{$page->text}\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function buildResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'quote' => ['type' => 'string'],
                'keywords' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => ['type' => 'string'],
                            'programs' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'category' => ['type' => 'string', 'enum' => array_values((array) config('top_message_insight.program_categories', []))],
                                        'detail' => ['type' => 'string'],
                                        'source_url' => ['type' => 'string'],
                                        'evidence' => ['type' => 'string'],
                                    ],
                                    'required' => ['name', 'category', 'detail', 'source_url', 'evidence'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['keyword', 'programs'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['quote', 'keywords'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $apiKey, string $model, string $prompt): array
    {
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('top_message_insight.timeout', 120);
        $maxRetries = (int) config('services.brand_wheel_ai.max_retries', 1);
        $maxOutputTokens = (int) config('top_message_insight.max_output_tokens', 3500);
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
                                'name' => 'top_message_insight',
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
                throw new TopMessageInsightException('AI_AUTH_FAILED', 'OpenAI APIの認証に失敗しました。');
            }

            if ($response->status() === 429) {
                throw new TopMessageInsightException('AI_RATE_LIMITED', 'OpenAI APIのレート制限に達しました。');
            }

            if ($response->status() === 408 || $response->status() === 504) {
                if ($attempt <= $maxRetries) {
                    continue;
                }

                throw new TopMessageInsightException('AI_TIMEOUT', 'OpenAI APIの呼び出しがタイムアウトしました。');
            }

            if (! $response->successful()) {
                throw new TopMessageInsightException('AI_REQUEST_FAILED', 'OpenAI APIの呼び出しに失敗しました(HTTP '.$response->status().')。');
            }

            return $response->json() ?? [];
        }

        throw new TopMessageInsightException('AI_UNAVAILABLE', 'OpenAI APIに接続できませんでした。', $lastException);
    }
}

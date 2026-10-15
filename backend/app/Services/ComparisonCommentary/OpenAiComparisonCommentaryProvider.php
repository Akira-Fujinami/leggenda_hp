<?php

namespace App\Services\ComparisonCommentary;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 依頼CU-2: 24項目で比べた材料と根拠の文から、強み・弱みの一文ずつを書くAI呼び出し(プロンプト comparison_commentary)。
 *
 * 呼び出し先(プロバイダ・モデル・温度)は既存のconfig('services.brand_wheel_ai')を使う(温度は0)。
 * 判定プロンプト(v9)・改善提案プロンプト(v14)とは別の、独立したプロンプト。版は
 * config('comparison_commentary.prompt_version')。プロンプト本文を変えたら上げる。
 *
 * AIがプロンプトを守らなくてもよいように、出力は必ずComparisonCommentaryVerifierが材料と突き合わせる
 * (プロンプトは確認の代わりではない)。
 */
class OpenAiComparisonCommentaryProvider implements ComparisonCommentaryProvider
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
        return (string) config('comparison_commentary.prompt_version', 'v1');
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
     * @param  array<string, mixed>  $material
     */
    public function buildPrompt(array $material): string
    {
        $min = (int) config('comparison_commentary.sentence_min_chars', 60);
        $max = (int) config('comparison_commentary.sentence_max_chars', 90);
        $forbidden = implode('」「', (array) config('comparison_commentary.forbidden_words', []));
        $selfLabel = (string) config('comparison_commentary.material_labels.self', '自社');
        $quoteMax = (int) config('comparison_commentary.quote_hint_max_chars', 25);

        $definitionLines = '';
        foreach ((array) config('brand_wheel.axes') as $axis) {
            foreach ((array) ($axis['sub_elements'] ?? []) as $subKey => $subName) {
                $definitionLines .= "- key: {$subKey} / 領域: ".($axis['name_ja'] ?? '')." / 項目: {$subName} / 定義: ".($axis['sub_element_definitions'][$subKey] ?? '')."\n";
            }
        }

        $materialJson = json_encode($material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $schema = json_encode($this->buildResponseSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
あなたは、企業の採用サイトの比較結果を、営業資料の一文にまとめるアシスタントです。
下の「材料」だけを使って、{$selfLabel}({$material['self_name']})の「強み」と「弱み」を、それぞれ1文で書いてください。

材料の見方:
- only_strengths: {$selfLabel}だけが書いている(競合のうち書いている会社が半数未満の)項目。self_evidence は{$selfLabel}のサイトの文章の抜き出し(quote_candidates はそれを短く区切ったもの)。
- holes: {$selfLabel}のサイトからは読み取れなかったが、競合の半数以上が書いている項目。competitor_evidence は、書いている競合のサイトの文章の抜き出し(company はその会社の呼び名、quote_candidates はそれを短く区切ったもの)。matched_companies は書いている競合の呼び名。
- tie_axes: 全社が同じ点数の領域(差がつかない領域)。
- competitors: 比較の競合の呼び名(label)。文や companies には、この label をそのまま使う。

24項目の定義(keyが項目のキー):
{$definitionLines}
強みの書き方:
- only_strengths があれば、その中から1〜2項目を選んで書く(項目名と、{$selfLabel}のサイトの表現を使う)。
- 競合の何社がその項目を書いているかは、competitor_matched_count と competitor_count を使って「競合N社のうちM社」の形で書いてよい。
- only_strengths が空なら、tie_axes に触れ、「強みだが競合も同等」と書いてよい。この場合 items は空にする。
- 領域の点数だけの言い換え(「4/4で…」)は書かない。「強調している」「優れている」のような評価の言葉は使わず、「〜と記している」「〜を紹介している」のように、書かれていることを事実として書く。

弱みの書き方:
- holes から、matched_companies が多い項目を優先して選ぶ。
- 競合がその項目を「どんな記述で」伝えているかを、competitor_evidence から具体的に書く。
- 自社については「サイトからは読み取れませんでした」と書く。
- holes が空なら、sentence を空文字にする(items・quotes・companies も空にする)。

必ず守ること:
- 材料に無いことは書かない。推測・評価・一般論を足さない。
- {$selfLabel}が書いていない項目について、「書いていない」「無い」と断定しない。「読み取れませんでした」「確認できませんでした」と書く。
- 文の中でサイトの表現を引用するときは、「」で囲み、その言葉は quotes にも入れる。quotes は、材料の quote_candidates(根拠の文を短く区切ったもの)の中から選び、一字一句そのまま使う(言い換え・要約・つなぎ合わせ・字句の変更をしない)。「」の中は、できるだけ短い言葉にする(目安は{$quoteMax}文字)。「」で囲んでよいのは quotes に入れた言葉だけ。文の中の「」の言葉と quotes の言葉は、同じ文字列にする。項目名・領域名・会社名・サイト名は「」で囲まず、そのまま書く(悪い例: 「成長機会」についての記述 / 良い例: 成長機会についての記述)。
- items は、その文で扱う項目の key。強みの items は{$selfLabel}が書いている項目、弱みの items は{$selfLabel}が読み取れなかった項目だけ。
- 弱みの companies は、文に出した競合の label。その会社が、弱みの items のすべてを書いている会社だけを入れる。文に会社名を出さないときは空にする。
- 数字は、材料にある数字だけを使う。
- 次の言葉は使わない: 「{$forbidden}」。「懸念」「届いていない」「不足している」「弱い」のような評価の言葉も使わない。
- 各文は1文で、{$min}〜{$max}文字。{$max}文字を超える文は使えない。「」の中の言葉も文字数に含まれるので、引用は短くする。
- 出力のJSONは、items → quotes → (companies) → sentence の順に書く。先に items と、根拠の文から抜き出した短い quotes を決め、その quotes の言葉を使って sentence を組み立てる。

出力は、次のJSON Schemaに従うJSONオブジェクトだけにしてください(説明文や前後のテキストは不要):
{$schema}

--- ここから材料 ---
{$materialJson}
--- ここまで材料 ---
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildResponseSchema(): array
    {
        $keys = [];
        foreach ((array) config('brand_wheel.axes') as $axis) {
            foreach (array_keys((array) ($axis['sub_elements'] ?? [])) as $subKey) {
                $keys[] = (string) $subKey;
            }
        }

        // 項目 → 引用 → (会社) → 文、の順に書かせる(先に短い引用を決めてから、文を組み立てさせるため)。
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $side = fn (array $extra) => [
            'type' => 'object',
            'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $keys]], 'quotes' => $strings] + $extra + ['sentence' => ['type' => 'string']],
            'required' => ['items', 'quotes', ...array_keys($extra), 'sentence'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'strength' => $side([]),
                'weakness' => $side(['companies' => $strings]),
            ],
            'required' => ['strength', 'weakness'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $apiKey, string $model, string $prompt): array
    {
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('comparison_commentary.timeout', 90);
        $maxRetries = (int) config('services.brand_wheel_ai.max_retries', 1);
        $maxOutputTokens = (int) config('comparison_commentary.max_output_tokens', 1500);
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
                                'name' => 'comparison_commentary',
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

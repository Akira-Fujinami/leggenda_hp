<?php

namespace App\Services\StrengthWeaknessSentence;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 依頼CV-2: 機械が選んだ「1項目・1社」の根拠の文(全文)から、強み・弱みの一文を1回の呼び出しでまとめて書くAI呼び出し
 * (プロンプト strength_weakness_sentence)。
 *
 * 呼び出し先(プロバイダ・モデル・温度)は既存のconfig('services.brand_wheel_ai')を使う(温度は0)。
 * 判定プロンプト(v9)・改善提案プロンプト(v14)・依頼CUのプロンプトとは別の、独立したプロンプト。版は
 * config('strength_weakness_sentence.prompt_version')。プロンプト本文を変えたら上げる。
 *
 * AIがプロンプトを守らなくてもよいように、出力は必ずStrengthWeaknessSentenceVerifierが材料と突き合わせる
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
        $limit = (int) config('strength_weakness_sentence.sentence_limit_chars', 100);
        $quoteMin = (int) config('strength_weakness_sentence.quote_min_chars', 10);
        $quoteMax = (int) config('strength_weakness_sentence.quote_max_chars', 35);
        $forbidden = implode('」「', (array) config('comparison_commentary.forbidden_words', []));
        $selfLabel = (string) config('comparison_commentary.material_labels.self', '自社');
        $placeholder = (string) config('strength_weakness_sentence.quote_placeholder', '{QUOTE}');

        $sections = '';
        $strengthRule = "- {$selfLabel}がその内容を書いていること、競合の社数のうち何社からしか読み取れなかったかを書く。そのうえで、求職者にとって何が伝わるかを一言添えてよい(例: 「〜という働き方の具体像が伝わります」)。";
        if ($material['strength'] !== null) {
            $s = $material['strength'];
            if (($s['basis'] ?? null) === 'survey' && is_array($s['survey'] ?? null)) {
                // 依頼CV追補: 自社だけの強みも半数ちょうども無いとき。競合との差は書かない(差が無いため)。
                $sections .= <<<SECTION
【強み】(strength)
- 取り上げる項目: {$s['name']}(領域: {$s['axis']})
- 項目の定義: {$s['definition']}
- 求職者調査で、求職者の{$s['survey']['percentage']}%が知りたい情報: {$s['survey']['name']}(この項目は、この情報に結び付く)
- {$selfLabel}({$material['self_name']})は、この内容を書いている。
- {$selfLabel}のサイトの根拠の文(抜き出し):
{$s['evidence']}

SECTION;
                $strengthRule = "- 「求職者の{$s['survey']['percentage']}%が知りたい{$s['survey']['name']}について、{$selfLabel}が具体的に書いていること」を、引用で示して書く。割合は材料の数字({$s['survey']['percentage']})をそのまま使う。競合との差や、競合の社数は書かない(このときは差が無いため)。";
            } else {
                $sections .= <<<SECTION
【強み】(strength)
- 取り上げる項目: {$s['name']}(領域: {$s['axis']})
- 項目の定義: {$s['definition']}
- {$selfLabel}({$material['self_name']})は、この内容を書いている。競合{$s['competitor_count']}社のうち、この内容を読み取れたのは{$s['matched']}社。
- {$selfLabel}のサイトの根拠の文(抜き出し):
{$s['evidence']}

SECTION;
            }
        }
        if ($material['weakness'] !== null) {
            $w = $material['weakness'];
            $sections .= <<<SECTION
【弱み】(weakness)
- 取り上げる項目: {$w['name']}(領域: {$w['axis']})
- 項目の定義: {$w['definition']}
- {$selfLabel}({$material['self_name']})のサイトからは、この内容を読み取れなかった。競合{$w['competitor_count']}社のうち{$w['matched']}社が、この内容を書いている。
- 取り上げる競合: {$w['company']}
- {$w['company']}のサイトの根拠の文(抜き出し):
{$w['evidence']}

SECTION;
        }

        $schema = json_encode($this->buildResponseSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
あなたは、企業の採用サイトの比較結果を、営業資料の一文にまとめるアシスタントです。
下の「材料」だけを使って、{$selfLabel}の「強み」と「弱み」を、それぞれ1文で書いてください。材料が無い側は、sentence と quote を空文字にしてください。

{$sections}
書き方(強み・弱みに共通):
- 各文は1文で、{$limit}字以内。
- 根拠の文から、その制度・取り組み・事実の「中身を表す部分」を1か所だけ引用する。quote はその引用で、根拠の文の一部を一字一句そのまま抜き出した{$quoteMin}〜{$quoteMax}字の言葉にする(言い換え・要約・つなぎ合わせをしない)。
- 会社名・社名の言い換え・「〜では」「〜は」だけの引用はしない(悪い例: 「マネーフォワードでは」「サイボウズのコンタクトセンターは」)。助詞(は・では・が・の・を・に・も)で終わる引用もしない。
- sentence の中で引用を入れる場所には、{$placeholder} とだけ書く(ちょうど1つ)。引用の「」はサーバーが入れるので、sentence には「」も『』も一切書かない。項目名・領域名・会社名・サイト名などをかぎ括弧で囲まない。
  (良い例: 福利厚生は自社サイトから読み取れませんでしたが、freeeは{$placeholder}と記載しています。 / 悪い例: 福利厚生は「自社サイト」から読み取れませんでしたが、freeeは「{$placeholder}」と記載しています。)
- 材料に無いことは書かない。推測・評価・一般論を足さない。数字は、材料にある数字だけを使う。
- 文に出してよい会社名は、材料にある会社だけ。強みの文には競合の会社名を出さない。
- 次の言葉は使わない: 「{$forbidden}」。評価や推測の言葉も使わない。

強みの書き方:
{$strengthRule}

弱みの書き方:
- {$selfLabel}のサイトからは「読み取れませんでした」と書く。「無い」「書いていない」と断定しない。取り上げる競合が、どんな内容を書いているかを引用で示す。

出力のJSONは、各側で quote → sentence の順に書く。先に quote を決め、その言葉を使って sentence を組み立てる。
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
        $side = [
            'type' => 'object',
            'properties' => ['quote' => ['type' => 'string'], 'sentence' => ['type' => 'string']],
            'required' => ['quote', 'sentence'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => ['strength' => $side, 'weakness' => $side],
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

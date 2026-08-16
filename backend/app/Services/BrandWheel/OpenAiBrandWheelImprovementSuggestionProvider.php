<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionInput;
use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Chat Completions APIを呼び出す、改善提案(page6)AIの実Provider。
 * OpenAiBrandWheelAnalysisProviderと同じリトライ・エラー分類方針・
 * services.brand_wheel_ai.*設定を再利用する(新しい環境変数は追加しない ――
 * 同じ「ブランド・ホイール」サブシステムの一部であるため)。
 *
 * 既存の改善提案(BrandWheelImprovementFocusComposer、グループ差バー＋証拠
 * カード)はAIを一切使わない決定的ロジックのまま維持し、このProviderは
 * その上に追加する「ワンポイント」「詳細提言パラグラフ」のみを生成する
 * (依頼者指定「情報が足りないので追加してください、という一般論から
 * 脱却する」への対応 ―― Quick Win判定・差別化vsギャップ埋め・実行難易度は
 * 企業ごとの文脈判断が要るため、決定的ルールだけでは実現できない)。
 *
 * 入力はBrandWheelImprovementSuggestionInputFactoryが組み立てた、既に
 * evidence実在検証・実行難易度タグ付けが済んだグラウンディング済みデータの
 * みで、AIはこの中に無い事実を作り出さないよう明示的に指示される。
 */
class OpenAiBrandWheelImprovementSuggestionProvider implements BrandWheelImprovementSuggestionProvider
{
    /**
     * v1(2026-08-17): 初版。
     */
    public const string PROMPT_VERSION = 'v1';

    public function __construct(
        private readonly BrandWheelImprovementSuggestionResponseParser $parser,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function model(): ?string
    {
        return (string) (config('services.brand_wheel_ai.model') ?: config('services.openai.model', 'gpt-4o-mini'));
    }

    public function promptVersion(): ?string
    {
        return self::PROMPT_VERSION;
    }

    public function analyze(BrandWheelImprovementSuggestionInput $input): BrandWheelImprovementSuggestionOutcome
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new BrandWheelAnalysisException('OPENAI_NOT_CONFIGURED', 'BRAND_WHEEL_AI_PROVIDER=openaiが指定されていますが、OPENAI_API_KEYが設定されていません。');
        }

        $model = $this->model();
        $prompt = $this->buildPrompt($input);

        $response = $this->request($apiKey, $model, $prompt);

        $content = $response['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new BrandWheelAnalysisException('AI_INVALID_RESPONSE', 'AIから有効な応答が返されませんでした。');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new BrandWheelAnalysisException('AI_INVALID_JSON', 'AIの応答をJSONとして解釈できませんでした。');
        }

        $result = $this->parser->parse($decoded, provider: 'openai', model: $model, isMock: false, promptVersion: self::PROMPT_VERSION);

        $usage = $response['usage'] ?? [];

        return new BrandWheelImprovementSuggestionOutcome(
            result: $result,
            usageInputTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            usageOutputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        );
    }

    private function buildPrompt(BrandWheelImprovementSuggestionInput $input): string
    {
        $facts = json_encode($input->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $forbiddenPhrases = implode('」「', (array) config('brand_wheel.forbidden_phrases', []));

        $competitorNote = $input->hasCompetitor
            ? '比較サイト(競合)のデータが含まれています。ギャップ埋め(競合にあり自社に無い情報を補う)と、'.
              '差別化(競合も手薄な領域を自社が先に充実させる)の両方の可能性を検討してください。'
            : '比較サイト(競合)のデータはありません。自社のデータのみから、候補者に伝わっていない可能性が'.
              '高い領域を判断してください。';

        $forbiddenNote = <<<TXT
- 以下の語は一切使わないでください: 「{$forbiddenPhrases}」。「魅力が無い/劣っている」という評価を
  示唆するため使用禁止です。
- 断定を避けてください。「〜という印象を与える可能性があります」「〜の場合、着手しやすい可能性が
  あります」のように、条件付き・可能性の表現を使ってください。「人事部だけで実施できます」のような
  断定はしないでください。
- データに含まれていない事実(社風・具体的な社内制度・実行体制など)を創作しないでください。
TXT;

        $genericBanNote = <<<TXT
以下のような一般論・誰でも言えるレベルの提言は禁止します:
- 「情報が不足しているため追加しましょう。」
- 「競合より少ないので増やしましょう。」
これらは、データの中の具体的な項目名・実行難易度を踏まえた、企業固有の提言に置き換えてください。
TXT;

        $reasoningSteps = <<<TXT
あなたは採用サイト改善・採用マーケティング・Employer Brandingに精通したプロのHRコンサルタントです。
渡されたデータ(下位要素ごとの該当有無・実行難易度タグ・グループ別優劣)をもとに、以下の順番で
内部的に検討したうえで、最終的な提言のみを出力してください(検討過程そのものは出力しないでください):

1. 自社の強み(self_matched_items)を整理する
2. 自社の不足(self_unmatched_items)を整理する
3. 競合の強み(competitor_matched_items、あれば)を整理する
4. 競合の不足(competitor_unmatched_items、あれば)を整理する
5. 自社と競合の差(group_totals、あれば)を特定する
6. それぞれの不足が候補者への情報伝達にどう影響するかを判断する
7. 改善候補を複数(3件程度)作る
8. 各候補の改善効果(候補者への影響の大きさ)を評価する
9. 各候補の実行難易度(self_unmatched_itemsのexecution_difficulty/execution_note)を評価する
10. 実行しやすく効果も見込める候補(Quick Win)を特定する
11. 実行難易度が高いが差別化効果の大きい候補(中長期施策)を特定する
12. 10と11を踏まえ、最初に着手すべき1つを選び、最終提言を生成する

{$competitorNote}

TXT;

        $schema = <<<TXT
{
  "one_point": "string",
  "recommendation": "string",
  "focus_sub_element_keys": ["string"]
}

one_pointは1文で、最優先で着手すべきアクションを一言で述べてください。
recommendationは3〜5文で、必ず「結論(何をすべきか)→なぜそう考えるか(効果・実行難易度・競合差の
根拠)→具体的に何をすべきか」の順で書いてください。「なぜ」の部分では、効果が大きい施策が必ずしも
最初にやるべき施策とは限らないこと(実行難易度が高い場合は後回しにできること)を、実際のデータに
基づいて説明してください。
focus_sub_element_keysには、recommendationで言及した下位要素のキー(データ内のsub_nameに対応する
実際のキー名。例: "purpose", "colleagues" 等、self_unmatched_items/competitor_matched_items等の
要素に対応する元のキー)を1〜3件、配列で挙げてください。キー名が分からない場合は空配列にしてください。
TXT;

        return <<<PROMPT
{$reasoningSteps}
{$forbiddenNote}

{$genericBanNote}

【データ(自社/競合の下位要素ごとの該当有無・実行難易度・グループ別優劣。すべてPHP側で事前検証済みの事実)】
{$facts}

以下のJSON Schemaに厳密に従うJSONオブジェクトのみを出力してください(説明文や前後のテキストは一切不要):
{$schema}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $apiKey, string $model, string $prompt): array
    {
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('services.brand_wheel_ai.timeout', 60);
        $maxRetries = (int) config('services.brand_wheel_ai.max_retries', 1);
        $maxOutputTokens = (int) config('services.brand_wheel_ai.max_output_tokens', 2000);
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
                                'name' => 'brand_wheel_improvement_suggestion',
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
                throw new BrandWheelAnalysisException('AI_AUTH_FAILED', 'OpenAI APIの認証に失敗しました。', isRetryable: false);
            }

            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');

                throw new BrandWheelAnalysisException(
                    'AI_RATE_LIMITED',
                    'OpenAI APIのレート制限に達しました。',
                    isRetryable: true,
                    retryAfterSeconds: $retryAfter !== null ? (int) $retryAfter : null,
                );
            }

            if ($response->status() === 408 || $response->status() === 504) {
                $lastException = null;

                if ($attempt <= $maxRetries) {
                    continue;
                }

                throw new BrandWheelAnalysisException('AI_TIMEOUT', 'OpenAI APIの呼び出しがタイムアウトしました。', isRetryable: true);
            }

            if (! $response->successful()) {
                throw new BrandWheelAnalysisException('AI_REQUEST_FAILED', 'OpenAI APIの呼び出しに失敗しました(HTTP '.$response->status().')。', isRetryable: true);
            }

            return $response->json() ?? [];
        }

        throw new BrandWheelAnalysisException('AI_UNAVAILABLE', 'OpenAI APIに接続できませんでした。', $lastException, isRetryable: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'one_point' => ['type' => ['string', 'null']],
                'recommendation' => ['type' => ['string', 'null']],
                'focus_sub_element_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['one_point', 'recommendation', 'focus_sub_element_keys'],
            'additionalProperties' => false,
        ];
    }
}

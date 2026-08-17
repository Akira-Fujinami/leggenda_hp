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
     *
     * v2(2026-08-18): クライアントレビュー対応。出力を単一段落(recommendation)
     * から構造化フィールド(reason/recommended_contents/mid_term_action/
     * quick_win/implementation_difficulty/candidate_impact/gap_closing/
     * differentiation_opportunities)へ拡張し、「情報が不足しているので
     * 追加してください」という一般論の禁止・Gap Closing(競合にあり自社に
     * 無い情報を補う)とDifferentiation(自社・競合とも手薄な領域で自社が
     * 先に充実させる)を明示的に区別して内部判断させる指示を追加した。
     * 出力構造が変わるため、v1の結果は再利用しない(このJobはanalysis_idの
     * unique制約により1回しか生成しない設計のため、再利用ロジック自体が
     * 存在しない ―― 既存のGenerateBrandWheelAnalysisJobのようなinput_hash
     * 再利用キャッシュはこの改善提案には無い)。
     */
    public const string PROMPT_VERSION = 'v2';

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
- 「掲載する情報を増やすことをおすすめします。」
これらは、データの中の具体的な項目名・実行難易度を踏まえた、企業固有の提言に置き換えてください。
「不足の指摘」ではなく「優先順位の判断」を示すことが目的です ―― 何を・なぜ・どの順番で改善すべきかを
判断してください。
TXT;

        $gapDifferentiationNote = <<<TXT
改善候補は必ず内部的に以下の2種類に分けて検討してください(UIラベルとしては出しませんが、
判断の軸として明確に区別すること):
- Gap Closing: 競合には該当があり(competitor_matched_items)、自社には無い(self_unmatched_items)
  情報を補う施策。競合との差を埋める効果がある。
- Differentiation: 自社・競合のどちらにも十分に伝えられていない領域
  (self_unmatched_itemsかつcompetitor_unmatched_items、またはcompetitor_matched_itemsが
  少ない領域)のうち、自社が先に情報を充実させることで差別化になりうる施策。
単純にGap Closing(競合に追いつく)だけを提案するのではなく、Differentiationの可能性も
必ず検討したうえで、両方を比較して最終提言を選んでください。
TXT;

        $reasoningSteps = <<<TXT
あなたは採用サイト改善・採用マーケティング・Employer Brandingに精通したプロのHRコンサルタントです。
渡されたデータ(下位要素ごとの該当有無・実行難易度タグ・グループ別優劣)をもとに、以下の順番で
内部的に検討したうえで、最終的な提言のみを出力してください(検討過程そのものは出力しないでください):

1. 自社で不足している項目(self_unmatched_items)を整理する
2. 自社で既に充実している項目(self_matched_items)を整理する
3. 競合で充実している項目(competitor_matched_items、あれば)を整理する
4. 競合でも不足している項目(competitor_unmatched_items、あれば)を整理する
5. 自社と競合の差(group_totals、あれば)を特定する
6. それぞれの不足・差が候補者への情報伝達にどう影響するか(候補者への影響度)を判断する
7. 改善候補を複数(3件程度)作り、Gap ClosingとDifferentiationの両方の観点で分類する
8. 各候補の改善効果(候補者への影響度)を評価する(low/medium/high)
9. 各候補の実行難易度を評価する(self_unmatched_itemsのexecution_difficulty/execution_noteを
   根拠にする。他部署・社員を巻き込む必要があるか、人事部内で完結できるか、既存情報から
   作れるか、社員インタビューや撮影が必要かを考慮する)
10. 実行しやすく効果も見込める候補(Quick Win)を特定する ―― 最も改善効果が大きい施策が
    必ずしも最初にやるべき施策とは限らない。実行難易度が高い施策より、既存情報から短期間で
    整理できる施策を優先することがある
11. 実行難易度が高いが差別化効果の大きい候補があれば、中長期施策として特定する
12. 差分の大きさ×候補者への影響×実行難易度×Quick Win性を総合し、最初に着手すべき1つを選ぶ

{$competitorNote}

{$gapDifferentiationNote}
TXT;

        $schema = <<<TXT
{
  "one_point": "string",
  "reason": "string",
  "recommended_contents": ["string"],
  "mid_term_action": "string または null",
  "quick_win": true または false,
  "implementation_difficulty": "low" または "medium" または "high",
  "candidate_impact": "low" または "medium" または "high",
  "gap_closing": ["string"],
  "differentiation_opportunities": ["string"],
  "recommendation": "string",
  "focus_sub_element_keys": ["string"]
}

- one_point: 1文で、最優先で着手すべきアクションを一言で言い切ってください(例:「まずは
  『新しい取り組み』と『競争力・独自性』を、具体的な事例として追加することを推奨します。」)。
  「掲載する情報を増やすことをおすすめします。」のような曖昧な言い回しは禁止です。
- reason: 2〜3文で、なぜその施策を最優先にすべきかを説明してください。競合との比較・
  候補者への影響・実行難易度の観点を、実際のデータに基づいて具体的に書いてください。
- recommended_contents: 具体的に追加すべき情報を2〜3項目、箇条書きの短いフレーズ(項目名や
  内容の要点、1件20〜30字程度)で挙げてください。data内の実在する下位要素名・具体的な
  内容に基づくものにしてください。
- mid_term_action: 実行難易度が高いが差別化効果の大きい中長期施策があれば1〜2文で。
  無ければnullにしてください(無理に埋めないこと)。
- quick_win: one_pointで挙げた最優先施策が、既存の社内情報から比較的着手しやすいと
  判断できる場合はtrue、社員インタビューや他部署の協力等が必要で時間がかかると
  判断できる場合はfalseにしてください。
- implementation_difficulty / candidate_impact: one_pointで挙げた施策についての
  実行難易度・候補者への影響度を、低/中/高のいずれかで判断してください。
- gap_closing: 競合には該当があり自社には無い項目のうち、今回の提言で言及したものの
  下位要素キー(例: "project_initiative")を配列で。無ければ空配列。
- differentiation_opportunities: 自社・競合とも手薄で、自社が先に充実させれば差別化に
  なりうる項目の下位要素キーを配列で。無ければ空配列。
- recommendation: 後方互換用に、結論→なぜ→具体的にの3〜5文でも記述してください
  (reason/recommended_contentsと重複しても構いません)。
- focus_sub_element_keys: 提言全体で言及した下位要素のキーを1〜3件、配列で。
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
                'reason' => ['type' => ['string', 'null']],
                'recommended_contents' => ['type' => 'array', 'items' => ['type' => 'string']],
                'mid_term_action' => ['type' => ['string', 'null']],
                'quick_win' => ['type' => ['boolean', 'null']],
                'implementation_difficulty' => ['type' => ['string', 'null'], 'enum' => ['low', 'medium', 'high', null]],
                'candidate_impact' => ['type' => ['string', 'null'], 'enum' => ['low', 'medium', 'high', null]],
                'gap_closing' => ['type' => 'array', 'items' => ['type' => 'string']],
                'differentiation_opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recommendation' => ['type' => ['string', 'null']],
                'focus_sub_element_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => [
                'one_point', 'reason', 'recommended_contents', 'mid_term_action', 'quick_win',
                'implementation_difficulty', 'candidate_impact', 'gap_closing', 'differentiation_opportunities',
                'recommendation', 'focus_sub_element_keys',
            ],
            'additionalProperties' => false,
        ];
    }
}

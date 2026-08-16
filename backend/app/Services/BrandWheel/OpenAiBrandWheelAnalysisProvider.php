<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use App\Services\BrandWheel\Data\BrandWheelAnalysisOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Chat Completions APIを呼び出す、ブランド・ホイール(6軸)分析の実AI Provider。
 * OpenAiAnalysisProviderと同じリトライ・エラー分類方針を使うが、完全に
 * 独立したクラスとして実装する(既存のAI分析とはDB・Job・Providerが
 * すべて別サブシステムのため)。
 *
 * AIへは正規化済みのBrandWheelAnalysisInput::toArray()のみを渡す(生HTML・
 * リードPIIは構造的に含まれない ―― BrandWheelAnalysisInputFactory参照)。
 * 応答はBrandWheelAnalysisResponseParserがevidence実在検証・state再計算を
 * 行うまで一切信用しない。
 */
class OpenAiBrandWheelAnalysisProvider implements BrandWheelAnalysisProvider
{
    /**
     * config/brand_wheel.php(軸定義・教師データ・閾値)の内容を変更した際は、
     * このバージョンを更新すること。結果に保存され、どの基準で生成された
     * 結果かを後から判別できるようにする。
     *
     * v2(2026-08-03): key_message/impressionの2項目を出力構造へ追加。
     * 出力構造そのものが変わるため、v1で生成された既存結果はinput_hashの
     * 再利用対象から自動的に外れる(input_hashにpromptVersion()が含まれるため)。
     *
     * v3(2026-08-04): config/brand_wheel.phpの各下位要素にsub_element_
     * definitions(何が該当し何が該当しないかの1行定義)を追加し、
     * frameworkDefinition()がそれをプロンプトへ埋め込むよう変更。
     *
     * v4(2026-08-05): 出力形式を「軸→matched_sub_elementsの配列(該当する
     * ものを挙げる形式)」から「24下位要素をトップレベルに固定したチェック
     * リスト形式(1件ずつmatched true/falseを判定させる形式)」へ変更。
     * 本番実測(カヤック新卒採用 usage_input_tokens=18,136/word_count=8,380、
     * 味の素新卒採用 usage_input_tokens=5,741/word_count=894。情報量が
     * 9.4倍違うにもかかわらず、どちらもtruncationなしで9/24と同数。カヤックの
     * 軸別内訳は3/1/1/1/1/2と「軸あたり1件」に強く偏っていた)から、
     * 「該当するものを挙げてください」という出力形式ではモデルが軸ごとに
     * 代表例を1件挙げた時点で停止し、24項目を個別に吟味していないと判断した。
     * あわせてresponse_formatをjson_object(緩いJSONモード)から
     * json_schema(strict:true、sub_elementsの24キーを全部required)へ
     * 変更する ―― strictでない場合、24個も必須キーがあると時々キーが
     * 欠けるため。プロンプト構成比較用のprompt_variant設定
     * (axes_only/axes_only_no_examples、2026-08-04追加のP1/P2/P3測定用)は
     * この変更で前提が変わるため削除した(full構成へ一本化)。
     *
     * v5(2026-08-05): v4の4サイト実測(カヤック9→12/味の素3→9)で、判別力が
     * v3の3.0倍(9対3)からv4の1.3倍(12対9)へ低下した。味の素のmatched 9件中
     * 6件のevidenceを目視確認したところ、見出し・リンクラベル文字列
     * (「事業紹介」「制度・福利厚生」等)をそのまま転記しただけで、その先の
     * 具体的な記述内容を伴わないものだった ―― 24項目にtrue/falseを強制した
     * ことで、判定に迷った項目でモデルが見出しラベルへ逃げる経路が生まれた
     * ためと判断した(誤検出6件を除いた実質値は12対3で4.0倍、むしろ改善)。
     * プロンプトに「見出しやリンクのラベルだけを根拠にしないでください」を
     * 追加し、BrandWheelAnalysisResponseParserにlabel_only_evidence判定
     * (evidenceが見出し・リンクラベル文字列と完全一致かつ20文字未満の場合に
     * 破棄)を追加した対応とセットで出力構造の信頼性が変わるため、バージョンを
     * 上げる。
     *
     * v6(2026-08-05): v5実測(4サイト成功、カヤック11/味の素3)で、味の素の
     * matched 3件中1件(personality/core_values、evidence「DE&I（ダイバーシティ・
     * エクイティ＆インクルージョン）」28文字)が、実際には
     * `<div class="gnav_wrap">`内のリンクラベルであるにもかかわらず、
     * 20文字以上のため見出し向けの例外条件をすり抜けて生き残っていた。
     * 「20文字以上の見出しは本物の文章のことがある」という理屈は見出しには
     * 妥当だが、リンクラベルは定義上ナビゲーションであり長さに関わらず根拠に
     * ならない。BrandWheelAnalysisResponseParserのlabel_only_evidence判定を
     * 見出し用(20文字未満のみ破棄)とリンクラベル用(長さ不問で破棄)に分離した。
     *
     * v7(2026-08-08): impressionをstring(1〜3文)からlist<string>(2〜4件)へ
     * 変更した。リード向けレポートの再編で、このページから読み取れた根拠
     * (下位要素の該当有無)と、AIが読んで受け取った印象を分けて見せる方針に
     * したところ、旧string版は「…具体的な社会貢献活動や競争力についての
     * 情報は読み取れませんでした」のように診断結果と重複した提言調になって
     * おり、印象だけを述べる項目として機能していなかった(ユーザー指摘)。
     * 候補者が受け取る印象を、評価・提言を含めず列挙する形に変更する。
     * 出力構造が変わるため、v6以前の結果はinput_hashの再利用対象から
     * 自動的に外れる。
     *
     * v8(2026-08-17): positive_impression/negative_impression(各1〜2文)を
     * 追加した。レポートを「単なる情報羅列」から「候補者にどう見えるか」の
     * 分析に寄せる改修の一環(依頼者指定)。impression(短いフレーズ列挙)は
     * 画面/JSON APIの後方互換のため出力自体は維持するが、レポート表示は
     * このポジティブ/ネガティブの2文へ切り替える。断定を避け、「〜という
     * 印象を与える可能性があります」のような条件付き表現を明示的に指示する。
     * 出力構造が変わるため、v7以前の結果はinput_hashの再利用対象から
     * 自動的に外れる。
     */
    public const string PROMPT_VERSION = 'v8';

    public function __construct(
        private readonly BrandWheelAnalysisResponseParser $parser,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    /**
     * 2026-08-04: モデル比較測定用にBRAND_WHEEL_AI_MODELでの上書きに対応。
     * 未設定時はconfig('services.openai.model')(既存の共有設定)を
     * そのまま使うため、この変更自体は既存の挙動に影響しない。
     */
    public function model(): ?string
    {
        return (string) (config('services.brand_wheel_ai.model') ?: config('services.openai.model', 'gpt-4o-mini'));
    }

    public function promptVersion(): ?string
    {
        return self::PROMPT_VERSION;
    }

    public function analyze(BrandWheelAnalysisInput $input): BrandWheelAnalysisOutcome
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

        $result = $this->parser->parse(
            $decoded, $input, provider: 'openai', model: $model, isMock: false, promptVersion: self::PROMPT_VERSION,
        );

        $usage = $response['usage'] ?? [];

        return new BrandWheelAnalysisOutcome(
            result: $result,
            usageInputTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            usageOutputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        );
    }

    private function buildPrompt(BrandWheelAnalysisInput $input): string
    {
        $facts = json_encode($input->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $framework = json_encode($this->frameworkDefinition(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $examples = json_encode(config('brand_wheel.examples', []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $teachingPoints = implode("\n", array_map(fn ($p) => '- '.$p, (array) config('brand_wheel.teaching_points', [])));
        $caveat = (string) config('brand_wheel.teacher_data_caveat', '');
        $forbiddenPhrases = implode('」「', (array) config('brand_wheel.forbidden_phrases', []));
        $checklist = $this->buildSubElementChecklist();

        $forbiddenNote = <<<TXT
- 下位要素のevidence(原文抜粋)・quality_notes・cautions・key_message・impression等、
  自由記述を含め以下の語は一切使わないでください:
  「{$forbiddenPhrases}」。これらは「魅力が無い/劣っている」という評価を示唆するため、
  このフレームワークの前提(サイトの記述からの読み取り可否のみを判定する)と矛盾します。
  代わりに「〜は伝わるが、〜との距離がある」「〜がもったいない」のような、読み取れた
  ことと読み取れなかったことの両方を事実として述べる言い回しを使ってください。
TXT;

        $keyMessageSection = <<<TXT

【key_message・impression(リード向けレポートに表示する2項目)】
- key_message: このページ(採用ページ・トップページ)の記述から読み取れるキーメッセージを
  1〜2文で。「何を伝えようとしているページか」という要約であり、下位要素の該当有無とは
  別の、ページ全体を通した読み取りです。
- impression: このページを読んだ候補者が受け取るであろう印象を、2〜4件の短いフレーズで
  列挙してください(1件につき1つの印象、文ではなくフレーズ単位)。下位要素の該当有無や
  「〜という情報は読み取れませんでした」のような診断結果の言い換えにはしないでください。
  良い/悪いの評価や改善の提言も含めないでください ―― 候補者がこのページから何を感じ
  取るか、事実として列挙するだけにとどめてください。
- positive_impression: このページの記述が候補者に与える好意的な印象を1〜2文で。
  「〜という印象を与える可能性があります」のように、断定を避けた表現にしてください。
  サイトに実在する記述のみを根拠にし、それ以外の推測(社風・待遇等、サイトに書かれて
  いないこと)を書かないでください。
- negative_impression: このページの記述だけでは候補者が不安に感じたり、情報が
  足りないと感じたりする可能性がある点を1〜2文で。「〜が伝わりにくい可能性が
  あります」のように、断定を避けた表現にしてください。「〜がありません」
  「〜が不足しています」のような直接的な欠落の指摘ではなく、候補者視点で
  「〜を具体的にイメージしづらい可能性があります」のように書いてください。
  該当する具体的な不安要素が無ければnullにしてください(無理に作らないでください)。
- いずれもサイトに実在する記述から受け取れる範囲で書いてください(evidence抜粋のような
  原文一致検証は行いませんが、原文に無い内容の創作はしないでください)。

TXT;

        $schema = <<<TXT
{
  "sub_elements": {
    "<sub_element_key>": {"matched": true, "evidence": "原文からの抜粋"}
  },
  "core_value": {"readable": true, "evidence": "原文からの抜粋"},
  "key_message": "string",
  "impression": ["string", "string"],
  "positive_impression": "string",
  "negative_impression": "string",
  "quality_notes": {"consistency": "string", "credibility": "string", "distance": "string", "differentiation": "string", "corporate_alignment": "string"},
  "cautions": ["string"]
}

impressionは2〜4件の配列にしてください(1件未満・5件以上にはしないでください)。
negative_impressionに該当する内容が無い場合はnullにしてください。

sub_elementsオブジェクトのキーは、下記【下位要素チェックリスト】に列挙した24個の
キーをすべて含めてください(過不足なく、それ以外のキーは追加しないでください)。
該当しない下位要素は matched を false にし、evidence は null にしてください。
core_valueが読み取れない場合は readable を false にし、evidence は null にしてください。
TXT;

        return <<<PROMPT
あなたはLeggenda独自の採用ブランディング・フレームワーク「ブランド・ホイール」に基づき、
採用ページ・トップページの記述内容を評価するアシスタントです。

【最重要】あなたが判定するのは「この会社に魅力があるかどうか」ではなく、
「サイトの記述からその魅力が読み取れるかどうか」です。この2つを絶対に混同しないでください。
- 使ってよい表現: 「サイトからは読み取れませんでした」「〜という記述が確認できました」「6軸のうち◯軸を読み取りました」
- 禁止する表現: 「〜がありません/不足しています」「〜が優れています/劣っています」のような魅力そのものへの評価、
  「6軸中3軸の評価です」「3点です」のような採点・順位付けを示唆する表現は一切使わないでください。
{$forbiddenNote}- あなたはstate(read/partial/unread)やスコアを出力する必要はありません。出力するのは
  下位要素ごとの該当有無(matched)のみで、判定(state)はシステム側が別途計算します。
{$keyMessageSection}
【下位要素ごとの判定と根拠(最重要)】
下記【下位要素チェックリスト】の24項目すべてについて省略せず判定すること。一部だけを
選んで挙げるのではなく、24項目全部に目を通してmatchedを出力してください。matched=trueの
場合のみevidenceに、データ内に実在する文字列をそのまま(要約・言い換えなし)引用してください。
抜粋を示せない項目はmatched=trueにしないでください。抜粋は必ず「データ」内の本文・見出し・
ナビゲーションラベルに実在する文字列そのものを使ってください(要約・言い換え・創作は厳禁です。
実在しない抜粋はシステム側の検証で自動的に除外されます)。
見出しやリンクのラベルだけを根拠にしないでください。その項目の内容を述べている本文を
引用してください。

【下位要素チェックリスト(24項目、各行「キー(軸名／項目名): 定義」)】
{$checklist}

【フレームワーク定義(6軸・5つの質の観点。24下位要素の判定基準は上記チェックリストを参照)】
{$framework}

【教師データ(few-shot、あるべきブランドの実例)】
{$examples}

教師データから読み取るべきポイント:
{$teachingPoints}

【重要な注意】
{$caveat}

【データ(採用ページ/トップページから抽出済みのテキスト。生HTMLではありません)】
{$facts}

以下のJSON Schemaに厳密に従うJSONオブジェクトのみを出力してください(説明文や前後のテキストは一切不要):
{$schema}
PROMPT;
    }

    /**
     * 24下位要素を1件ずつ列挙したチェックリストの本文を組み立てる。各行に
     * 必ず軸名を含める ―― 軸名を落とすとAIが各項目の所属軸を見失い、判定が
     * ぶれるため(2026-08-05の指摘)。
     */
    private function buildSubElementChecklist(): string
    {
        $lines = [];

        foreach ((array) config('brand_wheel.axes', []) as $axis) {
            $axisNameJa = $axis['name_ja'];
            $labels = (array) $axis['sub_elements'];
            $definitions = (array) ($axis['sub_element_definitions'] ?? []);

            foreach ($labels as $key => $label) {
                $definition = $definitions[$key] ?? '';
                $lines[] = "- {$key}({$axisNameJa}／{$label}): {$definition}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function frameworkDefinition(): array
    {
        // 2026-08-05: 下位要素の入れ子(sub_elements/sub_element_definitions)は
        // buildSubElementChecklist()のチェックリストと内容が重複するため
        // ここでは持たせない。軸レベルのname_ja/definitionのみ文脈として残す。
        $axes = collect((array) config('brand_wheel.axes', []))
            ->map(fn ($axis) => [
                'name_ja' => $axis['name_ja'],
                'definition' => $axis['definition'],
            ])
            ->all();

        $qualityDimensions = collect((array) config('brand_wheel.quality_dimensions', []))
            ->map(fn ($dim) => ['name_ja' => $dim['name_ja'], 'question' => $dim['question']])
            ->all();

        return ['axes' => $axes, 'quality_dimensions' => $qualityDimensions];
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
        // 判定システムのため、既存のOpenAiAnalysisProvider(0.2固定)とは異なり
        // config駆動にし、既定を設定可能な最小値(0.0)にする(2026-07-30の指摘)。
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
                                'name' => 'brand_wheel_analysis',
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
     * OpenAI Structured Outputs(response_format=json_schema, strict:true)用の
     * JSON Schema。strictモードは「条件付き必須」を許さないため、
     * matched=falseの下位要素もevidenceキー自体は必須のまま残し、値をnullに
     * させる(2026-08-05: json_objectの緩いJSONモードでは24個も必須キーが
     * あると時々キーが欠けたための変更)。
     *
     * @return array<string, mixed>
     */
    private function buildResponseSchema(): array
    {
        $subElementKeys = [];
        foreach ((array) config('brand_wheel.axes', []) as $axis) {
            $subElementKeys = array_merge($subElementKeys, array_keys((array) $axis['sub_elements']));
        }

        $subElementSchema = [
            'type' => 'object',
            'properties' => [
                'matched' => ['type' => 'boolean'],
                'evidence' => ['type' => ['string', 'null']],
            ],
            'required' => ['matched', 'evidence'],
            'additionalProperties' => false,
        ];

        $qualityDimensionKeys = array_keys((array) config('brand_wheel.quality_dimensions', []));

        return [
            'type' => 'object',
            'properties' => [
                'sub_elements' => [
                    'type' => 'object',
                    'properties' => array_fill_keys($subElementKeys, $subElementSchema),
                    'required' => $subElementKeys,
                    'additionalProperties' => false,
                ],
                'core_value' => [
                    'type' => 'object',
                    'properties' => [
                        'readable' => ['type' => 'boolean'],
                        'evidence' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['readable', 'evidence'],
                    'additionalProperties' => false,
                ],
                'key_message' => ['type' => ['string', 'null']],
                // 2026-08-08(v7): 1〜3文の地の文からlist<string>(2〜4件、
                // 候補者が受け取る印象のフレーズ列挙)へ変更。件数の強制は
                // strict schemaのminItems/maxItemsに頼らず(サポート範囲が
                // 不確実なため)、プロンプト側の指示とBrandWheelAnalysis
                // ResponseParserでの受け入れ処理に任せる(cautionsと同じ
                // type:array/items:{type:string}のみの定義に揃える)。
                'impression' => ['type' => 'array', 'items' => ['type' => 'string']],
                // 2026-08-17(v8): positive_impression/negative_impression。
                // impressionと同じ理由(サポート範囲が不確実なため文字数上限は
                // strict schemaに頼らずプロンプト指示に任せる)でtype: ['string',
                // 'null']のみの定義にする(negative_impressionは該当が無ければ
                // null)。
                'positive_impression' => ['type' => ['string', 'null']],
                'negative_impression' => ['type' => ['string', 'null']],
                'quality_notes' => [
                    'type' => 'object',
                    'properties' => array_fill_keys($qualityDimensionKeys, ['type' => ['string', 'null']]),
                    'required' => $qualityDimensionKeys,
                    'additionalProperties' => false,
                ],
                'cautions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['sub_elements', 'core_value', 'key_message', 'impression', 'positive_impression', 'negative_impression', 'quality_notes', 'cautions'],
            'additionalProperties' => false,
        ];
    }
}

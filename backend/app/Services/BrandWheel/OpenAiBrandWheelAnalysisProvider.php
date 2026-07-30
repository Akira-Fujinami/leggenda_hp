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
     */
    public const string PROMPT_VERSION = 'v1';

    public function __construct(
        private readonly BrandWheelAnalysisResponseParser $parser,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function model(): ?string
    {
        return (string) config('services.openai.model', 'gpt-4o-mini');
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
        $axisKeys = implode(', ', array_keys((array) config('brand_wheel.axes', [])));
        $forbiddenPhrases = implode('」「', (array) config('brand_wheel.forbidden_phrases', []));

        return <<<PROMPT
あなたはLeggenda独自の採用ブランディング・フレームワーク「ブランド・ホイール」に基づき、
採用ページ・トップページの記述内容を評価するアシスタントです。

【最重要】あなたが判定するのは「この会社に魅力があるかどうか」ではなく、
「サイトの記述からその魅力が読み取れるかどうか」です。この2つを絶対に混同しないでください。
- 使ってよい表現: 「サイトからは読み取れませんでした」「〜という記述が確認できました」「6軸のうち◯軸を読み取りました」
- 禁止する表現: 「〜がありません/不足しています」「〜が優れています/劣っています」のような魅力そのものへの評価、
  「6軸中3軸の評価です」「3点です」のような採点・順位付けを示唆する表現は一切使わないでください。
- 以下の語は、quality_notes・cautions等の自由記述を含め一切使わないでください:
  「{$forbiddenPhrases}」。これらは「魅力が無い/劣っている」という評価を示唆するため、
  このフレームワークの前提(サイトの記述からの読み取り可否のみを判定する)と矛盾します。
- あなたはstate(read/partial/unread)やスコアを出力する必要はありません。出力するのは
  下位要素ごとの該当有無(matched_sub_elements)のみで、判定(state)はシステム側が別途計算します。

【下位要素ごとの根拠】
下位要素ごとに、それを裏づける原文抜粋を必ず1つ添えてください。抜粋を示せない下位要素は
該当扱いにしないでください。抜粋は必ず「データ」内の本文・見出し・ナビゲーションラベルに
実在する文字列そのものを使ってください(要約・言い換え・創作は厳禁です。実在しない抜粋は
システム側の検証で自動的に除外されます)。

【フレームワーク定義(6軸・24下位要素・5つの質の観点)】
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
{
  "axes": {
    "<axis_key>": {"matched_sub_elements": [{"key": "string", "evidence": "原文からの抜粋"}]}
  },
  "core_value": {"readable": true, "evidence": "原文からの抜粋"},
  "quality_notes": {"consistency": "string", "credibility": "string", "distance": "string", "differentiation": "string", "corporate_alignment": "string"},
  "cautions": ["string"]
}

axesオブジェクトのキーは必ず次の6つをすべて含めてください({$axisKeys})。
該当する下位要素が無い軸は matched_sub_elements を空配列にしてください。
core_valueが読み取れない場合は readable を false にし、evidence は省略してください。
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function frameworkDefinition(): array
    {
        $axes = collect((array) config('brand_wheel.axes', []))
            ->map(fn ($axis, $key) => [
                'name_ja' => $axis['name_ja'],
                'definition' => $axis['definition'],
                'sub_elements' => $axis['sub_elements'],
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
                        'response_format' => ['type' => 'json_object'],
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
}

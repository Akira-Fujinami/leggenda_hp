<?php

namespace App\Services\AiAnalysis;

use App\Services\AiAnalysis\Data\AiAnalysisInput;
use App\Services\AiAnalysis\Data\AiAnalysisOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Chat Completions APIを呼び出す実AI Provider。
 *
 * AIへは正規化済みのAiAnalysisInput::toArray()のみを渡す
 * (Raw HTML・Lighthouse生JSON・Semrush Raw・スクリーンショット・APIキー等は
 * 一切含まれない ―― AiAnalysisInputFactoryの時点で除外済み)。
 * 応答は必ずJSONオブジェクトとして受け取り(response_format=json_object)、
 * AiAnalysisResponseParserで構造・参照整合性を検証してからDTO化する。
 */
class OpenAiAnalysisProvider implements AiAnalysisProvider
{
    public function __construct(
        private readonly AiAnalysisResponseParser $parser,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function analyze(AiAnalysisInput $input): AiAnalysisOutcome
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new AiAnalysisException('OPENAI_NOT_CONFIGURED', 'AI_PROVIDER=openaiが指定されていますが、OPENAI_API_KEYが設定されていません。');
        }

        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $maxInputTokens = config('services.ai.max_input_tokens');
        $prompt = $this->buildPrompt($input);

        if ($maxInputTokens !== null && $this->estimateTokenCount($prompt) > (int) $maxInputTokens) {
            throw new AiAnalysisException('AI_INPUT_TOO_LARGE', 'AIへの入力サイズがAI_MAX_INPUT_TOKENSの上限を超えています。');
        }

        $response = $this->request($apiKey, $model, $prompt);

        $content = $response['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new AiAnalysisException('AI_INVALID_RESPONSE', 'AIから有効な応答が返されませんでした。');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new AiAnalysisException('AI_INVALID_JSON', 'AIの応答をJSONとして解釈できませんでした。');
        }

        $result = $this->parser->parse($decoded, $input, provider: 'openai', model: $model, isMock: false);

        $usage = $response['usage'] ?? [];

        return new AiAnalysisOutcome(
            result: $result,
            usageInputTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            usageOutputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        );
    }

    private function buildPrompt(AiAnalysisInput $input): string
    {
        $facts = json_encode($input->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
あなたはWebサイトのSEO・パフォーマンス分析結果を要約するアシスタントです。
以下のJSONは、既に計測・採点済みのデータの要約です(生のHTMLやAPIレスポンスは含まれません)。
このデータのみを根拠に、日本語で分析結果を作成してください。データに無い事実を創作しないでください。

データ:
{$facts}

以下のJSON Schemaに厳密に従うJSONオブジェクトのみを出力してください(説明文や前後のテキストは一切不要):
{
  "summary": "string",
  "strengths": [{"title": "string", "description": "string", "evidence_metric_keys": ["string"]}],
  "weaknesses": [{"title": "string", "description": "string", "evidence_metric_keys": ["string"]}],
  "priority_actions": [{"title": "string", "description": "string", "priority": "critical|high|medium|low", "impact": "high|medium|low", "effort": "small|medium|large", "evidence_metric_keys": ["string"]}],
  "competitor_insights": [{"title": "string", "description": "string", "competitor_website_analysis_ids": [0]}],
  "cautions": ["string"],
  "confidence": 0.0
}

evidence_metric_keysには、上記データのimportant_metrics/unavailable_metrics/error_metricsに実在するkeyのみを使ってください。
competitor_website_analysis_idsには、上記データのcompetitor_gapsに実在するwebsite_analysis_idのみを使ってください。
confidenceは0.0から1.0の範囲で、分析の確信度を表してください。
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $apiKey, string $model, string $prompt): array
    {
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('services.ai.timeout', 60);
        $maxRetries = (int) config('services.ai.max_retries', 1);
        $maxOutputTokens = (int) config('services.ai.max_output_tokens', 2000);

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
                        'temperature' => 0.2,
                    ]);
            } catch (ConnectionException $e) {
                $lastException = $e;

                continue;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new AiAnalysisException('AI_AUTH_FAILED', 'OpenAI APIの認証に失敗しました。', isRetryable: false);
            }

            if ($response->status() === 429) {
                // レート制限はワーカーをブロックして待つのではなく、呼び出し側の
                // Jobがretryable+retryAfterSecondsを見てqueueへrelease()する設計
                // (FetchExternalSeoDataJobと同じ方針)。
                $retryAfter = $response->header('Retry-After');

                throw new AiAnalysisException(
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

                throw new AiAnalysisException('AI_TIMEOUT', 'OpenAI APIの呼び出しがタイムアウトしました。', isRetryable: true);
            }

            if (! $response->successful()) {
                throw new AiAnalysisException('AI_REQUEST_FAILED', 'OpenAI APIの呼び出しに失敗しました(HTTP '.$response->status().')。', isRetryable: true);
            }

            return $response->json() ?? [];
        }

        throw new AiAnalysisException('AI_UNAVAILABLE', 'OpenAI APIに接続できませんでした。', $lastException, isRetryable: true);
    }

    /**
     * 正確なトークナイザは使わず、文字数からの粗い概算で入力サイズ上限を検査する。
     */
    private function estimateTokenCount(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3);
    }
}

<?php

namespace App\Services\TopMessagePanel;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 依頼DD-3: パネルの「選ぶ」AIを、Claude(Anthropic Messages API)で動かす。プロンプト(v2)・出力の形・サーバー側の確認は、
 * OpenAiTopMessagePanelProvider と同じもの(プロンプト本文はそちらのbuildPrompt()を使う。変えない)。
 *
 * モデル名は env(TOP_MESSAGE_PANEL_MODEL)だけ(コードに既定値を持たない)。APIキーは services.anthropic.api_key。
 * 温度は送らない(Claude Sonnet 5.5 など新しいモデルは、既定以外の値を送ると400になる)。考える分も出力の上限に入るので、
 * 上限は config('top_message_panel.anthropic_max_output_tokens')。応答は text のブロックだけを読む。
 * 失敗したときは、状態コードとエラーの種類(error.type)だけをログに残す(error.message・キー・リクエスト本文は出さない)。
 */
class AnthropicTopMessagePanelProvider implements TopMessagePanelProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): ?string
    {
        $model = (string) config('top_message_panel.model');

        return $model !== '' ? $model : null;
    }

    public function promptVersion(): string
    {
        return (string) config('top_message_panel.prompt_version', 'v1');
    }

    public function select(array $material): array
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new ComparisonCommentaryException('ANTHROPIC_NOT_CONFIGURED', 'ANTHROPIC_API_KEYが設定されていません。');
        }
        $model = $this->model();
        if ($model === null) {
            throw new ComparisonCommentaryException('MODEL_NOT_CONFIGURED', 'TOP_MESSAGE_PANEL_MODELが設定されていません。');
        }

        $response = $this->request($apiKey, [
            'model' => $model,
            'max_tokens' => (int) config('top_message_panel.anthropic_max_output_tokens'),
            'messages' => [['role' => 'user', 'content' => (new OpenAiTopMessagePanelProvider)->buildPrompt($material)]],
        ]);

        $text = '';
        foreach ((array) ($response['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        if (trim($text) === '') {
            throw new ComparisonCommentaryException('AI_INVALID_RESPONSE', 'AIから有効な応答が返されませんでした。');
        }

        $decoded = $this->decode($text);
        if ($decoded === null) {
            throw new ComparisonCommentaryException('AI_INVALID_JSON', 'AIの応答をJSONとして解釈できませんでした。');
        }

        $usage = $response['usage'] ?? [];

        return [
            'decoded' => $decoded,
            'usage_input_tokens' => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            'usage_output_tokens' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
        ];
    }

    /**
     * 応答の文字から、JSONオブジェクトを取り出す(前後の説明・コードフェンスがあっても受ける)。
     *
     * @return ?array<string, mixed>
     */
    private function decode(string $text): ?array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $apiKey, array $body): array
    {
        $baseUrl = rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com'), '/');
        $timeout = (int) config('top_message_panel.timeout', 90);
        $maxRetries = (int) config('services.brand_wheel_ai.max_retries', 1);

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
                ])
                    ->acceptJson()
                    ->timeout($timeout)
                    ->post($baseUrl.'/v1/messages', $body);
            } catch (ConnectionException $e) {
                $lastException = $e;

                continue;
            }

            if (! $response->successful()) {
                $errorType = $response->json('error.type');
                Log::warning('Top message panel API failed', [
                    'http_status' => $response->status(),
                    'error_type' => is_string($errorType) ? $errorType : null,
                ]);
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new ComparisonCommentaryException('AI_AUTH_FAILED', 'Anthropic APIの認証に失敗しました。');
            }

            if ($response->status() === 429) {
                throw new ComparisonCommentaryException('AI_RATE_LIMITED', 'Anthropic APIのレート制限に達しました。');
            }

            if (in_array($response->status(), [408, 504, 529], true)) {
                if ($attempt <= $maxRetries) {
                    continue;
                }

                throw new ComparisonCommentaryException('AI_TIMEOUT', 'Anthropic APIの呼び出しがタイムアウトしました。');
            }

            if (! $response->successful()) {
                throw new ComparisonCommentaryException('AI_REQUEST_FAILED', 'Anthropic APIの呼び出しに失敗しました(HTTP '.$response->status().')。');
            }

            return $response->json() ?? [];
        }

        throw new ComparisonCommentaryException('AI_UNAVAILABLE', 'Anthropic APIに接続できませんでした。', $lastException);
    }
}

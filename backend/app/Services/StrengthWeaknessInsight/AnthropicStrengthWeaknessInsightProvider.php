<?php

namespace App\Services\StrengthWeaknessInsight;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 依頼DB-1・DB-3: Claude(Anthropic Messages API)に、強み・弱みの下書き(根拠つきの2行)を書かせる(プロンプト strength_weakness_insight、1社1回)。
 *
 * 呼び出し先・モデル・タイムアウト・再試行・出力の上限は config('strength_weakness_insight')と config('services.anthropic')。
 * モデル名・APIキーは env だけで渡す(コードに既定値を持たない)。temperature は config がnullのときは送らない
 * (Claude Sonnet 5.5 など新しいモデルは、既定以外の値を送ると400になる)。APIキーは、ログ・例外の文言に出さない。
 * Laravel の Http クライアントだけを使う(SDKは足さない)。
 *
 * プロンプトに文の例は書かない(観点だけ)。AIがプロンプトを守らなくてもよいように、書かれた文は必ず
 * StrengthWeaknessInsightVerifier が材料と突き合わせる(プロンプトは確認の代わりではない)。
 */
class AnthropicStrengthWeaknessInsightProvider implements StrengthWeaknessInsightProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): ?string
    {
        $model = (string) config('strength_weakness_insight.model');

        return $model !== '' ? $model : null;
    }

    public function promptVersion(): string
    {
        return (string) config('strength_weakness_insight.prompt_version', 'v1');
    }

    public function write(array $material): array
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new ComparisonCommentaryException('ANTHROPIC_NOT_CONFIGURED', 'ANTHROPIC_API_KEYが設定されていません。');
        }
        $model = $this->model();
        if ($model === null) {
            throw new ComparisonCommentaryException('MODEL_NOT_CONFIGURED', 'STRENGTH_WEAKNESS_INSIGHT_MODELが設定されていません。');
        }

        $response = $this->request($apiKey, $this->buildRequestBody($model, $this->buildPrompt($material)));

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
     * リクエストの本体(モデル・出力の上限・温度は config から)。温度は config がnullなら含めない。
     *
     * @return array<string, mixed>
     */
    public function buildRequestBody(string $model, string $prompt): array
    {
        $body = [
            'model' => $model,
            'max_tokens' => (int) config('strength_weakness_insight.max_output_tokens'),
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        $temperature = config('strength_weakness_insight.temperature');
        if ($temperature !== null) {
            $body['temperature'] = (float) $temperature;
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $material
     */
    public function buildPrompt(array $material): string
    {
        $max = (int) config('strength_weakness_insight.text_max_chars');
        $schema = json_encode($this->outputShape(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $companies = '自社: '.$material['self_name']."\n競合: ".implode('、', array_column((array) $material['competitors'], 'name'));

        $scores = '';
        foreach ((array) $material['axis_scores'] as $row) {
            $scores .= "- {$row['axis']}: 自社 {$row['self']}/{$row['denominator']}";
            foreach ((array) $material['competitors'] as $i => $competitor) {
                $scores .= "、{$competitor['name']} ".($row['competitors'][$i] ?? 0)."/{$row['denominator']}";
            }
            $scores .= "\n";
        }

        $judgments = '';
        foreach ((array) $material['items'] as $item) {
            $judgments .= "- {$item['name']}({$item['axis']}): {$item['definition']} / 自社 ".($item['self'] ? '○' : '×');
            foreach ((array) $material['competitors'] as $i => $competitor) {
                $judgments .= "、{$competitor['name']} ".(($item['competitors'][$i] ?? false) ? '○' : '×');
            }
            $judgments .= "\n";
        }

        $selfEvidence = '';
        foreach ((array) $material['self_evidence'] as $row) {
            $selfEvidence .= "- {$row['name']}: {$row['text']}\n";
        }
        $competitorEvidence = '';
        foreach ((array) $material['competitor_evidence'] as $row) {
            $competitorEvidence .= "- {$row['name']} / {$row['company']}: {$row['text']}\n";
        }

        $structure = $this->describeStructure($material['structure'] ?? null);

        return <<<PROMPT
あなたは、採用サイトを比較して所見を書く、採用ブランディングの専門家です。
下の「材料」(各社の採用サイトを機械的に判定した結果と、サイトに書かれている文)をもとに、求職者(転職・就職を考えている人)の目線で、競合と比べた{$material['self_name']}の採用サイトの強み1つと弱み1つを書いてください。

書き方:
- 点数を言い直すのではなく、サイトに書いてある具体的な言葉・ページの配置を根拠にしてください。根拠の言葉は、材料の中の文字をそのまま「」で引いてください(言い換えた言葉を「」に入れない)。
- 強み: 自社だけ、または自社が特にはっきり伝えていることを、具体的な言葉で書いてください。求職者にどう伝わるかの解釈を添えてかまいません。
- 弱み: 競合が伝えていて自社からは読み取れないこと、または自社の伝え方の弱さを書いてください。「無い」「書いていない」と断定せず、「読み取れない」「見当たらない」のように書いてください。
- 解釈(「〜に見える」「〜には判断材料になる」のような書き方)は書いてかまいません。ただし断定しないでください。
- 強み・弱みそれぞれ、全角{$max}字以内で、2行ほどにおさまる長さにしてください。
- 会社名は、材料にある会社だけを使ってください。数字は、材料にある数字だけを使ってください。
- 強すぎる評価の言葉(相手を強く非難する言葉)は使わないでください。
- 根拠に引いた言葉は、会社名と一緒に quotes に入れてください(text の「」の中の言葉は、すべて quotes にあること)。

出力は、次の形のJSONオブジェクトだけにしてください(説明文や前後のテキストは不要):
{$schema}

--- ここから材料 ---
【会社】
{$companies}

【領域別の点数】
{$scores}
【24項目の判定(○=読み取れた)】
{$judgments}
【自社の根拠の文(自社が○の項目)】
{$selfEvidence}
【競合の根拠の文(自社が×で、競合が○の項目)】
{$competitorEvidence}
【自社の採用TOPの構造(上から順)】
{$structure}--- ここまで材料 ---
PROMPT;
    }

    /**
     * 出力の形(説明用。文の例は含めない)。
     *
     * @return array<string, mixed>
     */
    public function outputShape(): array
    {
        return [
            'strength' => ['text' => '(強みの文)', 'quotes' => [['company' => '(会社名)', 'text' => '(材料からそのまま引いた言葉)']]],
            'weakness' => ['text' => '(弱みの文)', 'quotes' => [['company' => '(会社名)', 'text' => '(材料からそのまま引いた言葉)']]],
        ];
    }

    /**
     * @param  ?array<string, mixed>  $structure  PageStructureExtractor::extract()の戻り値(確認用の項目を除いたもの)
     */
    private function describeStructure(?array $structure): string
    {
        if ($structure === null) {
            return "(取得できませんでした)\n";
        }

        $lines = [];
        $nav = (array) ($structure['nav'] ?? []);
        $lines[] = 'グローバルナビ('.count($nav).'項目): '.($nav === [] ? '(なし)' : implode(' / ', array_map(fn (array $item, int $i) => ($i + 1).'.'.$item['text'], $nav, array_keys($nav))));
        $hero = (array) ($structure['hero'] ?? []);
        $lines[] = 'メインビジュアル: '.($hero === [] ? '(見つからない)' : implode(' / ', array_column($hero, 'text')));
        foreach ((array) ($structure['sections'] ?? []) as $i => $section) {
            $items = (array) ($section['items'] ?? []);
            $lines[] = 'セクション'.($i + 1).'「'.($section['heading'] !== '' ? $section['heading'] : '(見出しなし)').'」(項目'.(int) ($section['item_count'] ?? count($items)).'): '
                .implode(' / ', array_map(fn (array $item, int $k) => ($k + 1).'.'.$item['text'], $items, array_keys($items)));
        }
        $footer = $structure['footer'] ?? null;
        if (is_array($footer)) {
            $lines[] = 'フッター: '.$footer['text'];
        }
        $recruit = $structure['recruit_link'] ?? null;
        if (is_array($recruit)) {
            $lines[] = '採用への導線: '.json_encode($recruit, JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $lines)."\n";
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
        $timeout = (int) config('strength_weakness_insight.timeout', 120);
        $maxRetries = (int) config('strength_weakness_insight.max_retries', 1);

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

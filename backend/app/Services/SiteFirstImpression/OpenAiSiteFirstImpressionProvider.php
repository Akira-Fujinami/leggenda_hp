<?php

namespace App\Services\SiteFirstImpression;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 依頼CX-2: ページの画面の画像(最初に見える範囲・ページ全体。あれば)と、構造(IDつき)から、求職者目線の第一印象
 * (良い点・悪い点)を1回の呼び出しで書くAI呼び出し(プロンプト site_first_impression)。解釈を含んでよい。
 * 根拠として挙げた事実(構造のID・その要素の言葉)は、必ずSiteFirstImpressionVerifierが構造と突き合わせる。
 *
 * 呼び出し先(プロバイダ・モデル・温度)は既存のconfig('services.brand_wheel_ai')を使う(温度は0)。画像はimage_urlのdata URLで
 * 渡す(精細さはconfig)。判定プロンプト(v9)・改善提案(v14)・依頼CU/CVのプロンプトとは別の、独立したプロンプト。
 * 版は config('site_first_impression.prompt_version')。プロンプト本文を変えたら上げる。プロンプトに文の例は書かない
 * (依頼CWの反省: 例文がそのまま出力に使われるため)。見る観点だけを書く。
 */
class OpenAiSiteFirstImpressionProvider implements SiteFirstImpressionProvider
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
        return (string) config('site_first_impression.prompt_version', 'v1');
    }

    public function write(array $material): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new ComparisonCommentaryException('OPENAI_NOT_CONFIGURED', 'OPENAI_API_KEYが設定されていません。');
        }

        $response = $this->request($apiKey, $this->model(), $this->buildContent($material));

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
     * メッセージの内容: 画像(あれば)→ プロンプト本文。
     *
     * @param  array<string, mixed>  $material
     * @return list<array<string, mixed>>
     */
    public function buildContent(array $material): array
    {
        $parts = [];
        foreach ((array) ($material['images'] ?? []) as $image) {
            $parts[] = ['type' => 'text', 'text' => '画像: '.$image['label']];
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$image['mime'].';base64,'.$image['base64'], 'detail' => $image['detail']]];
        }
        $parts[] = ['type' => 'text', 'text' => $this->buildPrompt($material)];

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $material
     */
    public function buildPrompt(array $material): string
    {
        $min = (int) config('site_first_impression.points_min', 2);
        $max = (int) config('site_first_impression.points_max', 4);
        $titleMax = (int) config('site_first_impression.title_max_chars', 25);
        $bodyMax = (int) config('site_first_impression.body_max_chars', 120);
        $forbidden = implode('」「', array_merge((array) config('comparison_commentary.forbidden_words', []), (array) config('site_first_impression.extra_forbidden_words', [])));
        $hasImages = ! empty($material['images']);

        $structure = '';
        foreach ((array) $material['pages'] as $page) {
            $structure .= $this->describePage($page);
        }
        $imageNote = $hasImages
            ? "画像は、各ページの「最初に見える範囲」と「ページ全体」の画面です。見た目・配置・目立つものの判断に使ってください。\n"
            : "画像はありません。構造の情報だけで判断してください。\n";
        $schema = json_encode($this->buildResponseSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
あなたは、企業の採用サイトを、求職者(転職・就職を考えている人)の目線で見て、第一印象をまとめるアシスタントです。
{$material['company_name']}のコーポレートTOPと採用TOPを見て、求職者が受ける第一印象の「良いイメージ(good)」と「悪いイメージ(bad)」を書いてください。社内の営業資料の下書きに使います。

{$imageNote}
見るところ:
- 何を大事にしている会社か、伝わるか
- 採用への導線(採用情報への行きやすさ)
- 働く人の顔が見えるか(社員の声・仕事内容)
- 制度・働き方が見えるか
- 最初に目に入るもの(メインビジュアル)
- 求職者に関係ない情報の多さ

書き方:
- good・bad とも{$min}〜{$max}件。各点は、title({$titleMax}字以内の見出し)と body({$bodyMax}字以内の本文)と、根拠(evidence)。
- 各点に、根拠になる要素を1つ以上挙げる。evidence の id は下の「構造」にあるIDで、text は、そのIDの要素の言葉を一字一句そのまま抜き出したもの。
- 配置について書くときは、構造の情報(順番・項目数・採用への導線)に基づいて書く。数字は、構造に書かれている順番・項目数、または根拠の言葉にある数字だけを使う。
- 「ページに無い」と書くのは、構造を見て無いことが分かるものだけ。その場合は「確認できる範囲では」と添える。
- 求職者にどう見えるかという解釈は書いてよい。ただし断定しない。
- body の中で、サイトの言葉を引用するときは「」で囲み、その言葉は evidence の text と同じ文字にする。「」で囲んでよいのは evidence の text だけ。
- 企業名を書くときは、{$material['company_name']}だけにする。
- 次の言葉は使わない: 「{$forbidden}」。強い評価の言葉も使わない。

出力は、次のJSON Schemaに従うJSONオブジェクトだけにしてください(説明文や前後のテキストは不要):
{$schema}

--- ここから構造 ---
{$structure}--- ここまで構造 ---
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function describePage(array $page): string
    {
        $s = $page['structure'];
        $out = "■ {$page['label']}({$page['url']})\n";

        $out .= 'グローバルナビ('.count($s['nav'])."件、上から順):\n";
        foreach ($s['nav'] as $item) {
            $out .= "  {$item['id']}: {$item['text']}\n";
        }
        if ($s['nav'] === []) {
            $out .= "  (見つからなかった)\n";
        }

        $out .= "メインビジュアル(最初に見える範囲の要素):\n";
        foreach ($s['hero'] as $item) {
            $out .= "  {$item['id']} [{$item['kind']}]: {$item['text']}\n";
        }
        if ($s['hero'] === []) {
            $out .= "  (見つからなかった)\n";
        }

        $out .= 'セクション('.count($s['sections'])."件、上から順):\n";
        foreach ($s['sections'] as $i => $section) {
            $heading = $section['heading'] === '' ? '(見出しなし)' : $section['heading'];
            $out .= '  '.($i + 1)."番目 {$section['id']} 見出し: {$heading} / 項目数: {$section['item_count']}\n";
            foreach ($section['items'] as $item) {
                $out .= "    {$item['id']}: {$item['text']}\n";
            }
        }

        $out .= $s['footer'] === null ? "フッター: (見つからなかった)\n" : "フッター: {$s['footer']['id']}: {$s['footer']['text']}\n";

        if (is_array($s['recruit_link'])) {
            $link = $s['recruit_link'];
            $out .= '採用ページ(起点)へのリンク: '.match ($link['where']) {
                'nav' => "グローバルナビにある({$link['id']}、{$link['ordinal']}番目)",
                'section' => "セクション内にある({$link['id']}、項目{$link['ordinal']}番目)",
                'footer' => "フッターにある({$link['id']})",
                default => 'グローバルナビ・セクション・フッターのいずれにも見つからなかった',
            }."\n";
        }

        return $out."\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function buildResponseSchema(): array
    {
        $point = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'evidence' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'string'], 'text' => ['type' => 'string']],
                        'required' => ['id', 'text'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'body', 'evidence'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => ['good' => ['type' => 'array', 'items' => $point], 'bad' => ['type' => 'array', 'items' => $point]],
            'required' => ['good', 'bad'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function request(string $apiKey, string $model, array $content): array
    {
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('site_first_impression.timeout', 120);
        $maxRetries = (int) config('services.brand_wheel_ai.max_retries', 1);
        $maxOutputTokens = (int) config('site_first_impression.max_output_tokens', 2500);
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
                            ['role' => 'user', 'content' => $content],
                        ],
                        'response_format' => [
                            'type' => 'json_schema',
                            'json_schema' => [
                                'name' => 'site_first_impression',
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

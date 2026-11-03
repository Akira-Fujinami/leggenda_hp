<?php

namespace App\Services\CompanyProfile;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 依頼DK: Claude(Anthropic Messages API)に、Web検索(サーバー側のツール web_search)を使わせて、会社の理解のページの下書きを
 * JSONで書かせる。事実には必ず出典のURLと原文の一節を付けさせ、サーバーが確かめる(CompanyProfileVerifier)。
 *
 * モデル名は env(COMPANY_PROFILE_MODEL)だけ(コードに既定値を持たない)。温度は送らない。応答が pause_turn(検索の途中で止まった)の
 * ときは、そこまでの応答をそのまま渡して続きを頼む(config max_continuations 回まで)。
 * 失敗したときは、状態コードとエラーの種類(error.type)だけをログに残す(本文・キーは出さない)。
 */
class AnthropicCompanyProfileProvider implements CompanyProfileProvider
{
    public function model(): ?string
    {
        $model = (string) config('company_profile.model');

        return $model !== '' ? $model : null;
    }

    public function draft(array $material): array
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new ComparisonCommentaryException('ANTHROPIC_NOT_CONFIGURED', 'ANTHROPIC_API_KEYが設定されていません。');
        }
        $model = $this->model();
        if ($model === null) {
            throw new ComparisonCommentaryException('MODEL_NOT_CONFIGURED', 'COMPANY_PROFILE_MODELが設定されていません。');
        }

        $messages = [['role' => 'user', 'content' => $this->buildPrompt($material)]];
        $content = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $searches = 0;

        for ($turn = 0; $turn <= (int) config('company_profile.max_continuations', 2); $turn++) {
            $response = $this->request($apiKey, [
                'model' => $model,
                'max_tokens' => (int) config('company_profile.max_output_tokens'),
                'tools' => [[
                    'type' => 'web_search_20250305',
                    'name' => 'web_search',
                    'max_uses' => (int) config('company_profile.web_search_max_uses'),
                ]],
                'messages' => $messages,
            ]);
            $blocks = (array) ($response['content'] ?? []);
            $content = array_merge($content, $blocks);
            $inputTokens += (int) ($response['usage']['input_tokens'] ?? 0);
            $outputTokens += (int) ($response['usage']['output_tokens'] ?? 0);
            $searches += (int) ($response['usage']['server_tool_use']['web_search_requests'] ?? 0);

            if (($response['stop_reason'] ?? null) !== 'pause_turn') {
                break;
            }
            $messages = [$messages[0], ['role' => 'assistant', 'content' => $blocks]];
        }

        // JSONは最後のtextのブロック群から取る(検索の合間の文は使わない)。
        $text = '';
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        $decoded = $this->decode($text);
        if ($decoded === null) {
            throw new ComparisonCommentaryException('AI_INVALID_JSON', 'AIの応答をJSONとして解釈できませんでした。');
        }

        return [
            'decoded' => $decoded,
            'web_searches' => $searches,
            'usage_input_tokens' => $inputTokens,
            'usage_output_tokens' => $outputTokens,
        ];
    }

    /**
     * @param  array<string, mixed>  $material
     */
    public function buildPrompt(array $material): string
    {
        $pillars = (int) config('company_profile.pillars_max');
        $lines = [];
        $lines[] = 'あなたは、採用支援の会社の担当者として、お客様の会社(または競合の会社)を理解するための下書きを作ります。';
        $lines[] = '対象の会社: '.$material['company_name'];
        $lines[] = '採用サイト: '.$material['site_url'];
        $lines[] = '';
        $lines[] = '# 調べ方';
        $lines[] = '- web_search で、この会社の公式サイト(会社概要・トップメッセージ・事業紹介)、IR資料のHTMLページ、ニュースリリース、受賞の主催者の発表を調べてください。';
        $lines[] = '- 同じ名前の別の会社と取り違えないでください。上の採用サイトと同じ会社の情報だけを使ってください。';
        $lines[] = '- PDFのページは出典にしないでください(サーバーが中身を確かめられないため)。HTMLのページを出典にしてください。';
        $lines[] = '';
        $lines[] = '# 書くもの';
        $lines[] = '1. headline: 会社が掲げている言葉(トップメッセージの見出し・スローガン)。下の「トップメッセージの文」があれば、その中の1つの文か、その文の一部をそのまま抜き出し、sentence_id を付けてください。無ければ、会社のサイトの原文からそのまま抜き出し、source_url と quote を付けてください。'.config('company_profile.headline_min_chars').'〜'.config('company_profile.headline_max_chars').'字。';
        $lines[] = '2. lead: 会社の成り立ちと掲げていることを、'.config('company_profile.lead_max_chars').'字以内の1文で要約。数字(年数・人数など)を書くときは、トップメッセージの文か、下の事実の quote にあるものだけ。';
        $lines[] = '3. pillars: この会社を表す柱を'.$pillars.'つ。それぞれ:';
        $lines[] = '   - title: その柱を、私たちの理解として言い表した句('.config('company_profile.pillar_title_max_chars').'字以内)。数字を入れるときは、その柱の事実の quote にあるものだけ。';
        $lines[] = '   - value(大切にしていること): 会社自身のサイトに書かれている、その柱で会社が大切にしている考え・やり方。';
        $lines[] = '   - achievement(実績): 年数・件数・人数・代表的な案件など、事実で示せる実績。';
        $lines[] = '   - recognition(外からの評価): 受賞・認定・外部との提携など、会社の外から与えられた評価。';
        $lines[] = '   value・achievement・recognition は、それぞれ text('.config('company_profile.fact_max_chars').'字以内)、source_url(そのページのURL)、quote(そのページの原文をそのまま写した一節。'.config('company_profile.quote_min_chars').'〜'.config('company_profile.quote_max_chars').'字。言い換え・省略・つなぎ合わせをしない)を付けてください。';
        $lines[] = '   - text に書く数字・固有の名前(賞の名前・案件の名前など)は、すべて quote の中にあるものだけにしてください。';
        $lines[] = '   - value の source_url は、会社自身のサイトにしてください。';
        $lines[] = '   - 確かな出典が見つからない項目は null にしてください。推測で埋めないでください。';
        $lines[] = '';
        $lines[] = '# トップメッセージの文(ID付き。'.(($material['message_title'] ?? null) !== null ? '出典: '.$material['message_title'] : '無し').')';
        foreach ((array) $material['sentences'] as $sentence) {
            $lines[] = $sentence['id'].': '.$sentence['text'];
        }
        if ($material['sentences'] === []) {
            $lines[] = '(採用サイトの巡回では、トップメッセージのページを確認できませんでした)';
        }
        $lines[] = '';
        $lines[] = '# 出力';
        $lines[] = '調べ終えたら、最後に次の形のJSONだけを出力してください(前後に説明を書かない)。';
        $lines[] = '{"headline": {"text": string, "sentence_id": string|null, "source_url": string|null, "quote": string|null} | null,';
        $lines[] = ' "lead": string | null,';
        $lines[] = ' "pillars": [{"title": string, "value": FACT|null, "achievement": FACT|null, "recognition": FACT|null}]}';
        $lines[] = 'FACT = {"text": string, "source_url": string, "quote": string}';

        return implode("\n", $lines);
    }

    /**
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

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
            ])
                ->acceptJson()
                ->timeout((int) config('company_profile.timeout', 180))
                ->post($baseUrl.'/v1/messages', $body);
        } catch (ConnectionException $e) {
            throw new ComparisonCommentaryException('AI_UNAVAILABLE', 'Anthropic APIに接続できませんでした。', $e);
        }

        if (! $response->successful()) {
            $errorType = $response->json('error.type');
            Log::warning('Company profile API failed', [
                'http_status' => $response->status(),
                'error_type' => is_string($errorType) ? $errorType : null,
            ]);
            $code = match (true) {
                in_array($response->status(), [401, 403], true) => 'AI_AUTH_FAILED',
                $response->status() === 429 => 'AI_RATE_LIMITED',
                in_array($response->status(), [408, 504, 529], true) => 'AI_TIMEOUT',
                default => 'AI_REQUEST_FAILED',
            };
            throw new ComparisonCommentaryException($code, 'Anthropic APIの呼び出しに失敗しました(HTTP '.$response->status().')。');
        }

        return $response->json() ?? [];
    }
}

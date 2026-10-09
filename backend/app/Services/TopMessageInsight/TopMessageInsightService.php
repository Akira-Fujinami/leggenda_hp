<?php

namespace App\Services\TopMessageInsight;

use App\Models\WebsiteAnalysis;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CQ: 会社(WebsiteAnalysis)ごとに、トップメッセージ × 人事制度の結果を作って
 * 保存する。流れ: ページを見つける(AIなし) → AIで取り出す → サーバー側で原文と突き合わせる
 * → ファイルに置く。
 *
 * - 結果が既にあれば、AIを呼び直さない(force=trueを除く)。同じ比較でPPTXを何度
 *   ダウンロードしても、AIは呼ばれない(PPTXを作る側はファイルを読むだけ)。
 * - メッセージのページが無い/確認で足りなくなった場合は、status=not_createdのファイルを
 *   置く(理由つき)。AIの呼び出し自体が失敗した場合(通信・認証・レート制限・JSON不正)は
 *   ファイルを置かず例外を投げる ―― 一時的な失敗を「作らなかった」と確定させない。
 * - ログには件数だけを出す(本文・メッセージの文章は出さない)。
 */
class TopMessageInsightService
{
    /** 次の実行でやり直してよい失敗の理由。 */
    private const RETRYABLE_REASONS = ['ai_error', 'provider_unavailable'];

    public function __construct(
        private readonly TopMessagePageFinder $finder,
        private readonly TopMessageInsightVerifier $verifier,
        private readonly TopMessageInsightStore $store,
    ) {}

    /**
     * @return array<string, mixed> 置いた(または既にあった)結果
     *
     * @throws TopMessageInsightException AI呼び出しが失敗したとき
     */
    public function generate(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
    {
        $analysisId = (int) $websiteAnalysis->analysis_id;
        $websiteAnalysisId = (int) $websiteAnalysis->id;

        if (! $force) {
            $existing = $this->store->read($analysisId, $websiteAnalysisId);
            if ($existing !== null && $this->isFinal($existing)) {
                return $existing;
            }
        }

        $selection = $this->finder->find($websiteAnalysis);

        if ($selection->message === null) {
            return $this->save($websiteAnalysis, [
                'status' => 'not_created',
                'reason' => 'no_message_page',
                'message_candidate_count' => $selection->messageCandidateCount,
                'program_page_count' => count($selection->programPages),
                'discarded' => [],
            ]);
        }

        // 制度のページが1つも無ければ、制度は取り出せない(依頼CQ追補 CQA-3: 制度の出どころは制度のページだけ)。
        // AIは呼ばない。
        if ($selection->programPages === []) {
            return $this->save($websiteAnalysis, [
                'status' => 'not_created',
                'reason' => 'no_program_pages',
                'message_candidate_count' => $selection->messageCandidateCount,
                'program_page_count' => 0,
                'discarded' => [],
            ]);
        }

        [$message, $programPages] = $this->applyBudget($selection->message, $selection->programPages);

        $provider = app(TopMessageInsightProviderFactory::class)->make();

        $started = microtime(true);
        $outcome = $provider->analyze($message, $programPages);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $verified = $this->verifier->verify($outcome['decoded'], $message, $programPages);

        $base = [
            'prompt_version' => $provider->promptVersion(),
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'message_candidate_count' => $selection->messageCandidateCount,
            'program_page_count' => count($programPages),
            'discarded' => $verified['discarded'],
            'usage_input_tokens' => $outcome['usage_input_tokens'],
            'usage_output_tokens' => $outcome['usage_output_tokens'],
            'duration_ms' => $durationMs,
        ];

        if (! $verified['ok']) {
            return $this->save($websiteAnalysis, ['status' => 'not_created', 'reason' => $verified['reason']] + $base);
        }

        return $this->save($websiteAnalysis, [
            'status' => 'created',
            'reason' => null,
            'quote' => $verified['quote'],
            'keywords' => $verified['keywords'],
            'sources' => $this->buildSources($message, $verified['keywords']),
        ] + $base);
    }

    /**
     * 結果が確定しているか。AIの呼び出し・設定の失敗(reason=ai_error/provider_unavailable)の記録は
     * 確定ではない ―― 次に同じ比較のジョブが走ったときにやり直してよい(比較の完了は待たせない)。
     *
     * @param  array<string, mixed>  $result
     */
    public function isFinal(array $result): bool
    {
        return ! in_array($result['reason'] ?? null, self::RETRYABLE_REASONS, true);
    }

    /**
     * AIの呼び出し・設定の失敗を、結果のファイル(not_created)として記録する。ページは作られない。
     * 比較の完了(TopMessageInsightGate)を待たせないための記録でもある。
     */
    public function recordFailure(WebsiteAnalysis $websiteAnalysis, string $reason, ?string $errorCode): void
    {
        $this->save($websiteAnalysis, [
            'status' => 'not_created',
            'reason' => $reason,
            'error_code' => $errorCode,
            'discarded' => [],
        ]);
    }

    /**
     * 合計の上限(config)の中で、メッセージのページ → 制度のページの順に本文を割り当てる。
     * 制度のページは、短いページが余らせた分を長いページへ回す(均等割り→不足分の再配分)。
     * 並びは渡された順(起点配下 → 文字数の多い順)のまま。
     *
     * @param  list<TopMessagePage>  $programPages
     * @return array{0: TopMessagePage, 1: list<TopMessagePage>}
     */
    public function applyBudget(TopMessagePage $message, array $programPages): array
    {
        $total = (int) config('top_message_insight.input_max_chars', 15000);
        $messageMax = min((int) config('top_message_insight.message_page_max_chars', 4000), $total);

        $message = $message->withText(mb_substr($message->text, 0, $messageMax));
        $remaining = max(0, $total - mb_strlen($message->text));

        $order = array_keys($programPages);
        usort($order, fn (int $a, int $b) => mb_strlen($programPages[$a]->text) <=> mb_strlen($programPages[$b]->text));

        $allotted = [];
        $left = count($order);
        foreach ($order as $index) {
            $take = min(mb_strlen($programPages[$index]->text), intdiv($remaining, max(1, $left)));
            $allotted[$index] = $take;
            $remaining -= $take;
            $left--;
        }

        $result = [];
        foreach ($programPages as $index => $page) {
            if ($allotted[$index] > 0) {
                $result[] = $page->withText(mb_substr($page->text, 0, $allotted[$index]));
            }
        }

        return [$message, $result];
    }

    /**
     * 使ったページ = メッセージのページ + 採用された制度の出どころ(重複なし)。
     *
     * @param  list<array{keyword: string, programs: list<array{source_url: string, source_title: ?string}>}>  $keywords
     * @return list<array{url: string, title: string}>
     */
    private function buildSources(TopMessagePage $message, array $keywords): array
    {
        $sources = [TopMessagePage::keyOf($message->url) => ['url' => $message->url, 'title' => $this->sourceLabel($message->url, $message->title)]];

        foreach ($keywords as $keyword) {
            foreach ($keyword['programs'] as $program) {
                $key = TopMessagePage::keyOf($program['source_url']);
                $sources[$key] ??= ['url' => $program['source_url'], 'title' => $this->sourceLabel($program['source_url'], $program['source_title'])];
            }
        }

        return array_values($sources);
    }

    /**
     * 出典に並べるページ名。ページのタイトルの、区切り(| ｜ - 等、階層図と同じ
     * config('admin_comparison_pptx.site_hierarchy_tree_title_separators'))より前の部分。
     * タイトルが無ければURLのパス。
     */
    private function sourceLabel(string $url, ?string $title): string
    {
        $title = trim((string) $title);

        if ($title !== '') {
            foreach ((array) config('admin_comparison_pptx.site_hierarchy_tree_title_separators', []) as $separator) {
                $pos = mb_strpos($title, (string) $separator);
                if ($pos !== false && $pos > 0) {
                    $title = trim(mb_substr($title, 0, $pos));
                }
            }
            if ($title !== '') {
                return $title;
            }
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return $path !== '' ? $path : $url;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function save(WebsiteAnalysis $websiteAnalysis, array $result): array
    {
        $result['generated_at'] = now()->toIso8601String();

        $this->store->write((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id, $result);

        // 件数だけ。本文・メッセージの文章・ページ名は出さない。
        Log::info('Top message insight saved', [
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => $result['status'],
            'reason' => $result['reason'] ?? null,
            'message_candidate_count' => $result['message_candidate_count'] ?? null,
            'program_page_count' => $result['program_page_count'] ?? null,
            'keyword_count' => count($result['keywords'] ?? []),
            'program_count' => array_sum(array_map(fn (array $k) => count($k['programs']), $result['keywords'] ?? [])),
            'discarded' => $result['discarded'] ?? [],
            'usage_input_tokens' => $result['usage_input_tokens'] ?? null,
            'usage_output_tokens' => $result['usage_output_tokens'] ?? null,
        ]);

        return $result;
    }
}

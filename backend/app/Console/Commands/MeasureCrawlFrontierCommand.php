<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SeedsAndCrawlsMeasurementSites;
use App\Models\AnalysisCrawledPage;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\CrawlFrontierPolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * 依頼DF: 巡回の順番(制度のページを先に・同じ親ディレクトリに上限・個人情報/規約は回らない)の
 * 変更前/変更後を、同じサイトで巡回し直して並べる(AIなし。判定は呼ばない)。
 * 変更前 = 3つの仕組みをconfigで切った状態、変更後 = 既定のconfig。
 * 巡回したページのURLとページ名・件数だけを出す(本文は出さない)。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('crawl-frontier:measure
    {--sites=cybozu,moneyforward,smarthr,freee,kayac : 対象サイトキー(カンマ区切り)}
    {--modes=before,after : 巡回する状態(before=3つの仕組みを切る / after=既定)}
    {--json= : 結果をJSONで書き出すパス}
')]
#[Description('非本番・開発専用: 巡回の順番と除外(依頼DF)の変更前/変更後を、同じサイトで並べて測る(AIなし)')]
class MeasureCrawlFrontierCommand extends Command
{
    use SeedsAndCrawlsMeasurementSites;

    public function handle(AnalysisPipeline $pipeline, CrawlFrontierPolicy $policy): int
    {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $sites = self::SITES;
        $keys = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('sites')))));
        foreach ($keys as $key) {
            if (! isset($sites[$key])) {
                $this->error("未知のサイトキー: {$key}");

                return self::FAILURE;
            }
        }
        $modes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('modes')))));

        Queue::fake();
        $result = [];

        foreach ($keys as $key) {
            foreach ($modes as $mode) {
                config($mode === 'before'
                    ? ['brand_wheel.crawl_priority_enabled' => false, 'brand_wheel.crawl_same_parent_soft_cap' => 0, 'brand_wheel.crawl_skip_enabled' => false]
                    : ['brand_wheel.crawl_priority_enabled' => true, 'brand_wheel.crawl_same_parent_soft_cap' => 5, 'brand_wheel.crawl_skip_enabled' => true]);

                $linkSkipped = 0;
                $linkPrioritized = 0;
                $completed = [];
                Log::listen(function ($e) use (&$linkSkipped, &$linkPrioritized, &$completed) {
                    if ($e->message === 'brand_wheel_crawl_links_ordered') {
                        $linkSkipped += (int) ($e->context['skipped'] ?? 0);
                        $linkPrioritized += (int) ($e->context['prioritized'] ?? 0);
                    }
                    if ($e->message === 'brand_wheel_crawl_completed') {
                        $completed = $e->context;
                    }
                });

                $this->info("=== {$sites[$key]['label']} ({$key}) / {$mode} ===");
                [$analysisId, $waId] = $this->seedWebsiteAnalysis($key, $sites[$key], true);
                $this->runCrawlChain($pipeline, $analysisId, $waId, false);

                $rows = AnalysisCrawledPage::query()->where('website_analysis_id', $waId)->orderBy('id')->get();
                $fetched = $rows->where('status', 'fetched')->values();

                $perParent = [];
                $priority = [];
                foreach ($fetched as $i => $row) {
                    $url = (string) ($row->final_url ?: $row->url);
                    $perParent[$policy->parentKey($url)] = ($perParent[$policy->parentKey($url)] ?? 0) + 1;
                    if ($policy->isPriority($url, (string) $row->title)) {
                        $priority[] = $url;
                    }
                    $this->line(sprintf('  %2d. d%d %s  | %s', $i + 1, $row->depth, $url, (string) $row->title));
                }
                arsort($perParent);

                // 回らなかった規約ページ: 取得せず除外した行(パスで当たったもの)＋リンクの文字で除いた件数。
                $skippedRows = $rows->where('status', 'excluded_by_pattern')->filter(fn ($r) => $policy->shouldSkip((string) $r->url, (string) $r->title))->pluck('url')->values()->all();
                $pending = $rows->where('status', 'pending');
                $deferred = [];
                foreach ($pending as $row) {
                    $k = $policy->parentKey((string) $row->url);
                    if (($perParent[$k] ?? 0) >= $policy->sameParentSoftCap() && $policy->sameParentSoftCap() > 0) {
                        $deferred[$k] = ($deferred[$k] ?? 0) + 1;
                    }
                }

                $this->line(sprintf('  fetched=%d pending=%d reason=%s  制度の言葉に当たる取得ページ=%d  先に回したリンク(件数)=%d  規約等でリンクから除いた=%d  パスで除外した行=%d',
                    $fetched->count(), $pending->count(), $completed['reason'] ?? '-', count($priority), $linkPrioritized, $linkSkipped, count($skippedRows)));
                $top = array_slice($perParent, 0, 5, true);
                $this->line('  親ディレクトリ別(上位5): '.json_encode($top, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('  後回し(上限到達の親ごとの未巡回件数): '.json_encode($deferred, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $result[$key][$mode] = [
                    'website_analysis_id' => $waId,
                    'fetched' => $fetched->map(fn ($r) => ['depth' => $r->depth, 'url' => $r->final_url ?: $r->url, 'title' => $r->title])->all(),
                    'pending' => $pending->count(),
                    'priority_pages' => $priority,
                    'per_parent' => $perParent,
                    'deferred_per_parent' => $deferred,
                    'link_skipped' => $linkSkipped,
                    'link_prioritized' => $linkPrioritized,
                    'path_excluded_rows' => $skippedRows,
                    'completed' => $completed,
                ];
            }
        }

        if ($this->option('json')) {
            file_put_contents((string) $this->option('json'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}

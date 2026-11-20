<?php

namespace App\Services\Analysis;

use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\WebsiteAnalysis;

/**
 * 依頼DS-1(2026-11-20): 自社と競合で、比べる材料の量がそろっていないときの注記。
 *
 * 本番(報知新聞)で、自社サイトがアクセスを制限していて巡回できず(入力したページだけ)、競合はページをたどって
 * 50ページ集めたまま比較していた。比較は出したまま(判定は変えない)、理由をレポートの表紙と結果の画面に出す。
 *
 *  - self_blocked / self_not_crawled: 自社サイトを巡回できなかった(取得済み0ページ)のに、競合は巡回できた
 *  - competitor_not_crawled:          競合サイトを巡回できなかった(アクセスの制限=依頼DRのときは出さない)のに、自社は巡回できた
 *  - origin_imbalance:                採用ページ(起点)の配下から取れたページの数が大きく違う
 *
 * 巡回しない診断(crawl_site=false)・競合が無い診断では出さない。保存済みの巡回の結果だけを見る(通信しない)。
 * 文言・閾値は config('brand_wheel.comparison_balance_*')。
 */
class ComparisonMaterialBalanceService
{
    public function __construct(
        private readonly CrawlOriginScopeResolver $originScopeResolver,
        private readonly RobotsAccessClassifier $robots,
    ) {}

    /**
     * @return ?array{code: string, message: string}
     */
    public function notice(Analysis $analysis): ?array
    {
        if (! (bool) config('brand_wheel.comparison_balance_enabled', true) || $analysis->crawl_site !== true) {
            return null;
        }

        $analysis->loadMissing('websiteAnalyses.website');
        $self = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        $competitor = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary);
        if ($self === null || $competitor === null) {
            return null;
        }

        // 競合がアクセスを制限していたとき(依頼DR)は、そちらの案内(自社サイトの結果のみ)を出す。
        if ($this->robots->isBlocked($competitor)) {
            return null;
        }

        $messages = (array) config('brand_wheel.comparison_balance_messages', []);
        $selfCounts = $this->counts($self);
        $competitorCounts = $this->counts($competitor);

        if ($selfCounts['fetched'] === 0 && $competitorCounts['fetched'] > 0) {
            $code = $this->robots->isBlocked($self) ? 'self_blocked' : 'self_not_crawled';

            return $this->make($code, $messages);
        }
        if ($competitorCounts['fetched'] === 0 && $selfCounts['fetched'] > 0) {
            return $this->make('competitor_not_crawled', $messages);
        }

        $low = min($selfCounts['within'], $competitorCounts['within']);
        $high = max($selfCounts['within'], $competitorCounts['within']);
        $minGap = (int) config('brand_wheel.comparison_balance_min_gap', 10);
        $maxRatio = (float) config('brand_wheel.comparison_balance_max_ratio', 0.25);
        if ($high > 0 && ($high - $low) >= $minGap && ($low / $high) < $maxRatio) {
            return $this->make('origin_imbalance', $messages, [$selfCounts['within'], $competitorCounts['within']]);
        }

        return null;
    }

    /**
     * 取得済みのページの数と、そのうち採用ページ(起点)の配下の数。
     *
     * @return array{fetched: int, within: int}
     */
    public function counts(WebsiteAnalysis $websiteAnalysis): array
    {
        $pages = AnalysisCrawledPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->get(['url', 'final_url']);

        $scope = $this->originScopeResolver->resolveScope($websiteAnalysis);
        $within = $scope === null ? 0 : $pages->filter(fn (AnalysisCrawledPage $p) => $this->originScopeResolver->isWithinScope((string) $p->url, $p->final_url, $scope))->count();

        return ['fetched' => $pages->count(), 'within' => $within];
    }

    /**
     * @param  array<string, mixed>  $messages
     * @param  list<int>  $args
     * @return ?array{code: string, message: string}
     */
    private function make(string $code, array $messages, array $args = []): ?array
    {
        $template = $messages[$code] ?? null;
        if (! is_string($template) || trim($template) === '') {
            return null;
        }

        return ['code' => $code, 'message' => $args === [] ? $template : sprintf($template, ...$args)];
    }
}

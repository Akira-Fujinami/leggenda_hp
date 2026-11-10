<?php

namespace App\Services\Lead;

use App\Models\Analysis;
use App\Models\LeadSession;
use App\Services\Analysis\RobotsAccessClassifier;
use Illuminate\Support\Facades\Log;

/**
 * 依頼DR: リードのセルフ診断で、競合サイトが「このシステムからのアクセスを拒否していた」(robots.txt の取得が 401・403・429)ため
 * 比較できなかったときの扱い。
 *  - 診断の回数を消費しない(消費済みなら1回戻す)。お客様は、別の競合サイトでもう一度診断できる
 *  - 結果の画面・レポートの表紙に、理由を出す(文言は config)
 * 通信しない(保存済みの取得結果だけを見る)。
 */
class CompetitorAccessService
{
    public function __construct(private readonly RobotsAccessClassifier $robots) {}

    public function competitorBlocked(Analysis $analysis): bool
    {
        if ($analysis->project?->lead_session_id === null) {
            return false;
        }
        $analysis->loadMissing('websiteAnalyses.website');
        $competitor = $analysis->websiteAnalyses->first(fn ($wa) => ! (bool) $wa->website?->is_primary);

        return $competitor !== null && $this->robots->isBlocked($competitor);
    }

    /**
     * 競合が拒否していたときは、消費した診断の回数を1回戻す(二重には戻さない)。戻したら true。
     */
    public function refundIfCompetitorBlocked(Analysis $analysis): bool
    {
        $analysis->loadMissing('project');
        if (! $this->competitorBlocked($analysis)) {
            return false;
        }

        $updated = Analysis::query()->whereKey($analysis->id)->whereNotNull('lead_quota_consumed_at')->update(['lead_quota_consumed_at' => null]);
        if ($updated === 0) {
            return false;
        }

        LeadSession::query()->whereKey($analysis->project->lead_session_id)->where('analyses_used', '>', 0)->decrement('analyses_used');
        Log::info('Lead analysis quota refunded: competitor blocked the crawler', ['analysis_id' => $analysis->id]);

        return true;
    }
}

<?php

namespace App\Services\Analysis;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;

/**
 * 依頼DR: robots.txt が読めず巡回しなかった(crawl_finished_reason=robots_txt_unavailable)ときの、読めなかった理由。
 * 保存済みの robots.txt の取得結果(analysis_pages の page_type=robots の http_status)だけを見る(通信しない)。
 *
 *  - blocked:      401・403・429。相手のサーバーが、このシステムからのアクセスを拒否している(ボット対策など)。URLを変えても解消しない
 *  - server_error: 5xx。相手のサーバーの一時的な不調の可能性がある
 *  - unreachable:  取得結果が無い(時間切れ・接続の失敗など)
 *  - null:         robots.txt が理由で巡回を止めていない
 */
class RobotsAccessClassifier
{
    public const BLOCKED = 'blocked';

    public const SERVER_ERROR = 'server_error';

    public const UNREACHABLE = 'unreachable';

    /**
     * @return array{class: ?string, http_status: ?int}
     */
    public function classify(WebsiteAnalysis $websiteAnalysis): array
    {
        if ($websiteAnalysis->crawl_finished_reason !== 'robots_txt_unavailable') {
            return ['class' => null, 'http_status' => null];
        }

        $status = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('page_type', PageType::Robots)
            ->value('http_status');
        $status = $status === null ? null : (int) $status;

        $class = match (true) {
            $status === null => self::UNREACHABLE,
            in_array($status, (array) config('crawl_diagnostics.robots_blocked_statuses', [401, 403, 429]), true) => self::BLOCKED,
            $status >= 500 => self::SERVER_ERROR,
            default => self::UNREACHABLE,
        };

        return ['class' => $class, 'http_status' => $status];
    }

    public function isBlocked(WebsiteAnalysis $websiteAnalysis): bool
    {
        return $this->classify($websiteAnalysis)['class'] === self::BLOCKED;
    }
}

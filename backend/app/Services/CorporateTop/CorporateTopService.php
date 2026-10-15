<?php

namespace App\Services\CorporateTop;

use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalyzerClient;
use App\Services\Analysis\CrawlOriginScopeResolver;
use App\Services\Analysis\SafeHttpFetcher;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CR-1: 階層図のTOPにするコーポレートサイトのTOPを決め、1ページだけ取得して保存する(AIは使わない)。
 *
 * 候補(次の順):
 *  1. 起点と同じホストの一番上(起点がすでに一番上なら無し)
 *  2. 起点のホストの先頭のラベルが採用を表す語(config)なら、それを外したホストと、www.を付けたホスト
 *     (「-recruit」で終わるラベル(mixigroup-recruitなど)は対象外。理由をメタ情報に残す)
 *
 * 確かめ方(ここが本体): 候補のページを取得し、そのページの中に、採用サイトの起点へのリンクがあるときだけ
 * コーポレートTOPとして採用する。見つからなければ次の候補へ。どれも通らなければ、採用サイトの起点を
 * TOPとして描く(いまの描き方)ままにする。
 *
 * 依頼CY-1: コーポレートTOPが決まったときだけ、既存の描画の仕組み(analyzer)で同じページを1回だけ描画して、描画済みの
 * HTMLも保存する(JavaScriptで描かれるメニューを読むため。階層図の他メニューの取得に使う)。描画に失敗しても、静的なHTMLだけで進める
 * (メタ情報の rendered に ok / failed を残す)。階層図やPPTXを作るときは通信しない(保存済みのファイルを読むだけ)。
 *
 * 取得は既存の安全な取得処理(SafeHttpFetcher)で、静的なHTMLだけ。巡回の50件には数えず、巡回の順・件数・
 * ブランド・ホイールの入力には影響しない。取得に失敗しても例外は出さない(いまの描き方になるだけ)。
 * ログには件数・結果の識別子だけを出す(ページの本文は出さない)。
 */
class CorporateTopService
{
    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
        private readonly CorporateTopAnalyzer $analyzer,
        private readonly CorporateTopStore $store,
        private readonly CrawlOriginScopeResolver $scopeResolver,
    ) {}

    /**
     * 候補のURLと、対象外にした理由。
     *
     * @return array{0: list<string>, 1: ?string}
     */
    public function candidates(string $originUrl): array
    {
        $parts = parse_url($originUrl);
        if (! isset($parts['scheme'], $parts['host'])) {
            return [[], 'no_origin'];
        }

        $scheme = $parts['scheme'];
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $isTop = $path === '' || $path === '/' || (bool) preg_match('#^/index\.(?:html?|php)$#i', $path);

        $candidates = [];
        if (! $isTop) {
            $candidates[] = "{$scheme}://{$host}{$port}/";
        }

        $labels = explode('.', $host);
        $first = $labels[0];
        $skipped = null;

        $recruitLabels = array_map('strtolower', (array) config('admin_comparison_pptx.corporate_top_recruit_host_labels', []));
        if (count($labels) >= 3 && in_array($first, $recruitLabels, true)) {
            $rest = implode('.', array_slice($labels, 1));
            $candidates[] = "{$scheme}://{$rest}{$port}/";
            if (! str_starts_with($rest, 'www.')) {
                $candidates[] = "{$scheme}://www.{$rest}{$port}/";
            }
        } elseif (count($labels) >= 3 && str_ends_with($first, '-recruit')) {
            // この依頼では対象外(報告だけする)。
            $skipped = 'hyphen_recruit_host_not_supported';
        }

        return [array_values(array_unique($candidates)), $skipped];
    }

    /**
     * 自社サイト(WebsiteAnalysis)について、コーポレートTOPを決めて保存する。保存済みなら何もしない
     * (force=trueを除く)。
     *
     * @return array<string, mixed> メタ情報
     */
    public function run(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
    {
        $analysisId = (int) $websiteAnalysis->analysis_id;
        $websiteAnalysisId = (int) $websiteAnalysis->id;

        if (! $force) {
            $existing = $this->store->readMeta($analysisId, $websiteAnalysisId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $scope = $this->scopeResolver->resolveScope($websiteAnalysis);
        $attempts = [];
        $skipped = null;
        $meta = ['status' => 'not_found'];
        $html = null;

        if ($scope === null) {
            $skipped = 'no_origin';
        } else {
            [$candidates, $skipped] = $this->candidates($scope['origin_url']);

            foreach ($candidates as $candidate) {
                $outcome = $this->tryCandidate($candidate, $scope);
                $attempts[] = ['candidate' => $candidate, 'outcome' => $outcome['outcome']];

                if ($outcome['found'] !== null) {
                    $found = $outcome['found'];
                    $html = $found['html'];
                    $meta = [
                        'status' => 'found',
                        'candidate' => $candidate,
                        'url' => $found['url'],
                        'recruit_label' => $found['label'],
                        'recruit_url' => $found['recruit_url'],
                    ];

                    break;
                }
            }
        }

        $rendered = null;
        if ($html !== null && (bool) config('admin_comparison_pptx.corporate_top_render_enabled', true)) {
            $rendered = $this->render((string) $meta['url']);
            $meta['rendered'] = $rendered !== null ? 'ok' : 'failed';
        }

        $meta += ['skipped' => $skipped, 'attempts' => $attempts, 'checked_at' => now()->toIso8601String()];
        $this->store->write($analysisId, $websiteAnalysisId, $meta, $html, $rendered);

        Log::info('Corporate top resolved', [
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysisId,
            'status' => $meta['status'],
            'rendered' => $meta['rendered'] ?? null,
            'attempt_count' => count($attempts),
            'outcomes' => array_column($attempts, 'outcome'),
            'skipped' => $skipped,
        ]);

        return $meta;
    }

    /**
     * 依頼CY-1: 決まったコーポレートTOPを描画してHTMLを返す。失敗したらnull(例外は出さない)。
     */
    private function render(string $url): ?string
    {
        try {
            $data = app(AnalyzerClient::class)->render($url);
            $html = (string) ($data['html'] ?? '');

            return $html !== '' ? $html : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 1つの候補を取得して確かめる。例外は出さない。
     *
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @return array{outcome: string, found: ?array{html: string, url: string, label: ?string, recruit_url: string}}
     */
    private function tryCandidate(string $candidate, array $scope): array
    {
        try {
            $result = $this->fetcher->fetch($candidate, ['text/html', 'application/xhtml+xml'], (int) config('admin_comparison_pptx.corporate_top_fetch_timeout_seconds', 15));
        } catch (\Throwable) {
            return ['outcome' => 'fetch_failed', 'found' => null];
        }

        if ($result->httpStatus < 200 || $result->httpStatus >= 300 || $result->body === '') {
            return ['outcome' => 'http_'.$result->httpStatus, 'found' => null];
        }

        $link = $this->analyzer->findRecruitLink($result->body, $result->finalUrl, $scope);
        if ($link === null) {
            return ['outcome' => 'no_recruit_link', 'found' => null];
        }

        return ['outcome' => 'ok', 'found' => ['html' => $result->body, 'url' => $result->finalUrl, 'label' => $link['label'], 'recruit_url' => $link['url']]];
    }
}

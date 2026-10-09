<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SeedsAndCrawlsMeasurementSites;
use App\Models\AnalysisCrawledPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Report\AdminComparisonPptxDataBuilder;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\TopMessageInsight\TopMessageInsightProvider;
use App\Services\TopMessageInsight\TopMessageInsightProviderFactory;
use App\Services\TopMessageInsight\TopMessageInsightService;
use App\Services\TopMessageInsight\TopMessagePage;
use App\Services\TopMessageInsight\TopMessagePageFinder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * 依頼CQ-5: トップメッセージ × 人事制度を、実際のサイトと実際のAIで測る。
 *
 * 既定はAIを呼ばない(ページの選定 = CQ-1/CQ-2の確認だけ)。AIを呼ぶには
 * 明示的に--call-aiを要求する。巡回は会社ごとに1回、AIの呼び出し回数は
 * --max-ai-callsを超えない(超える計画は、何も回さずに中止する)。AIのHTTP再試行は0にする
 * (1回の実行 = 1回のリクエスト)。
 *
 * 出力は件数とURLだけ ―― メッセージの文章・制度の文章・本文は標準出力にも
 * ログにも出さない(スライドの画像とJSONは--out-dirへ書き出す)。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('top-message:measure
    {--sites= : 対象サイトキーのカンマ区切り(既定: moneyforward,kayac,cybozu,smarthr,freee)}
    {--site-url=* : 追加のサイト。キー=URL(例 orbray=https://example.com/recruit/)}
    {--repeat= : 同じ巡回結果でAIを繰り返す回数。サイトキー:回数のカンマ区切り(例 moneyforward:2,cybozu:2)。既定は各1回}
    {--reuse= : 巡回し直さず、巡回済みのWebsiteAnalysisを使う。サイトキー:website_analysis_idのカンマ区切り}
    {--call-ai : 実際にOpenAIを呼ぶ(既定はAIを呼ばず、ページの選定だけを確認する)}
    {--max-ai-calls=8 : AIの呼び出し回数の上限。計画がこれを超えるなら何も回さず中止する}
    {--out-dir= : 各回の結果(JSON)とスライド(pptx)の書き出し先}
')]
#[Description('非本番・開発専用: トップメッセージ × 人事制度のページを、実際のサイトと実際のAIで測る(依頼CQ-5、既定はAIを呼ばない)')]
class MeasureTopMessageInsightCommand extends Command
{
    use SeedsAndCrawlsMeasurementSites;

    private const DEFAULT_SITES = ['moneyforward', 'kayac', 'cybozu', 'smarthr', 'freee'];

    public function handle(
        AnalysisPipeline $pipeline,
        TopMessagePageFinder $finder,
        TopMessageInsightService $service,
        AdminComparisonPptxDataBuilder $dataBuilder,
        AdminComparisonPptxGenerator $generator,
    ): int {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $sites = self::SITES;
        foreach ((array) $this->option('site-url') as $pair) {
            [$key, $url] = array_pad(explode('=', (string) $pair, 2), 2, '');
            if ($key === '' || ! preg_match('#^https?://#', $url)) {
                $this->error("--site-url は キー=URL の形で指定してください: {$pair}");

                return self::FAILURE;
            }
            $sites[$key] = ['label' => $key, 'homepage_url' => $url, 'recruit_url' => $url];
        }

        $keys = $this->option('sites') !== null
            ? array_values(array_filter(explode(',', (string) $this->option('sites'))))
            : self::DEFAULT_SITES;
        foreach ($keys as $key) {
            if (! isset($sites[$key])) {
                $this->error("未定義のサイトキーです: {$key}（定義済み: ".implode(',', array_keys($sites)).'）');

                return self::FAILURE;
            }
        }

        $repeats = $this->parsePairs((string) $this->option('repeat'));
        $reuse = $this->parsePairs((string) $this->option('reuse'));
        $callAi = (bool) $this->option('call-ai');
        $maxAiCalls = (int) $this->option('max-ai-calls');

        // 計画の段階で上限を確認する(回す前に相談できるよう、何も実行せず止める)。
        $planned = $callAi ? array_sum(array_map(fn (string $k) => max(1, (int) ($repeats[$k] ?? 1)), $keys)) : 0;
        if ($planned > $maxAiCalls) {
            $this->error("AIの呼び出し計画が{$planned}回で、上限{$maxAiCalls}回を超えます。何も実行せず中止しました。");

            return self::FAILURE;
        }

        $outDir = $this->option('out-dir') ? rtrim((string) $this->option('out-dir'), '/\\') : null;
        if ($outDir !== null && ! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        // 巡回の連鎖は各Jobのhandle()をこの場で直接呼んで再現するため、自己dispatchを実キューへ積まない。
        Queue::fake();

        $counter = new \ArrayObject(['calls' => 0]);
        if ($callAi) {
            // 1回の実行 = 1回のリクエスト。再試行で呼び出し回数が増えないようにする。
            config(['services.brand_wheel_ai.provider' => 'openai', 'services.brand_wheel_ai.max_retries' => 0]);
            app()->instance(TopMessageInsightProviderFactory::class, $this->countingFactory($counter));
        }

        $summary = [];
        $results = [];

        foreach ($keys as $key) {
            $site = $sites[$key];
            $this->info("=== {$site['label']} ({$key}) ===");

            if (isset($reuse[$key])) {
                $wa = WebsiteAnalysis::findOrFail((int) $reuse[$key]);
                $this->line("  reuse: website_analysis_id={$wa->id}(巡回し直さない)");
            } else {
                [$analysisId, $waId] = $this->seedWebsiteAnalysis($key, $site, true);
                $this->runCrawlChain($pipeline, $analysisId, $waId, true);
                $wa = WebsiteAnalysis::findOrFail($waId);
            }

            $fetched = AnalysisCrawledPage::query()->where('website_analysis_id', $wa->id)->where('status', 'fetched')->count();
            $selection = $finder->find($wa);
            $this->line(sprintf('  巡回: fetched=%d  website_analysis_id=%d', $fetched, $wa->id));
            $this->line(sprintf(
                '  CQ-1 メッセージのページ: %s  語に当たったページ=%d件 → 代表者の語・インタビュー除外後の候補=%d件  URL=%s',
                $selection->message !== null ? '見つかった' : '見つからない',
                $selection->messageKeywordMatchCount,
                $selection->messageCandidateCount,
                $selection->message?->url ?? '-',
            ));
            $this->line(sprintf(
                '  CQ-2 制度のページ: 採用%d件 / 候補%d件  %s',
                count($selection->programPages),
                $selection->programCandidateCount,
                implode(' ', array_map(fn (TopMessagePage $p) => '['.$p->url.']', $selection->programPages)),
            ));

            $row = [
                'site' => $key,
                'website_analysis_id' => $wa->id,
                'message_page_found' => $selection->message !== null,
                'message_page_url' => $selection->message?->url,
                'message_keyword_match_count' => $selection->messageKeywordMatchCount,
                'message_candidate_count' => $selection->messageCandidateCount,
                'program_page_count' => count($selection->programPages),
                'program_candidate_count' => $selection->programCandidateCount,
                'runs' => [],
            ];

            if ($callAi && $selection->message !== null) {
                $runCount = max(1, (int) ($repeats[$key] ?? 1));
                for ($run = 1; $run <= $runCount; $run++) {
                    if ($counter['calls'] >= $maxAiCalls) {
                        $this->error("AIの呼び出しが上限{$maxAiCalls}回に達したため中止します。");

                        return self::FAILURE;
                    }

                    $result = $service->generate($wa, force: true);
                    $row['runs'][] = $this->describeRun($run, $result);
                    $this->line($this->formatRun($run, $result));

                    if ($outDir !== null) {
                        file_put_contents("{$outDir}/{$key}_run{$run}.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $page = $dataBuilder->topMessagePageFromResult($site['label'], $result);
                        if ($page !== null) {
                            file_put_contents("{$outDir}/{$key}_run{$run}.pptx", $generator->generateTopMessageSlide($page));
                        }
                    }

                    $results[$key][$run] = $result;
                }

                if ($runCount >= 2 && isset($results[$key][1], $results[$key][2])) {
                    $row['repeat_comparison'] = $this->compareRuns($results[$key][1], $results[$key][2]);
                    $this->line('  1回目と2回目: '.json_encode($row['repeat_comparison'], JSON_UNESCAPED_UNICODE));
                }
            } elseif ($callAi) {
                $this->line('  メッセージのページが無いため、AIは呼ばない(ページは作らない)。');
            }

            $summary[] = $row;
        }

        $this->info(sprintf('AI呼び出し回数(合計): %d / 上限 %d', $counter['calls'], $maxAiCalls));

        if ($outDir !== null) {
            file_put_contents("{$outDir}/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("結果を書き出しました: {$outDir}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function describeRun(int $run, array $result): array
    {
        return [
            'run' => $run,
            'status' => $result['status'],
            'reason' => $result['reason'] ?? null,
            'keyword_count' => count($result['keywords'] ?? []),
            'program_count' => array_sum(array_map(fn (array $k) => count($k['programs']), $result['keywords'] ?? [])),
            'discarded' => $result['discarded'] ?? [],
            'usage_input_tokens' => $result['usage_input_tokens'] ?? null,
            'usage_output_tokens' => $result['usage_output_tokens'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function formatRun(int $run, array $result): string
    {
        $d = $this->describeRun($run, $result);
        $discarded = $d['discarded'] === [] ? 'なし' : implode(', ', array_map(fn ($r, $n) => "{$r}={$n}", array_keys($d['discarded']), $d['discarded']));

        return sprintf(
            '  AI %d回目: status=%s reason=%s キーワード=%d 制度=%d 捨てた件数=[%s] tokens(in/out)=%s/%s',
            $run, $d['status'], $d['reason'] ?? '-', $d['keyword_count'], $d['program_count'], $discarded,
            $d['usage_input_tokens'] ?? '-', $d['usage_output_tokens'] ?? '-',
        );
    }

    /**
     * 同じ入力で2回回したときの、キーワードと制度の一致(件数だけ。文章は出さない)。
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, int|bool>
     */
    private function compareRuns(array $a, array $b): array
    {
        $keywords = fn (array $r): array => array_map(fn (array $k) => $k['keyword'], $r['keywords'] ?? []);
        $programs = fn (array $r): array => collect($r['keywords'] ?? [])->flatMap(fn (array $k) => array_map(fn (array $p) => $p['name'], $k['programs']))->all();
        $pairs = fn (array $r): array => collect($r['keywords'] ?? [])->flatMap(fn (array $k) => array_map(fn (array $p) => $k['keyword'].'→'.$p['name'], $k['programs']))->all();

        return [
            'same_quote' => ($a['quote'] ?? null) === ($b['quote'] ?? null),
            'keywords_run1' => count($keywords($a)),
            'keywords_run2' => count($keywords($b)),
            'keywords_in_both' => count(array_intersect($keywords($a), $keywords($b))),
            'programs_run1' => count($programs($a)),
            'programs_run2' => count($programs($b)),
            'programs_in_both' => count(array_intersect($programs($a), $programs($b))),
            'keyword_program_pairs_in_both' => count(array_intersect($pairs($a), $pairs($b))),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parsePairs(string $value): array
    {
        $pairs = [];
        foreach (array_filter(explode(',', $value)) as $pair) {
            [$k, $v] = array_pad(explode(':', trim($pair), 2), 2, null);
            if ($k !== null && $v !== null) {
                $pairs[$k] = $v;
            }
        }

        return $pairs;
    }

    /**
     * 本物のプロバイダを包んで、analyze()の呼び出し回数を数える。
     */
    private function countingFactory(\ArrayObject $counter): TopMessageInsightProviderFactory
    {
        return new class($counter) extends TopMessageInsightProviderFactory
        {
            public function __construct(private readonly \ArrayObject $counter) {}

            public function make(): TopMessageInsightProvider
            {
                $real = parent::make();
                $counter = $this->counter;

                return new class($real, $counter) implements TopMessageInsightProvider
                {
                    public function __construct(private readonly TopMessageInsightProvider $real, private readonly \ArrayObject $counter) {}

                    public function name(): string
                    {
                        return $this->real->name();
                    }

                    public function model(): ?string
                    {
                        return $this->real->model();
                    }

                    public function promptVersion(): string
                    {
                        return $this->real->promptVersion();
                    }

                    public function analyze(TopMessagePage $messagePage, array $programPages): array
                    {
                        $this->counter['calls']++;

                        return $this->real->analyze($messagePage, $programPages);
                    }
                };
            }
        };
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\CrawlWebsiteJob;
use App\Jobs\Analysis\CrawlWebsitePageJob;
use App\Jobs\Analysis\RenderCrawledPageJob;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\AnalyzerClient;
use App\Services\Analysis\CrawlLinkExtractor;
use App\Services\Analysis\CrawlPolicyResolver;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\PageHtmlResolver;
use App\Services\Analysis\RobotsTxtParser;
use App\Services\Analysis\SafeHttpFetcher;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼F(2026-08-25)。依頼D-7/E-7で3回続けて使い捨てスクリプトを書いては
 * 消していたため、対象サイト・URLがラウンドごとにずれ、測定結果の比較が
 * 崩れる問題があった(依頼者指摘)。このコマンドを唯一の測定資産として
 * リポジトリに残し、以後はこれを使い回す。
 *
 * 既定はドライラン(AIを一切呼ばない) ―― BrandWheelAnalysisInputFactory::
 * build()はAI呼び出しを含まない純粋なテキスト処理のため、
 * input_truncated・文字数・重複除去件数は決定論的に(コストゼロ・ばらつき
 * なしで)測定できる(依頼F-1)。実際にAIを呼ぶにはproduction環境と同じ
 * 事故防止の考え方で明示的に--call-aiを要求する。
 *
 * このコマンドはbuild()のロジックには一切手を入れず、呼び出すだけである
 * (依頼F、禁止事項)。クロール統合の内訳(重複除去件数・クラスタ別プール数・
 * 予算超過で切り詰められた文字数)は、BrandWheelAnalysisInputFactoryが
 * 依頼Eで既に出しているLog::info/Log::warningをこのプロセス内でこの
 * website_analysis_id宛てのぶんだけ読み取って集計する(ロジックの変更ではなく
 * 既存ログの読み取りのみ)。
 *
 * 開発・検証専用。スケジューラ等、本番で実行されうる経路には一切登録しない。
 */
#[Signature('brand-wheel:measure-crawl-input
    {--sites= : 対象サイトキーのカンマ区切り(既定: 全サイト。self::SITES参照)}
    {--tokens=6000,8000,10000,12000 : 試すAI_MAX_INPUT_TOKENSのカンマ区切り}
    {--no-crawl : crawl_site=falseで実行する(baseline比較用)}
    {--no-render : クロールは行うが条件付きレンダリングを無効化する}
    {--call-ai : 実際にOpenAI(gpt-4o)を呼ぶ(既定はAIを呼ばないドライラン)}
    {--json= : 結果をJSONファイルへ書き出すパス(省略時は標準出力へJSON出力)}
    {--dump-text= : BrandWheelAnalysisInputFactory::build()が実際に組み立てた本文(recruitPageBodyText/homepagePageBodyText)を、由来ページの内訳とあわせてこのディレクトリへ書き出す(依頼H)。AIには渡さない。ドライランでも使用可}
')]
#[Description('非本番・開発専用: クロール統合後のBrandWheelAnalysisInputFactory入力を測定する(依頼F、既定はAIを呼ばないドライラン)')]
class MeasureBrandWheelCrawlInputCommand extends Command
{
    /**
     * 依頼D-7/E-7から引き継ぐ5サイトの固定定義。ラウンドを跨いでも同じURLで
     * 測定できるよう、ここに固定する(依頼者指摘 ―― 使い捨てスクリプトの
     * たびにURLを検索し直していたため、ラウンド間の比較が崩れていた)。
     * recruit_urlがhomepage_urlと同一のサイトは、採用ページとトップページが
     * 実際に同一URLである(自己参照)ことを表す。
     *
     * @var array<string, array{label: string, homepage_url: string, recruit_url: string}>
     */
    private const SITES = [
        'shinkin' => ['label' => 'しんきん', 'homepage_url' => 'https://www.shinkin.co.jp/ssc/recruit/index.html', 'recruit_url' => 'https://www.shinkin.co.jp/ssc/recruit/index.html'],
        'nttdata' => ['label' => 'NTTデータ', 'homepage_url' => 'https://www.nttdata.com/global/ja/recruit/', 'recruit_url' => 'https://www.nttdata.com/global/ja/recruit/'],
        'smarthr' => ['label' => 'SmartHR', 'homepage_url' => 'https://hello-world.smarthr.co.jp', 'recruit_url' => 'https://hello-world.smarthr.co.jp'],
        'kayac' => ['label' => 'カヤック', 'homepage_url' => 'https://www.kayac.com/recruit/fresh', 'recruit_url' => 'https://www.kayac.com/recruit/fresh'],
        'kilfebon' => ['label' => 'キルフェボン', 'homepage_url' => 'https://www.quil-fait-bon-recruit.com', 'recruit_url' => 'https://www.quil-fait-bon-recruit.com'],
    ];

    public function handle(AnalysisPipeline $pipeline, BrandWheelAnalysisInputFactory $inputFactory): int
    {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $siteKeys = $this->option('sites') !== null
            ? array_filter(explode(',', (string) $this->option('sites')))
            : array_keys(self::SITES);

        foreach ($siteKeys as $key) {
            if (! isset(self::SITES[$key])) {
                $this->error("未定義のサイトキーです: {$key}（定義済み: ".implode(',', array_keys(self::SITES)).'）');

                return self::FAILURE;
            }
        }

        $tokenBudgets = array_map('intval', array_filter(explode(',', (string) $this->option('tokens'))));
        $crawlEnabled = ! (bool) $this->option('no-crawl');
        $renderEnabled = $crawlEnabled && ! (bool) $this->option('no-render');
        $callAi = (bool) $this->option('call-ai');
        $dumpTextDir = $this->option('dump-text');
        if (! empty($dumpTextDir) && ! is_dir((string) $dumpTextDir)) {
            mkdir((string) $dumpTextDir, 0755, true);
        }

        // Queue::fake(): CrawlWebsiteJob/CrawlWebsitePageJob/RenderCrawledPageJobは
        // 通常onQueue()->delay()で次のジョブをdispatchするが、このコマンドは
        // それらのhandle()をこの場で同期的に直接呼ぶ(依頼Cの巡回連鎖を1
        // プロセス内で再現する)ため、内部からの自己dispatchが実キューへ
        // 二重に積まれないようにする。
        Queue::fake();

        $results = [];

        foreach ($siteKeys as $key) {
            $site = self::SITES[$key];
            $this->info("=== {$site['label']} ({$key}) crawl=".($crawlEnabled ? 'on' : 'off').' render='.($renderEnabled ? 'on' : 'off').' ===');

            [$analysisId, $websiteAnalysisId] = $this->seedWebsiteAnalysis($key, $site, $crawlEnabled);

            if ($crawlEnabled) {
                $this->runCrawlChain($pipeline, $analysisId, $websiteAnalysisId, $renderEnabled);
            } else {
                $pipeline->dispatchBrandWheelAnalysisAfterCrawl($analysisId, $websiteAnalysisId);
            }

            $crawlCounts = AnalysisCrawledPage::query()->where('website_analysis_id', $websiteAnalysisId)
                ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->toArray();
            $renderedCount = AnalysisCrawledPage::query()->where('website_analysis_id', $websiteAnalysisId)
                ->whereNotNull('rendered_html_path')->count();

            foreach ($tokenBudgets as $tokens) {
                config(['services.ai.max_input_tokens' => $tokens]);

                $websiteAnalysis = WebsiteAnalysis::find($websiteAnalysisId);
                [$input, $logs] = $this->captureLogsDuring(
                    fn () => $inputFactory->build($websiteAnalysis),
                    $websiteAnalysisId,
                );

                $crawlIntegration = $logs['Brand wheel analysis input: crawled pages integrated'] ?? null;
                $truncationDetail = $logs['Brand wheel analysis input truncated due to AI_MAX_INPUT_TOKENS'] ?? null;

                if (! empty($dumpTextDir)) {
                    $this->dumpText((string) $dumpTextDir, $key, $tokens, $websiteAnalysisId, $input);
                }

                $row = [
                    'site' => $key,
                    'label' => $site['label'],
                    'crawl_site' => $crawlEnabled,
                    'render_enabled' => $renderEnabled,
                    'ai_max_input_tokens' => $tokens,
                    'input_truncated' => $input->inputTruncated,
                    'recruit_body_chars' => mb_strlen($input->recruitPageBodyText),
                    'homepage_body_chars' => mb_strlen($input->homepageBodyText),
                    'crawl_page_counts' => $crawlCounts,
                    'render_candidates_rendered' => $renderedCount,
                    'crawled_paragraphs_seen' => $crawlIntegration['crawled_paragraphs_seen'] ?? 0,
                    'crawled_paragraphs_deduped' => $crawlIntegration['crawled_paragraphs_deduped'] ?? 0,
                    'crawled_paragraphs_kept' => $crawlIntegration['crawled_paragraphs_kept'] ?? 0,
                    'recruit_cluster_pool_count' => $crawlIntegration['recruit_cluster_pool_count'] ?? 0,
                    'homepage_cluster_pool_count' => $crawlIntegration['homepage_cluster_pool_count'] ?? 0,
                    'truncation_recruit_body_chars_before' => $truncationDetail['recruit_body_chars_before'] ?? null,
                    'truncation_recruit_body_chars_after' => $truncationDetail['recruit_body_chars_after'] ?? null,
                    'truncation_homepage_body_chars_before' => $truncationDetail['homepage_body_chars_before'] ?? null,
                    'truncation_homepage_body_chars_after' => $truncationDetail['homepage_body_chars_after'] ?? null,
                    'truncation_crawl_chars_added' => $truncationDetail['crawl_chars_added'] ?? null,
                ];

                $this->line(sprintf(
                    '  tokens=%-6d truncated=%-5s recruit_chars=%-5d homepage_chars=%-5d dedup(seen=%d,dropped=%d,kept=%d)',
                    $tokens,
                    $input->inputTruncated ? 'true' : 'false',
                    $row['recruit_body_chars'],
                    $row['homepage_body_chars'],
                    $row['crawled_paragraphs_seen'],
                    $row['crawled_paragraphs_deduped'],
                    $row['crawled_paragraphs_kept'],
                ));

                if ($callAi) {
                    $row['ai_result'] = $this->callAiSynchronously($websiteAnalysisId, $pipeline, $inputFactory);
                    // 依頼J-2: provider/is_mockをmatched件数の隣に必ず表示する
                    // (静かにモックへフォールバックしても一見で気づけるように)。
                    $this->line(sprintf(
                        '  ai_result: status=%-10s matched=%-4s provider=%-8s is_mock=%-5s error_code=%s',
                        $row['ai_result']['status'] ?? 'null',
                        $row['ai_result']['matched'] ?? 'null',
                        $row['ai_result']['provider'] ?? 'null',
                        isset($row['ai_result']['is_mock']) ? ($row['ai_result']['is_mock'] ? 'true' : 'false') : 'null',
                        $row['ai_result']['error_code'] ?? 'null',
                    ));
                }

                $results[] = $row;
            }
        }

        $json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $jsonPath = $this->option('json');

        if (! empty($jsonPath)) {
            file_put_contents((string) $jsonPath, $json.PHP_EOL);
            $this->info("結果をJSONへ書き出しました: {$jsonPath}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} [analysis_id, website_analysis_id]
     */
    private function seedWebsiteAnalysis(string $key, array $site, bool $crawlEnabled): array
    {
        $fetcher = app(SafeHttpFetcher::class);
        $paths = app(AnalysisStoragePaths::class);

        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create([
            'status' => AnalysisStatus::Running,
            'crawl_site' => $crawlEnabled,
            'skip_brand_wheel' => false,
        ]);
        $website = Website::factory()->for($project)->create([
            'is_primary' => true,
            'url' => $site['homepage_url'],
            'normalized_url' => $site['homepage_url'],
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $homepagePath = $paths->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'homepage.html');
        $this->fetchAndSavePage($fetcher, $site['homepage_url'], $homepagePath, $websiteAnalysis->id, PageType::Homepage);

        $isSelfReference = $site['recruit_url'] === $site['homepage_url'];
        if ($isSelfReference) {
            // FetchRecruitPageJob::process()の自己参照検出(依頼B/優先度4-3)と
            // 同じ表現 ―― raw_html_pathを同一パスにする。
            AnalysisPage::query()->create([
                'website_analysis_id' => $websiteAnalysis->id,
                'page_type' => PageType::Recruit,
                'url' => $site['recruit_url'],
                'final_url' => $site['recruit_url'],
                'http_status' => 200,
                'raw_html_path' => $homepagePath,
                'fetched_at' => now(),
            ]);
        } else {
            $recruitPath = $paths->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'recruit.html');
            $this->fetchAndSavePage($fetcher, $site['recruit_url'], $recruitPath, $websiteAnalysis->id, PageType::Recruit);
        }

        // FetchRobotsJobと同じURL構成(Website.normalized_urlに'/robots.txt'を
        // 追加するだけ)を用いる ―― サイト固有のドメインルート推定等の
        // 別ロジックを新設しない。
        $robotsUrl = rtrim($site['homepage_url'], '/').'/robots.txt';
        $robotsPath = $paths->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'robots.txt');

        try {
            $result = $fetcher->fetch($robotsUrl);
            if ($result->httpStatus === 200) {
                Storage::disk('analysis')->put($robotsPath, $result->body);
            }
            AnalysisPage::query()->create([
                'website_analysis_id' => $websiteAnalysis->id,
                'page_type' => PageType::Robots,
                'url' => $robotsUrl,
                'final_url' => $result->finalUrl,
                'http_status' => $result->httpStatus,
                'raw_html_path' => $result->httpStatus === 200 ? $robotsPath : null,
                'fetched_at' => now(),
            ]);
        } catch (AnalysisException $e) {
            $this->warn("  robots.txt取得失敗({$key}): {$e->getMessage()}");
        }

        return [$analysis->id, $websiteAnalysis->id];
    }

    private function fetchAndSavePage(SafeHttpFetcher $fetcher, string $url, string $path, int $websiteAnalysisId, PageType $pageType): void
    {
        try {
            $result = $fetcher->fetch($url, ['text/html', 'application/xhtml+xml']);
            Storage::disk('analysis')->put($path, $result->body);

            AnalysisPage::query()->create([
                'website_analysis_id' => $websiteAnalysisId,
                'page_type' => $pageType,
                'url' => $result->requestedUrl,
                'final_url' => $result->finalUrl,
                'http_status' => $result->httpStatus,
                'content_type' => $result->contentType,
                'raw_html_path' => $path,
                'fetched_at' => now(),
            ]);
        } catch (AnalysisException $e) {
            $this->warn("  {$pageType->value}取得失敗({$url}): {$e->getMessage()}");
        }
    }

    /**
     * 依頼D-1のジョブ連鎖(CrawlWebsiteJob→CrawlWebsitePageJob→
     * RenderCrawledPageJob)を、このコマンドのプロセス内でhandle()を直接
     * 呼びながら再現する。「終端条件を先に見てから呼ぶ」のではなく「まず
     * 呼び、その結果として終端したかを都度確認する」順序を守ること ――
     * 依頼E-7の測定でこれを誤り、finalizeCrawl()を呼ぶはずの最後の1回を
     * 実行し損ねるバグを作り込んだため、修正済みの順序をここに引き継ぐ。
     */
    private function runCrawlChain(AnalysisPipeline $pipeline, int $analysisId, int $waId, bool $renderEnabled): void
    {
        (new CrawlWebsiteJob($analysisId, $waId))->handle(
            $pipeline,
            app(CrawlPolicyResolver::class),
            app(RobotsTxtParser::class),
            app(\App\Services\Analysis\SitemapParser::class),
            app(CrawlLinkExtractor::class),
        );

        $intervalMicros = (int) round((float) config('brand_wheel.crawl_request_interval_seconds', 1.0) * 1_000_000);
        $maxPages = (int) config('brand_wheel.crawl_max_pages', 50);

        // 依頼G(2026-08-25): 以前はfetchedCount/hasPendingを「呼ぶ前に外部から
        // 判定」していたため、CrawlWebsitePageJob::handle()内部の終端判定
        // (呼び出し開始時点の状態で判定する)より1回早くループを抜けてしまい、
        // finalizeCrawl()を呼ぶはずの最後の1回を実行し損ねていた
        // (依頼者指摘・実測で確認: しんきんのように少数ページで自然に
        // フロンティアが枯渇するサイトでは即座に0件、大量ページのサイトでは
        // 逆に終端後もfinalizeCrawl()が繰り返し呼ばれ続けていた)。
        //
        // 本番のキュー連鎖(CrawlWebsitePageJob::dispatchNext())は、handle()
        // 内部からその場で無条件に次のジョブをdispatchするだけで、外部から
        // 「呼ぶべきか」を判定する層が存在しないため、この不具合は測定用の
        // このループに限られる(本番のCrawlWebsitePageJob自体・
        // CrawlWebsitePageJobTestは無関係、影響なし)。
        //
        // 修正: 毎回無条件にhandle()を呼び、「finalizeCrawl()が実際に走った
        // ことを示す観測可能な結果」(=候補が選定された、またはブランド・
        // ホイールへ直接dispatchされた)が出た直後に限ってループを止める。
        // 事前の条件判定でスキップしない(RenderCrawledPageJobループと
        // 同じ考え方)。
        for ($i = 0; $i < $maxPages * 5 + 50; $i++) {
            $fetchedCount = AnalysisCrawledPage::query()->where('website_analysis_id', $waId)->where('status', 'fetched')->count();
            $hasPending = AnalysisCrawledPage::query()->where('website_analysis_id', $waId)->where('status', 'pending')->exists();

            if ($fetchedCount < $maxPages && $hasPending) {
                usleep($intervalMicros);
            }

            (new CrawlWebsitePageJob($analysisId, $waId))->handle(
                $pipeline,
                app(SafeHttpFetcher::class),
                app(CrawlLinkExtractor::class),
                app(RobotsTxtParser::class),
                app(CrawlPolicyResolver::class),
                app(AnalysisStoragePaths::class),
                app(HtmlSeoAnalyzer::class),
                app(PageHtmlResolver::class),
            );

            $dispatched = WebsiteAnalysis::find($waId)?->brand_wheel_dispatched_at !== null;
            $hasRenderCandidates = AnalysisCrawledPage::query()->where('website_analysis_id', $waId)->where('render_candidate', true)->exists();

            if ($dispatched || $hasRenderCandidates) {
                // finalizeCrawl()がこの直前のhandle()呼び出しの中で実際に
                // 走った(0件で直接dispatchされたか、候補が選定されたか)。
                if ($dispatched) {
                    return;
                }

                break;
            }
        }

        if ($renderEnabled) {
            for ($i = 0; $i < 15; $i++) {
                if (WebsiteAnalysis::find($waId)?->brand_wheel_dispatched_at !== null) {
                    break;
                }
                (new RenderCrawledPageJob($analysisId, $waId))->handle(
                    $pipeline,
                    app(AnalyzerClient::class),
                    app(AnalysisStoragePaths::class),
                );
            }
        } else {
            AnalysisCrawledPage::query()->where('website_analysis_id', $waId)->where('render_candidate', true)
                ->update(['render_candidate' => false]);
            if (WebsiteAnalysis::find($waId)?->brand_wheel_dispatched_at === null) {
                $pipeline->dispatchBrandWheelAnalysisAfterCrawl($analysisId, $waId);
            }
        }
    }

    /**
     * BrandWheelAnalysisInputFactory::build()が発するLog::info/Log::warning
     * (依頼Eで既に実装済み、この呼び出し中に変更・追加しない)のうち、この
     * website_analysis_id宛てのものだけを、$fn実行前後のログファイルの
     * バイト差分から読み取る。本文の実テキストはこれらのログに一切含まれ
     * ない(件数・文字数のみ)。
     *
     * @return array{0: mixed, 1: array<string, array<string, mixed>>}
     */
    private function captureLogsDuring(\Closure $fn, int $websiteAnalysisId): array
    {
        $logPath = storage_path('logs/laravel.log');
        $offsetBefore = is_file($logPath) ? filesize($logPath) : 0;

        $result = $fn();

        clearstatcache(true, $logPath);
        $captured = [];

        if (is_file($logPath)) {
            $handle = fopen($logPath, 'r');
            fseek($handle, $offsetBefore);
            $newContent = stream_get_contents($handle);
            fclose($handle);

            foreach (explode("\n", (string) $newContent) as $line) {
                if (! str_contains($line, "\"website_analysis_id\":{$websiteAnalysisId}")) {
                    continue;
                }
                if (! preg_match('/local\.(?:INFO|WARNING): (.+?) (\{.*\})\s*$/', $line, $m)) {
                    continue;
                }
                $context = json_decode($m[2], true);
                if (is_array($context) && ($context['website_analysis_id'] ?? null) === $websiteAnalysisId) {
                    $captured[trim($m[1])] = $context;
                }
            }
        }

        return [$result, $captured];
    }

    /**
     * 依頼者への注記: GenerateBrandWheelAnalysisJob::handle()を直接呼ぶため、
     * 実運用の$tries+release()によるレート制限時のキュー再試行が働かない。
     * AI_RATE_LIMITEDに達した場合はバックオフつきで手動リトライする(依頼E-7の
     * 測定で実際に発生・対処した内容を引き継ぐ)。AnalysisJobRecordを削除
     * してから再試行しないと、markRunning()が既存の終端行を見つけて即座に
     * 何もせずreturnし、pendingのまま固着する(同じくE-7で発見・修正済み)。
     *
     * 依頼I(2026-08-25)で発見: config('services.brand_wheel_ai.provider')の
     * 既定は'mock'であり、この開発環境はALLOW_MOCK_PROVIDERS=trueのため
     * ガードに引っかからず無言でMockBrandWheelAnalysisProviderへフォール
     * バックする(依頼E-7で一度発見・e7_measure.phpでは対処済みだった問題を、
     * このコマンドへ移植する際に見落としていた)。この結果、--call-aiを
     * 付けても実際にはAIを一切呼ばず、claimed=0/matched=0/discarded=0固定の
     * モック応答がstatus=successとして保存され、一見成功したように見えて
     * しまう(実際にしんきんの実行1件がこれで汚染されているのを検出した)。
     *
     * 依頼J-2(2026-08-25): config()の上書きを足すだけでは同じ移植漏れが
     * 再発しうる(依頼者指摘)ため、config上書きに加えて2つの構造的な
     * 防御を入れる。(1) 呼び出し前にBrandWheelAnalysisProviderFactoryが
     * 実際に何を解決するかをこの場で直接確認し、'openai'でなければ
     * ここで即座に例外を投げて停止する(GenerateBrandWheelAnalysisJobの
     * 内部で解決される想定と食い違っていないかを実際に検証してから進む)。
     * (2) 返り値に常にprovider/is_mockを含め、呼び出し元(handle())が
     * 標準出力・JSON出力の両方に必ず表示する ―― 件数の隣にproviderが
     * 出ることで、以後モックへ静かにフォールバックしても一見で気づける
     * ようにする。
     *
     * @return array{status: ?string, matched: ?int, error_code: ?string, provider: ?string, is_mock: ?bool}
     */
    private function callAiSynchronously(int $websiteAnalysisId, AnalysisPipeline $pipeline, BrandWheelAnalysisInputFactory $inputFactory): array
    {
        config(['services.brand_wheel_ai.provider' => 'openai']);
        if ((string) config('services.openai.api_key') === '') {
            return ['status' => 'skipped_no_api_key', 'matched' => null, 'error_code' => null, 'provider' => null, 'is_mock' => null];
        }

        $resolvedProvider = app(\App\Services\BrandWheel\BrandWheelAnalysisProviderFactory::class)->make();
        if ($resolvedProvider->name() !== 'openai') {
            throw new \RuntimeException(
                "--call-aiを指定しましたが、解決されたBrandWheelAnalysisProviderが".
                "'openai'ではなく'{$resolvedProvider->name()}'でした。config('services.brand_wheel_ai.provider')の".
                '上書きが効いていない可能性があります(依頼J-2の再発防止チェック)。モックのままAI測定として'.
                '扱われるのを防ぐため、ここで停止します。',
            );
        }

        $record = BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysisId)->latest('id')->first();

        $maxAttempts = 6;
        for ($attempt = 1; $record !== null && $record->status === 'pending' && $attempt <= $maxAttempts; $attempt++) {
            try {
                (new GenerateBrandWheelAnalysisJob($record->id))->handle($inputFactory, $pipeline);
            } catch (\Throwable $e) {
                return ['status' => 'exception', 'matched' => null, 'error_code' => $e->getMessage(), 'provider' => null, 'is_mock' => null];
            }
            $record = $record->fresh();

            if ($record !== null && $record->status === 'error' && $record->error_code === 'AI_RATE_LIMITED' && $attempt < $maxAttempts) {
                sleep(20 * $attempt);
                AnalysisJobRecord::query()
                    ->where('analysis_id', $record->analysis_id)
                    ->where('website_analysis_id', $record->website_analysis_id)
                    ->where('job_type', JobType::GenerateBrandWheelAnalysis)
                    ->delete();
                $record->update(['status' => 'pending', 'error_code' => null, 'error_message' => null]);
            }
        }

        if ($record === null) {
            return ['status' => null, 'matched' => null, 'error_code' => null, 'provider' => null, 'is_mock' => null];
        }

        // 依頼J-2: 事前にProviderFactoryの解決結果を確認済みだが、実際に
        // 保存された行がなお is_mock=true だった場合(想定していない
        // 経路での再発)にも気づけるよう、ここでも二重に確認して止める。
        if ($record->is_mock === true || $record->provider === 'mock') {
            throw new \RuntimeException(
                "--call-aiで保存された結果がis_mock=true(provider={$record->provider})でした。".
                '事前のProvider解決チェックを通過したにもかかわらずモックが保存されています。'.
                '想定外の経路のため、原因を特定するまでこの結果は測定に使わないでください。',
            );
        }

        $matched = collect((array) ($record->axes ?? []))->sum(fn (array $axis) => count($axis['matched_sub_elements'] ?? []));

        return [
            'status' => $record->status,
            'matched' => $matched,
            'error_code' => $record->error_code,
            'provider' => $record->provider,
            'is_mock' => $record->is_mock,
        ];
    }

    /**
     * 依頼H: BrandWheelAnalysisInputFactory::build()が実際に組み立てた本文
     * (AIへ渡る最終形、$input->recruitPageBodyText/homepageBodyText)を
     * そのままファイルへ書き出す。あわせて、どのページが候補になり得たかを
     * 把握できるよう、seedページ・クロールページそれぞれの由来(URL・
     * HTML取得元(rendered/static)・抽出後の文字数・クラスタ分類)を
     * PageHtmlResolver/HtmlSeoAnalyzer::extractBodyText()/isRecruitPageUrl()
     * という既存の公開APIだけを使って独立に再集計し、末尾に「参考」として
     * 添える。BrandWheelAnalysisInputFactory自体のロジックには一切触れて
     * いない(呼び出すだけ)。
     *
     * 最終テキスト内の1文字たりともAIへは渡さない(このコマンド自体が
     * ドライランで使われることを想定しており、--call-aiと独立に機能する)。
     */
    private function dumpText(string $dir, string $siteKey, int $tokens, int $websiteAnalysisId, BrandWheelAnalysisInput $input): void
    {
        $path = rtrim($dir, '/\\')."/{$siteKey}_tokens{$tokens}.txt";
        $htmlResolver = app(PageHtmlResolver::class);
        $htmlSeoAnalyzer = app(HtmlSeoAnalyzer::class);
        $disk = Storage::disk('analysis');

        $lines = [];
        $lines[] = "=== recruitPageBodyText (".mb_strlen($input->recruitPageBodyText)."字、AIへ渡る最終形そのまま) ===";
        $lines[] = $input->recruitPageBodyText;
        $lines[] = '';
        $lines[] = "=== homepageBodyText (".mb_strlen($input->homepageBodyText)."字、AIへ渡る最終形そのまま) ===";
        $lines[] = $input->homepageBodyText;
        $lines[] = '';
        $lines[] = '=== 参考: 由来ページの内訳(独立再集計。build()の選定・切り詰め結果とは別に、';
        $lines[] = '    候補になり得た全ページを一覧するためのもの。上記の最終テキストと';
        $lines[] = '    1対1には対応しない ―― 予算超過分・重複除去された段落は含まれない) ===';

        foreach ([PageType::Recruit, PageType::Homepage] as $pageType) {
            $page = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysisId)->where('page_type', $pageType)->first();
            if ($page === null) {
                $lines[] = "[seed:{$pageType->value}] (該当ページ無し)";

                continue;
            }
            $resolved = $htmlResolver->resolve($page);
            if ($resolved === null) {
                $lines[] = "[seed:{$pageType->value}] {$page->url} => 読めるHTMLなし";

                continue;
            }
            $body = $htmlSeoAnalyzer->extractBodyText($disk->get($resolved['path']), excludeNavigation: true);
            $lines[] = "[seed:{$pageType->value}] {$page->url} source={$resolved['source']} body_chars=".mb_strlen($body);
        }

        $crawledPages = AnalysisCrawledPage::query()
            ->where('website_analysis_id', $websiteAnalysisId)
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->whereNotNull('raw_html_path')
            ->orderBy('depth')->orderBy('id')
            ->get();

        foreach ($crawledPages as $page) {
            $resolved = $htmlResolver->resolve($page);
            if ($resolved === null) {
                $lines[] = "[crawl] {$page->url} => 読めるHTMLなし";

                continue;
            }
            $body = $htmlSeoAnalyzer->extractBodyText($disk->get($resolved['path']), excludeNavigation: true);
            $cluster = $htmlSeoAnalyzer->isRecruitPageUrl($page->final_url ?? $page->url) ? 'recruit' : 'homepage';
            $lines[] = "[crawl:{$cluster}] {$page->url} source={$resolved['source']} body_chars=".mb_strlen($body);
        }

        file_put_contents($path, implode("\n", $lines)."\n");
        $this->line("  dump-text: {$path}");
    }
}

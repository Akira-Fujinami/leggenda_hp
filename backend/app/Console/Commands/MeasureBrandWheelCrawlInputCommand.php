<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SeedsAndCrawlsMeasurementSites;
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
    {--page-stats= : 依頼CN-B1: 巡回したページごとの段落の統計と、入力に採用された文字数・段落数(件数のみ、本文は出さない)をこのJSONパスへ書き出す}
    {--judge-repeats=0 : 依頼CN-B3: 同じ入力(巡回し直さない)に対して、実際のAI判定をこの回数だけ繰り返し、軸ごとの○の下位要素のキーを出す(費用がかかる。0=行わない)}
    {--reuse= : 依頼CN-B3: 既に巡回済みのWebsiteAnalysisを使い回す(サイトキー:website_analysis_id のカンマ区切り。例 cybozu:763)。巡回し直さない ―― 同じ入力で判定を繰り返すため}
    {--dump-text= : BrandWheelAnalysisInputFactory::build()が実際に組み立てた本文(recruitPageBodyText/homepagePageBodyText)を、由来ページの内訳とあわせてこのディレクトリへ書き出す(依頼H)。AIには渡さない。ドライランでも使用可}
')]
#[Description('非本番・開発専用: クロール統合後のBrandWheelAnalysisInputFactory入力を測定する(依頼F、既定はAIを呼ばないドライラン)')]
class MeasureBrandWheelCrawlInputCommand extends Command
{
    use SeedsAndCrawlsMeasurementSites;

    /**
     * 依頼CN-B1: 福利厚生・給与・制度にあたるページを選ぶための語(URL・ページ名に
     * 含まれるか、大文字小文字を区別しない部分一致)。測定専用 ―― 入力の選定
     * ロジックには使わない。
     *
     * @var list<string>
     */
    private const TARGET_PAGE_WORDS = [
        '福利厚生', '給与', '報酬', '待遇', '手当', '制度', '評価', '休暇',
        'benefit', 'salary', 'compensation', 'assessment', 'evaluation', 'welfare', 'treatment', 'allowance', 'system', 'career', 'training', 'hr-system', 'pay',
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

        $reuse = [];
        foreach (array_filter(explode(',', (string) $this->option('reuse'))) as $pair) {
            [$reuseKey, $reuseId] = array_pad(explode(':', trim($pair), 2), 2, null);
            if ($reuseKey !== null && $reuseId !== null) {
                $reuse[$reuseKey] = (int) $reuseId;
            }
        }

        $results = [];

        foreach ($siteKeys as $key) {
            $site = self::SITES[$key];
            $this->info("=== {$site['label']} ({$key}) crawl=".($crawlEnabled ? 'on' : 'off').' render='.($renderEnabled ? 'on' : 'off').' ===');

            $reuseId = $reuse[$key] ?? null;
            if ($reuseId !== null) {
                // 巡回済みの結果を使い回す(巡回・シード・ディスパッチをしない)。
                $reused = WebsiteAnalysis::findOrFail($reuseId);
                $analysisId = (int) $reused->analysis_id;
                $websiteAnalysisId = (int) $reused->id;
                $this->line("  reuse: website_analysis_id={$websiteAnalysisId}(巡回し直さない)");
            } else {
                [$analysisId, $websiteAnalysisId] = $this->seedWebsiteAnalysis($key, $site, $crawlEnabled);

                if ($crawlEnabled) {
                    $this->runCrawlChain($pipeline, $analysisId, $websiteAnalysisId, $renderEnabled);
                } else {
                    $pipeline->dispatchBrandWheelAnalysisAfterCrawl($analysisId, $websiteAnalysisId);
                }
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

                if (! empty($this->option('page-stats'))) {
                    $row['page_stats'] = $this->collectPageStats($websiteAnalysisId, $input);
                }

                if ((int) $this->option('judge-repeats') > 0) {
                    $row['judgments'] = $this->runRepeatedJudgments(WebsiteAnalysis::find($websiteAnalysisId), (int) $this->option('judge-repeats'), $pipeline, $inputFactory);
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
     * 依頼CN-B1: 巡回したページごとの、本文の段落の統計と、AIへの入力に実際に
     * 採用された文字数・段落数。出すのは件数・文字数・出現回数だけ(本文・
     * 段落のテキストは出さない)。
     *
     * 採用の判定は、build()が実際に組み立てた入力(recruitPageBodyText/
     * homepagePageBodyText)の行との完全一致で行う(重複除去は「最初に現れた
     * ページ」が取るため、同じ行は最初のページにだけ数える。起点ページ
     * (seed)と同じ行はseedのものとして数えない。段落の途中で切って採用した
     * ものは完全一致しないため数えない ―― 全体で数件)。
     *
     * @return array<string, mixed>
     */
    private function collectPageStats(int $websiteAnalysisId, BrandWheelAnalysisInput $input): array
    {
        $resolver = app(PageHtmlResolver::class);
        $analyzer = app(HtmlSeoAnalyzer::class);
        $targetWords = array_values(array_filter((array) config('brand_wheel.measure_target_page_words', self::TARGET_PAGE_WORDS)));

        $finalLines = [];
        foreach (explode("\n", $input->recruitPageBodyText."\n".$input->homepageBodyText) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $finalLines[$line] = true;
            }
        }

        $seedLines = [];
        foreach ([PageType::Recruit, PageType::Homepage] as $type) {
            $seedPage = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysisId)->where('page_type', $type)->first();
            $resolved = $seedPage !== null ? $resolver->resolve($seedPage) : null;
            if ($resolved !== null) {
                foreach (explode("\n", $analyzer->extractBodyText(Storage::disk('analysis')->get($resolved['path']), excludeNavigation: true)) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $seedLines[$line] = true;
                    }
                }
            }
        }

        $owned = [];
        $pages = [];
        $pageModels = AnalysisCrawledPage::query()
            ->where('website_analysis_id', $websiteAnalysisId)
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->whereNotNull('raw_html_path')
            ->orderBy('depth')->orderBy('id')->get();

        foreach ($pageModels as $page) {
            $resolved = $resolver->resolve($page);
            if ($resolved === null) {
                continue;
            }
            $html = Storage::disk('analysis')->get($resolved['path']);
            $title = $analyzer->extractPageTitle($html);
            $url = (string) ($page->final_url ?? $page->url);
            $lengths = [];
            $adoptedChars = 0;
            $adoptedCount = 0;
            foreach (explode("\n", $analyzer->extractBodyText($html, excludeNavigation: true)) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $length = mb_strlen($line);
                $lengths[] = $length;
                if (isset($seedLines[$line]) || isset($owned[$line])) {
                    continue;
                }
                $owned[$line] = true;
                if (isset($finalLines[$line])) {
                    $adoptedChars += $length;
                    $adoptedCount++;
                }
            }

            $isTarget = false;
            foreach ($targetWords as $word) {
                if (mb_stripos($url, $word) !== false || ($title !== null && mb_stripos($title, $word) !== false)) {
                    $isTarget = true;
                    break;
                }
            }

            $pages[] = [
                'url' => $url,
                'has_title' => $title !== null,
                'is_target' => $isTarget,
                'chars' => array_sum($lengths),
                'paragraphs' => count($lengths),
                'median_paragraph_length' => $this->median($lengths),
                'adopted_chars' => $adoptedChars,
                'adopted_paragraphs' => $adoptedCount,
            ];
        }

        $top = $pages;
        usort($top, fn (array $a, array $b) => $b['adopted_chars'] <=> $a['adopted_chars']);
        $top = array_slice($top, 0, 5);

        $allText = $input->recruitPageBodyText."\n".$input->homepageBodyText;
        $keywordCounts = [];
        foreach (['福利厚生', '手当', '給与', '休暇', '評価'] as $word) {
            $keywordCounts[$word] = mb_substr_count($allText, $word);
        }

        return [
            'pages_read' => count($pages),
            'target_pages' => array_values(array_filter($pages, fn (array $p) => $p['is_target'])),
            'top5_by_adopted_chars' => array_map(fn (array $p) => [
                'url' => $p['url'], 'adopted_chars' => $p['adopted_chars'], 'adopted_paragraphs' => $p['adopted_paragraphs'],
                'median_paragraph_length' => $p['median_paragraph_length'],
            ], $top),
            'keyword_counts_in_input' => $keywordCounts,
            'pages_without_title_in_html' => count(array_filter($pages, fn (array $p) => ! $p['has_title'])),
            'pages_without_saved_title' => $pageModels->filter(fn (AnalysisCrawledPage $p) => trim((string) $p->title) === '')->count(),
        ];
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? $values[intdiv($n, 2)] : (int) round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }

    /**
     * 依頼CN-B3: 同じ巡回結果(巡回し直さない)に対して、実際のAI判定を$repeats回
     * 行い、軸ごとに○と判定された下位要素のキーを返す(本文・根拠の引用は返さない)。
     *
     * @return list<array<string, mixed>>
     */
    private function runRepeatedJudgments(WebsiteAnalysis $websiteAnalysis, int $repeats, AnalysisPipeline $pipeline, BrandWheelAnalysisInputFactory $inputFactory): array
    {
        $rows = [];
        for ($run = 1; $run <= $repeats; $run++) {
            AnalysisJobRecord::query()
                ->where('analysis_id', $websiteAnalysis->analysis_id)
                ->where('website_analysis_id', $websiteAnalysis->id)
                ->where('job_type', JobType::GenerateBrandWheelAnalysis)
                ->delete();
            BrandWheelAnalysisResult::query()->create([
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'status' => 'pending',
                'is_mock' => false,
                'input_hash' => '',
            ]);

            $ai = $this->callAiSynchronously($websiteAnalysis->id, $pipeline, $inputFactory);
            $record = BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->latest('id')->first();
            $axes = [];
            foreach ((array) ($record?->axes ?? []) as $axis) {
                $axes[(string) ($axis['axis_key'] ?? '?')] = array_column((array) ($axis['matched_sub_elements'] ?? []), 'key');
            }

            $rows[] = ['run' => $run, 'status' => $ai['status'] ?? null, 'provider' => $ai['provider'] ?? null, 'is_mock' => $ai['is_mock'] ?? null, 'error_code' => $ai['error_code'] ?? null, 'matched_sub_elements' => $axes];
            $this->line(sprintf('  judgment run=%d status=%s matched=%s', $run, $ai['status'] ?? 'null', $ai['matched'] ?? 'null'));
        }

        return $rows;
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

<?php

namespace Tests\Unit\Jobs\Analysis;

use Tests\TestCase;

/**
 * 依頼CA-2(2026-09-15、この依頼の主目的): 「巡回が終わる経路」で
 * crawl_finished_reasonの保存を数え落とさないことを、個別の経路テストとは
 * 別に、構造的に担保する。
 *
 * 背景: 依頼BVは「巡回が始まらない経路は2つ」と書いたが、実際は4つ
 * あった(CrawlWebsiteJob::handle()を140行目までしか読まずに書いたため)。
 * 個別の経路を2つ足すだけでは同じ見落としを繰り返しうる ――
 * CrawlWebsiteJob/CrawlWebsitePageJobのどちらも、巡回を終える経路は必ず
 * 1つの集約メソッド(finalizeWithoutCrawling()/finalizeCrawl())を経由する
 * よう作った(依頼CA-2の報告参照)。このテストは、その集約が実際に
 * 保たれていること ―― 「$pipeline->dispatchBrandWheelAnalysisAfterCrawl(」
 * の呼び出しが、各ファイル中でその集約メソッドの中にしか無いこと ――
 * をソースコードそのものから機械的に確認する。将来だれかが新しい終了経路を
 * 追加する際、集約メソッドを経由せず直接dispatchBrandWheelAnalysisAfterCrawl()
 * を呼んでしまうと、このテストが赤くなる。
 */
class CrawlFinishedReasonCoverageTest extends TestCase
{
    // 「->」付きで実際のメソッド呼び出しだけを対象にする ―― この定数名を
    // 説明するdocblockコメント自身(「dispatchBrandWheelAnalysisAfterCrawl()」、
    // 「->」無し)を誤って呼び出しとしてカウントしないため。
    private const DISPATCH_CALL = '->dispatchBrandWheelAnalysisAfterCrawl(';

    public function test_crawl_website_job_calls_dispatch_brand_wheel_analysis_after_crawl_only_inside_the_single_finalize_method(): void
    {
        $path = app_path('Jobs/Analysis/CrawlWebsiteJob.php');
        $source = (string) file_get_contents($path);

        $totalCalls = substr_count($source, self::DISPATCH_CALL);
        $this->assertGreaterThan(
            0,
            $totalCalls,
            'CrawlWebsiteJob::finalizeWithoutCrawling()が壊れていないか、少なくとも1回はdispatchBrandWheelAnalysisAfterCrawl()を呼んでいるはず',
        );

        $methodBody = $this->extractMethodBody($source, 'finalizeWithoutCrawling');
        $callsInsideMethod = substr_count($methodBody, self::DISPATCH_CALL);

        $this->assertSame(
            $totalCalls,
            $callsInsideMethod,
            'CrawlWebsiteJob.php内のdispatchBrandWheelAnalysisAfterCrawl()呼び出しは、'
            .'すべてfinalizeWithoutCrawling()の中だけにあるはず ―― ファイル中の他の場所で'
            .'直接呼んでいる箇所が見つかった(crawl_finished_reasonの保存漏れの恐れ)。',
        );
    }

    public function test_crawl_website_page_job_calls_dispatch_brand_wheel_analysis_after_crawl_only_inside_finalize_crawl(): void
    {
        $path = app_path('Jobs/Analysis/CrawlWebsitePageJob.php');
        $source = (string) file_get_contents($path);

        $totalCalls = substr_count($source, self::DISPATCH_CALL);
        $this->assertGreaterThan(
            0,
            $totalCalls,
            'CrawlWebsitePageJob::finalizeCrawl()が壊れていないか、少なくとも1回はdispatchBrandWheelAnalysisAfterCrawl()を呼んでいるはず',
        );

        $methodBody = $this->extractMethodBody($source, 'finalizeCrawl');
        $callsInsideMethod = substr_count($methodBody, self::DISPATCH_CALL);

        $this->assertSame(
            $totalCalls,
            $callsInsideMethod,
            'CrawlWebsitePageJob.php内のdispatchBrandWheelAnalysisAfterCrawl()呼び出しは、'
            .'すべてfinalizeCrawl()の中だけにあるはず ―― ファイル中の他の場所で直接'
            .'呼んでいる箇所が見つかった(crawl_finished_reasonの保存漏れの恐れ)。',
        );
    }

    /**
     * 「巡回が終わる経路」自体の一覧(依頼CA-2で報告する一覧と、実際の
     * ソースがズレていないこと)を、finalizeCrawl()/finalizeWithoutCrawling()
     * への呼び出し箇所の行数で確認する。件数がここと合わなくなったら、
     * 報告した一覧を書き直すこと。
     */
    public function test_the_number_of_termination_call_sites_matches_the_documented_enumeration(): void
    {
        $crawlWebsiteJobSource = (string) file_get_contents(app_path('Jobs/Analysis/CrawlWebsiteJob.php'));
        $crawlWebsitePageJobSource = (string) file_get_contents(app_path('Jobs/Analysis/CrawlWebsitePageJob.php'));

        // CrawlWebsiteJob: robots_txt_unavailable/no_allowed_hosts/
        // no_seed_urls_found/seed_job_failedの4箇所。
        $this->assertSame(4, substr_count($crawlWebsiteJobSource, '$this->finalizeWithoutCrawling('));

        // CrawlWebsitePageJob: max_pages/total_timeout/max_storage/exhausted/
        // robots_became_unavailable/failed_exceptionの6箇所。
        $this->assertSame(6, substr_count($crawlWebsitePageJobSource, '$this->finalizeCrawl('));
    }

    /**
     * ファイル冒頭からmethodName関数定義の開始"{"を見つけ、対応する"}"
     * までの範囲(ブレースの深さで判定)を本文として返す。厳密なPHPパーサー
     * ではないが、対象は自分たちが書いた2ファイルの単純なメソッド本文の
     * 抽出に限るため、この単純な深さカウントで十分(文字列リテラル中の
     * 波かっこは対象2ファイルのメソッド本文には出現しない)。
     */
    private function extractMethodBody(string $source, string $methodName): string
    {
        $needle = 'function '.$methodName.'(';
        $funcPos = strpos($source, $needle);
        $this->assertNotFalse($funcPos, "function {$methodName}(...) が見つからない");

        $bodyStart = strpos($source, '{', $funcPos);
        $this->assertNotFalse($bodyStart, "{$methodName}()の本文の開始位置が見つからない");

        $depth = 0;
        $length = strlen($source);
        for ($i = $bodyStart; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $bodyStart, $i - $bodyStart + 1);
                }
            }
        }

        $this->fail("{$methodName}()の本文の終端(対応する閉じ波かっこ)が見つからない");
    }
}

<?php

namespace App\Enums;

enum JobType: string
{
    case StartAnalysis = 'start_analysis';
    case FetchStaticPage = 'fetch_static_page';
    case FetchRobots = 'fetch_robots';
    case FetchSitemap = 'fetch_sitemap';
    case RenderPage = 'render_page';
    case CaptureScreenshotDesktop = 'capture_screenshot_desktop';
    case CaptureScreenshotMobile = 'capture_screenshot_mobile';
    case RunLighthouse = 'run_lighthouse';
    case AnalyzeHtmlSeo = 'analyze_html_seo';
    case ReanalyzeRenderedHtml = 'reanalyze_rendered_html';
    case DetectTechnology = 'detect_technology';
    case FetchExternalSeoData = 'fetch_external_seo_data';
    case FinalizeWebsiteAnalysis = 'finalize_website_analysis';
    case FinalizeAnalysis = 'finalize_analysis';
    // Phase 3: トップページのbusiness_links.recruitから検出したURLを取得・
    // 解析する採用ページ向けジョブ。採用ページが見つからない場合は
    // FetchRecruitPage以降が実際のHTTP取得/Analyzer呼び出しを行わず、
    // 即座に「計測対象外」相当の状態で終端する(placeholder自体は常に登録し、
    // 進捗計算・完了判定を他ジョブと同じ扱いにする)。
    case FetchRecruitPage = 'fetch_recruit_page';
    case AnalyzeRecruitPage = 'analyze_recruit_page';
    case RunRecruitLighthouse = 'run_recruit_lighthouse';
    // Phase 4: ブランド・ホイール(6軸)分析。Analyzerを一切使わない
    // (OpenAIへのHTTPのみ)ためANALYZER_CHAINには入れず、
    // AnalysisPipeline::dispatchWebsiteFanOut()から他の初回fan-outジョブと
    // 並列にdispatchする。skip_brand_wheel=trueのAnalysis(既定値、内部向け
    // ダッシュボード分析)ではexcludeSkippedJobTypes()により対象から
    // 除外される。
    case GenerateBrandWheelAnalysis = 'generate_brand_wheel_analysis';

    // 依頼M-1(2026-08-25): サイト全ページ巡回(依頼C〜D)・条件付きレンダリング
    // (依頼E)は、CrawlWebsiteJob/CrawlWebsitePageJob/RenderCrawledPageJobの
    // 連鎖として実装されており、依頼D時点では「管理画面専用の内部機能」という
    // 前提でAnalysisJobテーブルに一切登録していなかった。依頼Lでリード
    // (お客様向け)診断にも使うことになり前提が変わったため、進捗に反映する。
    // ページ1枚ごとに行を作ると行数が可変(最大50)になり既存の進捗計算・
    // 管理画面表示が壊れるため、巡回全体で1行(CrawlWebsite)、レンダリング
    // 全体で1行(RenderCrawledPages)に集約する。
    case CrawlWebsite = 'crawl_website';

    case RenderCrawledPages = 'render_crawled_pages';

    /**
     * WebsiteAnalysisの進捗(0-100)に対する重み。
     *
     * 依頼N(2026-08-25)でProgressCalculator::forWebsiteAnalysis()を
     * 「行が存在するジョブ種別の重みの合計」で正規化する方式に変更した
     * ため、**この16種(CrawlWebsite/RenderCrawledPagesを除く)が合計100に
     * なる、という制約はもう計算上必須ではない**(正規化により、どの
     * 部分集合が欠けても残りの合計に対する割合で100%まで到達する)。
     * ただし「重み配分の意味」を素直に保つため、この基本16種は引き続き
     * 合計100を維持する(依頼M-1で一時的に80%へ再スケールしていたが、
     * この依頼で依頼M-1以前の値に戻した ―― リード診断のようにLighthouse/
     * スクリーンショットが除外される経路で、除外された分だけ正規化の
     * 分母も一緒に小さくなるため、絶対値を動かす必要が無くなったため)。
     *
     * Phase 3でFetchExternalSeoData(Semrush等)を追加した際、既存の重みを
     * 比例的に少しずつ下げて11点分を確保した。静的/レンダリング済みHTML
     * 競合修正でReanalyzeRenderedHtmlを追加した際は、RenderPageの重みから
     * 4点を移した(RenderPageの後続処理という位置づけ)。
     *
     * Phase 3で採用ページ向け3ジョブ(FetchRecruitPage/AnalyzeRecruitPage/
     * RunRecruitLighthouse、合計12点)を追加した際は、既存12種の重みを
     * 一律88%(=88/100)に比例スケールし、端数は四捨五入した。
     *
     * Phase 4でGenerateBrandWheelAnalysis(1件のみ、重み10 ―― 単発の重い
     * 外部API呼び出しという性質がFetchExternalSeoDataと同格と判断)を
     * 追加した際は、既存15種の重みを一律90%(=90/100)に比例スケールし、
     * 端数は最大剰余法(largest remainder method)で配分した。
     *
     * CrawlWebsite(12)/RenderCrawledPages(8)は依頼M-1で追加した、上記16種
     * とは**独立**の重み(合計が100を超えてよい、依頼N指定)。
     * ProgressCalculatorが正規化するため、絶対値そのものではなく他の
     * 重みとの「比」だけが意味を持つ。巡回(最大50ページ取得、机上見積り
     * 約75秒)がレンダリング(最大10ページ、同約60秒)よりやや長いという
     * 依頼L-3の見積りに合わせて12:8の比率にした。crawl_site=trueの診断
     * では合計88(基本16種の合計100からLighthouse/スクリーンショット等の
     * 除外分を引いた値+20)前後が分母になり、20/88≈23%を巡回・
     * レンダリングが占める計算になる(依頼N報告の実測値を参照)。
     */
    public function weight(): int
    {
        return match ($this) {
            self::FetchStaticPage => 10,
            self::FetchRobots => 4,
            self::FetchSitemap => 4,
            self::RenderPage => 7,
            self::CaptureScreenshotDesktop => 7,
            self::CaptureScreenshotMobile => 7,
            self::RunLighthouse => 13,
            self::AnalyzeHtmlSeo => 7,
            self::ReanalyzeRenderedHtml => 4,
            self::DetectTechnology => 4,
            self::FetchExternalSeoData => 9,
            self::FinalizeWebsiteAnalysis => 3,
            self::FetchRecruitPage => 3,
            self::AnalyzeRecruitPage => 3,
            self::RunRecruitLighthouse => 5,
            self::GenerateBrandWheelAnalysis => 10,
            self::CrawlWebsite => 12,
            self::RenderCrawledPages => 8,
            self::StartAnalysis, self::FinalizeAnalysis => 0,
        };
    }

    /**
     * このジョブ種別が実行されるキュー名。
     * analysis: DB/軽量HTTP中心の処理。analysis-heavy: analyzer(Playwright/Lighthouse)を
     * 呼び出す重い処理で、専用ワーカー(queue-worker-heavy)に隔離する。
     * external-api: Semrush等の外部SEO API呼び出し(通常のanalysisキューとは
     * 分離し、外部APIのレート制限・障害が他ジョブに波及しないようにする)。
     * ReanalyzeRenderedHtmlは外部呼び出しを行わず、既に取得済みのレンダリング後
     * HTMLをディスクから読んで再解析するだけの軽量CPU処理のためanalysisキュー。
     * RunRecruitLighthouseはAnalyzerを呼び出す重い処理のためanalysis-heavy。
     * FetchRecruitPage/AnalyzeRecruitPageはFetchRobots/AnalyzeHtmlSeo同様、
     * 軽量なHTTP取得・静的HTML解析のためanalysis。
     * GenerateBrandWheelAnalysisはOpenAI呼び出しのため、既存の
     * GenerateAiAnalysisJobと同じaiキュー(queue-worker-externalが処理)。
     * CrawlWebsite(CrawlWebsiteJob/CrawlWebsitePageJob)はDB/軽量HTTPのみの
     * ためanalysis(default)。RenderCrawledPages(RenderCrawledPageJob)は
     * Analyzerを呼び出す重い処理のため、既存のRenderPage等と同じ
     * analysis-heavyに合わせる(依頼D-6で新規キューを作らないと判断した
     * 方針をそのまま踏襲)。
     */
    public function queueName(): string
    {
        return match ($this) {
            self::RenderPage,
            self::CaptureScreenshotDesktop,
            self::CaptureScreenshotMobile,
            self::RunLighthouse,
            self::DetectTechnology,
            self::RunRecruitLighthouse,
            self::RenderCrawledPages => 'analysis-heavy',
            self::FetchExternalSeoData => 'external-api',
            self::GenerateBrandWheelAnalysis => 'ai',
            default => 'analysis',
        };
    }

    /**
     * サイト単位で発生するジョブ種別 (Analysis単位のオーケストレーションジョブを除く)。
     *
     * @return list<self>
     */
    public static function websiteLevelTypes(): array
    {
        return [
            self::FetchStaticPage,
            self::FetchRobots,
            self::FetchSitemap,
            self::RenderPage,
            self::CaptureScreenshotDesktop,
            self::CaptureScreenshotMobile,
            self::RunLighthouse,
            self::AnalyzeHtmlSeo,
            self::ReanalyzeRenderedHtml,
            self::DetectTechnology,
            self::FetchExternalSeoData,
            self::FetchRecruitPage,
            self::AnalyzeRecruitPage,
            self::RunRecruitLighthouse,
            self::GenerateBrandWheelAnalysis,
            self::CrawlWebsite,
            self::RenderCrawledPages,
            self::FinalizeWebsiteAnalysis,
        ];
    }

    /**
     * StartAnalysisJobがファンアウトで直接、または(AnalyzeHtmlSeoJobのように)
     * 間接的に起動するサイト単位のジョブ種別。websiteLevelTypes()から
     * FinalizeWebsiteAnalysisを除いたもの。
     *
     * FinalizeWebsiteAnalysisJob自身はこれらが全て終端状態になった「結果」として
     * 起動されるため、この一覧には含めない ―― 含めてしまうと
     * 「FinalizeWebsiteAnalysis自身が終端状態になるまでFinalizeWebsiteAnalysisJobを
     * 起動しない」という循環待ちになり、進捗が100%手前で永久に止まってしまう
     * (実際に発生したデッドロック)。
     *
     * @return list<self>
     */
    public static function websiteFanOutTypes(): array
    {
        return array_values(array_filter(
            self::websiteLevelTypes(),
            fn (self $type) => $type !== self::FinalizeWebsiteAnalysis,
        ));
    }
}

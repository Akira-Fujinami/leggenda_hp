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
    case DetectTechnology = 'detect_technology';
    case FinalizeWebsiteAnalysis = 'finalize_website_analysis';
    case FinalizeAnalysis = 'finalize_analysis';

    /**
     * WebsiteAnalysisの進捗(0-100)に対する重み。サイト単位のジョブ10種で
     * 合計100になるようにしてある (Start/FinalizeAnalysisはAnalysis単位の
     * オーケストレーション用ジョブのため重みを持たない)。
     */
    public function weight(): int
    {
        return match ($this) {
            self::FetchStaticPage => 15,
            self::FetchRobots => 5,
            self::FetchSitemap => 5,
            self::RenderPage => 15,
            self::CaptureScreenshotDesktop => 10,
            self::CaptureScreenshotMobile => 10,
            self::RunLighthouse => 20,
            self::AnalyzeHtmlSeo => 10,
            self::DetectTechnology => 5,
            self::FinalizeWebsiteAnalysis => 5,
            self::StartAnalysis, self::FinalizeAnalysis => 0,
        };
    }

    /**
     * このジョブ種別が実行されるキュー名。
     * analysis: DB/軽量HTTP中心の処理。analysis-heavy: analyzer(Playwright/Lighthouse)を
     * 呼び出す重い処理で、専用ワーカー(queue-worker-heavy)に隔離する。
     */
    public function queueName(): string
    {
        return match ($this) {
            self::RenderPage,
            self::CaptureScreenshotDesktop,
            self::CaptureScreenshotMobile,
            self::RunLighthouse,
            self::DetectTechnology => 'analysis-heavy',
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
            self::DetectTechnology,
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
     * 起動しない」という循環待ちになり、進捗が100%手前(現状95%)で
     * 永久に止まってしまう(実際に発生したデッドロック)。
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

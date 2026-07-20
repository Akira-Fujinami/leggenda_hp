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
    case FetchExternalSeoData = 'fetch_external_seo_data';
    case FinalizeWebsiteAnalysis = 'finalize_website_analysis';
    case FinalizeAnalysis = 'finalize_analysis';

    /**
     * WebsiteAnalysisの進捗(0-100)に対する重み。サイト単位のジョブ11種で
     * 合計100になるようにしてある (Start/FinalizeAnalysisはAnalysis単位の
     * オーケストレーション用ジョブのため重みを持たない)。
     *
     * Phase 3でFetchExternalSeoData(Semrush等)を追加したため、既存の重みを
     * 比例的に少しずつ下げて11点分を確保した(合計は引き続き100)。
     */
    public function weight(): int
    {
        return match ($this) {
            self::FetchStaticPage => 13,
            self::FetchRobots => 5,
            self::FetchSitemap => 5,
            self::RenderPage => 13,
            self::CaptureScreenshotDesktop => 9,
            self::CaptureScreenshotMobile => 9,
            self::RunLighthouse => 17,
            self::AnalyzeHtmlSeo => 9,
            self::DetectTechnology => 4,
            self::FetchExternalSeoData => 11,
            self::FinalizeWebsiteAnalysis => 5,
            self::StartAnalysis, self::FinalizeAnalysis => 0,
        };
    }

    /**
     * このジョブ種別が実行されるキュー名。
     * analysis: DB/軽量HTTP中心の処理。analysis-heavy: analyzer(Playwright/Lighthouse)を
     * 呼び出す重い処理で、専用ワーカー(queue-worker-heavy)に隔離する。
     * external-api: Semrush等の外部SEO API呼び出し(通常のanalysisキューとは
     * 分離し、外部APIのレート制限・障害が他ジョブに波及しないようにする)。
     */
    public function queueName(): string
    {
        return match ($this) {
            self::RenderPage,
            self::CaptureScreenshotDesktop,
            self::CaptureScreenshotMobile,
            self::RunLighthouse,
            self::DetectTechnology => 'analysis-heavy',
            self::FetchExternalSeoData => 'external-api',
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
            self::FetchExternalSeoData,
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

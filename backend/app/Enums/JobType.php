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

    /**
     * WebsiteAnalysisの進捗(0-100)に対する重み。サイト単位のジョブ15種で
     * 合計100になるようにしてある (Start/FinalizeAnalysisはAnalysis単位の
     * オーケストレーション用ジョブのため重みを持たない)。
     *
     * Phase 3でFetchExternalSeoData(Semrush等)を追加した際、既存の重みを
     * 比例的に少しずつ下げて11点分を確保した。静的/レンダリング済みHTML
     * 競合修正でReanalyzeRenderedHtmlを追加した際は、RenderPageの重みから
     * 4点を移した(RenderPageの後続処理という位置づけ)。
     *
     * Phase 3で採用ページ向け3ジョブ(FetchRecruitPage/AnalyzeRecruitPage/
     * RunRecruitLighthouse、合計12点)を追加した際は、既存12種の重みを
     * 一律88%(=88/100)に比例スケールし、端数は四捨五入した
     * (合計は引き続き100。厳密な検算はJobTypeWeightTestを参照)。
     */
    public function weight(): int
    {
        return match ($this) {
            self::FetchStaticPage => 11,
            self::FetchRobots => 4,
            self::FetchSitemap => 4,
            self::RenderPage => 8,
            self::CaptureScreenshotDesktop => 8,
            self::CaptureScreenshotMobile => 8,
            self::RunLighthouse => 15,
            self::AnalyzeHtmlSeo => 8,
            self::ReanalyzeRenderedHtml => 4,
            self::DetectTechnology => 4,
            self::FetchExternalSeoData => 10,
            self::FinalizeWebsiteAnalysis => 4,
            self::FetchRecruitPage => 3,
            self::AnalyzeRecruitPage => 3,
            self::RunRecruitLighthouse => 6,
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
     */
    public function queueName(): string
    {
        return match ($this) {
            self::RenderPage,
            self::CaptureScreenshotDesktop,
            self::CaptureScreenshotMobile,
            self::RunLighthouse,
            self::DetectTechnology,
            self::RunRecruitLighthouse => 'analysis-heavy',
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
            self::ReanalyzeRenderedHtml,
            self::DetectTechnology,
            self::FetchExternalSeoData,
            self::FetchRecruitPage,
            self::AnalyzeRecruitPage,
            self::RunRecruitLighthouse,
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

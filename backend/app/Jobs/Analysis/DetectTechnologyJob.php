<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\MetricResult;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalyzerClient;
use App\Services\Analysis\PageHtmlResolver;
use Illuminate\Support\Facades\Storage;

/**
 * 使用技術の検出。可能ならRenderPageJobのレンダリング後HTML、無ければ
 * FetchStaticPageJobの静的HTMLをanalyzerへのヒントとして渡す
 * (どちらも無い場合はURLのみで呼び出し、analyzer側で取得させる)。
 *
 * 「技術の種類そのものへの優劣」はつけない方針のため、CMS/フレームワーク/
 * 計測ツールの個別検出結果はscoring_type=not_scored(採点対象外・情報表示専用)
 * として記録し、実際に採点対象となるのは「アクセス解析が設置されているか」
 * (analytics_configured)のみとする。
 */
class DetectTechnologyJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 2;

    // AnalyzerClient::technology()のHTTP timeout(60秒)と同値だと、RunLighthouseJobで
    // 実際に発生した障害と同じクラスの不具合(ジョブtimeoutとHTTP timeoutの
    // 競合によるWorkerプロセス強制終了)が起こり得る。30秒以上のマージンを保つ。
    public $timeout = 90;

    public $backoff = [10, 30];

    private const INFORMATIONAL_MAP = [
        'ga_detected' => ['Google Analytics'],
        'gtm_detected' => ['Google Tag Manager'],
        'clarity_detected' => ['Microsoft Clarity'],
        'meta_pixel_detected' => ['Meta Pixel'],
        'recaptcha_detected' => ['reCAPTCHA'],
        'cdn_detected' => ['Cloudflare'],
    ];

    private const CMS_OR_FRAMEWORK_CATEGORIES = ['cms', 'framework'];

    /**
     * このJobが担当する全MetricDefinitionキー(recordAllErrorで使う)。
     */
    private const ALL_KEYS = [
        'analytics_configured', 'cms_detected',
        'ga_detected', 'gtm_detected', 'clarity_detected', 'meta_pixel_detected', 'recaptcha_detected', 'cdn_detected',
    ];

    public function jobType(): JobType
    {
        return JobType::DetectTechnology;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $website = $websiteAnalysis->website;

        $page = AnalysisPage::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->where('page_type', PageType::Homepage)
            ->first();

        // 優先順位の解決はPageHtmlResolverへ切り出した(2026-08-04、
        // AnalyzeHtmlSeoJob/BrandWheelAnalysisInputFactoryとの3箇所目の
        // 重複を避けるため)。
        $resolved = app(PageHtmlResolver::class)->resolve($page);
        $html = $resolved !== null ? Storage::disk('analysis')->get($resolved['path']) : null;

        /** @var AnalyzerClient $client */
        $client = app(AnalyzerClient::class);

        try {
            $data = $client->technology($website->normalized_url, $html);

            $technologies = $data['technologies'] ?? [];
            if (! is_array($technologies)) {
                // analyzerがOOM再起動等の途中で、HTTPレベルは「成功」のまま
                // 不完全・想定外の形状のJSONを返すケースへの安全網。
                throw new \UnexpectedValueException('analyzerから技術検出の結果(technologies)が配列以外の形式で返されました。');
            }

            $names = array_column($technologies, 'name');

            $analyticsConfigured = in_array('Google Analytics', $names, true) || in_array('Google Tag Manager', $names, true);
            $this->recordMetric(
                $this->websiteAnalysisId,
                'analytics_configured',
                MetricResultStatus::Success,
                normalizedValue: $analyticsConfigured,
                rawValue: ['technologies' => $technologies],
                evidence: ['count' => count($technologies)],
                // 静的検出(GA/GTMの既知タグの有無)に基づく判定であり、独自計測・
                // サーバーサイド計測・同意後読み込み等は検出できないため、
                // 「未検出」であっても「未設置」と断定できるほどの確信度は無い。
                confidence: $analyticsConfigured ? 0.9 : 0.6,
            );

            $cmsOrFramework = array_values(array_filter($technologies, fn ($t) => in_array($t['category'] ?? null, self::CMS_OR_FRAMEWORK_CATEGORIES, true)));
            $this->recordMetric(
                $this->websiteAnalysisId,
                'cms_detected',
                $cmsOrFramework === [] ? MetricResultStatus::NotFound : MetricResultStatus::Success,
                normalizedValue: $cmsOrFramework[0]['name'] ?? null,
                rawValue: ['detected' => $cmsOrFramework],
            );

            foreach (self::INFORMATIONAL_MAP as $key => $matchNames) {
                $detected = array_values(array_filter($technologies, fn ($t) => in_array($t['name'] ?? null, $matchNames, true)));
                $this->recordMetric(
                    $this->websiteAnalysisId,
                    $key,
                    $detected === [] ? MetricResultStatus::NotFound : MetricResultStatus::Success,
                    normalizedValue: $detected !== [],
                    rawValue: ['detected' => $detected],
                );
            }
        } catch (AnalysisException $e) {
            // HTMLは取得できているが技術検出そのものが失敗したケース。
            // 「未取得0件なのに技術セクションが空」という矛盾を避けるため、
            // 全指標を明示的にError状態で記録してからジョブの
            // retry/backoff/markFailedの既存挙動を保つために再throwする。
            $this->recordAllError($e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            // AnalyzerClient自体は例外を投げなかった(HTTPレベルは成功扱い)が、
            // 返却データの形状が想定外だった場合等。ここでAnalysisExceptionへ
            // 変換せずに素通りさせると、handle()の汎用catch(\Throwable)で
            // UNKNOWN_ERROR(「予期しないエラーが発生しました。」)へ丸められて
            // しまい、原因調査ができなくなる(2026-07-25 Analyzer OOM調査で
            // 実際に観測された症状)。TECHNOLOGY_DETECTION_FAILEDとして
            // 明示的に分類する。
            $this->recordAllError($e->getMessage());

            throw new AnalysisException(AnalysisErrorCode::TechnologyDetectionFailed, '技術検出の結果を処理できませんでした。', $e);
        }
    }

    /**
     * render→desktop→mobile→technology→lighthouseの順次dispatchカスケード
     * (AnalysisPipeline::ANALYZER_CHAIN参照)。
     */
    protected function onWebsiteJobTerminal(AnalysisPipeline $pipeline): void
    {
        $pipeline->dispatchNextAnalyzerJob($this->analysisId, $this->websiteAnalysisId, $this->jobType());
    }

    /**
     * 技術検出そのものが失敗した際、対象の全キーをError状態で記録する。
     * ただし、この同一Analysisの同一websiteAnalysisIdに対して既にSuccessで
     * 記録済みのキー(過去の試行やretryで正しく取得できていたもの)は
     * 上書きしない ―― 一時的な失敗で過去の有効なデータを消してしまわない
     * ようにするため。
     */
    private function recordAllError(string $message): void
    {
        $existingStatuses = MetricResult::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->whereHas('metricDefinition', fn ($q) => $q->whereIn('key', self::ALL_KEYS))
            ->with('metricDefinition:id,key')
            ->get()
            ->keyBy(fn (MetricResult $result) => $result->metricDefinition?->key)
            ->map(fn (MetricResult $result) => $result->status);

        foreach (self::ALL_KEYS as $key) {
            if (($existingStatuses[$key] ?? null) === MetricResultStatus::Success) {
                continue;
            }

            $this->recordMetric($this->websiteAnalysisId, $key, MetricResultStatus::Error, errorMessage: $message);
        }
    }
}

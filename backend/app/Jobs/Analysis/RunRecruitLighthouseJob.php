<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Support\Facades\Log;

/**
 * 採用ページに対してLighthouseを実行する(観点④「見やすさ・使いやすさ」の
 * うち、コントラスト比監査を含むアクセシビリティ監査と体感速度)。
 * 既存のAnalyzerClient::lighthouse()をそのまま再利用し、新たなAnalyzer
 * 呼び出し経路は作らない。
 *
 * ANALYZER_CHAIN(RenderPage→...→RunLighthouse)の直後に連結して実行する
 * ―― トップページ用のRenderPage/Screenshot/DetectTechnology/RunLighthouseと
 * 同時に走らせると、Analyzerへの同時HTTPリクエストが増えメモリ圧迫の
 * リスクが再発するため(2026-07-25の障害調査で判明した既存の設計上の理由と
 * 同じ)。採用ページが見つからない場合は$recruitUrlがnullになり、
 * Analyzerを一切呼び出さずに正常終了する。
 */
class RunRecruitLighthouseJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    private const ALL_KEYS = ['recruit_lighthouse_accessibility', 'recruit_lighthouse_performance'];

    public $tries = 2;

    // RunLighthouseJobと同じ理由(AnalyzerClient::LIGHTHOUSE_TIMEOUT_SECONDSより
    // 30秒以上長く保つ)。
    public $timeout = 360;

    public $backoff = [30, 120];

    public function __construct(
        int $analysisId,
        int $websiteAnalysisId,
        public readonly ?string $recruitUrl,
    ) {
        parent::__construct($analysisId, $websiteAnalysisId);
    }

    public function jobType(): JobType
    {
        return JobType::RunRecruitLighthouse;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        if ($this->recruitUrl === null || $this->recruitUrl === '') {
            return;
        }

        /** @var AnalyzerClient $client */
        $client = app(AnalyzerClient::class);

        try {
            $this->rejectIfAnalyzerBusy($client);
            $data = $client->lighthouse($this->recruitUrl);
        } catch (AnalysisException $e) {
            $this->recordAllUnavailable($e->errorCode->value, $e->getMessage());

            throw $e;
        }

        $scores = $data['scores'] ?? [];
        $evidence = ['url' => $this->recruitUrl];
        $metadata = $data['metadata'] ?? null;
        $confidence = (($metadata['run_count'] ?? 1) <= 1) ? 0.75 : 0.95;

        $this->recordScore('recruit_lighthouse_accessibility', $scores['accessibility'] ?? null, ['scores' => $scores], $evidence, $confidence);
        $this->recordScore('recruit_lighthouse_performance', $scores['performance'] ?? null, ['scores' => $scores], $evidence, $confidence);
    }

    /**
     * @param  array<string, mixed>  $rawValue
     * @param  array<string, mixed>  $evidence
     */
    private function recordScore(string $key, ?float $value, array $rawValue, array $evidence, float $confidence): void
    {
        $this->recordMetric(
            $this->websiteAnalysisId,
            $key,
            $value !== null ? MetricResultStatus::Success : MetricResultStatus::Unavailable,
            normalizedValue: $value,
            rawValue: $rawValue,
            evidence: $evidence,
            confidence: $confidence,
        );
    }

    private function recordAllUnavailable(string $errorCode, string $errorMessage): void
    {
        foreach (self::ALL_KEYS as $key) {
            $this->recordMetric($this->websiteAnalysisId, $key, MetricResultStatus::Unavailable, errorCode: $errorCode, errorMessage: $errorMessage);
        }
    }

    /**
     * RunLighthouseJobと同じ安全弁(共有Playwrightブラウザのactive_contexts確認)。
     */
    private function rejectIfAnalyzerBusy(AnalyzerClient $client): void
    {
        $health = $client->healthDetails();
        $activeContexts = $health['active_contexts'] ?? 0;

        if ($activeContexts > 0) {
            Log::warning('RunRecruitLighthouseJob deferred because the shared analyzer browser still has active contexts', [
                'analysis_id' => $this->analysisId,
                'website_analysis_id' => $this->websiteAnalysisId,
                'active_contexts' => $activeContexts,
            ]);

            throw new AnalysisException(AnalysisErrorCode::AnalyzerUnavailable, 'analyzerが他の処理を実行中のため、Lighthouseの実行を延期します。');
        }
    }
}

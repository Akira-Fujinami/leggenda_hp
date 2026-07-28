<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Jobs\Analysis\Concerns\WritesAnalysisStorage;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Support\Facades\Log;

/**
 * Lighthouseの実行。生のLighthouseレポートはAPIレスポンスに含めず、
 * ストレージにのみ保存する。取得できなかった指標はnullのまま(0にしない)。
 * normalized_valueには生の値(スコアは0-100、コアウェブバイタルは実測値)を
 * 保存し、採点(0-1への変換)はMetricScorer(lighthouse/inverse_linear/threshold
 * strategy)側で行う。
 */
class RunLighthouseJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults, WritesAnalysisStorage;

    /**
     * このJobが担当する全MetricDefinitionキー(analyzer呼び出し失敗時に
     * まとめてunavailableにするため)。
     */
    private const ALL_KEYS = [
        'lighthouse_performance', 'lighthouse_accessibility', 'lighthouse_best_practices', 'lighthouse_seo_score',
        'fcp', 'lcp', 'cls', 'speed_index', 'tbt', 'lighthouse_request_count', 'lighthouse_transfer_size',
    ];

    public $tries = 2;

    // AnalyzerClient::LIGHTHOUSE_TIMEOUT_SECONDS(330秒)より30秒以上長く保つこと。
    // 同値または短いと、AnalyzerClient側のHTTP timeoutより先にLaravelキュー基盤の
    // ジョブtimeoutが発火し、handle()のtry/catchを経由せずWorkerプロセスごと
    // 強制終了させてしまう(2026-07-24の本番障害の直接原因)。
    public $timeout = 360;

    public $backoff = [30, 120];

    public function jobType(): JobType
    {
        return JobType::RunLighthouse;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $website = $websiteAnalysis->website;

        /** @var AnalyzerClient $client */
        $client = app(AnalyzerClient::class);

        try {
            $this->rejectIfAnalyzerBusy($client);

            $data = $client->lighthouse($website->normalized_url);

            if (isset($data['raw_report'])) {
                /** @var AnalysisStoragePaths $paths */
                $paths = app(AnalysisStoragePaths::class);
                $metadataPath = $paths->metadataPath($this->analysisId, $this->websiteAnalysisId, 'lighthouse.json');
                $this->putToAnalysisStorage($metadataPath, json_encode($data['raw_report'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (AnalysisException $e) {
            $this->recordAllUnavailable($e->errorCode->value, $e->getMessage());

            throw $e;
        }

        $scores = $data['scores'] ?? [];
        $metrics = $data['metrics'] ?? [];
        $metadata = $data['metadata'] ?? null;
        $evidence = ['url' => $website->normalized_url, 'metadata' => $metadata];
        // run_countが1(単発計測)の場合、外部広告・ネットワーク状況・Cookie
        // 表示等の影響を受けた1回限りの結果である可能性があるため、
        // 通常より低いconfidenceで記録する(3回計測・中央値化は将来課題)。
        $confidence = (($metadata['run_count'] ?? 1) <= 1) ? 0.75 : 0.95;

        $this->recordScore('lighthouse_performance', $scores['performance'] ?? null, ['scores' => $scores], $evidence, $confidence);
        $this->recordScore('lighthouse_accessibility', $scores['accessibility'] ?? null, ['scores' => $scores], $evidence, $confidence);
        $this->recordScore('lighthouse_best_practices', $scores['best_practices'] ?? null, ['scores' => $scores], $evidence, $confidence);
        $this->recordScore('lighthouse_seo_score', $scores['seo'] ?? null, ['scores' => $scores], $evidence, $confidence);

        $this->recordMetricValue('fcp', $metrics['fcp_ms'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('lcp', $metrics['lcp_ms'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('cls', $metrics['cls'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('speed_index', $metrics['speed_index_ms'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('tbt', $metrics['tbt_ms'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('lighthouse_request_count', $metrics['request_count'] ?? null, $metrics, $evidence, $confidence);
        $this->recordMetricValue('lighthouse_transfer_size', $metrics['transfer_size_bytes'] ?? null, $metrics, $evidence, $confidence);
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

    /**
     * @param  array<string, mixed>  $rawValue
     * @param  array<string, mixed>  $evidence
     */
    private function recordMetricValue(string $key, mixed $value, array $rawValue, array $evidence, float $confidence): void
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
     * Lighthouseは共有Playwrightブラウザとは別のChromeプロセスをchrome-launcherで
     * 都度起動するため、共有ブラウザのcontextが残っている間に実行が重なると、
     * 低メモリのRender環境で2つのブラウザプロセス分のメモリを同時に抱える
     * リスクがある。Backend側の順次dispatch(render→desktop→mobile→
     * technology→lighthouse)により通常は既にactive_contexts=0のはずだが、
     * 別サイト/別分析の処理と重なる可能性が皆無ではないため、念のため
     * ヘルスチェックで確認する。0でなければ即失敗にはせず、$triesの範囲で
     * リトライさせる(無制限の再試行は行わない)。ヘルスチェック自体が
     * 失敗した場合はfail-openとし、Lighthouseの実行を無条件に妨げない。
     */
    private function rejectIfAnalyzerBusy(AnalyzerClient $client): void
    {
        $health = $client->healthDetails();
        $activeContexts = $health['active_contexts'] ?? 0;

        if ($activeContexts > 0) {
            Log::warning('RunLighthouseJob deferred because the shared analyzer browser still has active contexts', [
                'analysis_id' => $this->analysisId,
                'website_analysis_id' => $this->websiteAnalysisId,
                'active_contexts' => $activeContexts,
            ]);

            throw new AnalysisException(AnalysisErrorCode::AnalyzerUnavailable, 'analyzerが他の処理を実行中のため、Lighthouseの実行を延期します。');
        }
    }

    /**
     * Phase 3以前はRunLighthouseがANALYZER_CHAINの最後尾だったため、
     * このフックは不要だった(既定のno-opのままで問題なかった)。
     * RunRecruitLighthouseを末尾に追加したことで、RunLighthouse自身の
     * 終端からも次のチェーン項目を起動する必要がある。
     */
    protected function onWebsiteJobTerminal(AnalysisPipeline $pipeline): void
    {
        $pipeline->dispatchNextAnalyzerJob($this->analysisId, $this->websiteAnalysisId, $this->jobType());
    }
}

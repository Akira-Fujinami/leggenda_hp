<?php

namespace App\Http\Controllers\Api\Lead;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Report\GenerateLeadReportJob;
use App\Models\Analysis;
use App\Models\CategoryDefinition;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\Project;
use App\Models\Report;
use App\Services\Analysis\AnalysisService;
use App\Services\Lead\LeadSessionService;
use App\Services\Scoring\OverallScoreCalculator;
use App\Services\WebsiteService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * リード向け簡易分析(自社1件+競合1件、または自社のみ)。既存の
 * AnalysisService::start()/StartAnalysisJob/AnalysisPipelineをそのまま
 * 再利用する ―― 分析ロジックを別系統で作らない。認可はProjectPolicy等の
 * Gateを使わず、LeadSession(ResolveLeadTokenミドルウェアが解決済み)と
 * 対象リソースのlead_session_idが一致するかをここで直接確認する。
 * リード分析ではCaptureScreenshotJob自体を省略するため(skip_screenshots)、
 * スクリーンショット配信エンドポイントは持たない。
 */
class LeadAnalysisController extends Controller
{
    public function __construct(
        private readonly LeadSessionService $leadSessions,
        private readonly WebsiteService $websites,
        private readonly AnalysisService $analyses,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var LeadSession $leadSession */
        $leadSession = $request->attributes->get('leadSession');

        if (! $this->leadSessions->canStartAnalysis($leadSession)) {
            return response()->json([
                'message' => 'このリンクでの診断は既にご利用いただいております。追加のご相談はお問い合わせフォームからお願いいたします。',
                'errors' => [],
                'error_code' => 'LEAD_ANALYSIS_QUOTA_EXCEEDED',
            ], 403);
        }

        if ($this->isCongested()) {
            // トークンを消費してはいけない(混雑は本人の責任ではないため)。
            return response()->json([
                'message' => '現在混み合っています。しばらくしてから再度お試しください。',
                'errors' => [],
                'error_code' => 'LEAD_ANALYZER_BUSY',
            ], 503);
        }

        $data = $request->validate([
            'self_url' => ['required', 'string', 'max:2048'],
            'competitor_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $sentinelUser = $this->leadSessions->sentinelUser();

        $project = new Project(['name' => "{$leadSession->company_name} 様セルフ診断"]);
        $project->user_id = $sentinelUser->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $selfWebsite = $this->websites->create($project, ['name' => '自社サイト', 'url' => $data['self_url'], 'is_primary' => true]);

        if (! empty($data['competitor_url'])) {
            $this->websites->create($project, ['name' => '比較サイト', 'url' => $data['competitor_url'], 'is_primary' => false]);
        }

        $analysis = $this->analyses->start($project, [
            'max_websites' => (int) config('lead.max_websites'),
            'skip_lighthouse' => (bool) config('lead.skip_lighthouse'),
            // CaptureScreenshotJobは77指標のうち1つも記録しないため、
            // リード分析では撮影自体を省略する(採点への影響はゼロ)。
            'skip_screenshots' => (bool) config('lead.skip_screenshots'),
        ], $sentinelUser);

        $this->leadSessions->recordAnalysisStarted($leadSession);

        return $this->success(['analysis_id' => $analysis->id], [], null, 201);
    }

    public function progress(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);
        $this->maybeDispatchReportGeneration($analysis);

        return $this->success([
            'percent' => $analysis->progress,
            'status' => $this->leadFacingStatus($analysis->status),
            'message' => $this->leadFacingMessage($analysis->status),
        ]);
    }

    public function results(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);
        $this->maybeDispatchReportGeneration($analysis);

        $analysis->load([
            'websiteAnalyses.website',
            'websiteAnalyses.recommendations',
            'websiteAnalyses.metricResults.metricDefinition',
        ]);

        $activeCategories = CategoryDefinition::query()->where('is_active', true)->orderBy('display_order')->get();
        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();
        $calculator = app(OverallScoreCalculator::class);

        return $this->success([
            'status' => $this->leadFacingStatus($analysis->status),
            'reports' => $this->reportStatusPayload($analysis),
            'websites' => $analysis->websiteAnalyses->map(function ($wa) use ($activeCategories, $activeDefinitions, $calculator) {
                $score = $calculator->calculate($activeCategories, $activeDefinitions, $wa->metricResults);

                return [
                    'website_name' => $wa->website?->name,
                    'is_primary' => (bool) $wa->website?->is_primary,
                    'score' => $score->toArray(),
                    // 指標77件の詳細一覧・Job名・エラーコード・内部IDは
                    // 意図的に含めない(社内担当が説明する余地を残すため)。
                    'top_recommendations' => $wa->recommendations
                        ->sortByDesc('sort_score')
                        ->take(3)
                        ->map(fn ($r) => [
                            'title' => $r->title,
                            'description' => $r->description,
                            'priority' => $r->priority->value,
                            'impact' => $r->impact->value,
                            'effort' => $r->effort->value,
                        ])->values(),
                ];
            })->values(),
        ]);
    }

    /**
     * リード向けレポート(Word/PDF)のダウンロード。既存のAnalysisController::
     * screenshot()と同じ方針(Storageへ直接アクセスさせず、DBが指す
     * storage_pathのみ配信)を踏襲する。storage_path・error_message等の
     * 内部情報はレスポンスに一切含めない。
     */
    public function downloadReport(Request $request, Analysis $analysis, string $format): StreamedResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);

        $formatEnum = ReportFormat::tryFrom($format);

        if ($formatEnum === null) {
            abort(404);
        }

        $report = Report::query()
            ->where('analysis_id', $analysis->id)
            ->where('format', $formatEnum->value)
            ->first();

        if ($report === null || $report->status !== ReportGenerationStatus::Completed) {
            throw new HttpResponseException(response()->json([
                'message' => 'レポートはまだ準備中です。しばらくしてから再度お試しください。',
                'errors' => [],
                'error_code' => 'REPORT_NOT_READY',
            ], 409));
        }

        if (! Storage::disk('analysis')->exists($report->storage_path)) {
            abort(404);
        }

        $filename = '診断レポート.'.$formatEnum->fileExtension();

        return Storage::disk('analysis')->download($report->storage_path, $filename, [
            'Content-Type' => $formatEnum->contentType(),
        ]);
    }

    /**
     * 結果が終端状態(completed/partial)になった最初のポーリングで一度だけ
     * レポート生成Jobを起動する。Reportテーブルの行(pending)をこの場で
     * 即座に作ってから冪等性のガードにするため、Job自体がまだ実行されて
     * いない(=行がまだ無い)間に2回目のポーリングが来ても二重dispatchしない。
     * 同時リクエストでの競合は(analysis_id, format)のunique制約違反として
     * 検出し、その場合は既に他方が作成済みとみなして何もしない。
     * failedの分析にはレポートを生成しない(表示できる結果が何もないため)。
     * 既存のFinalizeWebsiteAnalysisJob/FinalizeAnalysisJob等の共有
     * パイプラインには一切触れない。
     */
    private function maybeDispatchReportGeneration(Analysis $analysis): void
    {
        $status = $this->leadFacingStatus($analysis->status);

        if (! in_array($status, ['completed', 'partial'], true)) {
            return;
        }

        if (Report::query()->where('analysis_id', $analysis->id)->exists()) {
            return;
        }

        try {
            foreach ([ReportFormat::Docx, ReportFormat::Pdf] as $format) {
                Report::query()->create([
                    'analysis_id' => $analysis->id,
                    'format' => $format->value,
                    'storage_path' => '',
                    'status' => ReportGenerationStatus::Pending->value,
                ]);
            }
        } catch (QueryException) {
            return;
        }

        GenerateLeadReportJob::dispatch($analysis->id)->onQueue('reports');
    }

    /**
     * @return array{docx: string, pdf: string}
     */
    private function reportStatusPayload(Analysis $analysis): array
    {
        $reports = Report::query()->where('analysis_id', $analysis->id)->get()->keyBy('format');

        return [
            'docx' => $this->singleReportStatus($reports->get(ReportFormat::Docx->value)),
            'pdf' => $this->singleReportStatus($reports->get(ReportFormat::Pdf->value)),
        ];
    }

    private function singleReportStatus(?Report $report): string
    {
        if ($report === null) {
            return 'processing';
        }

        return match ($report->status) {
            ReportGenerationStatus::Completed => 'ready',
            ReportGenerationStatus::Failed => 'unavailable',
            ReportGenerationStatus::Pending => 'processing',
        };
    }

    private function authorizeLeadOwnsAnalysis(Request $request, Analysis $analysis): void
    {
        /** @var LeadSession $leadSession */
        $leadSession = $request->attributes->get('leadSession');

        $analysis->loadMissing('project');

        if ($analysis->project?->lead_session_id !== $leadSession->id) {
            abort(404);
        }
    }

    private function isCongested(): bool
    {
        $inFlight = Analysis::query()
            ->whereHas('project', fn ($q) => $q->whereNotNull('lead_session_id'))
            ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Running])
            ->count();

        return $inFlight >= (int) config('lead.max_concurrent_analyses');
    }

    private function leadFacingStatus(AnalysisStatus $status): string
    {
        return match ($status) {
            AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Running => 'processing',
            AnalysisStatus::Completed => 'completed',
            AnalysisStatus::Partial => 'partial',
            AnalysisStatus::Failed, AnalysisStatus::Cancelled => 'failed',
        };
    }

    private function leadFacingMessage(AnalysisStatus $status): string
    {
        return match ($status) {
            AnalysisStatus::Pending, AnalysisStatus::Queued => '診断の準備をしています…',
            AnalysisStatus::Running => 'サイトを診断しています。しばらくお待ちください…',
            AnalysisStatus::Completed => '診断が完了しました。',
            AnalysisStatus::Partial => '診断が完了しました(一部のデータは取得できませんでした)。',
            AnalysisStatus::Failed, AnalysisStatus::Cancelled => '診断に失敗しました。時間をおいて再度お試しください。',
        };
    }
}

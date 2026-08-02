<?php

namespace App\Http\Controllers\Api\Lead;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Jobs\Report\GenerateLeadReportJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\Project;
use App\Models\Report;
use App\Services\Analysis\AnalysisService;
use App\Services\Lead\LeadNotificationService;
use App\Services\Lead\LeadPerspectiveComposer;
use App\Services\Lead\LeadRecommendationComposer;
use App\Services\Lead\LeadScoreCalculator;
use App\Services\Lead\LeadSessionService;
use App\Services\WebsiteService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
        private readonly LeadNotificationService $notifications,
        private readonly LeadRecommendationComposer $recommendationComposer,
    ) {}

    /**
     * 画面向けの「特に改善効果が見込まれる項目」の件数。Word/PDFレポート
     * (ReportViewModelBuilder::MAX_RECOMMENDATIONS)とは意図的に別値 ――
     * 画面は要点のみ、レポートは持ち帰り資料として少し多めに載せる。
     */
    private const TOP_RECOMMENDATIONS_LIMIT = 3;

    public function store(Request $request): JsonResponse
    {
        /** @var LeadSession $leadSession */
        $leadSession = $request->attributes->get('leadSession');

        if (! $this->leadSessions->canStartAnalysis($leadSession)) {
            // 「開けない」という問い合わせの原因切り分け用(2026-07-29対応)。
            // トークン値・会社名等の個人情報は記録しない。
            Log::info('Lead analysis start rejected: quota exceeded', ['lead_session_id' => $leadSession->id]);

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

        // 通知はnotificationsキュー経由の非同期送信のため、ここでの失敗は
        // このレスポンス(=リードの診断開始そのもの)に一切影響しない。
        $rawToken = (string) $request->attributes->get('leadToken');
        $this->notifications->notifyAnalysisStarted($leadSession, $analysis->id, $rawToken);

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
            'websiteAnalyses.recommendations.metricResult.metricDefinition',
            'websiteAnalyses.metricResults.metricDefinition',
        ]);

        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();
        $calculator = app(LeadScoreCalculator::class);
        $perspectiveComposer = app(LeadPerspectiveComposer::class);

        return $this->success([
            'status' => $this->leadFacingStatus($analysis->status),
            'reports' => $this->reportStatusPayload($analysis),
            'websites' => $analysis->websiteAnalyses->map(function ($wa) use ($activeDefinitions, $calculator, $perspectiveComposer) {
                // 社内版(OverallScoreCalculator、7カテゴリ100点)とは別建ての
                // リード向け点数 ―― 4観点(LeadMetricCatalog)に表示している
                // 指標だけを対象に算出するため、画面の4観点表示と数値が
                // 完全に一致する(2026-07-28のユーザー指摘への対応)。
                // 社内版のスコアとは満点も内訳も異なるため、商談時に
                // 混同しないこと。
                $score = $calculator->calculate($activeDefinitions, $wa->metricResults);

                return [
                    'website_name' => $wa->website?->name,
                    'is_primary' => (bool) $wa->website?->is_primary,
                    'score' => $score->toArray(),
                    // 採用担当向けの4観点(書くべきこと/メッセージ/導線/見やすさ)。
                    // 内部の7カテゴリ(technical_seo等)は意図的に含めない
                    // ―― 採用担当の関心に沿わない情報量を増やさないため。
                    'perspectives' => $perspectiveComposer->compose($wa->metricResults),
                    // 指標77件の詳細一覧・Job名・エラーコード・内部IDは
                    // 意図的に含めない(社内担当が説明する余地を残すため)。
                    // title/descriptionはLeadRecommendationComposer経由 ―― 4観点
                    // (LeadMetricCatalog)に無いキー(例: アクセス解析タグ検出)は
                    // ここで絞り込まれ、一切表示されない(2026-08-03)。
                    'top_recommendations' => collect($this->recommendationComposer->compose(
                        $wa->recommendations,
                        self::TOP_RECOMMENDATIONS_LIMIT,
                    ))->map(fn (array $row) => [
                        'title' => $row['title'],
                        'description' => $row['description'],
                        'priority' => $row['recommendation']->priority->value,
                        'impact' => $row['recommendation']->impact->value,
                        'effort' => $row['recommendation']->effort->value,
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
     * 「もっと他社と比較したい/相談したい」ボタン。二重送信防止は
     * LeadSessionService::recordConsultationRequested()の条件付きUPDATEに
     * 一任する(このメソッド自身はread-then-writeの判定を行わない)。
     * 2回目以降の押下もエラーにはせず、既に送信済みである旨を返す
     * (ユーザーから見て連打が失敗として見えないようにするため)。
     *
     * ブランド・ホイール(6軸)分析Jobの起動もここで行うが、1通目
     * (相談受付)メールの送信が確定した「後」に行う ―― 評価Job側の
     * 失敗が相談受付そのもの・1通目メールを巻き込まないようにするため
     * (同一トランザクション・同一ジョブチェーンには置かない)。
     */
    public function requestConsultation(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);

        /** @var LeadSession $leadSession */
        $leadSession = $request->attributes->get('leadSession');

        $isFirstRequest = $this->leadSessions->recordConsultationRequested($leadSession);

        if ($isFirstRequest) {
            $rawToken = (string) $request->attributes->get('leadToken');
            $this->notifications->notifyConsultationRequested(
                $leadSession,
                $analysis->id,
                $rawToken,
                $this->scoreSummaryFor($analysis),
            );

            $this->dispatchBrandWheelAnalysis($analysis);
        }

        return $this->success(['already_requested' => ! $isFirstRequest]);
    }

    /**
     * ブランド・ホイール(6軸)分析Jobを起動する。生成物は社内向けのみで
     * リードには一切表示されないため、失敗してもこのレスポンス・1通目
     * メールには一切影響させない(例外はここで握りつぶし、ログにのみ残す)。
     *
     * リードの個人情報(会社名・担当者名・電話番号・メールアドレス・
     * フォーム入力値)はJobへ一切渡さない ―― 渡すのはBrandWheelAnalysisResult
     * のIDのみで、GenerateBrandWheelAnalysisJob自身もWebsiteAnalysisのIDしか
     * 受け取らない(LeadSessionへの依存を持たない設計、BrandWheelAnalysis
     * InputFactory参照)。
     *
     * OpenAIの二重呼び出し防止はGenerateBrandWheelAnalysisJobのShouldBeUnique
     * (#95)に一任する。このメソッド自体は$isFirstRequestのゲート
     * (recordConsultationRequestedの条件付きUPDATE)により、同一相談リクエストで
     * 二重に呼ばれることはない。
     */
    private function dispatchBrandWheelAnalysis(Analysis $analysis): void
    {
        try {
            $analysis->loadMissing('websiteAnalyses.website');
            $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn ($wa) => (bool) $wa->website?->is_primary);

            if ($selfWebsiteAnalysis === null) {
                // 通常の経路(store())では自社サイトのWebsiteは必ずis_primary=trueで
                // 作られるため、ここに来るのは想定外のデータ不整合である可能性が
                // 高い。運用上検知されるべき障害としてLog::errorで記録する
                // (2026-07-29の指摘によりLog::warningから格上げ)。
                Log::error('Brand wheel analysis dispatch skipped: no primary WebsiteAnalysis found', [
                    'analysis_id' => $analysis->id,
                ]);

                return;
            }

            $record = BrandWheelAnalysisResult::query()->create([
                'analysis_id' => $analysis->id,
                'website_analysis_id' => $selfWebsiteAnalysis->id,
                'status' => 'pending',
                'is_mock' => false,
                'input_hash' => '',
            ]);

            GenerateBrandWheelAnalysisJob::dispatch($record->id)->onQueue('ai');
        } catch (Throwable $e) {
            report($e);
            Log::warning('Brand wheel analysis dispatch failed', ['analysis_id' => $analysis->id]);
        }
    }

    /**
     * 通知メール本文向けの一文サマリー。LeadScoreCalculator(4観点スコープの
     * リード向けスコア)を再利用し、社内版のOverallScoreCalculatorには
     * 一切触れない。
     */
    private function scoreSummaryFor(Analysis $analysis): string
    {
        $analysis->loadMissing(['websiteAnalyses.website', 'websiteAnalyses.metricResults.metricDefinition']);

        $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn ($wa) => (bool) $wa->website?->is_primary);

        if ($selfWebsiteAnalysis === null) {
            return '(診断結果を取得できませんでした)';
        }

        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();
        $score = app(LeadScoreCalculator::class)->calculate($activeDefinitions, $selfWebsiteAnalysis->metricResults);

        return sprintf(
            '%d点 / %d点(測定カバー率%s%%)',
            $score->displayScore,
            (int) round($score->configuredMaxScore),
            number_format($score->coverageRate, 1),
        );
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

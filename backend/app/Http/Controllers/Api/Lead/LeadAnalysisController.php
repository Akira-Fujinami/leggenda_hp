<?php

namespace App\Http\Controllers\Api\Lead;

use App\Enums\AnalysisStatus;
use App\Enums\Device;
use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Models\CategoryDefinition;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\Project;
use App\Models\Screenshot;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisService;
use App\Services\Lead\LeadSessionService;
use App\Services\Scoring\OverallScoreCalculator;
use App\Services\WebsiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * リード向け簡易分析(自社1件+競合1件、または自社のみ)。既存の
 * AnalysisService::start()/StartAnalysisJob/AnalysisPipelineをそのまま
 * 再利用する ―― 分析ロジックを別系統で作らない。認可はProjectPolicy等の
 * Gateを使わず、LeadSession(ResolveLeadTokenミドルウェアが解決済み)と
 * 対象リソースのlead_session_idが一致するかをここで直接確認する。
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
        ], $sentinelUser);

        $this->leadSessions->recordAnalysisStarted($leadSession);

        return $this->success(['analysis_id' => $analysis->id], [], null, 201);
    }

    public function progress(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);

        return $this->success([
            'percent' => $analysis->progress,
            'status' => $this->leadFacingStatus($analysis->status),
            'message' => $this->leadFacingMessage($analysis->status),
        ]);
    }

    public function results(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);

        $analysis->load([
            'websiteAnalyses.website',
            'websiteAnalyses.screenshots',
            'websiteAnalyses.recommendations',
            'websiteAnalyses.metricResults.metricDefinition',
        ]);

        $activeCategories = CategoryDefinition::query()->where('is_active', true)->orderBy('display_order')->get();
        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();
        $calculator = app(OverallScoreCalculator::class);

        return $this->success([
            'status' => $this->leadFacingStatus($analysis->status),
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
                    'screenshots' => $wa->screenshots->map(fn ($s) => [
                        'device' => $s->device->value,
                        'url' => route('lead.analyses.screenshot', ['websiteAnalysis' => $wa->id, 'device' => $s->device->value]),
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    /**
     * リード向けのスクリーンショット配信。既存のAnalysisController::screenshot()
     * (auth:sanctum配下)とは別の、lead.tokenミドルウェア配下の専用エンドポイント
     * とする ―― リードはSanctumセッションを持たないため、既存エンドポイントを
     * 直接は使えない。認可ロジック(Storageへ直接アクセスさせず、DBが指す
     * storage_pathのみ配信する)は既存と同じ方針を踏襲する。
     */
    public function screenshot(Request $request, WebsiteAnalysis $websiteAnalysis, string $device): StreamedResponse|Response
    {
        $this->authorizeLeadOwnsWebsiteAnalysis($request, $websiteAnalysis);

        $deviceEnum = Device::tryFrom($device);

        if ($deviceEnum === null) {
            abort(404);
        }

        $screenshot = Screenshot::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('device', $deviceEnum)
            ->first();

        if ($screenshot === null || ! Storage::disk('analysis')->exists($screenshot->storage_path)) {
            abort(404);
        }

        return Storage::disk('analysis')->response($screenshot->storage_path, null, [
            'Content-Type' => $screenshot->mime_type,
        ]);
    }

    private function authorizeLeadOwnsWebsiteAnalysis(Request $request, WebsiteAnalysis $websiteAnalysis): void
    {
        /** @var LeadSession $leadSession */
        $leadSession = $request->attributes->get('leadSession');

        $websiteAnalysis->loadMissing('analysis.project');

        if ($websiteAnalysis->analysis?->project?->lead_session_id !== $leadSession->id) {
            abort(404);
        }
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

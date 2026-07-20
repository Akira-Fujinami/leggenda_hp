<?php

namespace App\Http\Controllers\Api;

use App\Enums\Device;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analyses\StartAnalysisRequest;
use App\Http\Resources\AnalysisProgressResource;
use App\Http\Resources\AnalysisResource;
use App\Http\Resources\AnalysisResultsResource;
use App\Models\Analysis;
use App\Models\Project;
use App\Models\Screenshot;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisService;
use App\Services\Comparison\ComparisonAssembler;
use App\Services\History\HistoryComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalysisController extends Controller
{
    public function __construct(
        private readonly AnalysisService $analyses,
        private readonly ComparisonAssembler $comparisonAssembler,
        private readonly HistoryComparisonService $historyComparisonService,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $analyses = $project->analyses()
            ->withCount('websiteAnalyses')
            ->orderByDesc('created_at')
            ->get();

        return $this->success(AnalysisResource::collection($analyses));
    }

    public function store(StartAnalysisRequest $request, Project $project): JsonResponse
    {
        $analysis = $this->analyses->start($project, $request->validated(), $request->user());

        return $this->success(new AnalysisResource($analysis->loadCount('websiteAnalyses')), [], '分析を開始しました。', 201);
    }

    public function show(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorize('view', $analysis);

        $analysis->loadCount('websiteAnalyses');

        return $this->success(new AnalysisResource($analysis));
    }

    public function progress(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorize('view', $analysis);

        $analysis->load(['websiteAnalyses.website', 'websiteAnalyses.jobs']);

        return $this->success(new AnalysisProgressResource($analysis));
    }

    public function results(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorize('view', $analysis);

        $analysis->load([
            'websiteAnalyses.website',
            'websiteAnalyses.pages',
            'websiteAnalyses.jobs',
            'websiteAnalyses.screenshots',
            'websiteAnalyses.metricResults.metricDefinition',
            'websiteAnalyses.recommendations.metricResult.metricDefinition',
        ]);

        return $this->success(new AnalysisResultsResource($analysis));
    }

    public function comparison(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorize('view', $analysis);

        return $this->success($this->comparisonAssembler->assemble($analysis));
    }

    public function historyComparison(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorize('view', $analysis);

        $previousAnalysisId = $request->filled('previous_analysis_id') ? $request->integer('previous_analysis_id') : null;

        $result = $this->historyComparisonService->compare($analysis, $previousAnalysisId, $request->user());

        return $this->success($result);
    }

    /**
     * スクリーンショット画像の配信。analyzerのストレージへ直接アクセスさせず、
     * 必ず認可チェックを経由してLaravelがストリーミングする。
     */
    public function screenshot(Request $request, WebsiteAnalysis $websiteAnalysis, string $device): StreamedResponse|Response
    {
        $this->authorize('view', $websiteAnalysis->analysis);

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
}

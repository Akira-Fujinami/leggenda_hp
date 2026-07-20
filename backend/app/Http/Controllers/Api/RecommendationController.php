<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecommendationResource;
use App\Models\Analysis;
use App\Models\Recommendation;
use App\Models\WebsiteAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function forAnalysis(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorize('view', $analysis);

        $websiteAnalysisIds = $analysis->websiteAnalyses()->pluck('id');

        $query = Recommendation::query()
            ->with('websiteAnalysis.website')
            ->whereIn('website_analysis_id', $websiteAnalysisIds);

        $recommendations = $this->applyFiltersAndSort($request, $query)->get();

        return $this->success(RecommendationResource::collection($recommendations));
    }

    public function forWebsiteAnalysis(Request $request, WebsiteAnalysis $websiteAnalysis): JsonResponse
    {
        $this->authorize('view', $websiteAnalysis->analysis);

        $query = Recommendation::query()
            ->with('websiteAnalysis.website')
            ->where('website_analysis_id', $websiteAnalysis->id);

        $recommendations = $this->applyFiltersAndSort($request, $query)->get();

        return $this->success(RecommendationResource::collection($recommendations));
    }

    private function applyFiltersAndSort(Request $request, \Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        if ($request->filled('category_key')) {
            $query->where('category_key', $request->string('category_key'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }

        if ($request->filled('effort')) {
            $query->where('effort', $request->string('effort'));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }

        if ($request->filled('website_analysis_id')) {
            $query->where('website_analysis_id', $request->integer('website_analysis_id'));
        }

        return match ($request->string('sort')->value()) {
            'impact' => $query->orderByRaw("CASE impact WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END"),
            'effort' => $query->orderByRaw("CASE effort WHEN 'small' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END"),
            'site' => $query->orderBy('website_analysis_id'),
            default => $query->orderByDesc('sort_score'),
        };
    }
}

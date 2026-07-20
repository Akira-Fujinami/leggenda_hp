import { useQuery } from "@tanstack/react-query";
import { comparisonApi } from "@/features/comparison/api";
import type { RecommendationFilters } from "@/types/comparison";

export function comparisonQueryKey(analysisId: number) {
  return ["analyses", "comparison", analysisId] as const;
}
export function historyComparisonQueryKey(analysisId: number, previousAnalysisId?: number) {
  return ["analyses", "history-comparison", analysisId, previousAnalysisId ?? null] as const;
}
export function analysisRecommendationsQueryKey(analysisId: number, filters: RecommendationFilters) {
  return ["analyses", "recommendations", analysisId, filters] as const;
}
export function websiteAnalysisRecommendationsQueryKey(websiteAnalysisId: number, filters: RecommendationFilters) {
  return ["website-analyses", "recommendations", websiteAnalysisId, filters] as const;
}

export function useComparison(analysisId: number) {
  return useQuery({
    queryKey: comparisonQueryKey(analysisId),
    queryFn: () => comparisonApi.get(analysisId),
    enabled: Number.isFinite(analysisId),
  });
}

export function useHistoryComparison(analysisId: number, previousAnalysisId?: number) {
  return useQuery({
    queryKey: historyComparisonQueryKey(analysisId, previousAnalysisId),
    queryFn: () => comparisonApi.historyComparison(analysisId, previousAnalysisId),
    enabled: Number.isFinite(analysisId),
  });
}

export function useAnalysisRecommendations(analysisId: number, filters: RecommendationFilters = {}) {
  return useQuery({
    queryKey: analysisRecommendationsQueryKey(analysisId, filters),
    queryFn: () => comparisonApi.recommendationsForAnalysis(analysisId, filters),
    enabled: Number.isFinite(analysisId),
  });
}

export function useWebsiteAnalysisRecommendations(websiteAnalysisId: number, filters: RecommendationFilters = {}) {
  return useQuery({
    queryKey: websiteAnalysisRecommendationsQueryKey(websiteAnalysisId, filters),
    queryFn: () => comparisonApi.recommendationsForWebsiteAnalysis(websiteAnalysisId, filters),
    enabled: Number.isFinite(websiteAnalysisId),
  });
}

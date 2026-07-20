import { api, ApiEnvelope } from "@/lib/api-client";
import type {
  ComparisonResult,
  HistoryComparisonResult,
  RecommendationFilters,
  RecommendationItem,
} from "@/types/comparison";

function buildQuery(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) search.set(key, String(value));
  }
  const query = search.toString();
  return query ? `?${query}` : "";
}

function toQueryParams(filters: RecommendationFilters): Record<string, string | number | undefined> {
  return { ...filters };
}

export const comparisonApi = {
  get: (analysisId: number) => api.get<ApiEnvelope<ComparisonResult>>(`/api/analyses/${analysisId}/comparison`),

  historyComparison: (analysisId: number, previousAnalysisId?: number) =>
    api.get<ApiEnvelope<HistoryComparisonResult>>(
      `/api/analyses/${analysisId}/history-comparison${buildQuery({ previous_analysis_id: previousAnalysisId })}`
    ),

  recommendationsForAnalysis: (analysisId: number, filters: RecommendationFilters = {}) =>
    api.get<ApiEnvelope<RecommendationItem[]>>(
      `/api/analyses/${analysisId}/recommendations${buildQuery(toQueryParams(filters))}`
    ),

  recommendationsForWebsiteAnalysis: (websiteAnalysisId: number, filters: RecommendationFilters = {}) =>
    api.get<ApiEnvelope<RecommendationItem[]>>(
      `/api/website-analyses/${websiteAnalysisId}/recommendations${buildQuery(toQueryParams(filters))}`
    ),
};

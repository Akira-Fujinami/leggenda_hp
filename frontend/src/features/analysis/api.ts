import { api, ApiEnvelope } from "@/lib/api-client";
import type { Analysis, AnalysisProgress, AnalysisResults } from "@/types/analysis";

export interface StartAnalysisInput {
  website_ids?: number[];
}

export const analysisApi = {
  list: (projectId: number) => api.get<ApiEnvelope<Analysis[]>>(`/api/projects/${projectId}/analyses`),
  start: (projectId: number, input: StartAnalysisInput = {}) =>
    api.post<ApiEnvelope<Analysis>>(`/api/projects/${projectId}/analyses`, input),
  get: (analysisId: number) => api.get<ApiEnvelope<Analysis>>(`/api/analyses/${analysisId}`),
  progress: (analysisId: number) => api.get<ApiEnvelope<AnalysisProgress>>(`/api/analyses/${analysisId}/progress`),
  results: (analysisId: number) => api.get<ApiEnvelope<AnalysisResults>>(`/api/analyses/${analysisId}/results`),
};

import { api, ApiEnvelope } from "@/lib/api-client";
import type { AiAnalysisResult } from "@/types/ai-analysis";

export const aiAnalysisApi = {
  get: (websiteAnalysisId: number) =>
    api.get<ApiEnvelope<AiAnalysisResult | null>>(`/api/website-analyses/${websiteAnalysisId}/ai-analysis`),

  generate: (websiteAnalysisId: number, confirm = false) =>
    api.post<ApiEnvelope<AiAnalysisResult>>(`/api/website-analyses/${websiteAnalysisId}/ai-analysis`, { confirm }),
};

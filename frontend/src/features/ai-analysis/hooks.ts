import { useMutation, useQuery, useQueryClient, type Query } from "@tanstack/react-query";
import { aiAnalysisApi } from "@/features/ai-analysis/api";
import type { ApiEnvelope } from "@/lib/api-client";
import type { AiAnalysisResult } from "@/types/ai-analysis";

export function aiAnalysisQueryKey(websiteAnalysisId: number) {
  return ["website-analyses", "ai-analysis", websiteAnalysisId] as const;
}

const POLLING_STATUSES = ["pending", "running"];

export function useAiAnalysis(websiteAnalysisId: number) {
  return useQuery({
    queryKey: aiAnalysisQueryKey(websiteAnalysisId),
    queryFn: () => aiAnalysisApi.get(websiteAnalysisId),
    enabled: Number.isFinite(websiteAnalysisId),
    refetchInterval: (query: Query<ApiEnvelope<AiAnalysisResult | null>>) => {
      const status = query.state.data?.data?.status;
      if (status && POLLING_STATUSES.includes(status)) {
        return 3000;
      }
      return false;
    },
  });
}

export function useGenerateAiAnalysis(websiteAnalysisId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (confirm: boolean) => aiAnalysisApi.generate(websiteAnalysisId, confirm),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: aiAnalysisQueryKey(websiteAnalysisId) });
    },
  });
}

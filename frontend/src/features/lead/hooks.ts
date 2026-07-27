import type { Query } from "@tanstack/react-query";
import { useMutation, useQuery } from "@tanstack/react-query";
import { ApiEnvelope } from "@/lib/api-client";
import { leadApi } from "@/features/lead/api";
import type { LeadAnalysisStartInput, LeadOnboardingInput, LeadProgress } from "@/types/lead";

const TERMINAL_STATUSES = ["completed", "partial", "failed"] as const;

export function useSubmitLeadOnboarding() {
  return useMutation({
    mutationFn: (input: LeadOnboardingInput) => leadApi.submitOnboarding(input),
  });
}

export function useStartLeadAnalysis(token: string) {
  return useMutation({
    mutationFn: (input: LeadAnalysisStartInput) => leadApi.startAnalysis(token, input),
  });
}

export function useLeadProgress(token: string, analysisId: number | null) {
  return useQuery({
    queryKey: ["lead", "progress", token, analysisId],
    queryFn: () => leadApi.progress(token, analysisId as number),
    enabled: analysisId !== null,
    refetchInterval: (query: Query<ApiEnvelope<LeadProgress>>) => {
      const status = query.state.data?.data.status;
      if (status && (TERMINAL_STATUSES as readonly string[]).includes(status)) {
        return false;
      }
      if (query.state.fetchFailureCount >= 5) {
        return false;
      }
      return 2500;
    },
  });
}

export function useLeadResults(token: string, analysisId: number | null) {
  return useQuery({
    queryKey: ["lead", "results", token, analysisId],
    queryFn: () => leadApi.results(token, analysisId as number),
    enabled: analysisId !== null,
  });
}

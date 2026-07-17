import { useMutation, useQuery, useQueryClient, type Query } from "@tanstack/react-query";
import { analysisApi, StartAnalysisInput } from "@/features/analysis/api";
import { projectQueryKey } from "@/features/projects/hooks";
import type { ApiEnvelope } from "@/lib/api-client";
import type { Analysis, AnalysisProgress, AnalysisStatus } from "@/types/analysis";

const TERMINAL_STATUSES: AnalysisStatus[] = ["completed", "partial", "failed", "cancelled"];

export function analysesQueryKey(projectId: number) {
  return ["analyses", "list", projectId] as const;
}

export function analysisQueryKey(analysisId: number) {
  return ["analyses", "detail", analysisId] as const;
}

export function analysisProgressQueryKey(analysisId: number) {
  return ["analyses", "progress", analysisId] as const;
}

export function analysisResultsQueryKey(analysisId: number) {
  return ["analyses", "results", analysisId] as const;
}

export function useAnalyses(projectId: number) {
  return useQuery({
    queryKey: analysesQueryKey(projectId),
    queryFn: () => analysisApi.list(projectId),
    enabled: Number.isFinite(projectId),
  });
}

export function useStartAnalysis(projectId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input?: StartAnalysisInput) => analysisApi.start(projectId, input ?? {}),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: analysesQueryKey(projectId) });
      queryClient.invalidateQueries({ queryKey: projectQueryKey(projectId) });
    },
  });
}

export function useAnalysis(analysisId: number) {
  return useQuery({
    queryKey: analysisQueryKey(analysisId),
    queryFn: () => analysisApi.get(analysisId),
    enabled: Number.isFinite(analysisId),
  });
}

/**
 * 実行中(pending/queued/running)の間だけ2.5秒間隔でポーリングし、
 * completed/partial/failed/cancelledに到達したら自動的に停止する。
 * 進捗率(0-100)は常にサーバー側の計算結果をそのまま表示し、
 * フロントエンドでは推測しない。
 */
export function useAnalysisProgress(analysisId: number) {
  return useQuery({
    queryKey: analysisProgressQueryKey(analysisId),
    queryFn: () => analysisApi.progress(analysisId),
    enabled: Number.isFinite(analysisId),
    refetchInterval: (query: Query<ApiEnvelope<AnalysisProgress>>) => {
      const status = query.state.data?.data.status;
      if (status && TERMINAL_STATUSES.includes(status)) {
        return false;
      }
      // 連続してエラーになる場合は無限にポーリングし続けず停止する
      // (query-client.tsのretry設定による2回の自動リトライも消費した上での判断)。
      if (query.state.fetchFailureCount >= 5) {
        return false;
      }
      return 2500;
    },
  });
}

export function useAnalysisResults(analysisId: number) {
  return useQuery({
    queryKey: analysisResultsQueryKey(analysisId),
    queryFn: () => analysisApi.results(analysisId),
    enabled: Number.isFinite(analysisId),
  });
}

export function isAnalysisTerminal(status: Analysis["status"]): boolean {
  return TERMINAL_STATUSES.includes(status);
}

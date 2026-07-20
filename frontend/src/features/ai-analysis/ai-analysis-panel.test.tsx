import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AiAnalysisPanel } from "@/features/ai-analysis/ai-analysis-panel";
import { ApiError } from "@/lib/api-client";
import type { AiAnalysisResult } from "@/types/ai-analysis";

const useAiAnalysisMock = vi.fn();
const useGenerateAiAnalysisMock = vi.fn();

vi.mock("@/features/ai-analysis/hooks", () => ({
  useAiAnalysis: (...args: unknown[]) => useAiAnalysisMock(...args),
  useGenerateAiAnalysis: (...args: unknown[]) => useGenerateAiAnalysisMock(...args),
}));

function makeResult(overrides: Partial<AiAnalysisResult> = {}): AiAnalysisResult {
  return {
    id: 1,
    analysis_id: 1,
    website_analysis_id: 1,
    provider: "mock",
    model: null,
    status: "success",
    summary: "テストサイトは概ね良好です。",
    strengths: [{ title: "強み", description: "説明", evidence_metric_keys: [] }],
    weaknesses: [],
    priority_actions: [],
    competitor_insights: [],
    cautions: ["これはモックデータです。実際のAI分析結果ではありません。"],
    confidence: 0.0,
    is_mock: true,
    error_code: null,
    error_message: null,
    generated_at: "2026-07-20T00:00:00+00:00",
    created_at: "2026-07-20T00:00:00+00:00",
    ...overrides,
  };
}

describe("AiAnalysisPanel", () => {
  it("shows an empty-state prompt when no AI analysis has been generated yet", () => {
    useAiAnalysisMock.mockReturnValue({ data: { data: null }, isLoading: false });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate: vi.fn(), isPending: false });

    render(<AiAnalysisPanel websiteAnalysisId={1} />);

    expect(screen.getByText("まだAI分析は生成されていません。")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "AI分析を生成する" })).toBeInTheDocument();
  });

  it("renders a demo-data badge and disclaimer cautions for mock results", () => {
    useAiAnalysisMock.mockReturnValue({ data: { data: makeResult() }, isLoading: false });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate: vi.fn(), isPending: false });

    render(<AiAnalysisPanel websiteAnalysisId={1} />);

    expect(screen.getByText("デモデータ")).toBeInTheDocument();
    expect(screen.getByText(/これはモックデータです/)).toBeInTheDocument();
  });

  it("renders structured strengths distinct from rule-based recommendations, labeled as AI reference info", () => {
    useAiAnalysisMock.mockReturnValue({ data: { data: makeResult() }, isLoading: false });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate: vi.fn(), isPending: false });

    render(<AiAnalysisPanel websiteAnalysisId={1} />);

    expect(screen.getByText("AIによる参考分析")).toBeInTheDocument();
    expect(screen.getByText("参考情報(AI生成)")).toBeInTheDocument();
    expect(screen.getByText("説明", { exact: false })).toBeInTheDocument();
  });

  it("shows a running/pending state while generation is in progress", () => {
    useAiAnalysisMock.mockReturnValue({ data: { data: makeResult({ status: "running", summary: null }) }, isLoading: false });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate: vi.fn(), isPending: false });

    render(<AiAnalysisPanel websiteAnalysisId={1} />);

    expect(screen.getByRole("button", { name: "生成中…" })).toBeDisabled();
  });

  it("shows an error state when generation failed", () => {
    useAiAnalysisMock.mockReturnValue({
      data: { data: makeResult({ status: "error", summary: null, error_message: "OpenAI APIの認証に失敗しました。" }) },
      isLoading: false,
    });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate: vi.fn(), isPending: false });

    render(<AiAnalysisPanel websiteAnalysisId={1} />);

    expect(screen.getByText(/OpenAI APIの認証に失敗しました。/)).toBeInTheDocument();
  });

  it("asks for confirmation before regenerating an existing result", () => {
    const mutate = vi.fn((_confirm, opts) => {
      opts.onError(new ApiError(409, "既にAI分析結果が存在します。再生成するにはconfirm=trueを指定してください。", {}, null));
    });
    useAiAnalysisMock.mockReturnValue({ data: { data: makeResult() }, isLoading: false });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate, isPending: false });

    render(<AiAnalysisPanel websiteAnalysisId={1} />);
    fireEvent.click(screen.getByRole("button", { name: "再生成する" }));

    expect(screen.getByText(/再生成にはAPIコストが発生します/)).toBeInTheDocument();
  });
});

import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { AiAnalysisSummary } from "@/features/comparison/ai-analysis-summary";
import type { AiAnalysisResult } from "@/types/ai-analysis";
import type { RankingEntry } from "@/types/comparison";

const useAiAnalysisMock = vi.fn();
const useGenerateAiAnalysisMock = vi.fn();

vi.mock("@/features/ai-analysis/hooks", () => ({
  useAiAnalysis: (...args: unknown[]) => useAiAnalysisMock(...args),
  useGenerateAiAnalysis: (...args: unknown[]) => useGenerateAiAnalysisMock(...args),
}));

function makeResult(overrides: Partial<AiAnalysisResult> = {}): AiAnalysisResult {
  return {
    id: 1, analysis_id: 1, website_analysis_id: 1, provider: "mock", model: null, status: "success",
    summary: "概ね良好です。", strengths: [], weaknesses: [], priority_actions: [], competitor_insights: [],
    cautions: [], confidence: 0, is_mock: true, error_code: null, error_message: null,
    generated_at: "2026-07-20T00:00:00+00:00", created_at: "2026-07-20T00:00:00+00:00",
    ...overrides,
  };
}

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "日本旅行", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
  {
    rank: 2, website_analysis_id: 2, website_id: 2, website_name: "楽天トラベル", is_primary: false,
    overall_score: 65, display_score: 65, coverage_rate: 80, confidence_rate: 90, low_data_warning: false,
    score_gap_vs_primary: -15,
  },
];

describe("AiAnalysisSummary", () => {
  it("shows a compact 未生成/生成する row for each site instead of a large panel", () => {
    useAiAnalysisMock.mockReturnValue({ data: { data: null }, isLoading: false });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate: vi.fn(), isPending: false });

    render(<AiAnalysisSummary ranking={ranking} />);

    expect(screen.getAllByText("未生成")).toHaveLength(2);
    expect(screen.getAllByRole("button", { name: "生成する" })).toHaveLength(2);
    expect(screen.queryByText("AIによる参考分析")).not.toBeInTheDocument();
  });

  it("shows a 詳細を見る prompt (not the full panel) when generation already succeeded", () => {
    useAiAnalysisMock.mockReturnValue({ data: { data: makeResult() }, isLoading: false });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate: vi.fn(), isPending: false });

    render(<AiAnalysisSummary ranking={ranking} />);

    expect(screen.queryByText("AIによる参考分析")).not.toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: "詳細を見る" })).toHaveLength(2);
  });

  it("expands to the full AiAnalysisPanel only for the site that was clicked", async () => {
    const user = userEvent.setup();
    useAiAnalysisMock.mockReturnValue({ data: { data: makeResult() }, isLoading: false });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate: vi.fn(), isPending: false });

    render(<AiAnalysisSummary ranking={ranking} />);

    await user.click(screen.getAllByRole("button", { name: "詳細を見る" })[0]);

    expect(screen.getAllByText("AIによる参考分析")).toHaveLength(1);
    // もう一方のサイトはまだコンパクト表示のまま。
    expect(screen.getByRole("button", { name: "詳細を見る" })).toBeInTheDocument();
  });

  it("calls the generate mutation when 生成する is clicked", async () => {
    const user = userEvent.setup();
    const mutate = vi.fn();
    useAiAnalysisMock.mockReturnValue({ data: { data: null }, isLoading: false });
    useGenerateAiAnalysisMock.mockReturnValue({ mutate, isPending: false });

    render(<AiAnalysisSummary ranking={[ranking[0]]} />);
    await user.click(screen.getByRole("button", { name: "生成する" }));

    expect(mutate).toHaveBeenCalledWith(false);
  });
});

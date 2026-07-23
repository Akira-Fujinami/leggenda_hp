import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { RecommendationPreview } from "@/features/comparison/recommendation-preview";
import type { RankingEntry, RecommendationItem } from "@/types/comparison";

const useAnalysisRecommendationsMock = vi.fn();

vi.mock("@/features/comparison/hooks", () => ({
  useAnalysisRecommendations: (...args: unknown[]) => useAnalysisRecommendationsMock(...args),
}));

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "自社サイト", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
];

function makeRecommendation(id: number, sortScore: number): RecommendationItem {
  return {
    id, website_analysis_id: 1, website_name: "自社サイト", category_key: "technical_seo",
    title: `提案${id}`, description: "問題の説明", evidence: { count: 1 }, current_value: { value: false },
    recommended_value: null, priority: "high", impact: "high", effort: "small", confidence: 1,
    status: "open", source: "rule", sort_score: sortScore, created_at: null,
  };
}

describe("RecommendationPreview", () => {
  it("shows only the top 5 recommendations, with a button to see the rest", () => {
    const items = Array.from({ length: 8 }, (_, i) => makeRecommendation(i + 1, 100 - i));
    useAnalysisRecommendationsMock.mockReturnValue({ data: { data: items }, isLoading: false });

    render(<RecommendationPreview analysisId={1} ranking={ranking} />);

    expect(screen.getByText("提案1")).toBeInTheDocument();
    expect(screen.getByText("提案5")).toBeInTheDocument();
    expect(screen.queryByText("提案6")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /すべての改善提案を見る/ })).toBeInTheDocument();
  });

  it("does not show a see-all button when there are 5 or fewer recommendations", () => {
    useAnalysisRecommendationsMock.mockReturnValue({ data: { data: [makeRecommendation(1, 90)] }, isLoading: false });

    render(<RecommendationPreview analysisId={1} ranking={ranking} />);

    expect(screen.queryByRole("button", { name: /すべての改善提案を見る/ })).not.toBeInTheDocument();
  });

  it("hides current value/evidence behind a per-item detail toggle", async () => {
    const user = userEvent.setup();
    useAnalysisRecommendationsMock.mockReturnValue({ data: { data: [makeRecommendation(1, 90)] }, isLoading: false });

    render(<RecommendationPreview analysisId={1} ranking={ranking} />);

    expect(screen.queryByText(/根拠:/)).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "詳細を見る" }));
    expect(screen.getByText(/根拠:/)).toBeInTheDocument();
  });

  it("swaps to the full RecommendationList when すべての改善提案を見る is clicked", async () => {
    const user = userEvent.setup();
    const items = Array.from({ length: 8 }, (_, i) => makeRecommendation(i + 1, 100 - i));
    useAnalysisRecommendationsMock.mockReturnValue({ data: { data: items }, isLoading: false, isError: false });

    render(<RecommendationPreview analysisId={1} ranking={ranking} />);
    await user.click(screen.getByRole("button", { name: /すべての改善提案を見る/ }));

    expect(screen.getByText("提案6")).toBeInTheDocument();
    expect(screen.getByText("ルールベース改善提案")).toBeInTheDocument();
  });

  it("shows an empty-state message when there are no recommendations", () => {
    useAnalysisRecommendationsMock.mockReturnValue({ data: { data: [] }, isLoading: false });

    render(<RecommendationPreview analysisId={1} ranking={ranking} />);

    expect(screen.getByText("現時点で改善提案はありません。")).toBeInTheDocument();
  });
});

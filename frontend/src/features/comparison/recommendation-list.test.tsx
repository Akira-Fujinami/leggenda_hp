import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { RecommendationList } from "@/features/comparison/recommendation-list";
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

function makeRecommendation(overrides: Partial<RecommendationItem> = {}): RecommendationItem {
  return {
    id: 1, website_analysis_id: 1, website_name: "自社サイト", category_key: "technical_seo",
    title: "titleタグを設定してください。", description: null, evidence: null, current_value: null,
    recommended_value: null, priority: "high", impact: "high", effort: "small", confidence: 1,
    status: "open", source: "rule", sort_score: 90, created_at: null,
    ...overrides,
  };
}

describe("RecommendationList", () => {
  it("renders recommendations returned by the hook", () => {
    useAnalysisRecommendationsMock.mockReturnValue({
      data: { data: [makeRecommendation()] },
      isLoading: false,
      isError: false,
    });

    render(<RecommendationList analysisId={1} ranking={ranking} />);

    expect(screen.getByText("titleタグを設定してください。")).toBeInTheDocument();
  });

  it("shows an empty-state message when no recommendations match the filters", () => {
    useAnalysisRecommendationsMock.mockReturnValue({ data: { data: [] }, isLoading: false, isError: false });

    render(<RecommendationList analysisId={1} ranking={ranking} />);

    expect(screen.getByText("条件に一致する改善提案はありません。")).toBeInTheDocument();
  });

  it("shows an error state when the request fails", () => {
    useAnalysisRecommendationsMock.mockReturnValue({ data: undefined, isLoading: false, isError: true });

    render(<RecommendationList analysisId={1} ranking={ranking} />);

    expect(screen.getByText("改善提案の取得に失敗しました。")).toBeInTheDocument();
  });
});

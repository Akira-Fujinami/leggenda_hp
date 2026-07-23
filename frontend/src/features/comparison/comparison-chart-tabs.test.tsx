import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ComparisonChartTabs } from "@/features/comparison/comparison-chart-tabs";
import type { CategoryComparison, RankingEntry } from "@/types/comparison";

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "日本旅行", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
];

const categories: CategoryComparison[] = [
  { key: "performance", name: "表示速度", configured_max_score: 15, sites: [{ website_analysis_id: 1, score: 10, max_available_score: 15, coverage_rate: 100, gap_vs_primary: null }] },
];

describe("ComparisonChartTabs", () => {
  it("shows the category-comparison (radar) tab by default", () => {
    render(<ComparisonChartTabs ranking={ranking} categories={categories} />);

    expect(screen.getByRole("tab", { name: "カテゴリ比較", selected: true })).toBeInTheDocument();
  });

  it("switches to the overall-score (bar chart) tab on click", async () => {
    const user = userEvent.setup();
    render(<ComparisonChartTabs ranking={ranking} categories={categories} />);

    await user.click(screen.getByRole("tab", { name: "総合スコア" }));

    expect(screen.getByRole("tab", { name: "総合スコア", selected: true })).toBeInTheDocument();
  });
});

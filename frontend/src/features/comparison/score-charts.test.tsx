import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { RadarCategoryChart } from "@/features/comparison/score-charts";
import type { CategoryComparison, RankingEntry } from "@/types/comparison";

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "自社サイト", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
  {
    rank: 2, website_analysis_id: 2, website_id: 2, website_name: "競合サイト", is_primary: false,
    overall_score: 65, display_score: 65, coverage_rate: 80, confidence_rate: 90, low_data_warning: false,
    score_gap_vs_primary: -15,
  },
];

async function openTable() {
  const user = userEvent.setup();
  await user.click(screen.getByRole("button", { name: /数値で表示/ }));
}

describe("RadarCategoryChart", () => {
  it("shows the category score budget per site in the (initially collapsed) table below the radar chart", async () => {
    const categories: CategoryComparison[] = [
      {
        key: "technical_seo", name: "技術的SEO", configured_max_score: 20,
        sites: [
          { website_analysis_id: 1, score: 18, max_available_score: 20, coverage_rate: 100, gap_vs_primary: null },
          { website_analysis_id: 2, score: 12, max_available_score: 20, coverage_rate: 100, gap_vs_primary: -6 },
        ],
      },
    ];

    render(<RadarCategoryChart ranking={ranking} categories={categories} />);

    expect(screen.queryByText("18 / 20")).not.toBeInTheDocument();
    await openTable();

    expect(screen.getByText("18 / 20")).toBeInTheDocument();
    expect(screen.getByText("12 / 20")).toBeInTheDocument();
  });

  it("shows 評価不可 for a mock-only category in the table instead of a fabricated fraction", async () => {
    const categories: CategoryComparison[] = [
      {
        key: "authority", name: "外部SEO", configured_max_score: 15,
        sites: [
          { website_analysis_id: 1, score: 0, max_available_score: 0, coverage_rate: 0, gap_vs_primary: null },
          { website_analysis_id: 2, score: 0, max_available_score: 0, coverage_rate: 0, gap_vs_primary: null },
        ],
      },
    ];

    render(<RadarCategoryChart ranking={ranking} categories={categories} />);
    await openTable();

    expect(screen.getAllByText("評価不可")).toHaveLength(2);
    expect(screen.queryByText(/0 \/ 15/)).not.toBeInTheDocument();
  });

  it("still shows a category with real scores in the table alongside a mock-only one", async () => {
    const categories: CategoryComparison[] = [
      {
        key: "technical_seo", name: "技術的SEO", configured_max_score: 20,
        sites: [
          { website_analysis_id: 1, score: 18, max_available_score: 20, coverage_rate: 100, gap_vs_primary: null },
          { website_analysis_id: 2, score: 12, max_available_score: 20, coverage_rate: 100, gap_vs_primary: -6 },
        ],
      },
      {
        key: "authority", name: "外部SEO", configured_max_score: 15,
        sites: [
          { website_analysis_id: 1, score: 0, max_available_score: 0, coverage_rate: 0, gap_vs_primary: null },
          { website_analysis_id: 2, score: 0, max_available_score: 0, coverage_rate: 0, gap_vs_primary: null },
        ],
      },
    ];

    render(<RadarCategoryChart ranking={ranking} categories={categories} />);
    await openTable();

    // 一覧表には両カテゴリとも表示される(評価不可カテゴリも明示的に見える)。
    expect(screen.getByText("技術的SEO")).toBeInTheDocument();
    expect(screen.getByText("外部SEO")).toBeInTheDocument();
    expect(screen.getAllByText("評価不可")).toHaveLength(2);
  });
});

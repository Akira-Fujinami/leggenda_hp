import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {
  CategoryComparisonAccordion,
  findInitialOpenCategory,
} from "@/features/comparison/category-comparison-accordion";
import type { CategoryComparison, MetricComparison, RankingEntry } from "@/types/comparison";

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

const categories: CategoryComparison[] = [
  {
    key: "technical_seo", name: "技術SEO", configured_max_score: 20,
    sites: [
      { website_analysis_id: 1, score: 18, max_available_score: 20, coverage_rate: 100, gap_vs_primary: null },
      { website_analysis_id: 2, score: 19, max_available_score: 20, coverage_rate: 100, gap_vs_primary: 1 },
    ],
  },
  {
    key: "performance", name: "表示速度", configured_max_score: 15,
    sites: [
      { website_analysis_id: 1, score: 0.6, max_available_score: 15, coverage_rate: 100, gap_vs_primary: null },
      { website_analysis_id: 2, score: 3.56, max_available_score: 15, coverage_rate: 100, gap_vs_primary: 2.96 },
    ],
  },
];

function siteVal(id: number, value: number, status: "success" | "unavailable" = "success") {
  return { website_analysis_id: id, status, value, confidence: 1, evidence: null, measured_at: null, error_code: null, error_message: null, is_mock: false, gap_vs_primary: null };
}

const metrics: MetricComparison[] = [
  {
    key: "title_present", name: "titleタグ", category_key: "technical_seo", value_type: "boolean", unit: null,
    source_type: "static_html", higher_is_better: true,
    sites: [siteVal(1, 1 as unknown as number), siteVal(2, 1 as unknown as number)],
  },
  {
    key: "lcp", name: "LCP", category_key: "performance", value_type: "number", unit: "秒",
    source_type: "lighthouse", higher_is_better: false,
    sites: [siteVal(1, 75.6), siteVal(2, 26.9)],
  },
  {
    key: "fcp", name: "FCP", category_key: "performance", value_type: "number", unit: "秒",
    source_type: "lighthouse", higher_is_better: false,
    sites: [siteVal(1, 21.6), siteVal(2, 21.6)],
  },
];

describe("findInitialOpenCategory", () => {
  it("picks the category with the largest normalized diff + problem ratio", () => {
    expect(findInitialOpenCategory(categories, metrics)).toBe("performance");
  });
});

describe("CategoryComparisonAccordion", () => {
  it("shows each category's per-site score, diff, and improve/unavailable counts in the header", () => {
    render(
      <CategoryComparisonAccordion
        ranking={ranking}
        categories={categories}
        metrics={metrics}
        filter="all"
        openCategories={[]}
        onOpenCategoriesChange={vi.fn()}
      />,
    );

    expect(screen.getByText("表示速度")).toBeInTheDocument();
    expect(screen.getByText(/0.6 \/ 15/)).toBeInTheDocument();
    expect(screen.getByText(/3.56 \/ 15/)).toBeInTheDocument();
    expect(screen.getByText("差 2.96")).toBeInTheDocument();
  });

  it("only shows metric rows for a category once it is in openCategories", () => {
    const { rerender } = render(
      <CategoryComparisonAccordion
        ranking={ranking}
        categories={categories}
        metrics={metrics}
        filter="all"
        openCategories={[]}
        onOpenCategoriesChange={vi.fn()}
      />,
    );

    expect(screen.queryByText("LCP")).not.toBeInTheDocument();

    rerender(
      <CategoryComparisonAccordion
        ranking={ranking}
        categories={categories}
        metrics={metrics}
        filter="all"
        openCategories={["performance"]}
        onOpenCategoriesChange={vi.fn()}
      />,
    );

    expect(screen.getAllByText("LCP").length).toBeGreaterThan(0);
  });

  it("filters out non-diff metrics entirely when the filter is 差がある項目のみ", () => {
    render(
      <CategoryComparisonAccordion
        ranking={ranking}
        categories={categories}
        metrics={metrics}
        filter="differences"
        openCategories={["performance"]}
        onOpenCategoriesChange={vi.fn()}
      />,
    );

    // LCP(75.6 vs 26.9, 10%以上の差)は表示、FCP(同値、good)は"差がある項目のみ"では
    // フィルタそのものにより非表示(折りたたみにも入らず、そもそも描画されない)。
    expect(screen.getAllByText("LCP").length).toBeGreaterThan(0);
    expect(screen.queryByText("FCP")).not.toBeInTheDocument();
    expect(screen.queryByText(/同等・良好な項目をすべて表示/)).not.toBeInTheDocument();
  });

  it("shows 評価不可 in the header for a category with no available score", () => {
    const unavailableCategories: CategoryComparison[] = [
      {
        key: "authority", name: "外部SEO", configured_max_score: 15,
        sites: [
          { website_analysis_id: 1, score: 0, max_available_score: 0, coverage_rate: 0, gap_vs_primary: null },
          { website_analysis_id: 2, score: 0, max_available_score: 0, coverage_rate: 0, gap_vs_primary: null },
        ],
      },
    ];

    render(
      <CategoryComparisonAccordion
        ranking={ranking}
        categories={unavailableCategories}
        metrics={[]}
        filter="all"
        openCategories={[]}
        onOpenCategoriesChange={vi.fn()}
      />,
    );

    expect(screen.getAllByText("評価不可")).toHaveLength(2);
  });

  it("collapses good metrics behind すべて表示 when filter=all, and shows them on click", async () => {
    const user = userEvent.setup();
    render(
      <CategoryComparisonAccordion
        ranking={ranking}
        categories={categories}
        metrics={metrics}
        filter="all"
        openCategories={["performance"]}
        onOpenCategoriesChange={vi.fn()}
      />,
    );

    expect(screen.queryByText("FCP")).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /同等・良好な項目をすべて表示/ }));
    expect(screen.getAllByText("FCP").length).toBeGreaterThan(0);
  });
});

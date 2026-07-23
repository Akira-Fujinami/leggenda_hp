import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { CategoryOverviewGrid } from "@/features/analysis/results/category-overview-grid";
import type { CategoryScore } from "@/types/analysis";

function makeCategory(overrides: Partial<CategoryScore> = {}): CategoryScore {
  return {
    key: "technical_seo", name: "技術SEO", score: 18, max_available_score: 20, configured_max_score: 20, coverage_rate: 100,
    ...overrides,
  };
}

describe("CategoryOverviewGrid", () => {
  it("navigates to the mapped section id when a category with a dedicated detail section is clicked", async () => {
    const user = userEvent.setup();
    const onViewDetails = vi.fn();

    render(<CategoryOverviewGrid categories={[makeCategory({ key: "content", name: "コンテンツ" })]} metrics={[]} onViewDetails={onViewDetails} />);
    await user.click(screen.getByRole("button", { name: "詳細を見る" }));

    expect(onViewDetails).toHaveBeenCalledWith("content");
  });

  it("does not navigate for a category with no dedicated section (accessibility), expanding locally instead", async () => {
    const user = userEvent.setup();
    const onViewDetails = vi.fn();

    render(<CategoryOverviewGrid categories={[makeCategory({ key: "accessibility", name: "アクセシビリティ" })]} metrics={[]} onViewDetails={onViewDetails} />);
    await user.click(screen.getByRole("button", { name: "詳細を開く" }));

    expect(onViewDetails).not.toHaveBeenCalled();
  });

  it("renders one card per category", () => {
    render(
      <CategoryOverviewGrid
        categories={[makeCategory({ key: "content", name: "コンテンツ" }), makeCategory({ key: "performance", name: "表示速度" })]}
        metrics={[]}
        onViewDetails={vi.fn()}
      />,
    );

    expect(screen.getByText("コンテンツ")).toBeInTheDocument();
    expect(screen.getByText("表示速度")).toBeInTheDocument();
  });
});

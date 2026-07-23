import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ComparisonStickyNav } from "@/features/comparison/comparison-sticky-nav";

describe("ComparisonStickyNav", () => {
  it("shows all 6 sections", () => {
    render(<ComparisonStickyNav activeId="summary" onNavigate={vi.fn()} />);

    for (const label of ["概要", "グラフ", "カテゴリ比較", "強み・弱み", "改善提案", "AI分析"]) {
      expect(screen.getByRole("button", { name: `${label}セクションへ移動` })).toBeInTheDocument();
    }
  });

  it("marks the active section and calls onNavigate on click", async () => {
    const user = userEvent.setup();
    const onNavigate = vi.fn();
    render(<ComparisonStickyNav activeId="charts" onNavigate={onNavigate} />);

    expect(screen.getByRole("button", { name: "グラフセクションへ移動" })).toHaveAttribute("aria-current", "true");

    await user.click(screen.getByRole("button", { name: "改善提案セクションへ移動" }));
    expect(onNavigate).toHaveBeenCalledWith("recommendations");
  });

  it("exposes a mobile section select with the same options", async () => {
    const user = userEvent.setup();
    const onNavigate = vi.fn();
    render(<ComparisonStickyNav activeId="summary" onNavigate={onNavigate} />);

    await user.selectOptions(screen.getByRole("combobox", { name: "セクションへ移動" }), "ai");

    expect(onNavigate).toHaveBeenCalledWith("ai");
  });
});

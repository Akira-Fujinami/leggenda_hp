import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ResultsSectionNav } from "@/features/analysis/results/results-section-nav";

describe("ResultsSectionNav", () => {
  it("only shows nav items for the given sectionIds, in the fixed section order", () => {
    render(<ResultsSectionNav sectionIds={["summary", "seo", "content"]} activeId="summary" onNavigate={vi.fn()} />);

    expect(screen.getByRole("button", { name: "概要セクションへ移動" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "SEOセクションへ移動" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "コンテンツセクションへ移動" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "集客・CTAセクションへ移動" })).not.toBeInTheDocument();
  });

  it("marks the active section with aria-current and calls onNavigate on click", async () => {
    const user = userEvent.setup();
    const onNavigate = vi.fn();
    render(<ResultsSectionNav sectionIds={["summary", "seo"]} activeId="seo" onNavigate={onNavigate} />);

    expect(screen.getByRole("button", { name: "SEOセクションへ移動" })).toHaveAttribute("aria-current", "true");

    await user.click(screen.getByRole("button", { name: "概要セクションへ移動" }));

    expect(onNavigate).toHaveBeenCalledWith("summary");
  });

  it("exposes a mobile section select with the same options", async () => {
    const user = userEvent.setup();
    const onNavigate = vi.fn();
    render(<ResultsSectionNav sectionIds={["summary", "seo"]} activeId="summary" onNavigate={onNavigate} />);

    const select = screen.getByLabelText("セクションへ移動");
    await user.selectOptions(select, "seo");

    expect(onNavigate).toHaveBeenCalledWith("seo");
  });
});

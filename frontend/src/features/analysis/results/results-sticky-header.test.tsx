import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ResultsStickyHeader } from "@/features/analysis/results/results-sticky-header";

const backMock = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ back: backMock }),
}));

describe("ResultsStickyHeader", () => {
  it("shows the selected site's name, status, and score with links to comparison/history", () => {
    render(<ResultsStickyHeader analysisId={1} websiteName="日本旅行" score={59} status="completed" />);

    expect(screen.getByText("日本旅行")).toBeInTheDocument();
    expect(screen.getByText("59点")).toBeInTheDocument();
    expect(screen.getByText("完了")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "比較" })).toHaveAttribute("href", "/analyses/1/comparison");
    expect(screen.getByRole("link", { name: "履歴" })).toHaveAttribute("href", "/analyses/1/history");
  });

  it("navigates back in history when the back button is clicked", async () => {
    const user = userEvent.setup();
    render(<ResultsStickyHeader analysisId={1} websiteName="日本旅行" score={59} status="completed" />);

    await user.click(screen.getByRole("button", { name: "戻る" }));

    expect(backMock).toHaveBeenCalledTimes(1);
  });
});

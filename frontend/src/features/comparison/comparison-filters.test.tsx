import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ComparisonFilters } from "@/features/comparison/comparison-filters";

describe("ComparisonFilters", () => {
  it("shows all 4 filter options", () => {
    render(<ComparisonFilters value="differences" onChange={vi.fn()} />);

    expect(screen.getByRole("option", { name: "差がある項目のみ" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "要改善のみ" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "未取得を含む" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "すべて表示" })).toBeInTheDocument();
  });

  it("calls onChange with the selected filter value", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<ComparisonFilters value="differences" onChange={onChange} />);

    await user.selectOptions(screen.getByRole("combobox"), "improve");

    expect(onChange).toHaveBeenCalledWith("improve");
  });
});

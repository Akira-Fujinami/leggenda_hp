import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import DashboardPage from "./page";

vi.mock("@/features/projects/hooks", () => ({
  useProjects: () => ({
    data: { data: [], meta: { pagination: { current_page: 1, last_page: 1, per_page: 12, total: 0 } } },
    isLoading: false,
    isError: false,
  }),
}));

describe("DashboardPage empty state", () => {
  it("shows a call-to-action instead of fabricated analysis data when there are no projects", () => {
    render(<DashboardPage />);

    expect(
      screen.getByText("まだ比較プロジェクトがありません。サイトを登録して分析を開始しましょう。"),
    ).toBeInTheDocument();
  });
});

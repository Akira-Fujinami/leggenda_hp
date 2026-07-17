import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AnalysisStatusBadge } from "@/features/analysis/analysis-status-badge";

describe("AnalysisStatusBadge", () => {
  it("shows a Japanese label for each known status", () => {
    render(<AnalysisStatusBadge status="running" />);
    expect(screen.getByText("実行中")).toBeInTheDocument();
  });

  it("shows completed", () => {
    render(<AnalysisStatusBadge status="completed" />);
    expect(screen.getByText("完了")).toBeInTheDocument();
  });

  it("shows partial", () => {
    render(<AnalysisStatusBadge status="partial" />);
    expect(screen.getByText("一部完了")).toBeInTheDocument();
  });

  it("shows failed", () => {
    render(<AnalysisStatusBadge status="failed" />);
    expect(screen.getByText("失敗")).toBeInTheDocument();
  });
});

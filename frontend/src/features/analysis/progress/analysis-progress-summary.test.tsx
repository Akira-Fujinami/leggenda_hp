import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AnalysisProgressSummary } from "@/features/analysis/progress/analysis-progress-summary";
import type { JobStatusSummary } from "@/types/analysis";

function jobs(overrides: Partial<JobStatusSummary> = {}): JobStatusSummary {
  return { total: 22, completed: 22, failed: 0, running: 0, pending: 0, skipped: 0, finished: 22, ...overrides };
}

describe("AnalysisProgressSummary", () => {
  it("shows only the progress heading while running, no result summary yet", () => {
    render(<AnalysisProgressSummary status="running" progress={65} jobs={jobs({ completed: 8, finished: 8 })} />);

    expect(screen.getByText("処理の進捗：65%")).toBeInTheDocument();
    expect(screen.queryByText("分析が完了しました")).not.toBeInTheDocument();
  });

  it("shows the completed result alongside 100% (not just a bare percentage)", () => {
    render(<AnalysisProgressSummary status="completed" progress={100} jobs={jobs({ completed: 22, failed: 0 })} />);

    expect(screen.getByText("処理の進捗：100%")).toBeInTheDocument();
    expect(screen.getByText("分析が完了しました")).toBeInTheDocument();
    expect(screen.getByText(/成功 22/)).toBeInTheDocument();
    expect(screen.getByText(/失敗 0/)).toBeInTheDocument();
  });

  it("explains why 100% still shows a partial status", () => {
    render(
      <AnalysisProgressSummary status="partial" progress={100} jobs={jobs({ completed: 16, failed: 6, finished: 22 })} />
    );

    expect(screen.getByText("処理の進捗：100%")).toBeInTheDocument();
    expect(screen.getByText("分析処理は完了しました")).toBeInTheDocument();
    expect(screen.getByText("一部の分析項目を取得できませんでした")).toBeInTheDocument();
    expect(screen.getByText(/成功 16/)).toBeInTheDocument();
    expect(screen.getByText(/失敗 6/)).toBeInTheDocument();
  });

  it("shows the failed result summary", () => {
    render(
      <AnalysisProgressSummary status="failed" progress={100} jobs={jobs({ completed: 0, failed: 22, finished: 22 })} />
    );

    expect(screen.getByText("分析処理は終了しました")).toBeInTheDocument();
    expect(screen.getByText("分析結果を作成できませんでした")).toBeInTheDocument();
  });
});

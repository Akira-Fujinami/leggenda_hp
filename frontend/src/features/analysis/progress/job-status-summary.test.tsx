import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { JobStatusSummary } from "@/features/analysis/progress/job-status-summary";

describe("JobStatusSummary", () => {
  it("shows processed/total and the success/failure breakdown", () => {
    render(
      <JobStatusSummary
        summary={{ total: 22, completed: 16, failed: 6, running: 0, pending: 0, skipped: 0, finished: 22 }}
      />
    );

    expect(screen.getByText("処理済み 22 / 22")).toBeInTheDocument();
    expect(screen.getByText(/成功 16/)).toBeInTheDocument();
    expect(screen.getByText(/失敗 6/)).toBeInTheDocument();
  });

  it("shows running/pending counts only when nonzero", () => {
    const { rerender } = render(
      <JobStatusSummary
        summary={{ total: 11, completed: 4, failed: 0, running: 3, pending: 4, skipped: 0, finished: 4 }}
      />
    );
    expect(screen.getByText("処理済み 4 / 11")).toBeInTheDocument();
    expect(screen.getByText(/実行中 3/)).toBeInTheDocument();
    expect(screen.getByText(/待機中 4/)).toBeInTheDocument();

    rerender(
      <JobStatusSummary
        summary={{ total: 11, completed: 11, failed: 0, running: 0, pending: 0, skipped: 0, finished: 11 }}
      />
    );
    expect(screen.queryByText(/実行中/)).not.toBeInTheDocument();
    expect(screen.queryByText(/待機中/)).not.toBeInTheDocument();
  });
});

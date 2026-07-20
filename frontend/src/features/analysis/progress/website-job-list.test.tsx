import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { WebsiteJobList } from "@/features/analysis/progress/website-job-list";
import type { AnalysisJobProgress } from "@/types/analysis";

describe("WebsiteJobList", () => {
  it("shows a short real description for each failed job, not just a red dot", () => {
    const jobs: AnalysisJobProgress[] = [
      { job_type: "render_page", status: "failed", error_message: "analyzerに接続できませんでした。" },
      { job_type: "capture_screenshot_desktop", status: "failed", error_message: "analyzerが混雑しています。" },
      { job_type: "fetch_static_page", status: "completed", error_message: null },
    ];

    render(<WebsiteJobList jobs={jobs} />);

    expect(screen.getAllByText("JavaScriptレンダリング").length).toBeGreaterThan(0);
    expect(screen.getByText("analyzerに接続できませんでした。")).toBeInTheDocument();
    expect(screen.getByText("analyzerが混雑しています。")).toBeInTheDocument();
  });

  it("falls back to a generic message when a failed job has no stored error_message", () => {
    const jobs: AnalysisJobProgress[] = [{ job_type: "fetch_robots", status: "failed", error_message: null }];

    render(<WebsiteJobList jobs={jobs} />);

    expect(screen.getByText("取得に失敗しました。")).toBeInTheDocument();
  });

  it("shows no failure list when nothing failed", () => {
    const jobs: AnalysisJobProgress[] = [{ job_type: "fetch_static_page", status: "completed", error_message: null }];

    render(<WebsiteJobList jobs={jobs} />);

    expect(screen.queryByText("取得に失敗しました。")).not.toBeInTheDocument();
  });
});

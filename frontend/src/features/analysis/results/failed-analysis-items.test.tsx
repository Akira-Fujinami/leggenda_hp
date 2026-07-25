import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { FailedAnalysisItems } from "@/features/analysis/results/failed-analysis-items";
import type { AnalysisJobError } from "@/types/analysis";

describe("FailedAnalysisItems", () => {
  it("renders nothing when there are no errors", () => {
    const { container } = render(<FailedAnalysisItems errors={[]} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("shows a compact failure summary with the detail table collapsed by default", async () => {
    const user = userEvent.setup();
    const errors: AnalysisJobError[] = [{ job_type: "detect_technology", error_code: "ANALYZER_TIMEOUT", error_message: "接続がタイムアウトしました。" }];

    render(<FailedAnalysisItems errors={errors} />);

    expect(screen.getByText(/分析失敗: 1件/)).toBeInTheDocument();
    expect(screen.getByText(/使用技術検出/)).toBeInTheDocument();
    expect(screen.queryByText("接続がタイムアウトしました。")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "詳細を見る" }));

    expect(screen.getByText("接続がタイムアウトしました。")).toBeInTheDocument();
    expect(screen.getByText(/技術検出カテゴリの評価のみに影響/)).toBeInTheDocument();
  });

  it("shows the analyzer's own message verbatim for the technology-detection-failed and screenshot-resource-limit codes (no generic fallback text)", async () => {
    const user = userEvent.setup();
    const errors: AnalysisJobError[] = [
      { job_type: "detect_technology", error_code: "TECHNOLOGY_DETECTION_FAILED", error_message: "技術検出の結果を処理できませんでした。" },
      { job_type: "capture_screenshot_desktop", error_code: "SCREENSHOT_RESOURCE_LIMIT", error_message: "画像サイズを縮小して複数回試みましたが、安全なメモリ範囲で撮影できませんでした。" },
    ];

    render(<FailedAnalysisItems errors={errors} />);
    await user.click(screen.getByRole("button", { name: "詳細を見る" }));

    expect(screen.getByText("技術検出の結果を処理できませんでした。")).toBeInTheDocument();
    expect(screen.getByText("画像サイズを縮小して複数回試みましたが、安全なメモリ範囲で撮影できませんでした。")).toBeInTheDocument();
    expect(screen.queryByText("予期しないエラーが発生しました。")).not.toBeInTheDocument();
  });
});

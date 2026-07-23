import { describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ScreenshotSection } from "@/features/analysis/results/screenshot-section";
import type { AnalysisJobError, AnalysisScreenshot } from "@/types/analysis";

describe("ScreenshotSection", () => {
  it("shows an unavailable placeholder for a device with no screenshot and no error", () => {
    render(<ScreenshotSection screenshots={[]} errors={[]} websiteName="サイトA" />);

    expect(screen.getAllByText("未取得").length).toBeGreaterThan(0);
  });

  it("shows a failure reason for a device whose screenshot job failed", () => {
    const errors: AnalysisJobError[] = [{ job_type: "capture_screenshot_desktop", error_code: "ANALYZER_TIMEOUT", error_message: "タイムアウトしました。" }];

    render(<ScreenshotSection screenshots={[]} errors={errors} websiteName="サイトA" />);

    expect(screen.getAllByText("タイムアウトしました。").length).toBeGreaterThan(0);
  });

  it("opens a lightbox dialog with the full-size image when a thumbnail is clicked", async () => {
    const user = userEvent.setup();
    const screenshots: AnalysisScreenshot[] = [{ device: "desktop", url: "https://example.com/desktop.png", width: 1280, height: 800 }];

    render(<ScreenshotSection screenshots={screenshots} errors={[]} websiteName="サイトA" />);

    const thumbnailButton = screen.getAllByRole("button", { name: /サイトA \(PC\)を拡大表示/ })[0];
    await user.click(thumbnailButton);

    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByRole("img", { name: "サイトA (PC)" })).toHaveAttribute("src", "https://example.com/desktop.png");
  });
});

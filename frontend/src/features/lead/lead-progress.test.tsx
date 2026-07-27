import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { LeadProgress } from "@/features/lead/lead-progress";

describe("LeadProgress", () => {
  it("shows the percent, message, and a wait hint while processing", () => {
    render(<LeadProgress progress={{ percent: 45, status: "processing", message: "サイトを診断しています。しばらくお待ちください…" }} />);

    expect(screen.getByText("サイトを診断しています。しばらくお待ちください…")).toBeInTheDocument();
    expect(screen.getByText(/1〜2分程度/)).toBeInTheDocument();
  });

  it("does not show the wait hint once completed", () => {
    render(<LeadProgress progress={{ percent: 100, status: "completed", message: "診断が完了しました。" }} />);

    expect(screen.getByText("診断が完了しました。")).toBeInTheDocument();
    expect(screen.queryByText(/1〜2分程度/)).not.toBeInTheDocument();
  });
});

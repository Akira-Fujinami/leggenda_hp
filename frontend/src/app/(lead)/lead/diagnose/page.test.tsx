import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import LeadDiagnosePage from "./page";

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams("token=abc"),
}));

vi.mock("@/features/lead/hooks", () => ({
  useStartLeadAnalysis: () => ({ mutate: vi.fn(), isPending: false, error: null }),
  useLeadProgress: () => ({ data: undefined, isLoading: true, isError: false, error: null }),
  useLeadResults: () => ({ data: undefined, isLoading: true }),
}));

describe("LeadDiagnosePage", () => {
  it("shows the step 2 heading block and card heading for the url input step", () => {
    render(<LeadDiagnosePage />);

    expect(screen.getByText("STEP 2 / 2")).toBeInTheDocument();
    expect(screen.getByText("診断するサイトをお選びください。")).toBeInTheDocument();
    expect(screen.getByText("URLのご入力")).toBeInTheDocument();
  });

  it("still renders the analysis form with the renamed url labels", () => {
    render(<LeadDiagnosePage />);

    expect(screen.getByLabelText("貴社の採用サイト URL")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "診断をはじめる" })).toBeInTheDocument();
  });
});

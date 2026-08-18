import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import LeadStartPage from "./page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
}));

vi.mock("@/features/lead/hooks", () => ({
  useSubmitLeadOnboarding: () => ({ mutate: vi.fn(), isPending: false, error: null }),
}));

describe("LeadStartPage", () => {
  it("shows the new heading block and card heading", () => {
    render(<LeadStartPage />);

    expect(screen.getByText("無料診断")).toBeInTheDocument();
    expect(screen.getByText("採用サイトは、候補者に何を伝えていますか。")).toBeInTheDocument();
    expect(screen.getByText("お客さま情報のご入力")).toBeInTheDocument();
  });

  it("still renders the onboarding form", () => {
    render(<LeadStartPage />);

    expect(screen.getByLabelText("会社名")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "無料で診断をはじめる" })).toBeInTheDocument();
  });
});

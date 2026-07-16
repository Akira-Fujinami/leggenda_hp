import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { WebsiteForm } from "@/features/websites/website-form";

vi.mock("@/features/websites/hooks", () => ({
  useCreateWebsite: () => ({
    mutate: vi.fn(),
    isPending: false,
    error: null,
  }),
}));

describe("WebsiteForm", () => {
  it("hides the registration form and shows a limit message when disabled", () => {
    render(<WebsiteForm projectId={1} disabled />);

    expect(screen.getByText("登録できるサイトは最大5件です。上限に達しています。")).toBeInTheDocument();
    expect(screen.queryByLabelText("サイト名")).not.toBeInTheDocument();
  });

  it("shows the registration form when not disabled", () => {
    render(<WebsiteForm projectId={1} disabled={false} />);

    expect(screen.getByLabelText("サイト名")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "サイトを追加" })).toBeInTheDocument();
  });
});

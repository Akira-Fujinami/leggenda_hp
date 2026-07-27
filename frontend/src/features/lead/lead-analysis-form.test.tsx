import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { LeadAnalysisForm } from "@/features/lead/lead-analysis-form";

const mutateMock = vi.fn();

vi.mock("@/features/lead/hooks", () => ({
  useStartLeadAnalysis: () => ({
    mutate: mutateMock,
    isPending: false,
    error: null,
  }),
}));

describe("LeadAnalysisForm", () => {
  it("requires the self site URL", async () => {
    const user = userEvent.setup();
    render(<LeadAnalysisForm token="abc" onStarted={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: "診断をはじめる" }));

    expect(await screen.findByText("自社サイトのURLを入力してください。")).toBeInTheDocument();
    expect(mutateMock).not.toHaveBeenCalled();
  });

  it("submits self_url alone when the competitor URL is left blank", async () => {
    const user = userEvent.setup();
    render(<LeadAnalysisForm token="abc" onStarted={vi.fn()} />);

    await user.type(screen.getByLabelText("自社サイトのURL"), "https://example.com");
    await user.click(screen.getByRole("button", { name: "診断をはじめる" }));

    await waitFor(() => {
      expect(mutateMock).toHaveBeenCalledWith(
        { self_url: "https://example.com", competitor_url: undefined },
        expect.anything(),
      );
    });
  });

  it("submits both self and competitor URLs when both are provided", async () => {
    const user = userEvent.setup();
    render(<LeadAnalysisForm token="abc" onStarted={vi.fn()} />);

    await user.type(screen.getByLabelText("自社サイトのURL"), "https://example.com");
    await user.type(screen.getByLabelText("比較したい競合サイトのURL(任意)"), "https://competitor.example.com");
    await user.click(screen.getByRole("button", { name: "診断をはじめる" }));

    await waitFor(() => {
      expect(mutateMock).toHaveBeenCalledWith(
        { self_url: "https://example.com", competitor_url: "https://competitor.example.com" },
        expect.anything(),
      );
    });
  });

  it("calls onStarted with the returned analysis id on success", async () => {
    const user = userEvent.setup();
    const onStarted = vi.fn();
    mutateMock.mockImplementation((_input, options) => {
      options.onSuccess({ data: { analysis_id: 42 } });
    });

    render(<LeadAnalysisForm token="abc" onStarted={onStarted} />);
    await user.type(screen.getByLabelText("自社サイトのURL"), "https://example.com");
    await user.click(screen.getByRole("button", { name: "診断をはじめる" }));

    await waitFor(() => {
      expect(onStarted).toHaveBeenCalledWith(42);
    });
  });
});

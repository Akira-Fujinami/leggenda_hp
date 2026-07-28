import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { LeadAnalysisForm } from "@/features/lead/lead-analysis-form";
import { useStartLeadAnalysis } from "@/features/lead/hooks";
import { ApiError } from "@/lib/api-client";

const mutateMock = vi.fn();

vi.mock("@/features/lead/hooks", () => ({
  useStartLeadAnalysis: vi.fn(),
}));

const mockUseStartLeadAnalysis = vi.mocked(useStartLeadAnalysis);

describe("LeadAnalysisForm", () => {
  beforeEach(() => {
    mockUseStartLeadAnalysis.mockReturnValue({
      mutate: mutateMock,
      isPending: false,
      error: null,
    } as ReturnType<typeof useStartLeadAnalysis>);
  });

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

  it("shows the unified recovery screen instead of the form when the token is missing or invalid, without exposing the reason", async () => {
    mockUseStartLeadAnalysis.mockReturnValue({
      mutate: mutateMock,
      isPending: false,
      error: new ApiError(401, "この診断URLは利用できません。お手数ですが、もう一度お申し込みください。", {}, "LEAD_TOKEN_INVALID", null, "/api/lead/analyses"),
    } as unknown as ReturnType<typeof useStartLeadAnalysis>);

    render(<LeadAnalysisForm token="abc" onStarted={vi.fn()} />);

    expect(screen.getByText("この診断URLは利用できません。お手数ですが、もう一度お申し込みください。")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "最初からやり直す" })).toHaveAttribute("href", "/lead/start");
    // トークンが壊れている以上、フォームへ再入力させても無駄なので出さない。
    expect(screen.queryByLabelText("自社サイトのURL")).not.toBeInTheDocument();
  });

  it("keeps showing the form with the specific message for non-token errors like congestion", () => {
    mockUseStartLeadAnalysis.mockReturnValue({
      mutate: mutateMock,
      isPending: false,
      error: new ApiError(503, "現在混み合っています。しばらくしてから再度お試しください。", {}, "LEAD_ANALYZER_BUSY", null, "/api/lead/analyses"),
    } as unknown as ReturnType<typeof useStartLeadAnalysis>);

    render(<LeadAnalysisForm token="abc" onStarted={vi.fn()} />);

    expect(screen.getByLabelText("自社サイトのURL")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "最初からやり直す" })).not.toBeInTheDocument();
  });
});

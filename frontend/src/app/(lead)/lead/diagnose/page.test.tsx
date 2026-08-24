import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import LeadDiagnosePage from "./page";
import { useLeadProgress, useLeadResults, useStartLeadAnalysis } from "@/features/lead/hooks";

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams("token=abc"),
}));

vi.mock("@/features/lead/hooks", () => ({
  useStartLeadAnalysis: vi.fn(),
  useLeadProgress: vi.fn(),
  useLeadResults: vi.fn(),
}));

const mockUseStartLeadAnalysis = vi.mocked(useStartLeadAnalysis);
const mockUseLeadProgress = vi.mocked(useLeadProgress);
const mockUseLeadResults = vi.mocked(useLeadResults);

describe("LeadDiagnosePage", () => {
  beforeEach(() => {
    mockUseStartLeadAnalysis.mockReturnValue({ mutate: vi.fn(), isPending: false, error: null } as never);
    mockUseLeadProgress.mockReturnValue({ data: undefined, isLoading: true, isError: false, error: null } as never);
    mockUseLeadResults.mockReturnValue({ data: undefined, isLoading: true } as never);
  });

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

describe("LeadDiagnosePage retry flow (2026-08-24追加)", () => {
  beforeEach(() => {
    mockUseStartLeadAnalysis.mockReturnValue({ mutate: vi.fn(), isPending: false, error: null } as never);
  });

  afterEach(() => {
    window.localStorage.clear();
  });

  /**
   * 自社サイトの分析が白紙(skipped)の場合、診断回数を消費していないため
   * 別のURLで再挑戦できる。保存済みのanalysisId(localStorage)を持つ状態から
   * 「別のURLで試す」を押すと、localStorageが消え、STEP2のURL入力フォームへ
   * 戻ることを確認する(バックエンドへの新規診断はLeadAnalysisForm側の
   * 既存テストで別途検証済み、ここではlocalStorageのクリアと画面遷移のみ)。
   */
  it("別のURLで試すを押すと保存済みのanalysisIdを破棄しURL入力フォームへ戻す", async () => {
    const user = userEvent.setup();
    window.localStorage.setItem("lead-analysis-id:abc", "42");

    mockUseLeadProgress.mockReturnValue({
      data: { data: { percent: 100, status: "completed", message: "診断が完了しました。" } },
      isLoading: false,
      isError: false,
      error: null,
    } as never);
    mockUseLeadResults.mockReturnValue({
      data: {
        data: {
          status: "completed",
          reports: { docx: "skipped", pdf: "skipped" },
          websites: [],
        },
      },
      isLoading: false,
    } as never);

    render(<LeadDiagnosePage />);

    expect(screen.getByText("今回はご用意できる診断結果がありませんでした。")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "別のURLで試す" }));

    expect(window.localStorage.getItem("lead-analysis-id:abc")).toBeNull();
    expect(screen.getByText("STEP 2 / 2")).toBeInTheDocument();
    expect(screen.getByLabelText("貴社の採用サイト URL")).toBeInTheDocument();
  });
});

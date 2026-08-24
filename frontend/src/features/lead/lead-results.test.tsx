import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { LeadResults } from "@/features/lead/lead-results";
import { useRequestConsultation } from "@/features/lead/hooks";
import type { LeadResults as LeadResultsType } from "@/types/lead";

vi.mock("@/features/lead/hooks", () => ({
  useRequestConsultation: vi.fn(),
}));

const mockUseRequestConsultation = vi.mocked(useRequestConsultation);

function mockConsultationState(overrides: Partial<ReturnType<typeof useRequestConsultation>> = {}) {
  mockUseRequestConsultation.mockReturnValue({
    mutate: vi.fn(),
    isPending: false,
    isSuccess: false,
    isError: false,
    data: undefined,
    ...overrides,
  } as ReturnType<typeof useRequestConsultation>);
}

// 2026-08-03の構成変更後、この画面は診断内容そのものを一切描かない。
// websitesは受け取るが表示には使わないため、フィクスチャも空で足りる
// (中身のある値を置くと「画面に出ているはず」という誤解を生む)。
function baseResults(overrides: Partial<LeadResultsType> = {}): LeadResultsType {
  return {
    status: "completed",
    reports: { docx: "ready", pdf: "ready" },
    websites: [],
    ...overrides,
  };
}

describe("LeadResults", () => {
  beforeEach(() => {
    mockConsultationState();
  });

  it("PDFのダウンロードリンクと相談導線だけを出し、診断内容そのものは画面に描かない", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={1} onRetry={vi.fn()} />);

    expect(screen.getByText("診断結果をPDFにまとめました")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "PDFでダウンロード" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "相談をリクエストする" })).toBeInTheDocument();

    // 画面に残していた診断内容(6軸・4観点・データ品質・改善提案)は出さない。
    expect(screen.queryByText("自社ページの分析結果")).not.toBeInTheDocument();
    expect(screen.queryByText(/4つの観点/)).not.toBeInTheDocument();
    expect(screen.queryByText(/採用サイトとして重要な4観点/)).not.toBeInTheDocument();
    expect(screen.queryByText("特に改善効果が見込まれる項目")).not.toBeInTheDocument();
  });

  it("Wordのダウンロードは画面に出さない(PDFのみ、2026-08-03のユーザー判断)", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={1} onRetry={vi.fn()} />);

    expect(screen.queryByText("Wordでダウンロード")).not.toBeInTheDocument();
  });

  it("PDFのリンク先はレポート取得エンドポイントを指す", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={7} onRetry={vi.fn()} />);

    expect(screen.getByRole("link", { name: "PDFでダウンロード" })).toHaveAttribute(
      "href",
      expect.stringContaining("/lead/analyses/7/report"),
    );
  });

  it("PDF生成中はリンクではなく準備中の状態を出す(壊れたリンクを踏ませない)", () => {
    const processing = baseResults({ reports: { docx: "ready", pdf: "processing" } });

    render(<LeadResults results={processing} token="tok" analysisId={1} onRetry={vi.fn()} />);

    expect(screen.queryByRole("link", { name: /PDF/ })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "PDFでダウンロード(準備中…)" })).toBeDisabled();
  });

  it("PDFが出せなかった場合、黙って空白にせず理由と次の導線を出す", () => {
    const unavailable = baseResults({ reports: { docx: "ready", pdf: "unavailable" } });

    render(<LeadResults results={unavailable} token="tok" analysisId={1} onRetry={vi.fn()} />);

    expect(screen.queryByRole("link", { name: /PDF/ })).not.toBeInTheDocument();
    expect(screen.getByText(/PDFのご用意ができませんでした/)).toBeInTheDocument();
    // 画面に何も残らない状態を作らない ―― 相談導線は必ず残る。
    expect(screen.getByRole("button", { name: "相談をリクエストする" })).toBeInTheDocument();
  });

  it("自社サイトの分析が白紙(skipped)の場合、再挑戦導線を出し相談導線は出さない", async () => {
    const user = userEvent.setup();
    const onRetry = vi.fn();
    const skipped = baseResults({ reports: { docx: "skipped", pdf: "skipped" } });

    render(<LeadResults results={skipped} token="tok" analysisId={1} onRetry={onRetry} />);

    expect(screen.getByText("今回はご用意できる診断結果がありませんでした。")).toBeInTheDocument();
    expect(screen.getByText(/ご利用回数に含まれておりません/)).toBeInTheDocument();
    // 診断回数を消費していないため、比較したい他社を前提とした相談導線は出さない。
    expect(screen.queryByRole("button", { name: "相談をリクエストする" })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /PDF/ })).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "別のURLで試す" }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it("一部のデータが取得できなかった場合は、その旨をPDFを渡す前に伝える", () => {
    render(<LeadResults results={baseResults({ status: "partial" })} token="tok" analysisId={1} onRetry={vi.fn()} />);

    expect(screen.getByText(/一部のデータは取得できませんでした/)).toBeInTheDocument();
  });

  it("shows a confirmation dialog before sending the consultation request", async () => {
    const user = userEvent.setup();
    const mutate = vi.fn();
    mockConsultationState({ mutate });

    render(<LeadResults results={baseResults()} token="tok" analysisId={1} onRetry={vi.fn()} />);

    expect(mutate).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "相談をリクエストする" }));

    expect(screen.getByText("相談をリクエストしますか？")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "リクエストする" }));

    expect(mutate).toHaveBeenCalledTimes(1);
  });

  it("shows a success message once the consultation request is newly accepted", () => {
    mockConsultationState({ isSuccess: true, data: { data: { already_requested: false } } as never });

    render(<LeadResults results={baseResults()} token="tok" analysisId={1} onRetry={vi.fn()} />);

    expect(screen.getByText("ご相談リクエストを受け付けました")).toBeInTheDocument();
  });

  it("honestly reports a repeat click as already requested, not as a fresh success", () => {
    mockConsultationState({ isSuccess: true, data: { data: { already_requested: true } } as never });

    render(<LeadResults results={baseResults()} token="tok" analysisId={1} onRetry={vi.fn()} />);

    expect(screen.getByText("既にご相談リクエストを受け付けています")).toBeInTheDocument();
  });

  it("shows an error message when the consultation request fails, without pretending it succeeded", () => {
    mockConsultationState({ isError: true });

    render(<LeadResults results={baseResults()} token="tok" analysisId={1} onRetry={vi.fn()} />);

    expect(screen.getByText("送信に失敗しました。しばらくしてから再度お試しください。")).toBeInTheDocument();
  });
});

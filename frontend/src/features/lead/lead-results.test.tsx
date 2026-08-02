import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { LeadResults } from "@/features/lead/lead-results";
import { useRequestConsultation } from "@/features/lead/hooks";
import type { LeadPerspective, LeadResults as LeadResultsType } from "@/types/lead";

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

const COMPLETENESS_NOTE =
  "業務内容・給与・勤務時間といった募集要項の記載内容の確認は、今後追加予定です。" +
  "現時点では、採用ページ自体の有無と、その情報量のみを確認しています。";

const USABILITY_NOTE =
  "現時点では、読み上げソフトへの対応や文字と背景のコントラストなど、" +
  "アクセシビリティ監査の範囲で自動判定しています。配色やレイアウトの" +
  "見た目そのものの印象は自動判定の対象外です。デザインの良し悪しは、" +
  "商談の際に担当者と直接ご確認ください。";

function basePerspectives(overrides: Partial<Record<LeadPerspective["key"], LeadPerspective>> = {}): LeadPerspective[] {
  const defaults: Record<LeadPerspective["key"], LeadPerspective> = {
    completeness: {
      key: "completeness",
      label: "書くべきことが書けているか",
      heading: "書くべきことが書けているか",
      note: COMPLETENESS_NOTE,
      summary: "採用ページを確認できました。",
      status: "good",
      items: [
        { label: "採用ページの案内", status: "good", detail: null },
        { label: "採用ページのタイトル設定", status: "good", detail: null },
      ],
    },
    clarity: {
      key: "clarity",
      label: "メッセージの分かりやすさ",
      heading: "伝えたいことが分かりやすく伝わっているか",
      note: null,
      status: "needs_review",
      items: [{ label: "ページタイトルの長さ", status: "needs_review", detail: null }],
    },
    findability: {
      key: "findability",
      label: "情報の取りやすさ・導線",
      heading: "知りたい情報にたどり着けるか",
      note: null,
      status: "good",
      items: [{ label: "問い合わせへの案内", status: "good", detail: null }],
    },
    usability: {
      key: "usability",
      label: "見やすさ・使いやすさ",
      heading: "見やすく、使いやすいか",
      note: USABILITY_NOTE,
      status: "needs_improvement",
      items: [
        { label: "文字と背景の色のコントラスト、読み上げソフトへの対応", status: "needs_improvement", detail: null },
      ],
    },
  };

  return [
    { ...defaults.completeness, ...overrides.completeness },
    { ...defaults.clarity, ...overrides.clarity },
    { ...defaults.findability, ...overrides.findability },
    { ...defaults.usability, ...overrides.usability },
  ];
}

// LeadScoreCalculatorはcategory_scoresを4観点のキー(completeness/clarity/
// findability/usability)で返し、nameにはLeadMetricCatalog::PERSPECTIVE_LABELS
// (内部名)を入れる。画面はnameを一切表示せず、見出しにはperspective.headingを
// 使う ―― この対応関係が崩れると比較チャートの数値が出なくなるため、
// フィクスチャも実APIと同じキーで持つ。
function baseWebsite(overrides: Partial<LeadResultsType["websites"][number]> = {}): LeadResultsType["websites"][number] {
  return {
    website_name: "サンプル株式会社",
    is_primary: true,
    score: {
      overall_score: 20,
      display_score: 20,
      available_score: 29,
      configured_max_score: 40,
      coverage_rate: 90,
      confidence_rate: 88,
      category_scores: [
        { key: "completeness", name: "書くべきことが書けているか", score: 8, max_available_score: 10, configured_max_score: 10, coverage_rate: 100 },
        { key: "clarity", name: "メッセージの分かりやすさ", score: 3, max_available_score: 6, configured_max_score: 10, coverage_rate: 60 },
        { key: "findability", name: "情報の取りやすさ・導線", score: 5, max_available_score: 5, configured_max_score: 10, coverage_rate: 50 },
        { key: "usability", name: "見やすさ・使いやすさ", score: 2, max_available_score: 8, configured_max_score: 10, coverage_rate: 80 },
      ],
      metric_summary: {
        success: 40,
        not_found: 5,
        unavailable: 2,
        error: 0,
        not_applicable: 0,
        scored_unavailable: 2,
        informational_unavailable: 0,
      },
    },
    perspectives: basePerspectives(),
    top_recommendations: [
      { title: "画像を圧縮してください", description: "表示速度の改善につながります。", priority: "high", impact: "high", effort: "low" },
    ],
    ...overrides,
  };
}

function baseResults(overrides: Partial<LeadResultsType> = {}): LeadResultsType {
  return {
    status: "completed",
    reports: { docx: "ready", pdf: "ready" },
    websites: [baseWebsite()],
    ...overrides,
  };
}

describe("LeadResults", () => {
  beforeEach(() => {
    mockConsultationState();
  });

  it("shows the recruiter-scoped score label (not the internal 総合スコア wording) at or above the honesty threshold", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.getByText("採用サイトとして重要な4観点での評価")).toBeInTheDocument();
    // 社内版の点数とは別建てであることが分かるよう、内部の「総合スコア」表記は出さない。
    expect(screen.queryByText("総合スコア")).not.toBeInTheDocument();
    expect(screen.getByText("サンプル株式会社")).toBeInTheDocument();
    expect(screen.getByText("自社サイト")).toBeInTheDocument();
  });

  it("shows the reference-only variant of the recruiter-scoped label and a warning when coverage is below 70%, never silently upgrading it", () => {
    const lowCoverage = baseResults({
      websites: [baseWebsite({ score: { ...baseWebsite().score, coverage_rate: 40 } })],
    });
    render(<LeadResults results={lowCoverage} token="tok" analysisId={1} />);

    expect(screen.getByText("採用サイトとして重要な4観点での参考評価")).toBeInTheDocument();
    expect(screen.getByText(/測定カバー率が40%のため、このスコアは参考値です/)).toBeInTheDocument();
  });

  it("shows the top recommendation", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.getByText("画像を圧縮してください")).toBeInTheDocument();
  });

  it("shows a partial-data notice when the analysis status is partial", () => {
    render(<LeadResults results={baseResults({ status: "partial" })} token="tok" analysisId={1} />);

    expect(screen.getByText(/一部のデータは取得できませんでした/)).toBeInTheDocument();
  });

  it("shows enabled download links pointing at the report endpoint once reports are ready", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={42} />);

    const wordLink = screen.getByRole("link", { name: "Wordでダウンロード" });
    const pdfLink = screen.getByRole("link", { name: "PDFでダウンロード" });

    expect(wordLink).toHaveAttribute("href", expect.stringContaining("/api/lead/analyses/42/reports/docx?token=tok"));
    expect(pdfLink).toHaveAttribute("href", expect.stringContaining("/api/lead/analyses/42/reports/pdf?token=tok"));
  });

  it("shows a disabled, processing state instead of a broken link while reports are still generating", () => {
    render(
      <LeadResults
        results={baseResults({ reports: { docx: "processing", pdf: "processing" } })}
        token="tok"
        analysisId={1}
      />,
    );

    expect(screen.queryByRole("link", { name: /ダウンロード/ })).not.toBeInTheDocument();
    expect(screen.getByText(/Wordでダウンロード\(準備中/)).toBeInTheDocument();
    expect(screen.getByText(/PDFでダウンロード\(準備中/)).toBeInTheDocument();
  });

  it("hides the download option entirely when a format failed to generate, rather than offering a broken link", () => {
    render(
      <LeadResults
        results={baseResults({ reports: { docx: "unavailable", pdf: "ready" } })}
        token="tok"
        analysisId={1}
      />,
    );

    expect(screen.queryByText(/Wordでダウンロード/)).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "PDFでダウンロード" })).toBeInTheDocument();
  });

  it("uses the recruiter's own questions as headings, not the internal perspective names", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.getByText("書くべきことが書けているか")).toBeInTheDocument();
    expect(screen.getByText("伝えたいことが分かりやすく伝わっているか")).toBeInTheDocument();
    expect(screen.getByText("知りたい情報にたどり着けるか")).toBeInTheDocument();
    expect(screen.getByText("見やすく、使いやすいか")).toBeInTheDocument();

    // バックエンドが返す内部名(perspective.label / category_scores[].name)そのままは
    // 見出しとして出さない。
    expect(screen.queryByText("メッセージの分かりやすさ")).not.toBeInTheDocument();
    expect(screen.queryByText("情報の取りやすさ・導線")).not.toBeInTheDocument();
  });

  it("④は色のコントラストに触れ、配色の印象自体は自動判定していない旨を明示する", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.getByText(/文字と背景のコントラスト/)).toBeInTheDocument();
    expect(screen.getByText(/配色やレイアウトの見た目そのものの印象は自動判定の対象外です/)).toBeInTheDocument();
  });

  it("shows qualitative status text instead of a numeric fraction for each perspective", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.getAllByText("確認をおすすめします").length).toBeGreaterThan(0);
    expect(screen.getAllByText("良好").length).toBeGreaterThan(0);
    expect(screen.getAllByText("改善の余地があります").length).toBeGreaterThan(0);

    // 観点ごとの分数表示("5 / 15"のような形式)は出さない。
    expect(screen.queryByText(/^\d+\s*\/\s*\d+$/)).not.toBeInTheDocument();
  });

  it("①観点は採用ページを検出できなかった場合、網羅しているかのように見せず正直に書く", () => {
    const notDetected = baseResults({
      websites: [
        baseWebsite({
          perspectives: basePerspectives({
            completeness: {
              key: "completeness",
              label: "書くべきことが書けているか",
              heading: "書くべきことが書けているか",
              note: COMPLETENESS_NOTE,
              summary: "採用ページを検出できませんでした。トップページに採用に関する案内が見つからなかったため、この観点は今回「計測対象外」です。",
              status: "not_detected",
              items: [],
            },
          }),
        }),
      ],
    });

    render(<LeadResults results={notDetected} token="tok" analysisId={1} />);

    expect(screen.getByText(/採用ページを検出できませんでした/)).toBeInTheDocument();
    expect(screen.getByText("計測対象外")).toBeInTheDocument();
  });

  it("既存の誠実性表示(計測できませんでした・評価不可)を4観点表示でも区別して見せる", () => {
    const unavailable = baseResults({
      websites: [
        baseWebsite({
          perspectives: basePerspectives({
            completeness: {
              key: "completeness",
              label: "書くべきことが書けているか",
              heading: "書くべきことが書けているか",
              note: COMPLETENESS_NOTE,
              summary: "採用ページへのリンクは見つかりましたが、内容を確認できませんでした。",
              status: "unavailable",
              items: [],
            },
            clarity: {
              key: "clarity",
              label: "メッセージの分かりやすさ",
              heading: "伝えたいことが分かりやすく伝わっているか",
              note: null,
              status: "not_measured",
              items: [{ label: "ページタイトルの長さ", status: "not_measured", detail: null }],
            },
          }),
        }),
      ],
    });

    render(<LeadResults results={unavailable} token="tok" analysisId={1} />);

    expect(screen.getByText("評価不可")).toBeInTheDocument();
    expect(screen.getAllByText("計測できませんでした").length).toBeGreaterThan(0);
  });

  // --- 2026-07-30の構成変更(縦の長さを抑え、2社を並べて比べられるようにする) ---

  it("4観点の比較ブロックに、自社と競合を重ねたレーダーの頂点を観点ごとに打つ", () => {
    const twoSites = baseResults({
      websites: [baseWebsite(), baseWebsite({ website_name: "競合株式会社", is_primary: false })],
    });

    render(<LeadResults results={twoSites} token="tok" analysisId={1} />);

    expect(screen.getByText("4つの観点での比較")).toBeInTheDocument();
    // completeness: 8/10 = 80%(configured_max_score 10 と同値のため、分母の
    // 取り違えでは差が出ない。分母の検証はlead-perspective-comparison.test.tsx側。)
    expect(screen.getByTestId("vertex-self-completeness")).toHaveAttribute("data-value", "80");
    expect(screen.getByTestId("vertex-competitor-completeness")).toHaveAttribute("data-value", "80");
  });

  it("項目の内訳は既定で折りたたまれており、開くまで縦に伸びない", async () => {
    const user = userEvent.setup();
    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.queryByText("採用ページの案内")).not.toBeInTheDocument();

    await user.click(screen.getAllByRole("button", { name: /内訳を見る/ })[0]!);

    expect(screen.getByText("採用ページの案内")).toBeInTheDocument();
  });

  it("2社のデータ品質(カバー率・信頼度)を同じ位置にまとめて出す", () => {
    const twoSites = baseResults({
      websites: [baseWebsite(), baseWebsite({ website_name: "競合株式会社", is_primary: false })],
    });

    render(<LeadResults results={twoSites} token="tok" analysisId={1} />);

    expect(screen.getByText("サンプル株式会社")).toBeInTheDocument();
    expect(screen.getByText("競合株式会社")).toBeInTheDocument();
    expect(screen.getAllByText("採用サイトとして重要な4観点での評価")).toHaveLength(2);
  });

  it("競合サイトへの改善提案は出さない(リードにとって他社への助言になるため)", () => {
    const twoSites = baseResults({
      websites: [
        baseWebsite(),
        baseWebsite({
          website_name: "競合株式会社",
          is_primary: false,
          top_recommendations: [
            { title: "競合サイト側の改善提案", description: "出してはいけない項目。", priority: "high", impact: "high", effort: "low" },
          ],
        }),
      ],
    });

    render(<LeadResults results={twoSites} token="tok" analysisId={1} />);

    expect(screen.getByText("画像を圧縮してください")).toBeInTheDocument();
    expect(screen.queryByText("競合サイト側の改善提案")).not.toBeInTheDocument();
  });

  it("shows a confirmation dialog before sending the consultation request", async () => {
    const user = userEvent.setup();
    const mutate = vi.fn();
    mockConsultationState({ mutate });

    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(mutate).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "相談をリクエストする" }));

    expect(screen.getByText("相談をリクエストしますか？")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "リクエストする" }));

    expect(mutate).toHaveBeenCalledTimes(1);
  });

  it("shows a success message once the consultation request is newly accepted", () => {
    mockConsultationState({ isSuccess: true, data: { data: { already_requested: false } } as never });

    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.getByText("ご相談リクエストを受け付けました")).toBeInTheDocument();
  });

  it("honestly reports a repeat click as already requested, not as a fresh success", () => {
    mockConsultationState({ isSuccess: true, data: { data: { already_requested: true } } as never });

    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.getByText("既にご相談リクエストを受け付けています")).toBeInTheDocument();
  });

  it("shows an error message when the consultation request fails, without pretending it succeeded", () => {
    mockConsultationState({ isError: true });

    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.getByText("送信に失敗しました。しばらくしてから再度お試しください。")).toBeInTheDocument();
  });
});

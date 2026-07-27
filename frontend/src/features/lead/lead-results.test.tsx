import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { LeadResults } from "@/features/lead/lead-results";
import type { LeadResults as LeadResultsType } from "@/types/lead";

function baseWebsite(overrides: Partial<LeadResultsType["websites"][number]> = {}): LeadResultsType["websites"][number] {
  return {
    website_name: "サンプル株式会社",
    is_primary: true,
    score: {
      overall_score: 72.5,
      display_score: 73,
      available_score: 72.5,
      configured_max_score: 100,
      coverage_rate: 90,
      confidence_rate: 88,
      category_scores: [
        { key: "technical_seo", name: "技術SEO", score: 15, max_available_score: 20, configured_max_score: 20, coverage_rate: 100 },
        { key: "performance", name: "表示速度", score: 10, max_available_score: 15, configured_max_score: 15, coverage_rate: 100 },
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
  it("shows 総合スコア when coverage is at or above the honesty threshold", () => {
    render(<LeadResults results={baseResults()} token="tok" analysisId={1} />);

    expect(screen.getByText("総合スコア")).toBeInTheDocument();
    expect(screen.getByText("サンプル株式会社")).toBeInTheDocument();
    expect(screen.getByText("自社サイト")).toBeInTheDocument();
  });

  it("shows 参考スコア and a warning when coverage is below 70%, never silently upgrading it", () => {
    const lowCoverage = baseResults({
      websites: [baseWebsite({ score: { ...baseWebsite().score, coverage_rate: 40 } })],
    });
    render(<LeadResults results={lowCoverage} token="tok" analysisId={1} />);

    expect(screen.getByText("参考スコア")).toBeInTheDocument();
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
});

import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SeoDetails } from "@/features/analysis/results/seo-details";
import type { MetricEvaluation } from "@/types/analysis";

async function openGoodItems() {
  const user = userEvent.setup();
  await user.click(screen.getByRole("button", { name: /良好な項目を表示/ }));
}

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "title_present", name: "titleタグ", category_key: "technical_seo", value_type: "boolean", unit: null, scoring_type: "boolean",
    status: "success", value: true, raw_value: null, evidence: null, min_value: null, target_value: null, max_value: null,
    higher_is_better: true, confidence: 1, source_type: "static_html", measured_at: null, error_code: null,
    error_message: null, counts_toward_score: true, score: 1, max_score: 1,
    ...overrides,
  };
}

describe("SeoDetails", () => {
  it("evaluates the title with its recommended character-length range", async () => {
    const metrics: MetricEvaluation[] = [
      makeMetric({ key: "title_present", value: true }),
      makeMetric({
        key: "title_length_optimal", name: "title文字数", unit: "chars", scoring_type: "range", value: 50,
        min_value: 10, max_value: 65, score: 0.87, max_score: 0.87,
      }),
    ];

    render(<SeoDetails metrics={metrics} seo={{ title: "サンプルタイトル", meta_description: null, h1_count: 1, word_count: 100 }} />);
    // 満点に近い(良好)ため、良好項目の折りたたみを開いてから確認する。
    await openGoodItems();

    expect(screen.getByText("タイトル(title)")).toBeInTheDocument();
    expect(screen.getByText(/50文字/)).toBeInTheDocument();
    expect(screen.getByText(/10〜65文字/)).toBeInTheDocument();
    expect(screen.getByText(/サンプルタイトル/)).toBeInTheDocument();
  });

  it("shows H1 as not found when valid_count is 0", () => {
    const metrics: MetricEvaluation[] = [
      makeMetric({
        key: "h1_single", name: "H1タグ(1件)", value: false, status: "not_found", score: 0, max_score: 3,
        raw_value: { count: 0, valid_count: 0, visible_count: 0, primary_text: null },
      }),
    ];

    render(<SeoDetails metrics={metrics} seo={null} />);

    expect(screen.getByText("検出されませんでした")).toBeInTheDocument();
    expect(screen.getByText(/有効なH1: 0件/)).toBeInTheDocument();
  });

  it("shows a single H1 as good and displays its content without auto-judging topic relevance", async () => {
    const metrics: MetricEvaluation[] = [
      makeMetric({
        key: "h1_single",
        name: "H1タグ(1件)",
        value: true,
        score: 3,
        max_score: 3,
        raw_value: { count: 1, valid_count: 1, visible_count: 1, primary_text: "ホテル・旅館ランキング" },
      }),
    ];

    render(<SeoDetails metrics={metrics} seo={null} />);
    await openGoodItems();

    expect(screen.getByText(/有効なH1: 1件/)).toBeInTheDocument();
    expect(screen.getByText(/代表H1: ホテル・旅館ランキング/)).toBeInTheDocument();
    expect(screen.getByText(/確認してください/)).toBeInTheDocument();
  });

  it("shows multiple H1 without falling back to a not-found badge, even though normalized_value is false", () => {
    // 今回報告された内部矛盾の回帰テスト: raw_value.count/valid_count > 0
    // なのに、normalized_value(採点専用のboolean)がfalseであることを理由に
    // 「検出されませんでした」/「なし」のようなバッジ・文言を表示しては
    // いけない。
    const metrics: MetricEvaluation[] = [
      makeMetric({
        key: "h1_single",
        name: "H1タグ(1件)",
        value: false,
        status: "success",
        score: 0,
        max_score: 3,
        raw_value: { count: 3, valid_count: 2, visible_count: 3, primary_text: "見出しA" },
      }),
    ];

    render(<SeoDetails metrics={metrics} seo={null} />);

    expect(screen.queryByText("検出されませんでした")).not.toBeInTheDocument();
    expect(screen.getByText(/有効なH1: 2件/)).toBeInTheDocument();
    expect(screen.getByText(/検出したH1: 3件/)).toBeInTheDocument();
    expect(screen.getByText(/広告・非主要見出し: 1件/)).toBeInTheDocument();
    expect(screen.getByText(/主要なH1が2件検出されました/)).toBeInTheDocument();
  });

  it("falls back to raw_value.count for pre-existing analyses recorded before valid_count existed", async () => {
    // 既存Analysis互換性の回帰テスト: valid_countフィールド導入前の古い
    // raw_value(count/texts/primary_textのみ)でも、実在するH1を
    // 誤って「検出されませんでした」と表示してはいけない。
    const metrics: MetricEvaluation[] = [
      makeMetric({
        key: "h1_single",
        name: "H1タグ(1件)",
        value: true,
        score: 3,
        max_score: 3,
        raw_value: { count: 1, texts: ["旧形式の見出し"], primary_text: "旧形式の見出し" },
      }),
    ];

    render(<SeoDetails metrics={metrics} seo={null} />);
    await openGoodItems();

    expect(screen.queryByText("検出されませんでした")).not.toBeInTheDocument();
    expect(screen.getByText(/有効なH1: 1件/)).toBeInTheDocument();
  });

  it("does not show ad or hidden H1 text as the representative content", () => {
    // validCount(1) !== totalCount(3)のため「要確認」扱いとなり、良好項目の
    // 折りたたみには入らず直接表示される。
    const metrics: MetricEvaluation[] = [
      makeMetric({
        key: "h1_single",
        name: "H1タグ(1件)",
        value: true,
        score: 3,
        max_score: 3,
        raw_value: { count: 3, valid_count: 1, visible_count: 3, primary_text: "ホテル・旅館ランキング" },
      }),
    ];

    render(<SeoDetails metrics={metrics} seo={null} />);

    expect(screen.getByText(/代表H1: ホテル・旅館ランキング/)).toBeInTheDocument();
    expect(screen.queryByText(/【PR】/)).not.toBeInTheDocument();
  });
});

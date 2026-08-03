import { describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { LeadBrandWheel, axisRatio, wheelPoint } from "@/features/lead/lead-brand-wheel";
import type {
  BrandWheelAxis,
  BrandWheelComparison,
  BrandWheelResult,
  BrandWheelStatus,
  LeadWebsiteResult,
} from "@/types/lead";

const AXIS_DEFS: { key: string; group: BrandWheelAxis["group"]; name: string }[] = [
  { key: "will_activity", group: "company_appeal", name: "活動的魅力" },
  { key: "asset", group: "company_appeal", name: "資産的魅力" },
  { key: "personality", group: "company_distance", name: "経営スタイル" },
  { key: "relationship", group: "company_distance", name: "就業環境" },
  { key: "emotional_benefit", group: "job_appeal", name: "情緒的便益" },
  { key: "financial_benefit", group: "job_appeal", name: "金銭的便益" },
];

function axes(counts: number[], maxCount = 4): BrandWheelAxis[] {
  return AXIS_DEFS.map((def, i) => ({
    ...def,
    matched_count: counts[i] ?? 0,
    max_count: maxCount,
    matched_sub_elements: Array.from({ length: counts[i] ?? 0 }, (_, n) => ({
      key: `${def.key}_${n}`,
      name: `${def.name}の要素${n + 1}`,
    })),
  }));
}

function wheel(overrides: Partial<BrandWheelResult> = {}): BrandWheelResult {
  return {
    status: "success",
    status_message: null,
    analyzed_url: "https://careers.example.co.jp/",
    axes: axes([3, 2, 1, 0, 1, 4]),
    key_message: "技術で社会基盤を支える、という主題が置かれています。",
    impression: "幅広さは伝わるものの、働く人にとっての嬉しさとの距離があります。",
    source_pages: { recruit_page: "read", home_page: "read" },
    ...overrides,
  };
}

function site(overrides: Partial<LeadWebsiteResult> = {}): LeadWebsiteResult {
  return {
    website_name: "自社株式会社",
    is_primary: true,
    score: {
      overall_score: 0,
      display_score: 0,
      available_score: 0,
      configured_max_score: 0,
      coverage_rate: 90,
      confidence_rate: 90,
      category_scores: [],
      metric_summary: {
        success: 0,
        not_found: 0,
        unavailable: 0,
        error: 0,
        not_applicable: 0,
        scored_unavailable: 0,
        informational_unavailable: 0,
      },
    },
    perspectives: [],
    top_recommendations: [],
    brand_wheel: wheel(),
    ...overrides,
  };
}

function competitor(overrides: Partial<LeadWebsiteResult> = {}): LeadWebsiteResult {
  return site({
    website_name: "競合株式会社",
    is_primary: false,
    brand_wheel: wheel({ axes: axes([4, 4, 3, 3, 2, 2]) }),
    ...overrides,
  });
}

const COMPARISON: BrandWheelComparison = {
  self_points: ["金銭的便益が最も内容として充足しています。"],
  competitor_points: ["全体的に情報が充足しています。"],
  one_point: { key: "insufficient_content", text: "候補者が十分な情報を得られない可能性があります。" },
};

describe("wheelPoint", () => {
  it("0時方向を起点に時計回りで軸を配置する", () => {
    const top = wheelPoint(0, 6, 1);
    const next = wheelPoint(1, 6, 1);

    expect(top.y).toBeLessThan(next.y);
    expect(next.x).toBeGreaterThan(top.x);
  });

  it("割合1が最も外側になる", () => {
    const inner = wheelPoint(0, 6, 0.5);
    const outer = wheelPoint(0, 6, 1);

    expect(outer.y).toBeLessThan(inner.y);
  });
});

describe("axisRatio", () => {
  it("max_countで割った割合を返す(満点は軸ごとにAPIが返す値を使う)", () => {
    expect(axisRatio({ ...AXIS_DEFS[0]!, matched_count: 3, max_count: 4, matched_sub_elements: [] })).toBe(0.75);
    // 満点が5に変わってもコード変更なしで追従する。
    expect(axisRatio({ ...AXIS_DEFS[0]!, matched_count: 3, max_count: 5, matched_sub_elements: [] })).toBe(0.6);
  });

  it("max_countが0の軸は割合を出さない(0として扱わない)", () => {
    expect(axisRatio({ ...AXIS_DEFS[0]!, matched_count: 0, max_count: 0, matched_sub_elements: [] })).toBeNull();
  });
});

describe("LeadBrandWheel", () => {
  it("自社と競合の面を重ねて描き、軸ごとの件数をテキストでも出す", () => {
    render(<LeadBrandWheel websites={[site(), competitor()]} comparison={COMPARISON} />);

    expect(screen.getByTestId("wheel-self")).toBeInTheDocument();
    expect(screen.getByTestId("wheel-competitor")).toBeInTheDocument();
    expect(screen.getByTestId("wheel-count-will_activity")).toHaveTextContent("3 / 4件");
    expect(screen.getByTestId("wheel-count-financial_benefit")).toHaveTextContent("4 / 4件");
  });

  it("満点が5でもそのまま表示する(フロント側に固定値を持たない)", () => {
    const five = site({ brand_wheel: wheel({ axes: axes([3, 2, 1, 0, 1, 5], 5) }) });

    render(<LeadBrandWheel websites={[five]} />);

    expect(screen.getByTestId("wheel-count-will_activity")).toHaveTextContent("3 / 5件");
  });

  it("読み取れた下位要素を軸ごとに列挙し、0件の軸はその旨を書く", () => {
    render(<LeadBrandWheel websites={[site()]} />);

    expect(screen.getByText("活動的魅力の要素1")).toBeInTheDocument();
    expect(screen.getByText("読み取れた内容はありません")).toBeInTheDocument();
  });

  it("キーメッセージと印象を出す", () => {
    render(<LeadBrandWheel websites={[site()]} />);

    expect(screen.getByText(/技術で社会基盤を支える/)).toBeInTheDocument();
    expect(screen.getByText(/働く人にとっての嬉しさとの距離/)).toBeInTheDocument();
  });

  it.each<BrandWheelStatus>(["pending", "insufficient_input", "recruit_page_unreadable", "no_matched_content", "error"])(
    "status=%s のときは図を描かず、バックエンドの理由文言をそのまま出す",
    (status) => {
      const blocked = site({
        brand_wheel: wheel({ status, status_message: "この項目の分析は行っていません。", axes: [] }),
      });

      render(<LeadBrandWheel websites={[blocked]} />);

      expect(screen.queryByTestId("wheel-self")).not.toBeInTheDocument();
      expect(screen.getByTestId("wheel-unavailable")).toBeInTheDocument();
      expect(screen.getByText("この項目の分析は行っていません。")).toBeInTheDocument();
    },
  );

  it("statusがsuccessでも軸が空なら図を描かない", () => {
    const empty = site({ brand_wheel: wheel({ axes: [], status_message: "読み取れませんでした。" }) });

    render(<LeadBrandWheel websites={[empty]} />);

    expect(screen.queryByTestId("wheel-self")).not.toBeInTheDocument();
    expect(screen.getByTestId("wheel-unavailable")).toBeInTheDocument();
  });

  it("競合の分析が成立していない場合は自社だけを描く", () => {
    const brokenCompetitor = competitor({
      brand_wheel: wheel({ status: "error", status_message: "取得できませんでした。", axes: [] }),
    });

    render(<LeadBrandWheel websites={[site(), brokenCompetitor]} comparison={COMPARISON} />);

    expect(screen.getByTestId("wheel-self")).toBeInTheDocument();
    expect(screen.queryByTestId("wheel-competitor")).not.toBeInTheDocument();
  });

  it("比較まとめとワンポイントは、バックエンドが導出した文言をそのまま出す", () => {
    render(<LeadBrandWheel websites={[site(), competitor()]} comparison={COMPARISON} />);

    // サマリー欄にも同じ自社の所見が出る(モックアップ通り)ため、比較まとめの中に
    // 限定して確認する。
    const summary = within(screen.getByTestId("wheel-comparison"));
    expect(summary.getByTestId("wheel-self-points")).toHaveTextContent("金銭的便益が最も内容として充足しています。");
    expect(summary.getByTestId("wheel-competitor-points")).toHaveTextContent("全体的に情報が充足しています。");
    expect(screen.getByTestId("wheel-one-point")).toHaveTextContent("候補者が十分な情報を得られない可能性があります。");
  });

  it("比較まとめの中身が空の場合は、枠だけを描かない", () => {
    const empty: BrandWheelComparison = { self_points: [], competitor_points: [], one_point: null };

    render(<LeadBrandWheel websites={[site(), competitor()]} comparison={empty} />);

    expect(screen.queryByTestId("wheel-comparison")).not.toBeInTheDocument();
    expect(screen.queryByText("他社ページ比較とのまとめ")).not.toBeInTheDocument();
  });

  it("競合サイトが無い場合は比較まとめを出さない", () => {
    render(<LeadBrandWheel websites={[site()]} comparison={COMPARISON} />);

    expect(screen.queryByText("他社ページ比較とのまとめ")).not.toBeInTheDocument();
  });

  it("ブランド・ホイールの結果自体が無い場合は何も描かない", () => {
    const { container } = render(<LeadBrandWheel websites={[site({ brand_wheel: null })]} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("サイトだけを見ている旨とAI利用を必ず明記する", () => {
    render(<LeadBrandWheel websites={[site()]} />);

    expect(screen.getByText(/サイトのみを見ています/)).toBeInTheDocument();
    expect(screen.getByText(/AIを使用しています/)).toBeInTheDocument();
  });
});

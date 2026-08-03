import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {
  COVERAGE_GAP_THRESHOLD,
  LeadPerspectiveComparison,
  buildComparisonRows,
  normalizedValue,
  radarPoint,
} from "@/features/lead/lead-perspective-comparison";
import type { CategoryScore } from "@/types/analysis";
import type { LeadPerspective, LeadPerspectiveKey, LeadPerspectiveStatus, LeadWebsiteResult } from "@/types/lead";

const HEADINGS: Record<LeadPerspectiveKey, string> = {
  completeness: "書くべきことが書けているか",
  clarity: "伝えたいことが分かりやすく伝わっているか",
  findability: "知りたい情報にたどり着けるか",
  usability: "見やすく、使いやすいか",
};

const KEYS: LeadPerspectiveKey[] = ["completeness", "clarity", "findability", "usability"];

function perspective(
  key: LeadPerspectiveKey,
  status: LeadPerspectiveStatus = "good",
  overrides: Partial<LeadPerspective> = {},
): LeadPerspective {
  return {
    key,
    label: `内部名: ${key}`,
    heading: HEADINGS[key],
    note: null,
    status,
    items: [{ label: `${key}の項目`, status, detail: null }],
    ...overrides,
  };
}

function categoryScore(key: LeadPerspectiveKey, score: number, maxAvailable: number, configuredMax: number): CategoryScore {
  return {
    key,
    name: `内部名: ${key}`,
    score,
    max_available_score: maxAvailable,
    configured_max_score: configuredMax,
    coverage_rate: configuredMax > 0 ? (maxAvailable / configuredMax) * 100 : 0,
  };
}

function website(overrides: Partial<LeadWebsiteResult> = {}): LeadWebsiteResult {
  return {
    website_name: "自社株式会社",
    is_primary: true,
    score: {
      overall_score: 20,
      display_score: 20,
      available_score: 30,
      configured_max_score: 40,
      coverage_rate: 90,
      confidence_rate: 88,
      category_scores: [
        categoryScore("completeness", 8, 10, 10),
        categoryScore("clarity", 3, 6, 10),
        categoryScore("findability", 5, 5, 10),
        categoryScore("usability", 4, 8, 10),
      ],
      metric_summary: {
        success: 10,
        not_found: 1,
        unavailable: 0,
        error: 0,
        not_applicable: 0,
        scored_unavailable: 0,
        informational_unavailable: 0,
      },
    },
    perspectives: KEYS.map((key) => perspective(key)),
    top_recommendations: [],
    ...overrides,
  };
}

function competitorWebsite(overrides: Partial<LeadWebsiteResult> = {}): LeadWebsiteResult {
  const base = website();

  return {
    ...base,
    website_name: "競合株式会社",
    is_primary: false,
    ...overrides,
  };
}

describe("normalizedValue", () => {
  it("取得できた項目の満点(max_available_score)を分母にする ―― configured_max_scoreを分母にすると未取得分だけ達成度が目減りする", () => {
    const site = website({
      score: {
        ...website().score,
        // 10点満点のうち4点分しか取得できず、そのうち3点を獲得した状態。
        category_scores: [categoryScore("completeness", 3, 4, 10)],
      },
      perspectives: [perspective("completeness", "needs_review")],
    });

    // 3/4 = 75%。configured_max_score(10)を分母にすると30%になってしまう。
    expect(normalizedValue(site, site.perspectives[0]!)).toBe(75);
  });

  it("1件も取得できていない観点は0%ではなくnullを返す(0点として扱わない)", () => {
    const site = website({
      score: { ...website().score, category_scores: [categoryScore("completeness", 0, 0, 10)] },
      perspectives: [perspective("completeness", "needs_review")],
    });

    expect(normalizedValue(site, site.perspectives[0]!)).toBeNull();
  });

  it.each<LeadPerspectiveStatus>(["not_measured", "not_applicable", "not_detected", "unavailable"])(
    "未取得系の状態(%s)は数値を出さない",
    (status) => {
      const site = website({ perspectives: [perspective("completeness", status)] });

      expect(normalizedValue(site, site.perspectives[0]!)).toBeNull();
    },
  );

  it("該当するcategory_scoresが無い場合もnullを返す(存在しない数値を作らない)", () => {
    const site = website({
      score: { ...website().score, category_scores: [] },
      perspectives: [perspective("completeness", "good")],
    });

    expect(normalizedValue(site, site.perspectives[0]!)).toBeNull();
  });
});

describe("radarPoint", () => {
  it("0時方向を起点に時計回りで軸を配置する", () => {
    const top = radarPoint(0, 4, 100);
    const right = radarPoint(1, 4, 100);

    expect(top.y).toBeLessThan(right.y);
    expect(right.x).toBeGreaterThan(top.x);
  });

  it("値0は中心に一致する(0として描かないことは呼び出し側の責務)", () => {
    const center = radarPoint(0, 4, 0);
    const outer = radarPoint(0, 4, 100);

    expect(center.x).toBeCloseTo(outer.x, 5);
    expect(center.y).toBeGreaterThan(outer.y);
  });
});

describe("buildComparisonRows", () => {
  it("観点の並び順と見出しは自社サイト側のperspectivesをそのまま使う", () => {
    const rows = buildComparisonRows(website(), competitorWebsite());

    expect(rows.map((row) => row.key)).toEqual(KEYS);
    expect(rows.map((row) => row.heading)).toEqual(KEYS.map((key) => HEADINGS[key]));
  });

  it("競合サイトが無い場合、competitorはnullになる", () => {
    const rows = buildComparisonRows(website(), null);

    expect(rows.every((row) => row.competitor === null)).toBe(true);
  });
});

describe("LeadPerspectiveComparison", () => {
  it("観点ごとに自社・競合の数値を出す", () => {
    render(<LeadPerspectiveComparison websites={[website(), competitorWebsite()]} />);

    // completeness: 8/10 = 80%
    expect(screen.getByTestId("value-self-completeness")).toHaveTextContent("80");
    // clarity: 3/6 = 50%
    expect(screen.getByTestId("value-competitor-clarity")).toHaveTextContent("50");
  });

  it("数値を出せない観点は0として描かず、図からも除外してその旨を明記する", () => {
    const site = website({
      perspectives: [perspective("completeness", "not_measured"), ...KEYS.slice(1).map((k) => perspective(k))],
    });

    render(<LeadPerspectiveComparison websites={[site]} />);

    expect(screen.getByTestId("value-self-completeness")).toHaveTextContent("数値なし");
    // 理由はバックエンドと同じ文言で示す。
    expect(screen.getByText("計測できませんでした")).toBeInTheDocument();
    // 図の頂点は3つに減る(0の頂点を作らない)。
    expect(screen.getByTestId("radar-self")).toHaveAttribute("data-points", "3");
    expect(screen.getByText(/図に含まれていません/)).toBeInTheDocument();
  });

  it("レーダー図は自社と競合を重ねて描き、4軸すべてに値があれば頂点は4つになる", () => {
    render(<LeadPerspectiveComparison websites={[website(), competitorWebsite()]} />);

    expect(screen.getByTestId("radar-self")).toHaveAttribute("data-points", "4");
    expect(screen.getByTestId("radar-competitor")).toHaveAttribute("data-points", "4");
  });

  it("頂点が2つ以下のときは面(polygon)にせず、存在しない面積を作らない", () => {
    const site = website({
      perspectives: [
        perspective("completeness", "good"),
        perspective("clarity", "good"),
        perspective("findability", "not_measured"),
        perspective("usability", "unavailable"),
      ],
    });

    const { container } = render(<LeadPerspectiveComparison websites={[site]} />);

    expect(screen.getByTestId("radar-self")).toHaveAttribute("data-points", "2");
    expect(container.querySelector('[data-testid="radar-self"] polygon')).toBeNull();
    expect(container.querySelector('[data-testid="radar-self"] polyline')).not.toBeNull();
  });

  it("取得率を常に併記する(数値の土台が分かる状態を保つ)", () => {
    render(
      <LeadPerspectiveComparison
        websites={[website(), competitorWebsite({ score: { ...competitorWebsite().score, coverage_rate: 85 } })]}
      />,
    );

    expect(screen.getByText(/取得率: 自社 90% ／ 競合 85%/)).toBeInTheDocument();
  });

  it("2社の取得率が離れている場合、そのまま並べて比較できない旨を注記する", () => {
    render(
      <LeadPerspectiveComparison
        websites={[
          website({ score: { ...website().score, coverage_rate: 95 } }),
          competitorWebsite({
            score: { ...competitorWebsite().score, coverage_rate: 95 - COVERAGE_GAP_THRESHOLD },
          }),
        ]}
      />,
    );

    expect(screen.getByText(/この数値をそのまま並べての比較はできません/)).toBeInTheDocument();
  });

  it("取得率が近い場合は比較不可の注記を出さない", () => {
    render(
      <LeadPerspectiveComparison
        websites={[
          website({ score: { ...website().score, coverage_rate: 95 } }),
          competitorWebsite({ score: { ...competitorWebsite().score, coverage_rate: 90 } }),
        ]}
      />,
    );

    expect(screen.queryByText(/この数値をそのまま並べての比較はできません/)).not.toBeInTheDocument();
  });

  it("どちらかの取得率が70%未満なら参考値であることを明示する", () => {
    render(<LeadPerspectiveComparison websites={[website({ score: { ...website().score, coverage_rate: 40 } })]} />);

    expect(screen.getByText("(参考値)")).toBeInTheDocument();
    expect(screen.getByText(/参考値としてご確認ください/)).toBeInTheDocument();
  });

  it("項目の内訳は既定で閉じており、開くと項目とその判定が出る", async () => {
    const user = userEvent.setup();
    render(<LeadPerspectiveComparison websites={[website()]} />);

    expect(screen.queryByText("completenessの項目")).not.toBeInTheDocument();

    await user.click(screen.getAllByRole("button", { name: /内訳を見る/ })[0]!);

    expect(screen.getByText("completenessの項目")).toBeInTheDocument();
  });

  it("数値の表は既定で閉じており、開くと観点ごとの数値が出る(ツールチップだけが読む手段にならない)", async () => {
    const user = userEvent.setup();
    render(<LeadPerspectiveComparison websites={[website()]} />);

    expect(screen.queryByRole("table")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "数値を表で見る" }));

    expect(screen.getByRole("table")).toBeInTheDocument();
    expect(screen.getByRole("cell", { name: "80" })).toBeInTheDocument();
  });

  it("競合サイトが無い場合は競合の凡例もバーも出さない", () => {
    render(<LeadPerspectiveComparison websites={[website()]} />);

    expect(screen.getByText("4つの観点での評価")).toBeInTheDocument();
    expect(screen.queryByText("競合")).not.toBeInTheDocument();
    expect(screen.queryByTestId("radar-competitor")).not.toBeInTheDocument();
  });

  it("バックエンドの内部名(label/category name)は画面に出さない", () => {
    render(<LeadPerspectiveComparison websites={[website(), competitorWebsite()]} />);

    expect(screen.queryByText("内部名: completeness")).not.toBeInTheDocument();
  });
});

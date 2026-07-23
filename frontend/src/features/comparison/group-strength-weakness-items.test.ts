import { describe, expect, it } from "vitest";
import { groupStrengthWeaknessItems } from "@/features/comparison/group-strength-weakness-items";
import type { CategoryComparison, MetricComparison, StrengthWeaknessItem } from "@/types/comparison";

function metricComparison(key: string, name: string, categoryKey: string): MetricComparison {
  return { key, name, category_key: categoryKey, value_type: "number", unit: "ms", source_type: "lighthouse", higher_is_better: false, sites: [] };
}

const categories: CategoryComparison[] = [{ key: "performance", name: "表示速度", configured_max_score: 15, sites: [] }];

describe("groupStrengthWeaknessItems", () => {
  it("merges 2+ metric-level weaknesses from the same category into one combined line", () => {
    const items: StrengthWeaknessItem[] = [
      { type: "metric", metric_key: "fcp", label: "FCPが競合平均を下回っています" },
      { type: "metric", metric_key: "lcp", label: "LCPが競合平均を下回っています" },
      { type: "metric", metric_key: "tbt", label: "TBTが競合平均を下回っています" },
    ];
    const metrics = [metricComparison("fcp", "FCP", "performance"), metricComparison("lcp", "LCP", "performance"), metricComparison("tbt", "TBT", "performance")];

    const result = groupStrengthWeaknessItems(items, metrics, categories, "weakness");

    expect(result).toHaveLength(1);
    expect(result[0].label).toContain("表示速度");
    expect(result[0].label).toContain("競合より低い水準");
    expect(result[0].label).toContain("FCP");
    expect(result[0].label).toContain("LCP");
    expect(result[0].label).toContain("TBT");
  });

  it("keeps a single metric item from a category standalone instead of merging", () => {
    const items: StrengthWeaknessItem[] = [{ type: "metric", metric_key: "fcp", label: "FCPが競合平均を下回っています" }];
    const metrics = [metricComparison("fcp", "FCP", "performance")];

    const result = groupStrengthWeaknessItems(items, metrics, categories, "weakness");

    expect(result).toHaveLength(1);
    expect(result[0].label).toBe("FCPが競合平均を下回っています");
  });

  it("does not merge metric items when the category already has its own category-level item", () => {
    const items: StrengthWeaknessItem[] = [
      { type: "category", category_key: "performance", label: "表示速度のスコアが低水準です" },
      { type: "metric", metric_key: "fcp", label: "FCPが競合平均を下回っています" },
      { type: "metric", metric_key: "lcp", label: "LCPが競合平均を下回っています" },
    ];
    const metrics = [metricComparison("fcp", "FCP", "performance"), metricComparison("lcp", "LCP", "performance")];

    const result = groupStrengthWeaknessItems(items, metrics, categories, "weakness");

    expect(result).toHaveLength(3);
    expect(result.map((r) => r.label)).toContain("表示速度のスコアが低水準です");
    expect(result.map((r) => r.label)).toContain("FCPが競合平均を下回っています");
  });

  it("leaves recommendation-type items untouched", () => {
    const items: StrengthWeaknessItem[] = [{ type: "recommendation", metric_key: null, label: "titleを設定してください。", priority: "high" }];

    const result = groupStrengthWeaknessItems(items, [], categories, "weakness");

    expect(result).toEqual([{ key: "recommendation-titleを設定してください。", label: "titleを設定してください。" }]);
  });
});

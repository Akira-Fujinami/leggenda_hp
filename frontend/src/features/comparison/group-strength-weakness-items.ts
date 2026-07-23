import type { CategoryComparison, MetricComparison, StrengthWeaknessItem } from "@/types/comparison";

export interface GroupedDisplayItem {
  key: string;
  label: string;
}

/**
 * 同一カテゴリに属するmetric種別の項目が複数ある場合、1行に集約して表示する
 * (例: FCP/LCP/TBTが個別に並ぶ代わりに「表示速度が競合より低水準 主な要因:FCP、LCP、TBT」)。
 * category種別・recommendation種別の項目、および単独(同カテゴリ1件のみ)の
 * metric項目はそのまま個別表示する。バックエンドの強み・弱み判定ロジックには
 * 一切手を加えない、表示専用の集約。
 */
export function groupStrengthWeaknessItems(
  items: StrengthWeaknessItem[],
  metrics: MetricComparison[],
  categories: CategoryComparison[],
  direction: "strength" | "weakness",
): GroupedDisplayItem[] {
  const metricByKey = new Map(metrics.map((m) => [m.key, m]));
  const categoryNameByKey = new Map(categories.map((c) => [c.key, c.name]));

  const categoryItems = items.filter((i) => i.type === "category");
  const coveredCategoryKeys = new Set(categoryItems.map((i) => i.category_key));
  const metricItems = items.filter((i) => i.type === "metric");
  const otherItems = items.filter((i) => i.type !== "category" && i.type !== "metric");

  const metricsByCategory = new Map<string, StrengthWeaknessItem[]>();
  const standaloneMetricItems: StrengthWeaknessItem[] = [];

  for (const item of metricItems) {
    const categoryKey = item.metric_key ? metricByKey.get(item.metric_key)?.category_key : undefined;
    if (!categoryKey || coveredCategoryKeys.has(categoryKey)) {
      standaloneMetricItems.push(item);
      continue;
    }
    const list = metricsByCategory.get(categoryKey) ?? [];
    list.push(item);
    metricsByCategory.set(categoryKey, list);
  }

  const result: GroupedDisplayItem[] = [];

  for (const item of categoryItems) {
    result.push({ key: `category-${item.category_key}`, label: item.label });
  }

  for (const [categoryKey, groupItems] of metricsByCategory) {
    if (groupItems.length < 2) {
      standaloneMetricItems.push(...groupItems);
      continue;
    }
    const categoryName = categoryNameByKey.get(categoryKey) ?? categoryKey;
    const metricNames = groupItems
      .map((i) => (i.metric_key ? metricByKey.get(i.metric_key)?.name : undefined) ?? i.metric_key ?? "")
      .filter(Boolean)
      .join("、");
    const levelLabel = direction === "strength" ? "競合より高い水準" : "競合より低い水準";
    result.push({ key: `category-group-${categoryKey}`, label: `${categoryName}が${levelLabel}　主な要因:${metricNames}` });
  }

  for (const item of standaloneMetricItems) {
    result.push({ key: `metric-${item.metric_key}`, label: item.label });
  }

  for (const item of otherItems) {
    result.push({ key: `recommendation-${item.label}`, label: item.label });
  }

  return result;
}

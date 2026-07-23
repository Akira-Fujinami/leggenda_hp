export interface ResultsSectionDef {
  id: string;
  label: string;
  /** このセクションに対応するCategoryDefinition.key(カテゴリカードの「詳細を見る」からの遷移先解決に使う)。 */
  categoryKey?: string;
}

export const RESULTS_SECTIONS: ResultsSectionDef[] = [
  { id: "summary", label: "概要" },
  { id: "priority", label: "優先改善" },
  { id: "categories", label: "カテゴリ評価" },
  { id: "seo", label: "SEO", categoryKey: "technical_seo" },
  { id: "content", label: "コンテンツ", categoryKey: "content" },
  { id: "conversion", label: "集客・CTA", categoryKey: "conversion" },
  { id: "performance", label: "表示速度", categoryKey: "performance" },
  { id: "technology", label: "技術", categoryKey: "technology" },
  { id: "authority", label: "外部SEO", categoryKey: "authority" },
  { id: "screenshots", label: "スクリーンショット" },
  { id: "failed", label: "取得失敗" },
];

/** アコーディオンとして開閉制御する対象のセクション(概要/優先改善/カテゴリ一覧は常時表示のため対象外)。 */
export const ACCORDION_SECTION_IDS = RESULTS_SECTIONS.filter((s) => s.categoryKey || s.id === "screenshots" || s.id === "failed").map(
  (s) => s.id,
);

export function categoryKeyToSectionId(categoryKey: string): string | undefined {
  return RESULTS_SECTIONS.find((s) => s.categoryKey === categoryKey)?.id;
}

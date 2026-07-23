"use client";

export interface ComparisonSectionDef {
  id: string;
  label: string;
}

export const COMPARISON_SECTIONS: ComparisonSectionDef[] = [
  { id: "summary", label: "概要" },
  { id: "charts", label: "グラフ" },
  { id: "categories", label: "カテゴリ比較" },
  { id: "strengths", label: "強み・弱み" },
  { id: "recommendations", label: "改善提案" },
  { id: "ai", label: "AI分析" },
];

/**
 * results画面のResultsSectionNav(features/analysis/results/results-section-nav.tsx)と
 * 同じパターン(デスクトップ横並びボタン+モバイルselect、IntersectionObserverで
 * アクティブ表示)。ナビのボタンとAccordionトリガー等でテキストが重複すると
 * アクセシビリティツリー上で名前が衝突する(resultsページ実装時に発見)ため、
 * 各ボタンにはaria-labelで「〜セクションへ移動」を付与する。
 */
export function ComparisonStickyNav({
  activeId,
  onNavigate,
}: {
  activeId: string | null;
  onNavigate: (id: string) => void;
}) {
  return (
    <nav aria-label="比較ページ内ナビゲーション" className="sticky top-0 z-20 -mx-4 border-b bg-background px-4">
      <div className="hidden gap-1 overflow-x-auto py-1.5 sm:flex">
        {COMPARISON_SECTIONS.map((s) => (
          <button
            key={s.id}
            type="button"
            onClick={() => onNavigate(s.id)}
            aria-current={activeId === s.id ? "true" : undefined}
            aria-label={`${s.label}セクションへ移動`}
            className={`shrink-0 rounded-md px-2.5 py-1 text-sm whitespace-nowrap transition-colors ${
              activeId === s.id ? "bg-muted font-medium text-foreground" : "text-muted-foreground hover:bg-muted/50"
            }`}
          >
            {s.label}
          </button>
        ))}
      </div>
      <div className="py-1.5 sm:hidden">
        <label className="sr-only" htmlFor="comparison-section-select">
          セクションへ移動
        </label>
        <select
          id="comparison-section-select"
          value={activeId ?? COMPARISON_SECTIONS[0].id}
          onChange={(e) => onNavigate(e.target.value)}
          className="w-full rounded-md border bg-background px-2 py-1.5 text-sm"
        >
          {COMPARISON_SECTIONS.map((s) => (
            <option key={s.id} value={s.id}>
              {s.label}
            </option>
          ))}
        </select>
      </div>
    </nav>
  );
}

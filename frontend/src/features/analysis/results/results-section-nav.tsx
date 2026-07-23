"use client";

import { RESULTS_SECTIONS } from "@/features/analysis/results/section-config";

export function ResultsSectionNav({
  sectionIds,
  activeId,
  onNavigate,
}: {
  sectionIds: string[];
  activeId: string | null;
  onNavigate: (id: string) => void;
}) {
  const sections = RESULTS_SECTIONS.filter((s) => sectionIds.includes(s.id));

  return (
    <nav aria-label="結果ページ内ナビゲーション" className="sticky top-[52px] z-20 -mx-4 border-b bg-background px-4">
      <div className="hidden gap-1 overflow-x-auto py-1.5 sm:flex">
        {sections.map((s) => (
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
        <label className="sr-only" htmlFor="results-section-select">
          セクションへ移動
        </label>
        <select
          id="results-section-select"
          value={activeId ?? sections[0]?.id ?? ""}
          onChange={(e) => onNavigate(e.target.value)}
          className="w-full rounded-md border bg-background px-2 py-1.5 text-sm"
        >
          {sections.map((s) => (
            <option key={s.id} value={s.id}>
              {s.label}
            </option>
          ))}
        </select>
      </div>
    </nav>
  );
}

export type ComparisonFilterValue = "differences" | "improve" | "unavailable" | "all";

export const COMPARISON_FILTER_OPTIONS: Array<{ value: ComparisonFilterValue; label: string }> = [
  { value: "differences", label: "差がある項目のみ" },
  { value: "improve", label: "要改善のみ" },
  { value: "unavailable", label: "未取得を含む" },
  { value: "all", label: "すべて表示" },
];

export const DEFAULT_COMPARISON_FILTER: ComparisonFilterValue = "differences";

export function ComparisonFilters({
  value,
  onChange,
}: {
  value: ComparisonFilterValue;
  onChange: (value: ComparisonFilterValue) => void;
}) {
  return (
    <div className="flex items-center gap-2">
      <label className="sr-only" htmlFor="comparison-filter-select">
        比較項目の絞り込み
      </label>
      <select
        id="comparison-filter-select"
        value={value}
        onChange={(e) => onChange(e.target.value as ComparisonFilterValue)}
        className="rounded-md border bg-background px-2 py-1.5 text-sm"
      >
        {COMPARISON_FILTER_OPTIONS.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
}

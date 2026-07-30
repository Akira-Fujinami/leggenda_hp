import { PERSPECTIVE_STATUS_LABELS, PERSPECTIVE_STATUS_STYLES } from "@/features/lead/perspective-status";
import { cn } from "@/lib/utils";
import type { LeadPerspectiveStatus } from "@/types/lead";

/**
 * 項目1件の定性判定バッジ。lead-results.tsxとlead-perspective-comparison.tsxの
 * 双方から使うため独立ファイルに置く(相互importを避ける)。
 */
export function PerspectiveStatusBadge({ status }: { status: LeadPerspectiveStatus }) {
  return (
    <span
      className={cn(
        "inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium",
        PERSPECTIVE_STATUS_STYLES[status],
      )}
    >
      {PERSPECTIVE_STATUS_LABELS[status]}
    </span>
  );
}

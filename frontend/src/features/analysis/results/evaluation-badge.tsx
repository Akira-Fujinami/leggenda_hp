import { AlertTriangle, Ban, CheckCircle2, CircleDashed, HelpCircle, Info, MinusCircle, XCircle, type LucideIcon } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { EVALUATION_BADGE_VARIANT, EVALUATION_LABELS, type EvaluationState } from "@/features/analysis/metric-evaluation";

/**
 * 色(Badgeのvariant)だけに頼らず、状態ごとに異なるアイコン+テキストで
 * 示すための対応表(良好=check, 要確認=warning, 要改善=error,
 * 未取得=help, 検出されませんでした=minus, 計測失敗=dashed, 対象外=禁止, 情報=info)。
 */
export const EVALUATION_ICONS: Record<EvaluationState, LucideIcon> = {
  good: CheckCircle2,
  review: AlertTriangle,
  improve: XCircle,
  not_found: MinusCircle,
  unavailable: HelpCircle,
  not_applicable: Ban,
  failed: CircleDashed,
  info: Info,
};

export function EvaluationBadge({ state, className }: { state: EvaluationState; className?: string }) {
  const Icon = EVALUATION_ICONS[state];
  return (
    <Badge variant={EVALUATION_BADGE_VARIANT[state]} className={className}>
      <Icon className="size-3" />
      {EVALUATION_LABELS[state]}
    </Badge>
  );
}

import { Badge } from "@/components/ui/badge";
import type { AnalysisStatus, WebsiteAnalysisStatus } from "@/types/analysis";

const LABELS: Record<AnalysisStatus | WebsiteAnalysisStatus, string> = {
  pending: "待機中",
  queued: "待機中",
  running: "実行中",
  completed: "完了",
  partial: "一部完了",
  failed: "失敗",
  cancelled: "キャンセル済み",
};

export function AnalysisStatusBadge({ status }: { status: AnalysisStatus | WebsiteAnalysisStatus }) {
  if (status === "completed") {
    return <Badge>{LABELS[status]}</Badge>;
  }
  if (status === "failed") {
    return <Badge variant="destructive">{LABELS[status]}</Badge>;
  }
  if (status === "partial") {
    return <Badge variant="secondary">{LABELS[status]}</Badge>;
  }
  return <Badge variant="outline">{LABELS[status]}</Badge>;
}

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { isRedirectableStatus, redirectAnnouncement } from "@/features/analysis/progress-copy";
import type { AnalysisStatus } from "@/types/analysis";

export function AutoRedirectNotice({
  status,
  pending,
  cancelled,
  onRedirectNow,
  onCancel,
}: {
  status: AnalysisStatus;
  pending: boolean;
  cancelled: boolean;
  onRedirectNow: () => void;
  onCancel: () => void;
}) {
  if (!isRedirectableStatus(status) || (!pending && !cancelled)) {
    return null;
  }

  return (
    <Alert>
      <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
        <span>
          {pending ? redirectAnnouncement(status) : "自動遷移を停止しました。結果を確認する準備ができたら以下のボタンから移動してください。"}
        </span>
        <span className="flex gap-2">
          <Button size="sm" onClick={onRedirectNow}>
            今すぐ結果を見る
          </Button>
          {pending && (
            <Button size="sm" variant="outline" onClick={onCancel}>
              自動遷移を停止
            </Button>
          )}
        </span>
      </AlertDescription>
    </Alert>
  );
}

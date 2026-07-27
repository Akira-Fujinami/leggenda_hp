import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ProgressBar } from "@/features/analysis/progress/progress-bar";
import type { LeadProgress as LeadProgressType } from "@/types/lead";

/**
 * リード向けの簡易進捗表示。Job名・エラーコード等の技術的な詳細は
 * 一切出さず、想定所要時間の目安のみを添える(実測: 自社+競合1件で
 * 概ね1分程度)。
 */
export function LeadProgress({ progress }: { progress: LeadProgressType }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">診断状況</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <ProgressBar value={progress.percent} />
        <p className="text-sm text-muted-foreground">{progress.message}</p>
        {progress.status === "processing" && (
          <p className="text-xs text-muted-foreground">通常1〜2分程度で完了します。このままお待ちください。</p>
        )}
      </CardContent>
    </Card>
  );
}

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { RankingEntry } from "@/types/comparison";

export function RankingSummary({ ranking }: { ranking: RankingEntry[] }) {
  const hasPrimary = ranking.some((entry) => entry.is_primary);
  const hasLowData = ranking.some((entry) => entry.low_data_warning);

  return (
    <Card>
      <CardHeader>
        <CardTitle>サイト別ランキング</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {!hasPrimary && (
          <Alert>
            <AlertDescription>
              自社サイトが設定されていないため、順位比較のみ表示しています(自社との差分は表示されません)。
            </AlertDescription>
          </Alert>
        )}
        {hasLowData && (
          <Alert variant="destructive">
            <AlertDescription>測定データが少ないサイトがあります。順位が実態を正確に反映していない可能性があります。</AlertDescription>
          </Alert>
        )}

        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>順位</TableHead>
              <TableHead>サイト</TableHead>
              <TableHead className="text-right">総合スコア</TableHead>
              <TableHead className="text-right">自社との差</TableHead>
              <TableHead className="text-right">カバー率</TableHead>
              <TableHead className="text-right">信頼度</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {ranking.map((entry) => (
              <TableRow key={entry.website_analysis_id}>
                <TableCell className="font-medium">{entry.rank}</TableCell>
                <TableCell>
                  <div className="flex items-center gap-2">
                    <span>{entry.website_name ?? `サイト #${entry.website_id}`}</span>
                    {entry.is_primary && <Badge variant="secondary">自社</Badge>}
                    {entry.low_data_warning && <Badge variant="destructive">データ不足</Badge>}
                  </div>
                </TableCell>
                <TableCell className="text-right">{entry.display_score}</TableCell>
                <TableCell className="text-right">
                  {entry.score_gap_vs_primary === null
                    ? "-"
                    : entry.score_gap_vs_primary === 0
                      ? "±0"
                      : entry.score_gap_vs_primary > 0
                        ? `+${entry.score_gap_vs_primary}`
                        : entry.score_gap_vs_primary}
                </TableCell>
                <TableCell className="text-right">{Math.round(entry.coverage_rate)}%</TableCell>
                <TableCell className="text-right">{Math.round(entry.confidence_rate)}%</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );
}

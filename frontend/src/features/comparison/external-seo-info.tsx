import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { ExternalSeoInfo, RankingEntry } from "@/types/comparison";

const UNAVAILABLE_REASON_LABELS: Record<string, string> = {
  SEO_PROVIDER_INVALID: "SEO_PROVIDERの設定が不正です。",
  SEMRUSH_NOT_CONFIGURED: "Semrush APIキーが設定されていません。",
  MOCK_PROVIDER_NOT_ALLOWED: "デモデータの利用が許可されていません。",
  MOCK_PROVIDER_NOT_ALLOWED_IN_PRODUCTION: "本番環境ではデモデータを利用できません。",
  SEMRUSH_AUTH_FAILED: "Semrush APIの認証に失敗しました。",
  SEMRUSH_RATE_LIMITED: "Semrush APIのレート制限に達しました。",
  SEMRUSH_QUOTA_EXCEEDED: "Semrush APIの利用可能ユニットが不足しています。",
  SEMRUSH_DAILY_LIMIT_REACHED: "本日の外部SEO API利用上限に達しています。",
  SEMRUSH_UNAVAILABLE: "Semrush APIに接続できませんでした。",
  SEMRUSH_INVALID_DOMAIN: "対象ドメインを判定できませんでした。",
};

export function ExternalSeoInfoPanel({ ranking, externalSeo }: { ranking: RankingEntry[]; externalSeo: ExternalSeoInfo[] }) {
  const nameOf = (websiteAnalysisId: number) =>
    ranking.find((r) => r.website_analysis_id === websiteAnalysisId)?.website_name ?? `サイト #${websiteAnalysisId}`;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">外部SEOデータ(Semrush等)</CardTitle>
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>サイト</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>Provider</TableHead>
              <TableHead>データベース</TableHead>
              <TableHead>取得日時</TableHead>
              <TableHead>キャッシュ</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {externalSeo.map((info) => (
              <TableRow key={info.website_analysis_id}>
                <TableCell>{nameOf(info.website_analysis_id)}</TableCell>
                <TableCell>
                  {info.status === "success" ? (
                    <div className="flex items-center gap-1.5">
                      <Badge variant={info.is_mock ? "outline" : "secondary"}>{info.is_mock ? "デモデータ" : "実データ"}</Badge>
                    </div>
                  ) : (
                    <span className="text-muted-foreground">
                      未取得
                      {info.error_code && ` (${UNAVAILABLE_REASON_LABELS[info.error_code] ?? info.error_code})`}
                    </span>
                  )}
                </TableCell>
                <TableCell className="text-muted-foreground">{info.provider ?? "-"}</TableCell>
                <TableCell className="text-muted-foreground">{info.database ?? "-"}</TableCell>
                <TableCell className="text-muted-foreground">
                  {info.fetched_at ? new Date(info.fetched_at).toLocaleString("ja-JP") : "-"}
                </TableCell>
                <TableCell className="text-muted-foreground">{info.cache_hit ? "利用" : "-"}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );
}

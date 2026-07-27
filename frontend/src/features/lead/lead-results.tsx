import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DataQualityNotice } from "@/features/analysis/results/data-quality-notice";
import { ScreenshotLightbox } from "@/features/analysis/results/screenshot-lightbox";
import type { LeadResults as LeadResultsType, LeadWebsiteResult } from "@/types/lead";

const PRIORITY_LABELS: Record<string, string> = { high: "優先度: 高", medium: "優先度: 中", low: "優先度: 低" };

function WebsiteResultCard({ website }: { website: LeadWebsiteResult }) {
  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <CardTitle className="text-base">{website.website_name}</CardTitle>
        {website.is_primary && <Badge variant="secondary">自社サイト</Badge>}
      </CardHeader>
      <CardContent className="space-y-4">
        <DataQualityNotice score={website.score} />

        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {website.score.category_scores.map((c) => (
            <div key={c.key} className="rounded-md border p-2 text-center">
              <p className="text-xs text-muted-foreground">{c.name}</p>
              <p className="text-sm font-semibold">
                {Math.round(c.score)} <span className="text-xs font-normal text-muted-foreground">/ {c.configured_max_score}</span>
              </p>
            </div>
          ))}
        </div>

        {website.top_recommendations.length > 0 && (
          <div className="space-y-2">
            <p className="text-sm font-medium">特に改善効果が見込まれる項目</p>
            <ul className="space-y-2">
              {website.top_recommendations.map((r, i) => (
                <li key={i} className="rounded-md border p-3">
                  <div className="flex items-center justify-between gap-2">
                    <p className="font-medium">{r.title}</p>
                    <Badge variant="outline">{PRIORITY_LABELS[r.priority] ?? r.priority}</Badge>
                  </div>
                  <p className="mt-1 text-sm text-muted-foreground">{r.description}</p>
                </li>
              ))}
            </ul>
          </div>
        )}

        {website.screenshots.length > 0 && (
          <div className="grid grid-cols-2 gap-2">
            {website.screenshots.map((s) => (
              <ScreenshotLightbox
                key={s.device}
                src={s.url}
                alt={`${website.website_name ?? ""}(${s.device === "desktop" ? "PC" : "モバイル"})`}
                trigger={
                  // eslint-disable-next-line @next/next/no-img-element
                  <img
                    src={s.url}
                    alt={`${website.website_name ?? ""}(${s.device === "desktop" ? "PC" : "モバイル"})`}
                    loading="lazy"
                    className="w-full rounded-md border object-contain"
                    style={{ maxHeight: 200 }}
                  />
                }
              />
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * リード向けの簡易結果画面。既存の結果画面(指標77件の詳細一覧・Job名・
 * エラーコード等)はそのまま出さず、社内担当が説明する余地を残すために
 * サマリーのみ表示する。誠実性の維持(未取得を0点扱いにしない・カバー率
 * 警告を消さない)は既存のDataQualityNoticeをそのまま再利用することで
 * 自動的に担保される。
 */
export function LeadResults({ results }: { results: LeadResultsType }) {
  return (
    <div className="space-y-4">
      {results.status === "partial" && (
        <p className="text-sm text-muted-foreground">
          一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。
        </p>
      )}
      {results.websites.map((website, i) => (
        <WebsiteResultCard key={i} website={website} />
      ))}
    </div>
  );
}

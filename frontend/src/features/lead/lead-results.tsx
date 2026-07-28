import { useState } from "react";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DataQualityNotice } from "@/features/analysis/results/data-quality-notice";
import { leadApi } from "@/features/lead/api";
import { useRequestConsultation } from "@/features/lead/hooks";
import { PERSPECTIVE_STATUS_LABELS, PERSPECTIVE_STATUS_STYLES } from "@/features/lead/perspective-status";
import { cn } from "@/lib/utils";
import type { LeadPerspective, LeadPerspectiveStatus, LeadReportStatus, LeadResults as LeadResultsType, LeadWebsiteResult } from "@/types/lead";

const PRIORITY_LABELS: Record<string, string> = {
  critical: "優先度: 緊急",
  high: "優先度: 高",
  medium: "優先度: 中",
  low: "優先度: 低",
};

function PerspectiveStatusBadge({ status }: { status: LeadPerspectiveStatus }) {
  return (
    <span className={cn("inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium", PERSPECTIVE_STATUS_STYLES[status])}>
      {PERSPECTIVE_STATUS_LABELS[status]}
    </span>
  );
}

/**
 * 採用担当が実際に持つ4つの問い(perspective.heading、バックエンドの
 * LeadMetricCatalog::PERSPECTIVE_HEADINGSが唯一の定義元)をそのまま見出しに
 * する ―― Word/PDFレポートも同じ値を参照するため、フロント側で別の文言を
 * 持たない。数値(スコアの分数表示)は主役にせず、「良好」「確認を
 * おすすめします」「改善の余地があります」といった定性判定を主表示にする。
 * 未取得(計測できませんでした・計測対象外・評価不可)は改善の余地ありと
 * 混同されないよう、別の色で表示する(誠実性の維持)。
 */
function PerspectiveCard({ perspective }: { perspective: LeadPerspective }) {
  return (
    <div className="space-y-2 rounded-md border p-3">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm font-medium">{perspective.heading}</p>
        <PerspectiveStatusBadge status={perspective.status} />
      </div>
      {perspective.note && <p className="text-xs text-muted-foreground">{perspective.note}</p>}
      {perspective.summary && <p className="text-sm">{perspective.summary}</p>}
      {perspective.items.length > 0 && (
        <ul className="space-y-1.5">
          {perspective.items.map((item, i) => (
            <li key={i} className="flex items-center justify-between gap-2 text-sm">
              <span>{item.label}</span>
              <PerspectiveStatusBadge status={item.status} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function WebsiteResultCard({ website }: { website: LeadWebsiteResult }) {
  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <CardTitle className="text-base">{website.website_name}</CardTitle>
        {website.is_primary && <Badge variant="secondary">自社サイト</Badge>}
      </CardHeader>
      <CardContent className="space-y-4">
        {/* このスコアは社内版(7カテゴリ100点)とは別建て ―― 4観点に表示している
            指標だけを対象に算出しているため、満点も内訳も社内版とは異なる。
            商談時に取り違えないよう、見出しでそれと分かる表現にする
            (2026-07-28のユーザー指摘への対応)。 */}
        <DataQualityNotice
          score={website.score}
          label="採用サイトとして重要な4観点での評価"
          referenceLabel="採用サイトとして重要な4観点での参考評価"
        />

        <div className="space-y-2">
          {website.perspectives.map((perspective) => (
            <PerspectiveCard key={perspective.key} perspective={perspective} />
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
      </CardContent>
    </Card>
  );
}

const REPORT_LABELS: Record<"docx" | "pdf", string> = { docx: "Wordでダウンロード", pdf: "PDFでダウンロード" };

function ReportDownloadButton({
  token,
  analysisId,
  format,
  status,
}: {
  token: string;
  analysisId: number;
  format: "docx" | "pdf";
  status: LeadReportStatus;
}) {
  if (status === "unavailable") {
    return null;
  }

  if (status === "processing") {
    return (
      <Button variant="outline" disabled>
        {REPORT_LABELS[format]}(準備中…)
      </Button>
    );
  }

  // Base UIのButtonはリンク(<a>)をrenderで差し替える用途を想定していない
  // (公式ドキュメントで非推奨)ため、ボタンの見た目だけをbuttonVariantsで
  // <a>へ直接適用する。
  return (
    <a href={leadApi.reportDownloadUrl(token, analysisId, format)} className={buttonVariants({ variant: "outline" })}>
      {REPORT_LABELS[format]}
    </a>
  );
}

/**
 * 「もっと他社と比較したい/相談したい」ボタン。押下確認(誤タップ防止)の
 * ためAlertDialogを挟み、実際にPOST /lead/analyses/{id}/consultationを
 * 呼ぶ。二重送信防止はバックエンド側(consultation_requested_atの条件付き
 * UPDATE)に一任し、フロント側は結果(already_requested)をそのまま表示する
 * だけにする ―― フロント側だけの判定で「送信済み」の表示を作らない。
 */
function ConsultationCta({ token, analysisId }: { token: string; analysisId: number }) {
  const [open, setOpen] = useState(false);
  const mutation = useRequestConsultation(token, analysisId);

  if (mutation.isSuccess) {
    return (
      <div className="rounded-md border p-4 text-center">
        <p className="font-medium">
          {mutation.data.data.already_requested ? "既にご相談リクエストを受け付けています" : "ご相談リクエストを受け付けました"}
        </p>
        <p className="mt-1 text-sm text-muted-foreground">担当者より追ってご連絡いたします。</p>
      </div>
    );
  }

  return (
    <div className="space-y-3 rounded-md border p-4 text-center">
      <div>
        <p className="font-medium">もっと他社と比較したい場合はご相談ください</p>
        <p className="mt-1 text-sm text-muted-foreground">
          比較したいサイトを3〜5社お知らせいただければ、後日担当より結果をご説明します。
        </p>
      </div>

      <AlertDialog open={open} onOpenChange={setOpen}>
        <AlertDialogTrigger render={<Button variant="outline" />}>相談をリクエストする</AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>相談をリクエストしますか？</AlertDialogTitle>
            <AlertDialogDescription>
              お申し込み時にご入力いただいた会社名・ご担当者名・メールアドレスとあわせて、担当者へ通知します。
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>キャンセル</AlertDialogCancel>
            <AlertDialogAction
              disabled={mutation.isPending}
              onClick={() => mutation.mutate(undefined, { onSuccess: () => setOpen(false) })}
            >
              リクエストする
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {mutation.isError && (
        <p className="text-sm text-destructive">送信に失敗しました。しばらくしてから再度お試しください。</p>
      )}
    </div>
  );
}

/**
 * リード向けの簡易結果画面。既存の結果画面(指標77件の詳細一覧・Job名・
 * エラーコード等)はそのまま出さず、社内担当が説明する余地を残すために
 * サマリーのみ表示する。誠実性の維持(未取得を0点扱いにしない・カバー率
 * 警告を消さない)は既存のDataQualityNoticeをそのまま再利用することで
 * 自動的に担保される。
 */
export function LeadResults({
  results,
  token,
  analysisId,
}: {
  results: LeadResultsType;
  token: string;
  analysisId: number;
}) {
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
      <div className="flex flex-wrap gap-2">
        <ReportDownloadButton token={token} analysisId={analysisId} format="docx" status={results.reports.docx} />
        <ReportDownloadButton token={token} analysisId={analysisId} format="pdf" status={results.reports.pdf} />
      </div>
      <ConsultationCta token={token} analysisId={analysisId} />
    </div>
  );
}

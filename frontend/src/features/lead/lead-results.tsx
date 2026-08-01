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
import { DataQualityNotice } from "@/features/analysis/results/data-quality-notice";
import { leadApi } from "@/features/lead/api";
import { useRequestConsultation } from "@/features/lead/hooks";
import { LeadPerspectiveComparison } from "@/features/lead/lead-perspective-comparison";
import type { LeadReportStatus, LeadResults as LeadResultsType, LeadWebsiteResult } from "@/types/lead";

const PRIORITY_LABELS: Record<string, string> = {
  critical: "優先度: 緊急",
  high: "優先度: 高",
  medium: "優先度: 中",
  low: "優先度: 低",
};

/**
 * サイト1件分のデータ品質(点数・カバー率・信頼度・未取得件数・参考値警告)。
 *
 * 2026-07-30の構成変更前は、サイトごとのカードの中に1つずつ置かれていたため、
 * 2社分が縦に遠く離れて並んでいた。比較チャートの直下に横並びで置くことで、
 * どちらの数値がどれだけの情報量に基づくのかを同時に確認できるようにする
 * (誠実性の維持に必要な情報なので、折りたたみの中には入れない)。
 */
const SELF_SITE_BADGE = "自社サイト";

function WebsiteQualityCard({ website }: { website: LeadWebsiteResult }) {
  // website_nameが未設定のときバックエンドは「自社サイト」「比較サイト」を
  // 入れてくる。その場合にバッジを併記すると「自社サイト 自社サイト」と重なるため、
  // 名前がバッジと同じ文言のときはバッジを出さない。
  const showBadge = website.is_primary && website.website_name !== SELF_SITE_BADGE;

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <p className="text-sm font-medium">{website.website_name}</p>
        {showBadge && <Badge variant="secondary">{SELF_SITE_BADGE}</Badge>}
      </div>
      {/* このスコアは社内版(7カテゴリ100点)とは別建て ―― 4観点に表示している
          指標だけを対象に算出しているため、満点も内訳も社内版とは異なる。
          商談時に取り違えないよう、見出しでそれと分かる表現にする
          (2026-07-28のユーザー指摘への対応)。 */}
      <DataQualityNotice
        score={website.score}
        label="採用サイトとして重要な4観点での評価"
        referenceLabel="採用サイトとして重要な4観点での参考評価"
      />
    </div>
  );
}

/**
 * 改善の提案は自社サイト分だけを出す ―― 競合サイトに対する「改善効果が
 * 見込まれる項目」は、リードにとっては他社への助言であり、自社の検討材料に
 * ならないため画面に出さない(2026-07-30の構成変更)。
 */
function TopRecommendations({ website }: { website: LeadWebsiteResult }) {
  if (website.top_recommendations.length === 0) {
    return null;
  }

  return (
    <div className="space-y-2 rounded-md border p-4">
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
 * サマリーのみ表示する。
 *
 * 2026-07-30の構成変更(「縦に長すぎて読みづらい」「グラフで2社の比較を
 * しやすくしたい」への対応): 従来のサイト別カード2枚(各カード内で4観点を
 * 常に全展開)をやめ、
 *   1. 4観点の比較ブロック(観点ごとに自社/競合のバー、項目内訳は折りたたみ)
 *   2. サイトごとのデータ品質(カバー率・信頼度・参考値警告)を横並び
 *   3. 自社サイトの改善提案
 * の順に組み替えた。誠実性の維持(未取得を0点扱いにしない・カバー率警告を
 * 消さない)は、既存のDataQualityNoticeをそのまま再利用し、かつ比較ブロック
 * 側でも未取得の観点をバー0ではなく状態文言で出すことで担保する。
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
  const selfWebsite = results.websites.find((website) => website.is_primary) ?? results.websites[0];

  return (
    <div className="space-y-4">
      {results.status === "partial" && (
        <p className="text-sm text-muted-foreground">
          一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。
        </p>
      )}

      {results.websites.length > 0 && <LeadPerspectiveComparison websites={results.websites} />}

      <div className="grid gap-4 md:grid-cols-2">
        {results.websites.map((website, i) => (
          <WebsiteQualityCard key={i} website={website} />
        ))}
      </div>

      {selfWebsite && <TopRecommendations website={selfWebsite} />}

      <div className="flex flex-wrap gap-2">
        <ReportDownloadButton token={token} analysisId={analysisId} format="docx" status={results.reports.docx} />
        <ReportDownloadButton token={token} analysisId={analysisId} format="pdf" status={results.reports.pdf} />
      </div>
      <ConsultationCta token={token} analysisId={analysisId} />
    </div>
  );
}

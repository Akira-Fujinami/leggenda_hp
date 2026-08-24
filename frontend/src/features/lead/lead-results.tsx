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
import { Button, buttonVariants } from "@/components/ui/button";
import { leadApi } from "@/features/lead/api";
import { useRequestConsultation } from "@/features/lead/hooks";
import type { LeadReportStatus, LeadResults as LeadResultsType } from "@/types/lead";

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
 * リード向けの結果画面。
 *
 * 2026-08-03の構成変更: 診断内容そのもの(6軸のブランド・ホイール・4観点の
 * 比較・データ品質・改善提案)を画面から全部外し、「PDFのダウンロードリンク」と
 * 「相談導線」だけを置く形にした(ユーザー判断)。
 *
 * 理由: 6軸が読み取れなかった場合・4観点の①が採点対象を持たない場合など、
 * 画面上は「枠だけがあって中身が無い」状態が普通に発生していた。中身の無い枠を
 * 並べるくらいなら、読める形にまとまっているPDFを渡し、続きは担当者が説明する
 * ほうが確実である、という判断。
 *
 * したがってこの画面は、PDFが用意できているかどうかだけを正直に伝える。
 * PDFが出せなかった場合に黙って何も出さない(空白の画面になる)ことは
 * 許されないため、その場合も理由と次の導線を必ず出す。
 *
 * 画面から外したコンポーネント(LeadBrandWheel / LeadPerspectiveComparison /
 * DataQualityNotice)はリポジトリに残してある ―― 表示している内容と同じものを
 * PDF側に載せる作業が残っており、また差し戻しの可能性もあるため。
 */
/**
 * 自社サイトの分析が「白紙」(ブランド・ホイールがerror/insufficient_input/
 * matched=0)だった場合の表示。診断回数は消費していないため、相談導線
 * (ConsultationCta、比較したい他社3〜5社を前提とした文言)は出さず、
 * 「別のURLで試す」ボタン(onRetry、STEP2のURL入力フォームへ戻す)と
 * 一般的なお問い合わせ導線だけを出す(2026-08-24追加、依頼者確定文言)。
 */
function SkippedNotice({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="space-y-3 rounded-md border p-4 text-center">
      <div>
        <p className="font-medium">今回はご用意できる診断結果がありませんでした。</p>
        <p className="mt-1 text-sm text-muted-foreground">
          恐れ入りますが、別のURLで再度お試しいただけますでしょうか。採用サイトのトップページ、またはコーポレートサイトの採用情報ページをおすすめします。
        </p>
        <p className="mt-1 text-sm text-muted-foreground">今回の診断は、ご利用回数に含まれておりません。</p>
      </div>

      <Button onClick={onRetry}>別のURLで試す</Button>

      <p className="text-sm text-muted-foreground">
        引き続きサポートいたしますので、ご相談は
        <a
          href="https://leggenda-co.web-tools.biz/inquiry"
          className="underline underline-offset-2"
          target="_blank"
          rel="noreferrer"
        >
          お問い合わせフォーム
        </a>
        からお気軽にどうぞ。
      </p>
    </div>
  );
}

export function LeadResults({
  results,
  token,
  analysisId,
  onRetry,
}: {
  results: LeadResultsType;
  token: string;
  analysisId: number;
  onRetry: () => void;
}) {
  const pdfStatus = results.reports.pdf;

  if (pdfStatus === "skipped") {
    return <SkippedNotice onRetry={onRetry} />;
  }

  return (
    <div className="space-y-4">
      {results.status === "partial" && (
        <p className="text-sm text-muted-foreground">
          一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。
        </p>
      )}

      <div className="space-y-3 rounded-md border p-4 text-center">
        <div>
          <p className="font-medium">診断結果をPDFにまとめました</p>
          <p className="mt-1 text-sm text-muted-foreground">
            サイトから確認できた内容と、改善のご提案をまとめています。
          </p>
        </div>

        {/* unavailableのときReportDownloadButtonはnullを返す。ボタンを消すだけだと
            画面から何も無くなってしまうので、出せなかったことをここで明示する。 */}
        {pdfStatus === "unavailable" ? (
          <p className="text-sm">
            PDFのご用意ができませんでした。お手数ですが、下のボタンからご相談ください。担当者より結果をご説明します。
          </p>
        ) : (
          <ReportDownloadButton token={token} analysisId={analysisId} format="pdf" status={pdfStatus} />
        )}
      </div>

      <ConsultationCta token={token} analysisId={analysisId} />
    </div>
  );
}

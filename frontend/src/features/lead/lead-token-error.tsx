import { Alert, AlertDescription } from "@/components/ui/alert";
import { buttonVariants } from "@/components/ui/button";

/**
 * トークン照合に失敗した場合(未指定・期限切れ・使用済み・該当なしの
 * いずれか)の統一エラー表示。理由をユーザーに区別して伝えない
 * ―― トークンの存在有無を推測できてしまうこと自体がセキュリティ上
 * 望ましくないため(サーバー側のログでは区別して記録する。
 * ResolveLeadToken参照)。
 *
 * 必ず/lead/startへの復帰導線を添える。メールクライアントによるURLの
 * 折り返しで末尾の数文字が欠けるといった、正当な利用者側では防げない
 * 原因でトークンが壊れることが実運用で頻発するため、フォーム入力後の
 * 離脱という最も損失の大きい形で行き止まりにしないための対応
 * (2026-07-29の実運用インシデントへの対応)。
 */
export function LeadTokenError() {
  return (
    <Alert variant="destructive">
      <AlertDescription>
        <p className="mb-3">この診断URLは利用できません。お手数ですが、もう一度お申し込みください。</p>
        <a href="/lead/start" className={buttonVariants({ variant: "outline" })}>
          最初からやり直す
        </a>
      </AlertDescription>
    </Alert>
  );
}

/**
 * ApiErrorがトークン照合失敗(未指定/期限切れ/使用済み/該当なし)由来かどうかを
 * 判定する。バックエンドのエラーメッセージ文言を画面にそのまま出さず、
 * 常にLeadTokenErrorの統一文言へ寄せるために使う。
 */
export function isLeadTokenError(error: unknown): boolean {
  if (typeof error !== "object" || error === null || !("errorCode" in error)) return false;

  const code = (error as { errorCode: unknown }).errorCode;

  return code === "LEAD_TOKEN_MISSING" || code === "LEAD_TOKEN_INVALID";
}

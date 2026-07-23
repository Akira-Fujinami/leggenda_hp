import { ApiError, ApiNetworkError } from "@/lib/api-client";

export interface ApiErrorDescription {
  /** ユーザーに見せてよいメッセージ(秘密情報・SQL・スタックトレースは含まない)。 */
  message: string;
  /** 401の場合のみtrue。呼び出し側でログイン導線を出す判断に使う。 */
  isUnauthenticated: boolean;
  /** サポート問い合わせ等で使える、backendログと突き合わせ可能なID(あれば)。 */
  requestId: string | null;
}

/**
 * useProjects等のAPIエラーを、ユーザー向け表示用に分類する。
 * 401/419/403/422/500/ネットワークエラーのいずれであっても、SQLやスタックトレース等の
 * 内部情報は一切含めない(開発者向け詳細はapi-client.ts側で既にconsole.errorへ出力済み)。
 */
export function describeApiError(error: unknown): ApiErrorDescription {
  if (error instanceof ApiError) {
    if (error.status === 401) {
      return {
        message: "セッションの有効期限が切れました。再度ログインしてください。",
        isUnauthenticated: true,
        requestId: error.requestId,
      };
    }

    if (error.status === 419) {
      // api-client.ts側でCSRF Cookie再取得→1回リトライを既に試みた後の失敗。
      return {
        message: "通信の検証に失敗しました。ページを再読み込みしてお試しください。",
        isUnauthenticated: false,
        requestId: error.requestId,
      };
    }

    if (error.status === 403) {
      return {
        message: "この操作を行う権限がありません。",
        isUnauthenticated: false,
        requestId: error.requestId,
      };
    }

    if (error.status === 422) {
      return {
        message: error.message || "入力内容をご確認ください。",
        isUnauthenticated: false,
        requestId: error.requestId,
      };
    }

    return {
      message: "サーバーでエラーが発生しました。時間をおいて再度お試しください。",
      isUnauthenticated: false,
      requestId: error.requestId,
    };
  }

  if (error instanceof ApiNetworkError) {
    return {
      message: error.message,
      isUnauthenticated: false,
      requestId: null,
    };
  }

  return {
    message: "予期しないエラーが発生しました。時間をおいて再度お試しください。",
    isUnauthenticated: false,
    requestId: null,
  };
}

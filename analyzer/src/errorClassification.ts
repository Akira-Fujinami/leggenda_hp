/**
 * Playwright/Lighthouse等が投げる例外を、ユーザー向けに分類したエラーコード・
 * 日本語メッセージへ変換する。生のエラーメッセージ・スタックトレースは
 * ログにのみ残し(呼び出し元でlogger経由)、ユーザー向けレスポンスには含めない。
 */
export interface ClassifiedError {
  code: string;
  message: string;
}

const PATTERNS: Array<{ code: string; message: string; test: (raw: string) => boolean }> = [
  {
    code: "NAVIGATION_TIMEOUT",
    message: "ページの読み込みがタイムアウトしました。",
    test: (raw) => /timeout.*exceeded/i.test(raw) || raw.includes("Timeout"),
  },
  {
    code: "ACCESS_DENIED",
    message: "サイトからアクセスを拒否されました(403/429等)。",
    test: (raw) => /\b(403|429)\b/.test(raw) || /forbidden|too many requests/i.test(raw),
  },
  {
    code: "TOO_MANY_REDIRECTS",
    message: "リダイレクトが多すぎるため処理を中断しました。",
    test: (raw) => /too many redirects|ERR_TOO_MANY_REDIRECTS/i.test(raw),
  },
  {
    code: "SSL_ERROR",
    message: "SSL/TLS証明書の検証に失敗しました。",
    test: (raw) => /ERR_CERT|SSL|certificate/i.test(raw),
  },
  {
    code: "DNS_ERROR",
    message: "ドメイン名を解決できませんでした。",
    test: (raw) => /ERR_NAME_NOT_RESOLVED|ENOTFOUND|getaddrinfo/i.test(raw),
  },
  {
    code: "CONNECTION_REFUSED",
    message: "サイトへの接続が拒否されました。",
    test: (raw) => /ERR_CONNECTION_REFUSED|ECONNREFUSED|ERR_CONNECTION_RESET|ECONNRESET/i.test(raw),
  },
  {
    code: "PAGE_CRASHED",
    message: "ページの読み込み中にブラウザが異常終了しました。",
    test: (raw) => /crash/i.test(raw) || /Target (page|crashed)/i.test(raw),
  },
  {
    code: "BROWSER_DISCONNECTED",
    message: "ブラウザとの接続が切断されました。",
    test: (raw) => /Target page, context or browser has been closed|browser has disconnected/i.test(raw),
  },
  {
    code: "BROWSER_LAUNCH_FAILED",
    message: "ブラウザの起動に失敗しました。",
    test: (raw) => /Failed to launch|browserType\.launch/i.test(raw),
  },
];

export function classifyError(error: unknown): ClassifiedError {
  const raw = error instanceof Error ? `${error.name}: ${error.message}` : String(error);

  for (const pattern of PATTERNS) {
    if (pattern.test(raw)) {
      return { code: pattern.code, message: pattern.message };
    }
  }

  return { code: "UNKNOWN_ANALYZER_ERROR", message: "分析処理中に不明なエラーが発生しました。" };
}

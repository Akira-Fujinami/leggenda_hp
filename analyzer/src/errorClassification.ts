/**
 * Playwright/Lighthouse等が投げる例外を、ユーザー向けに分類したエラーコード・
 * 日本語メッセージへ変換する。生のエラーメッセージ・スタックトレースは
 * ログにのみ残し(呼び出し元でlogger経由)、ユーザー向けレスポンスには含めない。
 *
 * どの操作(render/screenshot/lighthouse/technology)から発生したかで、同じ
 * "timeout"という事象でも意味が異なる(例: Lighthouse自身の計測timeoutと
 * ページ遷移timeoutは原因も対処も別)。operationを渡すことでこれを区別する。
 */
export interface ClassifiedError {
  code: string;
  message: string;
  /** true: 同条件で再試行すれば成功し得る。false: 再試行しても変わらない。 */
  retryable: boolean;
}

export type AnalyzerOperation = "render" | "screenshot" | "lighthouse" | "technology";

/**
 * 同じoperation内でも、どの処理フェーズで例外が発生したかによって
 * 分類すべきコードが異なる(例: screenshot操作でも、ページ遷移自体は
 * 完了した後にpage.screenshot()自体がタイムアウトすることがあり、これは
 * NAVIGATION_TIMEOUTではなくSCREENSHOT_TIMEOUTとして区別すべき)。
 * "navigation"は既定挙動(従来通りoperation別のOPERATION_TIMEOUT)に委ねるため
 * 明示的なタグ付けは不要で、"capture"のようなナビゲーション後の後続処理を
 * 明示的にタグ付けする場合にのみPhaseErrorでラップする。
 */
export type OperationPhase = "capture";

export class PhaseError extends Error {
  constructor(
    public readonly phase: OperationPhase,
    public readonly cause: unknown,
  ) {
    super(cause instanceof Error ? cause.message : String(cause));
    this.name = cause instanceof Error ? cause.name : "PhaseError";
  }
}

/**
 * quality低下→captured height半減→viewportのみ撮影、という束縛された
 * フォールバックの全段階を試しても撮影が成功しなかった(≒安全なメモリ予算内で
 * 撮影できなかった)ことを示す専用のエラー。SCREENSHOT_TIMEOUTやTARGET_CRASHED
 * 等の生の原因分類より優先して判定する(呼び出し元は既に十分縮小・再試行済み
 * であることが分かっているため)。
 */
export class ScreenshotResourceExhaustedError extends Error {
  constructor(public readonly cause: unknown) {
    super(cause instanceof Error ? cause.message : String(cause));
    this.name = "ScreenshotResourceExhaustedError";
  }
}

interface Pattern {
  code: string;
  message: string;
  retryable: boolean;
  test: (raw: string) => boolean;
}

// 操作の種類によらず、原因そのものから一意に分類できるもの。
// timeoutより先に評価する(timeoutの文言を含むメッセージでも、より具体的な
// 原因が判明していればそちらを優先するため)。
const UNIVERSAL_PATTERNS: Pattern[] = [
  {
    code: "ACCESS_DENIED",
    message: "サイトからアクセスを拒否されました(403/429等)。",
    retryable: false,
    test: (raw) => /\b(403|429)\b/.test(raw) || /forbidden|too many requests/i.test(raw),
  },
  {
    code: "TOO_MANY_REDIRECTS",
    message: "リダイレクトが多すぎるため処理を中断しました。",
    retryable: false,
    test: (raw) => /too many redirects|ERR_TOO_MANY_REDIRECTS/i.test(raw),
  },
  {
    code: "TLS_ERROR",
    message: "SSL/TLS証明書の検証に失敗しました。",
    retryable: false,
    test: (raw) => /ERR_CERT|SSL|certificate/i.test(raw),
  },
  {
    code: "DNS_ERROR",
    message: "ドメイン名を解決できませんでした。",
    retryable: false,
    test: (raw) => /ERR_NAME_NOT_RESOLVED|ENOTFOUND|getaddrinfo/i.test(raw),
  },
  {
    code: "CONNECTION_REFUSED",
    message: "サイトへの接続が拒否されました。",
    retryable: true,
    test: (raw) => /ERR_CONNECTION_REFUSED|ECONNREFUSED|ERR_CONNECTION_RESET|ECONNRESET/i.test(raw),
  },
  {
    code: "BROWSER_DISCONNECTED",
    message: "ブラウザとの接続が切断されました。",
    retryable: true,
    test: (raw) => /browser has disconnected/i.test(raw),
  },
  {
    // Playwrightの"Target page, context or browser has been closed"は
    // page/context/browserのどれが実際に閉じられたかを個別に報告しないため、
    // withPage()がリクエスト単位で管理するcontextの終了を代表として扱う。
    // TARGET_CRASHEDより先に判定すること ―― "Target page, ..."は
    // "Target (page|crashed)"のより緩いパターンとも重複し得るため。
    code: "CONTEXT_CLOSED",
    message: "処理中にブラウザのコンテキストが終了しました。",
    retryable: true,
    test: (raw) => /context or browser has been closed|Execution context was destroyed/i.test(raw),
  },
  {
    code: "PAGE_CLOSED",
    message: "処理中にページが閉じられました。",
    retryable: true,
    test: (raw) => /Target closed/i.test(raw),
  },
  {
    code: "TARGET_CRASHED",
    message: "ページの読み込み中にブラウザが異常終了しました。",
    retryable: true,
    test: (raw) => /crash/i.test(raw),
  },
  {
    code: "BROWSER_LAUNCH_FAILED",
    message: "ブラウザの起動に失敗しました。",
    retryable: true,
    test: (raw) => /Failed to launch|browserType\.launch/i.test(raw),
  },
  {
    // OSによるプロセス強制終了(OOM kill)自体はcatchできない例外だが、
    // 稀にNode/Playwright側がsyscall失敗として検知できるケースのための
    // 安全網。Backend側の推定分類(502/接続リセット等の組み合わせ)が
    // 本命であり、これは補助的な分類にすぎない。
    code: "OUT_OF_MEMORY",
    message: "メモリ不足のため処理を継続できませんでした。",
    retryable: true,
    test: (raw) => /ENOMEM|out of memory/i.test(raw),
  },
];

const OPERATION_TIMEOUT: Record<AnalyzerOperation, ClassifiedError> = {
  render: { code: "NAVIGATION_TIMEOUT", message: "ページの読み込みがタイムアウトしました。", retryable: true },
  screenshot: { code: "NAVIGATION_TIMEOUT", message: "ページの読み込みがタイムアウトしました。", retryable: true },
  technology: { code: "NAVIGATION_TIMEOUT", message: "ページの読み込みがタイムアウトしました。", retryable: true },
  lighthouse: { code: "LIGHTHOUSE_TIMEOUT", message: "Lighthouse計測がタイムアウトしました。", retryable: true },
};

// ナビゲーション完了後の後続処理(例: page.screenshot()自体)でタイムアウトした
// 場合の専用コード。PhaseErrorでタグ付けされた例外にのみ適用され、
// operationの既定(NAVIGATION_TIMEOUT等)より優先される。
const PHASE_TIMEOUT: Record<OperationPhase, ClassifiedError> = {
  capture: { code: "SCREENSHOT_TIMEOUT", message: "スクリーンショットの生成がタイムアウトしました。", retryable: true },
};

const OPERATION_FALLBACK: Partial<Record<AnalyzerOperation, ClassifiedError>> = {
  screenshot: { code: "SCREENSHOT_FAILED", message: "スクリーンショットの取得に失敗しました。", retryable: true },
  technology: { code: "TECHNOLOGY_FAILED", message: "技術検出に失敗しました。", retryable: true },
};

const TIMEOUT_PATTERN = /timeout.*exceeded/i;

export function classifyError(error: unknown, operation?: AnalyzerOperation): ClassifiedError {
  if (error instanceof ScreenshotResourceExhaustedError) {
    return {
      code: "SCREENSHOT_RESOURCE_LIMIT",
      message: "画像サイズを縮小して複数回試みましたが、安全なメモリ範囲で撮影できませんでした。",
      retryable: true,
    };
  }

  const phase = error instanceof PhaseError ? error.phase : undefined;
  // PhaseErrorは元の例外(cause)をラップしているだけなので、DNS/TLS/クラッシュ等の
  // universalな原因判定は常にcauseの生メッセージに対して行う(タイムアウトで
  // なければphaseを問わず通常通り分類する)。
  const unwrapped = error instanceof PhaseError ? error.cause : error;
  const raw = unwrapped instanceof Error ? `${unwrapped.name}: ${unwrapped.message}` : String(unwrapped);

  for (const pattern of UNIVERSAL_PATTERNS) {
    if (pattern.test(raw)) {
      return { code: pattern.code, message: pattern.message, retryable: pattern.retryable };
    }
  }

  if (TIMEOUT_PATTERN.test(raw) || raw.includes("Timeout")) {
    if (phase) {
      return PHASE_TIMEOUT[phase];
    }
    return OPERATION_TIMEOUT[operation ?? "render"];
  }

  const fallback = operation ? OPERATION_FALLBACK[operation] : undefined;
  if (fallback) {
    return fallback;
  }

  return { code: "UNKNOWN_ANALYZER_ERROR", message: "分析処理中に不明なエラーが発生しました。", retryable: false };
}

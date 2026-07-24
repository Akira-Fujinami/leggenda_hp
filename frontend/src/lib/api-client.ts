// frontendと同一Origin上のNext.js Route Handler(/backend/[...path])を経由して
// Laravel backendへプロキシする(frontend/src/app/backend/[...path]/route.ts)。
// これにより、ブラウザは常にfrontendと同じOriginにしかアクセスしないため、
// 別ドメインのXSRF-TOKEN Cookieがdocument.cookieから読めない問題や、
// SameSite=None運用が不要になる。
// 末尾スラッシュを正規化する("/backend/"のようにスラッシュ付きで設定されても、
// パス側は常に"/sanctum/..."/"/api/..."から始まるため二重スラッシュにならないようにする)。
const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? "/backend").replace(/\/+$/, "");

// このクライアントはブラウザ(document.cookie)を前提にしたCookieベースの
// Sanctum SPA認証専用。Server Components/Route Handlersなどサーバー側から
// 認証済みAPIを直接呼ぶ場合、document は存在せず(getCookieはnullを返す)、
// 代わりにNextの `cookies()` でリクエストのCookieヘッダーを読み取り、
// 手動でfetchへ転送する実装が別途必要になる(現状そのような呼び出しは存在しない)。

export class ApiError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]>;
  readonly errorCode: string | null;
  /** Backendが AssignRequestId ミドルウェアで付与する X-Request-Id。障害調査でBackendログと突き合わせるためのもの。 */
  readonly requestId: string | null;
  readonly endpoint: string;

  constructor(
    status: number,
    message: string,
    errors: Record<string, string[]>,
    errorCode: string | null,
    requestId: string | null,
    endpoint: string,
  ) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
    this.errorCode = errorCode;
    this.requestId = requestId;
    this.endpoint = endpoint;
  }
}

/**
 * fetch()自体が失敗した場合(真のネットワーク断、またはCORSでブロックされた場合)。
 * ブラウザの仕様上、fetch()はセキュリティ上の理由でネットワークエラーとCORSエラーを
 * 区別する情報をJavaScriptへ渡さない(どちらも同じTypeErrorになる)ため、
 * このクラスでは両者をまとめて扱う。
 */
export class ApiNetworkError extends Error {
  readonly endpoint: string;

  constructor(endpoint: string) {
    super("ネットワークエラーが発生しました。接続状況をご確認のうえ、時間をおいて再度お試しください。");
    this.name = "ApiNetworkError";
    this.endpoint = endpoint;
  }
}

function getCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

async function ensureCsrfCookie(): Promise<void> {
  await fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: "include" });
}

export interface ApiEnvelope<T> {
  data: T;
  meta: Record<string, unknown>;
  message: string | null;
}

interface ApiFetchOptions extends Omit<RequestInit, "body"> {
  body?: unknown;
}

async function apiFetch<T>(path: string, options: ApiFetchOptions = {}, isRetry = false): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();
  const isMutation = !["GET", "HEAD"].includes(method);

  if (isMutation && !getCookie("XSRF-TOKEN")) {
    await ensureCsrfCookie();
  }

  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(options.body !== undefined ? { "Content-Type": "application/json" } : {}),
    ...(isMutation ? { "X-XSRF-TOKEN": getCookie("XSRF-TOKEN") ?? "" } : {}),
  };

  let response: Response;

  try {
    response = await fetch(`${API_URL}${path}`, {
      ...options,
      method,
      headers,
      credentials: "include",
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    });
  } catch (cause) {
    // ネットワーク断・CORSブロックはここに来る(ブラウザはJSへ区別を渡さない)。
    // ユーザーには秘密情報を出さず、開発者向けにはconsoleへ詳細を残す。
    console.error(`[api] network error: ${method} ${path}`, cause);
    throw new ApiNetworkError(path);
  }

  const requestId = response.headers.get("X-Request-Id");

  if (response.status === 204) {
    return undefined as T;
  }

  // 419 = CSRFトークン不一致。セッションは生きているがXSRF-TOKEN Cookieが
  // 古い/未取得のまま送られた場合に起こりうるため、CSRF Cookieを取り直して
  // 一度だけ再試行する。isRetryで再試行を1回に制限し、無限ループを防ぐ。
  if (response.status === 419 && isMutation && !isRetry) {
    await ensureCsrfCookie();
    return apiFetch<T>(path, options, true);
  }

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    // 開発者向け: statusコード・endpoint・request ID・error code・response messageを
    // consoleへ出す(ユーザー向けUIには表示しない)。監視ログ収集サービスがあれば
    // ここから送信する拡張ポイントにもなる。
    console.error(`[api] ${response.status} ${method} ${path}`, {
      requestId,
      errorCode: body?.error_code ?? null,
      message: body?.message ?? null,
    });

    throw new ApiError(
      response.status,
      body?.message ?? "エラーが発生しました。",
      body?.errors ?? {},
      body?.error_code ?? null,
      requestId,
      path,
    );
  }

  return body as T;
}

export const api = {
  get: <T>(path: string) => apiFetch<T>(path, { method: "GET" }),
  post: <T>(path: string, body?: unknown) => apiFetch<T>(path, { method: "POST", body }),
  patch: <T>(path: string, body?: unknown) => apiFetch<T>(path, { method: "PATCH", body }),
  delete: <T>(path: string) => apiFetch<T>(path, { method: "DELETE" }),
};

// 末尾スラッシュを正規化する(NEXT_PUBLIC_API_URLに"https://api.example.com/"のように
// スラッシュ付きで設定されても、パス側は常に"/api/..."から始まるため二重スラッシュにならないようにする)。
const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/+$/, "");

// このクライアントはブラウザ(document.cookie)を前提にしたCookieベースの
// Sanctum SPA認証専用。Server Components/Route Handlersなどサーバー側から
// 認証済みAPIを直接呼ぶ場合、document は存在せず(getCookieはnullを返す)、
// 代わりにNextの `cookies()` でリクエストのCookieヘッダーを読み取り、
// 手動でfetchへ転送する実装が別途必要になる(現状そのような呼び出しは存在しない)。

export class ApiError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]>;
  readonly errorCode: string | null;

  constructor(status: number, message: string, errors: Record<string, string[]>, errorCode: string | null) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
    this.errorCode = errorCode;
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

  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    method,
    headers,
    credentials: "include",
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  });

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
    throw new ApiError(
      response.status,
      body?.message ?? "エラーが発生しました。",
      body?.errors ?? {},
      body?.error_code ?? null,
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

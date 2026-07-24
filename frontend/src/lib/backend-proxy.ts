import { NextRequest, NextResponse } from "next/server";

const TIMEOUT_MS = 30_000;

// hop-by-hop header (RFC 9110 7.6.1) + 本プロキシが独自に再構築するヘッダーは
// リクエスト転送時に除外する。Cookie/X-XSRF-TOKEN/Accept/Content-Type/
// User-Agent/X-Requested-With等は除外リストに無いため、そのまま転送される。
const EXCLUDED_REQUEST_HEADERS = new Set([
  "host",
  "connection",
  "content-length",
  "transfer-encoding",
  "keep-alive",
  "upgrade",
  "proxy-authorization",
  "proxy-authenticate",
  "te",
  "trailer",
  // Origin/Refererは信頼済みのFRONTEND_ORIGINで明示的に上書きするため、
  // ブラウザから送られてきた値をそのまま転送しない(なりすまし防止)。
  "origin",
  "referer",
]);

// hop-by-hop header + レスポンス生成時に作り直す/個別処理するヘッダーは除外する。
const EXCLUDED_RESPONSE_HEADERS = new Set([
  "connection",
  "transfer-encoding",
  "keep-alive",
  "upgrade",
  "proxy-authenticate",
  "proxy-authorization",
  "te",
  "trailer",
  "content-length", // ボディを作り直すため、古いcontent-lengthは転送しない
  "content-encoding", // undiciが既に展開したボディを転送するため、圧縮情報も転送しない
  "set-cookie", // 個別処理するため、汎用コピーループでは扱わない
]);

export class BackendProxyError extends Error {
  readonly status: number;
  readonly code: string;

  constructor(status: number, code: string, message: string) {
    super(message);
    this.name = "BackendProxyError";
    this.status = status;
    this.code = code;
  }
}

function backendUrl(): string {
  const value = process.env.BACKEND_URL;

  if (!value) {
    throw new BackendProxyError(502, "BACKEND_UNAVAILABLE", "Backend service is unavailable.");
  }

  return value;
}

function frontendOrigin(): string | undefined {
  return process.env.FRONTEND_ORIGIN;
}

/**
 * ブラウザから来たパスセグメント配列(Next.jsの[...path]が返す、既にURLデコード済みの配列)を、
 * BACKEND_URL配下の安全なURLへ変換する。
 *
 * - 各セグメントを個別にencodeURIComponentしてから連結する(セグメント内に"/"や".."が
 *   紛れ込んでいても、新しいパス区切りとして解釈されないようにするため)。
 * - "."・".."単体のセグメントは明示的に拒否する。
 * - 最後に、組み立てたURLのOriginがBACKEND_URLのOriginと完全一致することを検証する
 *   (URL構築ロジックに万一バグがあっても、任意ホストへの転送=SSRFを防ぐ最終防衛線)。
 */
export function buildTargetUrl(pathSegments: string[], search: string): URL {
  const base = new URL(backendUrl());

  const safeSegments = pathSegments.map((segment) => {
    if (segment === "" || segment === "." || segment === "..") {
      throw new BackendProxyError(400, "BACKEND_INVALID_PATH", "Invalid request path.");
    }

    return encodeURIComponent(segment);
  });

  const target = new URL(base.toString());
  const basePath = base.pathname.endsWith("/") ? base.pathname.slice(0, -1) : base.pathname;
  target.pathname = `${basePath}/${safeSegments.join("/")}`;
  target.search = search;

  if (target.origin !== base.origin) {
    throw new BackendProxyError(400, "BACKEND_INVALID_PATH", "Invalid request path.");
  }

  return target;
}

/**
 * Backendレスポンスから複数のSet-Cookieを、1つに結合せず個別の配列として取り出す。
 * Node/undiciの Headers.getSetCookie() が使えればそれを使う(推奨・正確)。
 * 使えない実行環境向けのフォールバックとして、", " の直後がCookie名らしきトークンに
 * 続く箇所でのみ分割する正規表現を使う(Expires属性中のカンマでは分割しない)。
 * 単純な `.split(",")` は禁止(Expires属性のカンマで壊れるため)。
 */
export function extractSetCookieHeaders(headers: Headers): string[] {
  if (typeof (headers as { getSetCookie?: () => string[] }).getSetCookie === "function") {
    return (headers as unknown as { getSetCookie: () => string[] }).getSetCookie();
  }

  const combined = headers.get("set-cookie");

  if (!combined) {
    return [];
  }

  console.warn("[backend-proxy] Headers.getSetCookie() is unavailable; falling back to a regex-based splitter.");

  return combined.split(/,(?=\s*[^;=\s]+=)/).map((part) => part.trim());
}

/**
 * 個々のSet-Cookie文字列からDomain属性のみを取り除く。
 * Path・Expires・Max-Age・Secure・HttpOnly・SameSiteはLaravelが返した値をそのまま維持する
 * (Domain属性が無いCookieはFrontendの現在のホストに対するホスト限定Cookieとして保存される)。
 */
export function rewriteSetCookieForFrontend(raw: string): string {
  const parts = raw.split(";").map((part) => part.trim());
  const [nameValue, ...attributes] = parts;
  const kept = attributes.filter((attr) => !/^domain=/i.test(attr));

  return [nameValue, ...kept].join("; ");
}

function buildForwardRequestHeaders(request: NextRequest, requestId: string): Headers {
  const headers = new Headers();

  request.headers.forEach((value, key) => {
    if (EXCLUDED_REQUEST_HEADERS.has(key.toLowerCase())) {
      return;
    }
    headers.set(key, value);
  });

  // SanctumのEnsureFrontendRequestsAreStateful::fromFrontend()は
  // Referer(優先)またはOriginのホスト名が SANCTUM_STATEFUL_DOMAINS に一致するかで
  // stateful(Cookieセッション認証)か否かを判定する。ブラウザ由来の値は信用せず、
  // 常に信頼済みのFRONTEND_ORIGINへ明示的に差し替える
  // (不正な外部Originを無条件でLaravelへ転送しないため)。
  const origin = frontendOrigin();
  if (origin) {
    headers.set("Origin", origin);
    headers.set("Referer", `${origin}/`);
  }

  headers.set("X-Request-Id", requestId);

  return headers;
}

function buildProxyResponseHeaders(backendResponse: Response, requestId: string): Headers {
  const headers = new Headers();

  backendResponse.headers.forEach((value, key) => {
    if (EXCLUDED_RESPONSE_HEADERS.has(key.toLowerCase())) {
      return;
    }
    headers.set(key, value);
  });

  // APIレスポンスはブラウザ・中間キャッシュのいずれにもキャッシュさせない。
  headers.set("Cache-Control", "no-store");
  headers.set("X-Request-Id", backendResponse.headers.get("x-request-id") ?? requestId);

  for (const rawSetCookie of extractSetCookieHeaders(backendResponse.headers)) {
    headers.append("Set-Cookie", rewriteSetCookieForFrontend(rawSetCookie));
  }

  return headers;
}

function errorResponse(status: number, code: string, message: string, requestId: string): NextResponse {
  return NextResponse.json(
    { message, code },
    { status, headers: { "Cache-Control": "no-store", "X-Request-Id": requestId } },
  );
}

/**
 * ブラウザからの `/backend/*` リクエストを、固定のBACKEND_URL配下のLaravel APIへ
 * そのまま転送する(同一オリジンBFFプロキシ)。転送先ホストは常にBACKEND_URLのみで、
 * ユーザー入力(パス・クエリ・ヘッダー)から転送先ホストが変わることは無い
 * (buildTargetUrlのOrigin検証を参照)。
 */
export async function proxyToBackend(
  request: NextRequest,
  method: string,
  pathSegments: string[],
): Promise<NextResponse> {
  const requestId = crypto.randomUUID();

  let target: URL;
  try {
    target = buildTargetUrl(pathSegments, request.nextUrl.search);
  } catch (error) {
    if (error instanceof BackendProxyError) {
      return errorResponse(error.status, error.code, error.message, requestId);
    }
    return errorResponse(502, "BACKEND_UNAVAILABLE", "Backend service is unavailable.", requestId);
  }

  const headers = buildForwardRequestHeaders(request, requestId);
  const hasBody = !["GET", "HEAD"].includes(method);
  // リクエストボディは(ファイルアップロードの無い、小さなJSON API呼び出しのみのため)
  // ストリームではなくバッファとして読み、Node fetchのストリーミングボディに
  // 必要な `duplex: "half"` の指定漏れを避ける。
  const body = hasBody ? await request.arrayBuffer() : undefined;

  const timeoutSignal = AbortSignal.timeout(TIMEOUT_MS);
  const signal = typeof AbortSignal.any === "function" ? AbortSignal.any([request.signal, timeoutSignal]) : timeoutSignal;

  let backendResponse: Response;
  try {
    backendResponse = await fetch(target, {
      method,
      headers,
      body,
      redirect: "manual",
      signal,
      cache: "no-store",
    });
  } catch (error) {
    // DOMException(AbortSignal.timeout()が投げるTimeoutError)はJS実行環境によって
    // Errorを継承しない場合があるため、instanceof Errorではなく name プロパティのみで判定する。
    const errorName = (error as { name?: unknown } | null)?.name;

    if (errorName === "TimeoutError") {
      console.error("[backend-proxy] timeout", { requestId, method, path: target.pathname });
      return errorResponse(504, "BACKEND_TIMEOUT", "Backend request timed out.", requestId);
    }

    console.error("[backend-proxy] connection failed", {
      requestId,
      method,
      path: target.pathname,
      error: error instanceof Error ? error.message : String(error),
    });
    return errorResponse(502, "BACKEND_UNAVAILABLE", "Backend service is unavailable.", requestId);
  }

  const responseHeaders = buildProxyResponseHeaders(backendResponse, requestId);

  return new NextResponse(backendResponse.body, {
    status: backendResponse.status,
    statusText: backendResponse.statusText,
    headers: responseHeaders,
  });
}

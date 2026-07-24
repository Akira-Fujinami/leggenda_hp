import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

function setCookie(name: string, value: string) {
  document.cookie = `${name}=${value}; path=/`;
}

function clearAllCookies() {
  document.cookie.split(";").forEach((entry) => {
    const name = entry.split("=")[0]?.trim();
    if (name) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/`;
    }
  });
}

async function loadApiClient(apiUrl: string) {
  vi.stubEnv("NEXT_PUBLIC_API_URL", apiUrl);
  return import("@/lib/api-client");
}

describe("api-client", () => {
  beforeEach(() => {
    clearAllCookies();
    vi.resetModules();
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.restoreAllMocks();
  });

  it("sends credentials:include and Accept header, without touching the CSRF cookie for GET", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(JSON.stringify({ data: { ok: true }, meta: {}, message: null }), { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("https://backend.example.com");
    await api.get("/api/user");

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("https://backend.example.com/api/user");
    expect(init.credentials).toBe("include");
    expect(init.headers.Accept).toBe("application/json");
  });

  it("normalizes a trailing slash in NEXT_PUBLIC_API_URL", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("https://backend.example.com/");
    await api.get("/api/user");

    expect(fetchMock.mock.calls[0][0]).toBe("https://backend.example.com/api/user");
  });

  it("defaults the base URL to the same-origin /backend proxy when NEXT_PUBLIC_API_URL is unset", async () => {
    vi.stubEnv("NEXT_PUBLIC_API_URL", "");
    // 空文字は `?? "/backend"` のnullish coalescingでは既定値へフォールバックしない
    // (unset/undefinedの場合のみフォールバックする)ため、キー自体を削除して検証する。
    delete process.env.NEXT_PUBLIC_API_URL;

    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal("fetch", fetchMock);

    vi.resetModules();
    const { api } = await import("@/lib/api-client");
    await api.get("/api/user");

    expect(fetchMock.mock.calls[0][0]).toBe("/backend/api/user");
  });

  it("also normalizes a trailing slash on the default /backend proxy path", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("/backend/");
    await api.get("/api/user");

    expect(fetchMock.mock.calls[0][0]).toBe("/backend/api/user");
  });

  it("fetches the CSRF cookie before a mutating request when none is present yet, and sends X-XSRF-TOKEN", async () => {
    const fetchMock = vi.fn().mockImplementation(async (url: string) => {
      if (url.endsWith("/sanctum/csrf-cookie")) {
        setCookie("XSRF-TOKEN", "token-abc");
        return new Response(null, { status: 204 });
      }
      return new Response(JSON.stringify({ data: {}, meta: {}, message: null }), { status: 200 });
    });
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("https://backend.example.com");
    await api.post("/api/login", { email: "a@example.com", password: "x" });

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0][0]).toBe("https://backend.example.com/sanctum/csrf-cookie");

    const [, loginInit] = fetchMock.mock.calls[1];
    expect(loginInit.headers["X-XSRF-TOKEN"]).toBe("token-abc");
    expect(loginInit.credentials).toBe("include");
  });

  it("URL-decodes the XSRF-TOKEN cookie value before sending it as X-XSRF-TOKEN (Laravel percent-encodes it)", async () => {
    // LaravelはXSRF-TOKEN CookieをbaseNN風の値でpercent-encodeして返す。
    // "+"や"="等が%XXとしてCookieに入るため、ヘッダーへ送る前にURLデコードが必要。
    setCookie("XSRF-TOKEN", "abc%2Bdef%3D");
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: {}, meta: {}, message: null }), { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("https://backend.example.com");
    await api.post("/api/login", { email: "a@example.com", password: "x" });

    const [, loginInit] = fetchMock.mock.calls[0];
    expect(loginInit.headers["X-XSRF-TOKEN"]).toBe("abc+def=");
  });

  it("does not send GET/HEAD requests with an X-XSRF-TOKEN header", async () => {
    setCookie("XSRF-TOKEN", "token-abc");
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: {}, meta: {}, message: null }), { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("https://backend.example.com");
    await api.get("/api/projects");

    const [, init] = fetchMock.mock.calls[0];
    expect(init.headers["X-XSRF-TOKEN"]).toBeUndefined();
  });

  it("does not refetch the CSRF cookie when one is already present", async () => {
    setCookie("XSRF-TOKEN", "existing-token");
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(JSON.stringify({ data: {}, meta: {}, message: null }), { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("https://backend.example.com");
    await api.post("/api/login", { email: "a@example.com", password: "x" });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][1].headers["X-XSRF-TOKEN"]).toBe("existing-token");
  });

  it("retries exactly once after a 419 by refetching the CSRF cookie, then succeeds", async () => {
    setCookie("XSRF-TOKEN", "stale-token");
    let loginAttempts = 0;

    const fetchMock = vi.fn().mockImplementation(async (url: string) => {
      if (url.endsWith("/sanctum/csrf-cookie")) {
        setCookie("XSRF-TOKEN", "fresh-token");
        return new Response(null, { status: 204 });
      }
      loginAttempts += 1;
      if (loginAttempts === 1) {
        return new Response(JSON.stringify({ message: "CSRF token mismatch." }), { status: 419 });
      }
      return new Response(JSON.stringify({ data: { id: 1 }, meta: {}, message: null }), { status: 200 });
    });
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("https://backend.example.com");
    const result = await api.post("/api/login", { email: "a@example.com", password: "x" });

    expect(result).toEqual({ data: { id: 1 }, meta: {}, message: null });
    expect(loginAttempts).toBe(2);

    const csrfCalls = fetchMock.mock.calls.filter(([url]) => url.endsWith("/sanctum/csrf-cookie"));
    expect(csrfCalls).toHaveLength(1);
  });

  it("does not retry infinitely when 419 persists after refetching the CSRF cookie", async () => {
    setCookie("XSRF-TOKEN", "stale-token");

    const fetchMock = vi.fn().mockImplementation(async (url: string) => {
      if (url.endsWith("/sanctum/csrf-cookie")) {
        setCookie("XSRF-TOKEN", "still-stale");
        return new Response(null, { status: 204 });
      }
      return new Response(JSON.stringify({ message: "CSRF token mismatch." }), { status: 419 });
    });
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("https://backend.example.com");

    await expect(api.post("/api/login", { email: "a@example.com", password: "x" })).rejects.toMatchObject({
      status: 419,
    });

    const csrfCalls = fetchMock.mock.calls.filter(([url]) => url.endsWith("/sanctum/csrf-cookie"));
    expect(csrfCalls).toHaveLength(1);

    const loginCalls = fetchMock.mock.calls.filter(([url]) => url.endsWith("/api/login"));
    expect(loginCalls).toHaveLength(2); // 初回 + 再試行1回のみ (無限ループしない)
  });

  it("throws an ApiError with status 401 on unauthenticated responses", async () => {
    // Responseのbodyは一度しか読めないため、呼び出しごとに新しいインスタンスを返す
    // (mockResolvedValueで同一インスタンスを使い回すと、2回目の.json()が空になる)。
    const fetchMock = vi.fn().mockImplementation(
      async () =>
        new Response(JSON.stringify({ message: "ログインが必要です。", error_code: "UNAUTHENTICATED" }), {
          status: 401,
        }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const { api, ApiError } = await loadApiClient("https://backend.example.com");

    await expect(api.get("/api/user")).rejects.toBeInstanceOf(ApiError);
    await expect(api.get("/api/user")).rejects.toMatchObject({ status: 401, errorCode: "UNAUTHENTICATED" });
  });

  it("captures the X-Request-Id response header on ApiError for backend/frontend log correlation", async () => {
    const fetchMock = vi.fn().mockImplementation(
      async () =>
        new Response(JSON.stringify({ message: "Server Error" }), {
          status: 500,
          headers: { "X-Request-Id": "11111111-1111-1111-1111-111111111111" },
        }),
    );
    vi.stubGlobal("fetch", fetchMock);
    vi.spyOn(console, "error").mockImplementation(() => {});

    const { api, ApiError } = await loadApiClient("https://backend.example.com");

    await expect(api.get("/api/projects")).rejects.toMatchObject({
      status: 500,
      requestId: "11111111-1111-1111-1111-111111111111",
      endpoint: "/api/projects",
    } satisfies Partial<InstanceType<typeof ApiError>>);
  });

  it("throws ApiNetworkError (not ApiError) when fetch itself fails, e.g. network outage or CORS block", async () => {
    const fetchMock = vi.fn().mockRejectedValue(new TypeError("Failed to fetch"));
    vi.stubGlobal("fetch", fetchMock);
    const consoleErrorSpy = vi.spyOn(console, "error").mockImplementation(() => {});

    const { api, ApiNetworkError } = await loadApiClient("https://backend.example.com");

    await expect(api.get("/api/projects")).rejects.toBeInstanceOf(ApiNetworkError);
    expect(consoleErrorSpy).toHaveBeenCalled();
  });

  it("sends credentials:include on logout (mutating, authenticated) requests", async () => {
    setCookie("XSRF-TOKEN", "token-abc");
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(JSON.stringify({ data: {}, meta: {}, message: null }), { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    const { api } = await loadApiClient("https://backend.example.com");
    await api.post("/api/logout");

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("https://backend.example.com/api/logout");
    expect(init.credentials).toBe("include");
    expect(init.headers["X-XSRF-TOKEN"]).toBe("token-abc");
  });
});

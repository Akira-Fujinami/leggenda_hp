import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { NextRequest } from "next/server";
import {
  BackendProxyError,
  buildTargetUrl,
  extractSetCookieHeaders,
  proxyToBackend,
  rewriteSetCookieForFrontend,
} from "@/lib/backend-proxy";

function req(url: string, init?: RequestInit): NextRequest {
  return new NextRequest(url, init);
}

describe("buildTargetUrl", () => {
  beforeEach(() => {
    vi.stubEnv("BACKEND_URL", "https://backend.example.com");
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("builds a URL under BACKEND_URL for the given path segments", () => {
    const target = buildTargetUrl(["api", "projects"], "");
    expect(target.toString()).toBe("https://backend.example.com/api/projects");
  });

  it("preserves the query string", () => {
    const target = buildTargetUrl(["api", "projects"], "?page=2");
    expect(target.search).toBe("?page=2");
  });

  it("percent-encodes a segment so it cannot introduce a new path separator or escape the origin", () => {
    const target = buildTargetUrl(["api", "a/b"], "");
    expect(target.pathname).toBe("/api/a%2Fb");
    expect(target.origin).toBe("https://backend.example.com");
  });

  it("rejects a literal '..' path segment", () => {
    expect(() => buildTargetUrl(["api", ".."], "")).toThrow(BackendProxyError);
  });

  it("rejects a literal '.' path segment", () => {
    expect(() => buildTargetUrl(["."], "")).toThrow(BackendProxyError);
  });

  it("rejects an empty path segment", () => {
    expect(() => buildTargetUrl(["api", ""], "")).toThrow(BackendProxyError);
  });

  it("throws a BACKEND_UNAVAILABLE-classed error when BACKEND_URL is not configured", () => {
    vi.stubEnv("BACKEND_URL", "");
    expect(() => buildTargetUrl(["api"], "")).toThrow(BackendProxyError);
    try {
      buildTargetUrl(["api"], "");
    } catch (error) {
      expect(error).toBeInstanceOf(BackendProxyError);
      expect((error as BackendProxyError).status).toBe(502);
      expect((error as BackendProxyError).code).toBe("BACKEND_UNAVAILABLE");
    }
  });
});

describe("extractSetCookieHeaders", () => {
  it("uses Headers.getSetCookie() when available", () => {
    const headers = new Headers();
    headers.append("set-cookie", "XSRF-TOKEN=abc; Path=/; Secure");
    headers.append("set-cookie", "leggenda_hp_session=def; Path=/; HttpOnly; Secure; SameSite=Lax");

    const result = extractSetCookieHeaders(headers);

    expect(result).toHaveLength(2);
    expect(result[0]).toContain("XSRF-TOKEN=abc");
    expect(result[1]).toContain("leggenda_hp_session=def");
  });

  it("falls back to a comma-aware splitter (not a naive comma split) when getSetCookie is unavailable", () => {
    const fakeHeaders = {
      get: (name: string) =>
        name === "set-cookie"
          ? "XSRF-TOKEN=abc; expires=Thu, 24-Jul-2026 00:00:00 GMT; Path=/, leggenda_hp_session=def; Path=/"
          : null,
    } as unknown as Headers;

    const result = extractSetCookieHeaders(fakeHeaders);

    expect(result).toEqual([
      "XSRF-TOKEN=abc; expires=Thu, 24-Jul-2026 00:00:00 GMT; Path=/",
      "leggenda_hp_session=def; Path=/",
    ]);
  });

  it("returns an empty array when there is no set-cookie header", () => {
    expect(extractSetCookieHeaders(new Headers())).toEqual([]);
  });
});

describe("rewriteSetCookieForFrontend", () => {
  it("removes the Domain attribute while preserving Expires/Max-Age/Path/Secure/SameSite", () => {
    const raw =
      "XSRF-TOKEN=abc123; expires=Thu, 24-Jul-2026 00:00:00 GMT; Max-Age=7200; path=/; domain=backend-xxxx.onrender.com; secure; samesite=lax";

    const result = rewriteSetCookieForFrontend(raw);

    expect(result).not.toMatch(/domain=/i);
    expect(result).toContain("XSRF-TOKEN=abc123");
    expect(result).toContain("path=/");
    expect(result).toContain("secure");
    expect(result).toContain("samesite=lax");
    expect(result).toContain("Max-Age=7200");
    expect(result).toContain("expires=Thu, 24-Jul-2026 00:00:00 GMT");
  });

  it("preserves HttpOnly on the session cookie", () => {
    const raw = "leggenda_hp_session=def456; path=/; domain=backend-xxxx.onrender.com; httponly; secure; samesite=lax";

    const result = rewriteSetCookieForFrontend(raw);

    expect(result).toContain("httponly");
    expect(result).not.toMatch(/domain=/i);
  });

  it("leaves a cookie without a Domain attribute unchanged", () => {
    const raw = "leggenda_hp_session=def456; path=/; httponly; secure; samesite=lax";
    expect(rewriteSetCookieForFrontend(raw)).toBe(raw);
  });
});

describe("proxyToBackend", () => {
  beforeEach(() => {
    vi.stubEnv("BACKEND_URL", "https://backend.example.com");
    vi.stubEnv("FRONTEND_ORIGIN", "https://frontend.example.com");
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.restoreAllMocks();
  });

  it("forwards GET requests to BACKEND_URL with the query string, Cookie, and X-XSRF-TOKEN, overriding Origin/Referer", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(JSON.stringify({ data: [] }), { status: 200, headers: { "content-type": "application/json" } }));
    vi.stubGlobal("fetch", fetchMock);

    const request = req("https://frontend.example.com/backend/api/projects?page=2", {
      method: "GET",
      headers: {
        Cookie: "XSRF-TOKEN=tok; leggenda_hp_session=sess",
        "X-XSRF-TOKEN": "tok",
        Origin: "https://evil.example",
        Referer: "https://evil.example/",
        "X-Requested-With": "XMLHttpRequest",
      },
    });

    await proxyToBackend(request, "GET", ["api", "projects"]);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [target, init] = fetchMock.mock.calls[0];
    expect(String(target)).toBe("https://backend.example.com/api/projects?page=2");
    expect(init.method).toBe("GET");

    const sentHeaders = init.headers as Headers;
    expect(sentHeaders.get("Cookie")).toBe("XSRF-TOKEN=tok; leggenda_hp_session=sess");
    expect(sentHeaders.get("X-XSRF-TOKEN")).toBe("tok");
    // Origin/Refererはブラウザ由来の値(evil.example)を信用せず、FRONTEND_ORIGINへ上書きする。
    expect(sentHeaders.get("Origin")).toBe("https://frontend.example.com");
    expect(sentHeaders.get("Referer")).toBe("https://frontend.example.com/");
    expect(sentHeaders.get("X-Requested-With")).toBe("XMLHttpRequest");
    expect(sentHeaders.has("X-Request-Id")).toBe(true);
  });

  it("forwards a JSON POST body and the resulting Location/status", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(JSON.stringify({ data: { id: 1 } }), { status: 201, headers: { Location: "/api/projects/1" } }));
    vi.stubGlobal("fetch", fetchMock);

    const request = req("https://frontend.example.com/backend/api/projects", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name: "test" }),
    });

    const response = await proxyToBackend(request, "POST", ["api", "projects"]);

    const [, init] = fetchMock.mock.calls[0];
    expect(init.method).toBe("POST");
    expect(new TextDecoder().decode(init.body as ArrayBuffer)).toBe(JSON.stringify({ name: "test" }));

    expect(response.status).toBe(201);
    expect(response.headers.get("Location")).toBe("/api/projects/1");
    const body = await response.json();
    expect(body).toEqual({ data: { id: 1 } });
  });

  it("forwards a 204 No Content response with an empty body", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(req("https://frontend.example.com/backend/sanctum/csrf-cookie"), "GET", [
      "sanctum",
      "csrf-cookie",
    ]);

    expect(response.status).toBe(204);
  });

  it("forwards the backend's X-Request-Id response header", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response("{}", { status: 200, headers: { "X-Request-Id": "11111111-1111-1111-1111-111111111111" } }));
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(req("https://frontend.example.com/backend/api/user"), "GET", ["api", "user"]);

    expect(response.headers.get("X-Request-Id")).toBe("11111111-1111-1111-1111-111111111111");
  });

  it("sets Cache-Control: no-store on every proxied response", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response("{}", { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(req("https://frontend.example.com/backend/api/user"), "GET", ["api", "user"]);

    expect(response.headers.get("Cache-Control")).toBe("no-store");
  });

  it("does not forward hop-by-hop response headers or the backend's stale Content-Length", async () => {
    const backendHeaders = new Headers();
    backendHeaders.set("Content-Type", "application/json");
    backendHeaders.set("Connection", "keep-alive");
    backendHeaders.set("Transfer-Encoding", "chunked");
    backendHeaders.set("Keep-Alive", "timeout=5");
    backendHeaders.set("Content-Length", "9999");

    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ ok: true }), { status: 200, headers: backendHeaders }));
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(req("https://frontend.example.com/backend/api/x"), "GET", ["api", "x"]);

    expect(response.headers.get("connection")).toBeNull();
    expect(response.headers.get("transfer-encoding")).toBeNull();
    expect(response.headers.get("keep-alive")).toBeNull();
    expect(response.headers.get("content-length")).not.toBe("9999");
  });

  it("forwards multiple Set-Cookie headers individually with the Domain attribute stripped", async () => {
    const backendHeaders = new Headers();
    backendHeaders.append(
      "set-cookie",
      "XSRF-TOKEN=tok123; expires=Thu, 24-Jul-2026 00:00:00 GMT; Max-Age=7200; path=/; domain=backend-xxxx.onrender.com; secure; samesite=lax",
    );
    backendHeaders.append(
      "set-cookie",
      "leggenda_hp_session=sess456; expires=Thu, 24-Jul-2026 02:00:00 GMT; Max-Age=7200; path=/; domain=backend-xxxx.onrender.com; httponly; secure; samesite=lax",
    );
    backendHeaders.set("content-type", "application/json");

    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({}), { status: 200, headers: backendHeaders }));
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(
      req("https://frontend.example.com/backend/api/login", { method: "POST", body: "{}" }),
      "POST",
      ["api", "login"],
    );

    const setCookies = extractSetCookieHeaders(response.headers);

    expect(setCookies).toHaveLength(2);
    expect(setCookies[0]).toContain("XSRF-TOKEN=tok123");
    expect(setCookies[0]).not.toMatch(/domain=/i);
    expect(setCookies[0]).toContain("secure");
    expect(setCookies[0]).toContain("Max-Age=7200");
    expect(setCookies[1]).toContain("leggenda_hp_session=sess456");
    expect(setCookies[1]).toContain("httponly");
    expect(setCookies[1]).not.toMatch(/domain=/i);
  });

  it("returns 400 and never calls fetch for a path-traversal segment", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(req("https://frontend.example.com/backend/api/.."), "GET", ["api", ".."]);

    expect(response.status).toBe(400);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("returns 502 and never calls fetch when BACKEND_URL is not configured", async () => {
    vi.stubEnv("BACKEND_URL", "");
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(req("https://frontend.example.com/backend/api/x"), "GET", ["api", "x"]);

    expect(response.status).toBe(502);
    const body = await response.json();
    expect(body.code).toBe("BACKEND_UNAVAILABLE");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("returns 504 BACKEND_TIMEOUT when the backend does not respond within the timeout", async () => {
    const fetchMock = vi.fn().mockRejectedValue(new DOMException("The operation timed out.", "TimeoutError"));
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(req("https://frontend.example.com/backend/api/x"), "GET", ["api", "x"]);

    expect(response.status).toBe(504);
    const body = await response.json();
    expect(body).toEqual({ message: "Backend request timed out.", code: "BACKEND_TIMEOUT" });
  });

  it("returns 502 BACKEND_UNAVAILABLE when the connection to the backend fails", async () => {
    const fetchMock = vi.fn().mockRejectedValue(new TypeError("fetch failed"));
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(req("https://frontend.example.com/backend/api/x"), "GET", ["api", "x"]);

    expect(response.status).toBe(502);
    const body = await response.json();
    expect(body).toEqual({ message: "Backend service is unavailable.", code: "BACKEND_UNAVAILABLE" });
  });

  it("never leaks the backend URL or a stack trace in error responses", async () => {
    const fetchMock = vi.fn().mockRejectedValue(new TypeError("connect ECONNREFUSED 10.0.0.5:8000"));
    vi.stubGlobal("fetch", fetchMock);

    const response = await proxyToBackend(req("https://frontend.example.com/backend/api/x"), "GET", ["api", "x"]);
    const text = await response.text();

    expect(text).not.toContain("backend.example.com");
    expect(text).not.toContain("ECONNREFUSED");
    expect(text).not.toContain("10.0.0.5");
  });
});

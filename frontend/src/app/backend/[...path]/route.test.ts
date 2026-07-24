import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { NextRequest } from "next/server";
import { DELETE, GET, HEAD, OPTIONS, PATCH, POST, PUT } from "./route";

function req(url: string, init?: RequestInit): NextRequest {
  return new NextRequest(url, init);
}

function ctx(path: string[]) {
  return { params: Promise.resolve({ path }) };
}

describe("backend proxy route handlers", () => {
  beforeEach(() => {
    vi.stubEnv("BACKEND_URL", "https://backend.example.com");
    vi.stubEnv("FRONTEND_ORIGIN", "https://frontend.example.com");
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.restoreAllMocks();
  });

  const cases: Array<{
    handler: typeof GET;
    method: string;
    path: string[];
    hasBody: boolean;
  }> = [
    { handler: GET, method: "GET", path: ["api", "user"], hasBody: false },
    { handler: POST, method: "POST", path: ["api", "login"], hasBody: true },
    { handler: PUT, method: "PUT", path: ["api", "projects", "1"], hasBody: true },
    { handler: PATCH, method: "PATCH", path: ["api", "projects", "1"], hasBody: true },
    { handler: DELETE, method: "DELETE", path: ["api", "projects", "1"], hasBody: false },
    { handler: OPTIONS, method: "OPTIONS", path: ["api", "login"], hasBody: false },
    { handler: HEAD, method: "HEAD", path: ["sanctum", "csrf-cookie"], hasBody: false },
  ];

  it.each(cases)("$method resolves params and forwards to BACKEND_URL/$path.join('/')", async ({ handler, method, path, hasBody }) => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(hasBody ? "{}" : null, { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    const request = req(`https://frontend.example.com/backend/${path.join("/")}`, {
      method,
      body: hasBody ? "{}" : undefined,
      headers: hasBody ? { "Content-Type": "application/json" } : undefined,
    });

    await handler(request, ctx(path));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [target, init] = fetchMock.mock.calls[0];
    expect(String(target)).toBe(`https://backend.example.com/${path.join("/")}`);
    expect(init.method).toBe(method);
  });

  it("exports dynamic = force-dynamic so the proxy is never statically cached", async () => {
    const routeModule = await import("./route");
    expect(routeModule.dynamic).toBe("force-dynamic");
  });
});

import type { NextRequest } from "next/server";
import { proxyToBackend } from "@/lib/backend-proxy";

// このRoute Handlerは常に受信リクエストを検査してBackendへ転送する動的プロキシであり、
// 静的化・キャッシュ化してはならない。
export const dynamic = "force-dynamic";

type RouteContext = { params: Promise<{ path: string[] }> };

async function handle(request: NextRequest, context: RouteContext, method: string) {
  const { path } = await context.params;
  return proxyToBackend(request, method, path);
}

export async function GET(request: NextRequest, context: RouteContext) {
  return handle(request, context, "GET");
}

export async function POST(request: NextRequest, context: RouteContext) {
  return handle(request, context, "POST");
}

export async function PUT(request: NextRequest, context: RouteContext) {
  return handle(request, context, "PUT");
}

export async function PATCH(request: NextRequest, context: RouteContext) {
  return handle(request, context, "PATCH");
}

export async function DELETE(request: NextRequest, context: RouteContext) {
  return handle(request, context, "DELETE");
}

export async function OPTIONS(request: NextRequest, context: RouteContext) {
  return handle(request, context, "OPTIONS");
}

export async function HEAD(request: NextRequest, context: RouteContext) {
  return handle(request, context, "HEAD");
}

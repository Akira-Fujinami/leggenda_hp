"use client";

import { useEffect, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

type HealthState =
  | { phase: "loading" }
  | { phase: "error"; message: string }
  | { phase: "ok"; status: string; checks: Record<string, string> };

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export function HealthStatus() {
  const [state, setState] = useState<HealthState>({ phase: "loading" });

  useEffect(() => {
    let cancelled = false;

    fetch(`${API_URL}/api/health`)
      .then(async (res) => {
        const body = await res.json();
        if (cancelled) return;
        setState({ phase: "ok", status: body.data.status, checks: body.data.checks });
      })
      .catch(() => {
        if (cancelled) return;
        setState({ phase: "error", message: "backend APIに接続できませんでした。" });
      });

    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <Card className="w-full max-w-md">
      <CardHeader>
        <CardTitle className="flex items-center justify-between">
          Backend API接続状態
          {state.phase === "ok" && (
            <Badge variant={state.status === "ok" ? "default" : "destructive"}>
              {state.status}
            </Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="text-sm text-muted-foreground">
        {state.phase === "loading" && <p>{API_URL} を確認しています…</p>}
        {state.phase === "error" && <p className="text-destructive">{state.message}</p>}
        {state.phase === "ok" && (
          <ul className="space-y-1">
            {Object.entries(state.checks).map(([name, value]) => (
              <li key={name} className="flex justify-between">
                <span>{name}</span>
                <span>{value}</span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

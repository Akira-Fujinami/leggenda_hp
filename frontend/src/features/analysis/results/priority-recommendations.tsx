import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { ResultRecommendation } from "@/types/analysis";

const PRIORITY_LABELS: Record<ResultRecommendation["priority"], string> = { critical: "緊急", high: "高", medium: "中", low: "低" };
const PRIORITY_VARIANTS: Record<ResultRecommendation["priority"], "destructive" | "default" | "secondary" | "outline"> = {
  critical: "destructive",
  high: "default",
  medium: "secondary",
  low: "outline",
};
const IMPACT_LABELS: Record<ResultRecommendation["impact"], string> = { high: "大", medium: "中", low: "小" };
const EFFORT_LABELS: Record<ResultRecommendation["effort"], string> = { small: "小", medium: "中", large: "大" };

function formatValue(value: unknown): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === "object") {
    const record = value as Record<string, unknown>;
    return Object.entries(record)
      .map(([k, v]) => `${k}: ${String(v)}`)
      .join(", ");
  }

  return String(value);
}

export function PriorityRecommendations({ recommendations, url }: { recommendations: ResultRecommendation[]; url: string | null }) {
  const top5 = recommendations.slice(0, 5);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">優先改善項目</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {top5.length === 0 ? (
          <p className="text-sm text-muted-foreground">現時点で改善提案はありません。</p>
        ) : (
          top5.map((rec) => {
            const current = formatValue(rec.current_value);
            const recommended = formatValue(rec.recommended_value);
            const evidence = formatValue(rec.evidence);

            return (
              <div key={rec.id} className="rounded-md border p-3">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={PRIORITY_VARIANTS[rec.priority]}>優先度: {PRIORITY_LABELS[rec.priority]}</Badge>
                  <Badge variant="outline">想定効果: {IMPACT_LABELS[rec.impact]}</Badge>
                  <Badge variant="outline">工数: {EFFORT_LABELS[rec.effort]}</Badge>
                </div>
                <p className="mt-2 font-medium">{rec.title}</p>
                {rec.description && <p className="mt-1 text-sm text-muted-foreground">問題: {rec.description}</p>}
                {evidence && <p className="mt-1 text-xs text-muted-foreground">根拠: {evidence}</p>}
                {(current || recommended) && (
                  <p className="mt-1 text-xs text-muted-foreground">
                    {current && `現在値: ${current}`}
                    {current && recommended && " ・ "}
                    {recommended && `推奨値: ${recommended}`}
                  </p>
                )}
                {url && <p className="mt-1 text-xs text-muted-foreground">対象URL: {url}</p>}
              </div>
            );
          })
        )}
      </CardContent>
    </Card>
  );
}

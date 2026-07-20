import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { RankingEntry, StrengthWeaknessGroup } from "@/types/comparison";

export function StrengthsWeaknesses({
  ranking,
  strengths,
  weaknesses,
}: {
  ranking: RankingEntry[];
  strengths: StrengthWeaknessGroup[];
  weaknesses: StrengthWeaknessGroup[];
}) {
  const nameOf = (websiteAnalysisId: number) =>
    ranking.find((r) => r.website_analysis_id === websiteAnalysisId)?.website_name ?? `サイト #${websiteAnalysisId}`;

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">強み</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {strengths.every((group) => group.items.length === 0) && (
            <p className="text-sm text-muted-foreground">目立った強みは見つかりませんでした。</p>
          )}
          {strengths
            .filter((group) => group.items.length > 0)
            .map((group) => (
              <div key={group.website_analysis_id}>
                <p className="text-sm font-medium">{nameOf(group.website_analysis_id)}</p>
                <ul className="mt-1 list-inside list-disc space-y-0.5 text-sm text-muted-foreground">
                  {group.items.map((item, index) => (
                    <li key={index}>{item.label}</li>
                  ))}
                </ul>
              </div>
            ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">弱み・改善余地</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {weaknesses.every((group) => group.items.length === 0) && (
            <p className="text-sm text-muted-foreground">目立った弱みは見つかりませんでした。</p>
          )}
          {weaknesses
            .filter((group) => group.items.length > 0)
            .map((group) => (
              <div key={group.website_analysis_id}>
                <p className="text-sm font-medium">{nameOf(group.website_analysis_id)}</p>
                <ul className="mt-1 list-inside list-disc space-y-0.5 text-sm text-muted-foreground">
                  {group.items.map((item, index) => (
                    <li key={index}>{item.label}</li>
                  ))}
                </ul>
              </div>
            ))}
        </CardContent>
      </Card>
    </div>
  );
}

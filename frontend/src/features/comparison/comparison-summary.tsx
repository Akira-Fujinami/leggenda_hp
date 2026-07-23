import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DataQualityWarnings } from "@/features/comparison/data-quality-warnings";
import { ExternalSeoMockNotice } from "@/features/comparison/external-seo-mock-notice";
import { isCategoryUnavailable } from "@/features/comparison/category-availability";
import { RankingSummary } from "@/features/comparison/ranking-summary";
import type { CategoryComparison, DataQuality, ExternalSeoInfo, RankingEntry } from "@/types/comparison";

const TOP_DIFFERENCES_COUNT = 3;

interface CategoryDifference {
  key: string;
  name: string;
  diff: number;
}

function topDifferences(categories: CategoryComparison[]): CategoryDifference[] {
  return categories
    .map((category) => {
      const available = category.sites.filter((s) => !isCategoryUnavailable(s));
      if (available.length < 2) return null;
      const scores = available.map((s) => s.score);
      const diff = Math.round((Math.max(...scores) - Math.min(...scores)) * 100) / 100;
      return { key: category.key, name: category.name, diff };
    })
    .filter((c): c is CategoryDifference => c !== null && c.diff > 0)
    .sort((a, b) => b.diff - a.diff)
    .slice(0, TOP_DIFFERENCES_COUNT);
}

export function ComparisonSummary({
  ranking,
  categories,
  dataQuality,
  externalSeo,
}: {
  ranking: RankingEntry[];
  categories: CategoryComparison[];
  dataQuality: DataQuality[];
  externalSeo: ExternalSeoInfo[];
}) {
  const differences = topDifferences(categories);

  return (
    <div className="space-y-4">
      <RankingSummary ranking={ranking} />

      {differences.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">重要な差</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-2 sm:grid-cols-3">
            {differences.map((d) => (
              <div key={d.key} className="rounded-md border p-3">
                <p className="text-sm font-medium">{d.name}</p>
                <p className="mt-1 text-xs text-muted-foreground">差 {d.diff}</p>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <DataQualityWarnings ranking={ranking} dataQuality={dataQuality} />
      <ExternalSeoMockNotice ranking={ranking} externalSeo={externalSeo} />
    </div>
  );
}

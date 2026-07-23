"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { groupStrengthWeaknessItems, type GroupedDisplayItem } from "@/features/comparison/group-strength-weakness-items";
import type { CategoryComparison, MetricComparison, RankingEntry, StrengthWeaknessGroup } from "@/types/comparison";

const INITIAL_VISIBLE_COUNT = 5;

function SiteItemList({
  siteName,
  items,
}: {
  siteName: string;
  items: GroupedDisplayItem[];
}) {
  const [open, setOpen] = useState(false);
  const visible = items.slice(0, INITIAL_VISIBLE_COUNT);
  const rest = items.slice(INITIAL_VISIBLE_COUNT);

  return (
    <div>
      <p className="text-sm font-medium">{siteName}</p>
      <ul className="mt-1 list-inside list-disc space-y-0.5 text-sm text-muted-foreground">
        {visible.map((item) => (
          <li key={item.key}>{item.label}</li>
        ))}
      </ul>
      {rest.length > 0 && (
        <Collapsible open={open} onOpenChange={setOpen} className="mt-1">
          <CollapsibleTrigger render={<Button variant="ghost" size="sm" className="h-6 gap-1 px-1.5 text-xs" />}>
            <ChevronDown className={`size-3 transition-transform ${open ? "rotate-180" : ""}`} />
            すべて表示({items.length}件)
          </CollapsibleTrigger>
          <CollapsibleContent>
            <ul className="mt-1 list-inside list-disc space-y-0.5 text-sm text-muted-foreground">
              {rest.map((item) => (
                <li key={item.key}>{item.label}</li>
              ))}
            </ul>
          </CollapsibleContent>
        </Collapsible>
      )}
    </div>
  );
}

export function StrengthWeaknessSummary({
  ranking,
  strengths,
  weaknesses,
  metrics,
  categories,
}: {
  ranking: RankingEntry[];
  strengths: StrengthWeaknessGroup[];
  weaknesses: StrengthWeaknessGroup[];
  metrics: MetricComparison[];
  categories: CategoryComparison[];
}) {
  const nameOf = (websiteAnalysisId: number) =>
    ranking.find((r) => r.website_analysis_id === websiteAnalysisId)?.website_name ?? `サイト #${websiteAnalysisId}`;

  const groupedStrengths = strengths.map((g) => ({
    websiteAnalysisId: g.website_analysis_id,
    items: groupStrengthWeaknessItems(g.items, metrics, categories, "strength"),
  }));
  const groupedWeaknesses = weaknesses.map((g) => ({
    websiteAnalysisId: g.website_analysis_id,
    items: groupStrengthWeaknessItems(g.items, metrics, categories, "weakness"),
  }));

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">強み</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {groupedStrengths.every((g) => g.items.length === 0) && (
            <p className="text-sm text-muted-foreground">目立った強みは見つかりませんでした。</p>
          )}
          {groupedStrengths
            .filter((g) => g.items.length > 0)
            .map((g) => (
              <SiteItemList key={g.websiteAnalysisId} siteName={nameOf(g.websiteAnalysisId)} items={g.items} />
            ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">弱み・改善余地</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {groupedWeaknesses.every((g) => g.items.length === 0) && (
            <p className="text-sm text-muted-foreground">目立った弱みは見つかりませんでした。</p>
          )}
          {groupedWeaknesses
            .filter((g) => g.items.length > 0)
            .map((g) => (
              <SiteItemList key={g.websiteAnalysisId} siteName={nameOf(g.websiteAnalysisId)} items={g.items} />
            ))}
        </CardContent>
      </Card>
    </div>
  );
}

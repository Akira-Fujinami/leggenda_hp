"use client";

import { useState } from "react";
import { ChevronDown, FlaskConical, HelpCircle } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { ExternalSeoInfoPanel } from "@/features/comparison/external-seo-info";
import type { ExternalSeoInfo, RankingEntry } from "@/types/comparison";

/**
 * 外部SEO詳細(Provider/取得日時等の診断テーブル)。全サイトMockの場合は
 * 「評価不可・デモデータあり」の1行に折りたたみ、詳細は展開時のみ表示する。
 * 実データが存在/混在する場合は現行どおり常時表示する。
 */
export function ExternalSeoComparison({ ranking, externalSeo }: { ranking: RankingEntry[]; externalSeo: ExternalSeoInfo[] }) {
  const [open, setOpen] = useState(false);
  const withData = externalSeo.filter((e) => e.status === "success");
  const allMock = withData.length > 0 && withData.every((e) => e.is_mock);

  if (!allMock) {
    return <ExternalSeoInfoPanel ranking={ranking} externalSeo={externalSeo} />;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">外部SEO</CardTitle>
      </CardHeader>
      <CardContent className="space-y-2">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="outline" className="gap-1">
            <HelpCircle className="size-3" />
            評価不可
          </Badge>
          <Badge variant="outline" className="gap-1">
            <FlaskConical className="size-3" />
            デモデータあり
          </Badge>
        </div>
        <Collapsible open={open} onOpenChange={setOpen}>
          <CollapsibleTrigger render={<Button variant="ghost" size="sm" className="gap-1" />}>
            <ChevronDown className={`size-3.5 transition-transform ${open ? "rotate-180" : ""}`} />
            詳細を見る
          </CollapsibleTrigger>
          <CollapsibleContent className="pt-2">
            <ExternalSeoInfoPanel ranking={ranking} externalSeo={externalSeo} />
          </CollapsibleContent>
        </Collapsible>
      </CardContent>
    </Card>
  );
}

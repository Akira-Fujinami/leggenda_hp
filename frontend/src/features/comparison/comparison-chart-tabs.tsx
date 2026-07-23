"use client";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { BarOverallChart, RadarCategoryChart } from "@/features/comparison/score-charts";
import type { CategoryComparison, RankingEntry } from "@/types/comparison";

/**
 * レーダーチャートと棒グラフを縦に連続表示せず、Tabsで切り替える。
 * 初期表示は「カテゴリ比較」(レーダー)。
 */
export function ComparisonChartTabs({ ranking, categories }: { ranking: RankingEntry[]; categories: CategoryComparison[] }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">グラフ</CardTitle>
      </CardHeader>
      <CardContent>
        <Tabs defaultValue="categories">
          <TabsList>
            <TabsTrigger value="categories">カテゴリ比較</TabsTrigger>
            <TabsTrigger value="overall">総合スコア</TabsTrigger>
          </TabsList>
          <TabsContent value="categories">
            <RadarCategoryChart ranking={ranking} categories={categories} />
          </TabsContent>
          <TabsContent value="overall">
            <BarOverallChart ranking={ranking} />
          </TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  );
}

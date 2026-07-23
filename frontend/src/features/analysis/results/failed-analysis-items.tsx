"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { isJobRetryable, jobTypeLabel } from "@/features/analysis/job-labels";
import type { AnalysisJobError } from "@/types/analysis";

const IMPACT_BY_JOB: Record<string, string> = {
  render_page: "スクリーンショット・表示速度(Lighthouse)の計測に影響します。",
  capture_screenshot_desktop: "PCのスクリーンショット表示のみに影響します。",
  capture_screenshot_mobile: "モバイルのスクリーンショット表示のみに影響します。",
  run_lighthouse: "表示速度・アクセシビリティスコアの評価のみに影響します。",
  fetch_external_seo_data: "外部SEO・ドメイン評価カテゴリの評価のみに影響します。",
  fetch_static_page: "全ての分析項目が評価できません。",
  fetch_robots: "robots.txt関連の項目のみに影響します。",
  fetch_sitemap: "sitemap.xml関連の項目のみに影響します。",
  analyze_html_seo: "SEO・コンテンツ・集客関連の評価に影響します。",
  detect_technology: "技術検出カテゴリの評価のみに影響します。",
};

export function FailedAnalysisItems({ errors }: { errors: AnalysisJobError[] }) {
  const [open, setOpen] = useState(false);
  if (errors.length === 0) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">分析できなかった項目</CardTitle>
      </CardHeader>
      <CardContent className="space-y-2">
        <Alert variant="destructive">
          <AlertDescription>
            分析失敗: {errors.length}件({errors.map((e) => jobTypeLabel(e.job_type)).join("、")})
          </AlertDescription>
        </Alert>

        <Collapsible open={open} onOpenChange={setOpen}>
          <CollapsibleTrigger render={<Button variant="ghost" size="sm" className="gap-1" />}>
            <ChevronDown className={`size-3.5 transition-transform ${open ? "rotate-180" : ""}`} />
            {open ? "詳細を閉じる" : "詳細を見る"}
          </CollapsibleTrigger>
          <CollapsibleContent className="pt-2">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>分析項目</TableHead>
                  <TableHead>原因</TableHead>
                  <TableHead>影響範囲</TableHead>
                  <TableHead>再実行</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {errors.map((error) => (
                  <TableRow key={error.job_type}>
                    <TableCell className="font-medium">{jobTypeLabel(error.job_type)}</TableCell>
                    <TableCell className="text-muted-foreground">{error.error_message ?? error.error_code ?? "不明なエラー"}</TableCell>
                    <TableCell className="text-muted-foreground">{IMPACT_BY_JOB[error.job_type] ?? "一部の評価項目に影響します。"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {isJobRetryable(error.job_type) ? "再分析で再取得できる可能性があります" : "-"}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CollapsibleContent>
        </Collapsible>
      </CardContent>
    </Card>
  );
}

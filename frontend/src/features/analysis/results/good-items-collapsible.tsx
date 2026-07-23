"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";

export function GoodItemsCollapsible({
  count,
  children,
  label = "良好な項目を表示",
  contentClassName = "mt-2 grid gap-2 sm:grid-cols-2",
}: {
  count: number;
  children: React.ReactNode;
  /** トリガーに表示するラベル(件数は自動で付与される)。既定は結果画面と同じ文言。 */
  label?: string;
  /** 展開時のコンテンツラッパーに適用するクラス名(既定は2列グリッド)。 */
  contentClassName?: string;
}) {
  const [open, setOpen] = useState(false);
  if (count === 0) return null;

  return (
    <Collapsible open={open} onOpenChange={setOpen} className="sm:col-span-2">
      <CollapsibleTrigger render={<Button variant="ghost" size="sm" className="gap-1" />}>
        <ChevronDown className={`size-3.5 transition-transform ${open ? "rotate-180" : ""}`} />
        {label}({count}件)
      </CollapsibleTrigger>
      <CollapsibleContent>
        <div className={contentClassName}>{children}</div>
      </CollapsibleContent>
    </Collapsible>
  );
}

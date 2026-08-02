import { useState } from "react";
import { ChevronDownIcon, ChevronUpIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { COVERAGE_THRESHOLD } from "@/features/analysis/results/data-quality-notice";
import { PerspectiveStatusBadge } from "@/features/lead/perspective-status-badge";
import { PERSPECTIVE_STATUS_LABELS } from "@/features/lead/perspective-status";
import { cn } from "@/lib/utils";
import type { CategoryScore } from "@/types/analysis";
import type { LeadPerspective, LeadPerspectiveKey, LeadPerspectiveStatus, LeadWebsiteResult } from "@/types/lead";

/**
 * 4観点を自社/競合で並べて見せる比較ブロック。
 *
 * 背景(2026-07-30のユーザー指摘): 従来は自社カード・競合カードが縦に2枚積まれ、
 * 各カード内で4観点が常に全展開されていたため、同じ観点を2社で比べるには
 * 遠く離れた2箇所をスクロールで往復する必要があった(「縦に長すぎて読みづらい」)。
 * 全体像をここに集約し、項目単位の内訳だけを折りたたむ。
 *
 * 設計上の制約(いずれも既存の誠実性の維持と同じ考え方):
 *
 * 1. レーダーチャート(多角形)にはしない。4観点は独立した項目で軸の並び順に
 *    意味がなく、順序を変えるだけで面積が変わるため、実際の差より大きく/
 *    小さく見える。
 * 2. 分母はconfigured_max_scoreではなくmax_available_scoreを使う。
 *    configured_max_scoreは「このリリースで何点満点か」という設計上固定の値で、
 *    実測できたかどうかに左右されない ―― これを分母にすると、取得できなかった
 *    項目がある分だけ達成度が目減りし、「未取得の指標を0点として扱わない」
 *    という原則を集計レベルで破ることになる。
 * 3. 取得できなかった観点は長さ0のバーで描かない。バーが無いことは0点として
 *    読まれるため、バックエンドと同じ文言(PERSPECTIVE_STATUS_LABELS)で
 *    「計測できませんでした」等と明示する。
 * 4. 2は同時に別の問題を生む。自社のカバー率50%・競合95%のとき、それぞれ
 *    自分の取得範囲で正規化した数値が同じ軸に並び、直接比較できる形で見えて
 *    しまう。そのためカバー率を必ず併記し、差が大きい場合は注記を出す。
 * 5. 色だけで系列を区別しない。各バーに「自社」「競合」のラベルを直接置く。
 *    良し悪しを示す緑・赤は使わない(点数の高低は情報の置き方の話であり、
 *    企業としての優劣ではない)。
 * 6. 注記(note)と要約(summary)は折りたたみの外に置く。④の「配色やレイアウトの
 *    見た目そのものの印象は自動判定の対象外」のような但し書きが隠れていると、
 *    バーの数値をデザインの評価だと誤読される。
 */

// 2系統(自社/競合)の識別色。色覚特性のシミュレーションを含む検証済みの値で、
// ブランド・ホイールのヘキサゴンと同一 ―― 片方だけ変更しないこと。
const SELF_BAR = "bg-[#2a78d6] dark:bg-[#3987e5]";
const COMPETITOR_BAR = "bg-[#eb6834] dark:bg-[#d95926]";
const GRID_LINE = "bg-[#e1e0d9] dark:bg-[#2c2c2a]";
const UNMEASURED_RULE = "bg-[#898781]";

/** 達成度を数値で出せるのはこの3状態のときだけ(それ以外は未取得系)。 */
const MEASURED_STATUSES: readonly LeadPerspectiveStatus[] = ["good", "needs_review", "needs_improvement"];

/** カバー率がこれ以上離れていたら、単純比較できない旨を注記する。 */
export const COVERAGE_GAP_THRESHOLD = 20;

const SELF_LABEL = "自社";
const COMPETITOR_LABEL = "競合";

export interface ComparisonRow {
  key: LeadPerspectiveKey;
  heading: string;
  note: string | null;
  self: PerspectiveCell;
  competitor: PerspectiveCell | null;
}

export interface PerspectiveCell {
  perspective: LeadPerspective;
  /** 0〜100に正規化した達成度。数値として出せない場合はnull。 */
  value: number | null;
}

/**
 * 観点1つ・サイト1つ分の達成度。
 *
 * max_available_score(取得できた項目の満点合計)を分母にする。0の場合は
 * 1件も測れていないということなので、0%ではなくnull(数値を出さない)を返す。
 */
export function normalizedValue(website: LeadWebsiteResult, perspective: LeadPerspective): number | null {
  if (!MEASURED_STATUSES.includes(perspective.status)) {
    return null;
  }

  const category: CategoryScore | undefined = website.score.category_scores.find(
    (score) => score.key === perspective.key,
  );

  if (!category || category.max_available_score <= 0) {
    return null;
  }

  return Math.round((category.score / category.max_available_score) * 100);
}

/**
 * 観点の並び順と見出しは自社サイトのperspectivesをそのまま使う ―― 見出しの
 * 唯一の定義元はバックエンドのLeadMetricCatalog::PERSPECTIVE_HEADINGSであり、
 * フロント側で別の文言を持たない。
 */
export function buildComparisonRows(self: LeadWebsiteResult, competitor: LeadWebsiteResult | null): ComparisonRow[] {
  return self.perspectives.map((perspective) => {
    const counterpart = competitor?.perspectives.find((p) => p.key === perspective.key) ?? null;

    return {
      key: perspective.key,
      heading: perspective.heading,
      note: perspective.note,
      self: { perspective, value: normalizedValue(self, perspective) },
      competitor:
        competitor && counterpart
          ? { perspective: counterpart, value: normalizedValue(competitor, counterpart) }
          : null,
    };
  });
}

const TICKS = [0, 25, 50, 75, 100] as const;

function GridLines() {
  return (
    <span aria-hidden className="pointer-events-none absolute inset-y-0 left-0 right-0 block">
      {TICKS.map((tick) => (
        <span key={tick} className={cn("absolute -top-1 -bottom-1 block w-px", GRID_LINE)} style={{ left: `${tick}%` }} />
      ))}
    </span>
  );
}

/**
 * バー1本。値はバーの中ではなく右の固定幅カラムに置く ―― バーが短いときに
 * ラベルがはみ出したり、長いときに枠外へ溢れるのを構造的に防ぐ。
 */
function Bar({
  seriesLabel,
  series,
  perspectiveKey,
  value,
  barClass,
}: {
  seriesLabel: string;
  series: "self" | "competitor";
  perspectiveKey: LeadPerspectiveKey;
  value: number;
  barClass: string;
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="w-7 shrink-0 text-[11px] text-muted-foreground">{seriesLabel}</span>
      <span className="relative block h-3.5 flex-1">
        <GridLines />
        <span
          data-testid={`bar-${series}-${perspectiveKey}`}
          data-value={value}
          className={cn("absolute inset-y-0 left-0 block rounded-r-[4px]", barClass)}
          style={{ width: `${Math.max(0, Math.min(100, value))}%` }}
        />
      </span>
      <span className="w-8 shrink-0 text-right text-[11px] tabular-nums text-muted-foreground">{value}</span>
    </div>
  );
}

/**
 * 数値を出せない観点。長さ0のバーは描かない ―― バーが無いことは0点として
 * 読まれるため、罫線とテキストで「数値が出ていない」ことを明示する。
 * 理由(計測できませんでした/計測対象外/評価不可/該当なし)はすぐ上の状態行が
 * 担うので、ここでは繰り返さない。
 */
function UnmeasuredBar({ seriesLabel }: { seriesLabel: string }) {
  return (
    <div className="flex items-center gap-2">
      <span className="w-7 shrink-0 text-[11px] text-muted-foreground">{seriesLabel}</span>
      <span aria-hidden className={cn("block h-px w-6 shrink-0", UNMEASURED_RULE)} />
      <span className="text-[11px] text-muted-foreground">数値なし</span>
    </div>
  );
}

function LegendSwatch({ label, barClass }: { label: string; barClass: string }) {
  return (
    <span className="flex items-center gap-1.5">
      <span aria-hidden className={cn("size-2.5 rounded-[2px]", barClass)} />
      {label}
    </span>
  );
}

function formatRate(rate: number): string {
  return `${Math.round(rate)}%`;
}

/** 観点1つ分の項目内訳(既定で閉じている)。 */
function PerspectiveDetail({ row, showSiteLabels }: { row: ComparisonRow; showSiteLabels: boolean }) {
  const [open, setOpen] = useState(false);
  const cells = [
    { label: "自社サイト", cell: row.self },
    ...(row.competitor ? [{ label: "競合サイト", cell: row.competitor }] : []),
  ];
  const itemCount = cells.reduce((total, { cell }) => total + cell.perspective.items.length, 0);

  if (itemCount === 0) {
    return null;
  }

  return (
    <div>
      <Button variant="ghost" size="xs" aria-expanded={open} onClick={() => setOpen((prev) => !prev)}>
        {open ? "内訳を閉じる" : `内訳を見る(${itemCount}件)`}
        {open ? <ChevronUpIcon /> : <ChevronDownIcon />}
      </Button>

      {open && (
        <div className="mt-2 space-y-3 rounded-md border p-3">
          {cells.map(({ label, cell }) => (
            <div key={label} className="space-y-1.5">
              {showSiteLabels && <p className="text-[11px] text-muted-foreground">{label}</p>}
              {cell.perspective.items.length === 0 ? (
                <p className="text-xs text-muted-foreground">確認できた項目はありません。</p>
              ) : (
                <ul className="space-y-1.5">
                  {cell.perspective.items.map((item, i) => (
                    <li key={i} className="flex items-start justify-between gap-2 text-sm">
                      <span>{item.label}</span>
                      <PerspectiveStatusBadge status={item.status} />
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

export function LeadPerspectiveComparison({ websites }: { websites: LeadWebsiteResult[] }) {
  const [tableOpen, setTableOpen] = useState(false);

  const self = websites.find((w) => w.is_primary) ?? websites[0];
  const competitor = websites.find((w) => !w.is_primary) ?? null;

  if (!self) {
    return null;
  }

  const rows = buildComparisonRows(self, competitor);
  const selfCoverage = self.score.coverage_rate;
  const competitorCoverage = competitor?.score.coverage_rate ?? null;

  const isReferenceOnly =
    selfCoverage < COVERAGE_THRESHOLD || (competitorCoverage !== null && competitorCoverage < COVERAGE_THRESHOLD);
  const coverageGap =
    competitorCoverage !== null && Math.abs(selfCoverage - competitorCoverage) >= COVERAGE_GAP_THRESHOLD;

  return (
    <div className="space-y-4 rounded-md border p-4">
      <div className="space-y-1">
        <p className="text-sm font-medium">
          {competitor ? "4つの観点での比較" : "4つの観点での評価"}
          {isReferenceOnly && <span className="ml-2 text-xs font-normal text-muted-foreground">(参考値)</span>}
        </p>
        <p className="text-xs text-muted-foreground">
          それぞれの観点について、取得できた情報から算出した達成度です。数値の高さは「求職者に伝わる形で情報が置かれているか」を表すもので、企業としての優劣を示すものではありません。
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
        <LegendSwatch label={SELF_LABEL} barClass={SELF_BAR} />
        {competitor && <LegendSwatch label={COMPETITOR_LABEL} barClass={COMPETITOR_BAR} />}
        <span>0〜100で表しています(縦の目盛りは25刻み)</span>
      </div>

      {/* 画面が広いときは2列に折り返す ―― 1列のままだと観点ごとの注記が積み上がり、
          PCでは縦に間延びして全体像が1画面に収まらない(2026-07-30のユーザー指摘)。
          目盛りの数値行は置かない。2列にすると列ごとに軸が必要になり、
          そのぶん縦を消費するわりに、各バーの右端に実数が出ているため情報量が増えない。 */}
      <div className="grid gap-x-8 gap-y-5 lg:grid-cols-2">
        {rows.map((row) => (
          <div key={row.key} className="space-y-1.5">
            <p className="text-sm font-medium">{row.heading}</p>

            {/* 定性判定はバックエンドのstatusLabel()と同じ文言をそのまま出す。
                spanで囲むのは、画面とWord/PDFレポートで文言が食い違っていないことを
                テストから1語単位で検証できるようにするため。 */}
            <p className="text-xs text-muted-foreground">
              {SELF_LABEL}: <span>{PERSPECTIVE_STATUS_LABELS[row.self.perspective.status]}</span>
              {row.competitor && (
                <>
                  {" ／ "}
                  {COMPETITOR_LABEL}: <span>{PERSPECTIVE_STATUS_LABELS[row.competitor.perspective.status]}</span>
                </>
              )}
            </p>

            <div className="space-y-0.5">
              {row.self.value === null ? (
                <UnmeasuredBar seriesLabel={SELF_LABEL} />
              ) : (
                <Bar
                  seriesLabel={SELF_LABEL}
                  series="self"
                  perspectiveKey={row.key}
                  value={row.self.value}
                  barClass={SELF_BAR}
                />
              )}
              {row.competitor &&
                (row.competitor.value === null ? (
                  <UnmeasuredBar seriesLabel={COMPETITOR_LABEL} />
                ) : (
                  <Bar
                    seriesLabel={COMPETITOR_LABEL}
                    series="competitor"
                    perspectiveKey={row.key}
                    value={row.competitor.value}
                    barClass={COMPETITOR_BAR}
                  />
                ))}
            </div>

            {row.note && <p className="text-xs text-muted-foreground">{row.note}</p>}

            {row.self.perspective.summary && (
              <div>
                {competitor && <p className="text-[11px] text-muted-foreground">自社サイト</p>}
                <p className="text-sm">{row.self.perspective.summary}</p>
              </div>
            )}
            {row.competitor?.perspective.summary && (
              <div>
                <p className="text-[11px] text-muted-foreground">競合サイト</p>
                <p className="text-sm">{row.competitor.perspective.summary}</p>
              </div>
            )}

            <PerspectiveDetail row={row} showSiteLabels={competitor !== null} />
          </div>
        ))}
      </div>

      <div className="space-y-1 border-t pt-3 text-xs text-muted-foreground">
        <p>
          取得できなかった項目は0点として扱わず、算出の対象から外しています(取得率: {SELF_LABEL}{" "}
          {formatRate(selfCoverage)}
          {competitorCoverage !== null && ` ／ ${COMPETITOR_LABEL} ${formatRate(competitorCoverage)}`})。
        </p>
        {coverageGap && <p>2社で取得できた情報の量が異なるため、この数値をそのまま並べての比較はできません。</p>}
        {isReferenceOnly && <p>取得できた情報が限られているため、参考値としてご確認ください。</p>}
      </div>

      <div>
        <Button variant="outline" size="sm" aria-expanded={tableOpen} onClick={() => setTableOpen((open) => !open)}>
          {tableOpen ? "表を閉じる" : "数値を表で見る"}
        </Button>
        {tableOpen && (
          <Table className="mt-3">
            <TableHeader>
              <TableRow>
                <TableHead>観点</TableHead>
                <TableHead className="text-right">{SELF_LABEL}</TableHead>
                {competitor && <TableHead className="text-right">{COMPETITOR_LABEL}</TableHead>}
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.key}>
                  <TableCell className="whitespace-normal">{row.heading}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {row.self.value === null
                      ? PERSPECTIVE_STATUS_LABELS[row.self.perspective.status]
                      : row.self.value}
                  </TableCell>
                  {competitor && (
                    <TableCell className="text-right tabular-nums">
                      {row.competitor === null
                        ? "―"
                        : row.competitor.value === null
                          ? PERSPECTIVE_STATUS_LABELS[row.competitor.perspective.status]
                          : row.competitor.value}
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </div>
    </div>
  );
}

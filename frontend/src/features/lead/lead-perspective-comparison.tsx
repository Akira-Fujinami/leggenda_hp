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
 * 4観点を自社/競合で重ねて見せる比較ブロック(レーダー/多角形チャート)。
 *
 * 背景(2026-08-02のユーザー要望): 観点ごとに自社・競合の棒を並べていた従来の
 * 表示を、2社の形を重ねて全体像を一目で比べられる多角形(レーダー)チャートに
 * 置き換えた。観点は4つなので図形は四角形になる。
 *
 * 以前この比較は「レーダーにしない」方針だった ―― 4観点は独立した項目で軸の
 * 並び順に意味がなく、順序を変えるだけで面積が変わり、実際の差より大きく/
 * 小さく見えるため。今回それをユーザー判断で上書きするにあたり、その懸念自体は
 * 消えないので、次の形で従来と同じ誠実性を保つ:
 *
 * 1. 面積の大小を優劣として読ませない。図の下に「軸の順序に意味はなく、面積は
 *    全体像をつかむための目安」である旨を明記する。
 * 2. 分母はconfigured_max_scoreではなくmax_available_scoreを使う。
 *    configured_max_scoreを分母にすると、取得できなかった項目がある分だけ
 *    達成度が目減りし、「未取得の指標を0点として扱わない」原則を破る。
 * 3. 取得できなかった観点は0の頂点を打たない。頂点を開けたまま(その軸に線を
 *    繋がずに)描き、数値は「数値なし」と明示する ―― 頂点が無い=0点として
 *    読まれないよう、多角形をその角で開ける。
 * 4. 自社のカバー率と競合のカバー率が異なると、それぞれ自分の取得範囲で正規化
 *    した数値が同じ図に重なる。そのためカバー率を必ず併記し、差が大きい場合は
 *    そのまま並べて比較できない旨を注記する。
 * 5. 色だけで系列を区別しない。自社=実線+丸、競合=点線+ひし形の頂点にし、
 *    凡例と各観点の数値(本文テキスト)を併記する。良し悪しを示す緑・赤は
 *    使わない(点数の高低は情報の置き方の話であり、企業としての優劣ではない)。
 * 6. 注記(note)・要約(summary)・正確な数値表は従来どおり残す。SVGは装飾
 *    (aria-hidden)とし、数値は本文テキストと数値表で読める状態を保つ ―― 図が
 *    読めない環境でも同じ情報にたどり着けるようにする。
 */

// 2系統(自社/競合)の識別色。色覚特性のシミュレーションを含む検証済みの値で、
// ブランド・ホイールのヘキサゴンと同一 ―― 片方だけ変更しないこと。
// 凡例のスウォッチ(背景色)用。
const SELF_BAR = "bg-[#2a78d6] dark:bg-[#3987e5]";
const COMPETITOR_BAR = "bg-[#eb6834] dark:bg-[#d95926]";

// レーダー(SVG)用の同一色。stroke=輪郭線、fill=塗り(薄く)、mark=頂点。
const SERIES_STYLE = {
  self: {
    stroke: "stroke-[#2a78d6] dark:stroke-[#3987e5]",
    fill: "fill-[#2a78d6]/15 dark:fill-[#3987e5]/20",
    mark: "fill-[#2a78d6] dark:fill-[#3987e5]",
    dash: undefined as string | undefined,
    marker: "circle" as const,
  },
  competitor: {
    stroke: "stroke-[#eb6834] dark:stroke-[#d95926]",
    fill: "fill-[#eb6834]/15 dark:fill-[#d95926]/20",
    mark: "fill-[#eb6834] dark:fill-[#d95926]",
    dash: "5 4" as string | undefined,
    marker: "diamond" as const,
  },
} as const;

const GRID_STROKE = "stroke-[#e1e0d9] dark:stroke-[#2c2c2a]";

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
 * フロント側で別の文言を持たない。レーダーの軸(頂点)の並び順もこの順序に従う。
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

// --- レーダー(多角形)チャートの描画 ---------------------------------------
//
// SVGは装飾(aria-hidden)。同じ数値は各観点の本文テキストと数値表で読めるため、
// スクリーンリーダー向けには重複読み上げを避けてSVGを隠す。

const VIEWBOX = 232;
const CENTER = VIEWBOX / 2; // 116
const RADIUS = 88; // 100%の頂点までの半径
const RING_TICKS = [25, 50, 75, 100] as const; // 25刻みのグリッド(0は中心)
const LABEL_OFFSET = 15; // 軸番号を外周のさらに外に置く

interface RadarAxis {
  key: LeadPerspectiveKey;
  index: number;
  unit: { x: number; y: number };
  value: number | null;
}

/** 軸iの単位ベクトル(真上を起点に時計回り。y軸は画面座標で下向き)。 */
function axisUnit(index: number, count: number): { x: number; y: number } {
  const angle = ((-90 + (360 * index) / count) * Math.PI) / 180;
  return { x: Math.cos(angle), y: Math.sin(angle) };
}

/** 単位ベクトル×達成度(0〜100)を画面座標に変換する。 */
function toXY(unit: { x: number; y: number }, value: number, radius = RADIUS): { x: number; y: number } {
  const r = (radius * value) / 100;
  return { x: CENTER + unit.x * r, y: CENTER + unit.y * r };
}

function pointsAttr(axes: RadarAxis[], value: number): string {
  return axes
    .map((axis) => {
      const p = toXY(axis.unit, value);
      return `${p.x.toFixed(1)},${p.y.toFixed(1)}`;
    })
    .join(" ");
}

/** 頂点マーカー。自社=丸、競合=ひし形 ―― 色だけに頼らず形でも系列を区別する。 */
function Vertex({
  series,
  axisKey,
  value,
  point,
}: {
  series: "self" | "competitor";
  axisKey: LeadPerspectiveKey;
  value: number;
  point: { x: number; y: number };
}) {
  const style = SERIES_STYLE[series];
  const testId = `vertex-${series}-${axisKey}`;

  if (style.marker === "diamond") {
    const d = 3.4;
    const pts = `${point.x},${point.y - d} ${point.x + d},${point.y} ${point.x},${point.y + d} ${point.x - d},${point.y}`;
    return <polygon data-testid={testId} data-value={value} points={pts} className={style.mark} />;
  }

  return <circle data-testid={testId} data-value={value} cx={point.x} cy={point.y} r={2.9} className={style.mark} />;
}

/**
 * 1系列(自社 or 競合)の多角形。
 *
 * 全4観点が測れている場合は閉じた多角形(塗りあり)。1つでも数値なしの観点が
 * あれば、その角では線を繋がず「頂点を開けたまま」描く(塗りは付けない) ――
 * 未取得の観点を0点として面積に含めないため。
 */
function RadarSeries({ axes, series }: { axes: RadarAxis[]; series: "self" | "competitor" }) {
  const style = SERIES_STYLE[series];
  const count = axes.length;
  const allMeasured = axes.every((axis) => axis.value !== null);

  return (
    <g>
      {allMeasured ? (
        <polygon
          points={axes.map((axis) => `${toXY(axis.unit, axis.value!).x.toFixed(1)},${toXY(axis.unit, axis.value!).y.toFixed(1)}`).join(" ")}
          strokeWidth={2}
          strokeLinejoin="round"
          strokeDasharray={style.dash}
          className={cn(style.fill, style.stroke)}
        />
      ) : (
        axes.map((axis, i) => {
          const next = axes[(i + 1) % count]!;
          if (axis.value === null || next.value === null) {
            return null; // 未取得の観点に隣接する辺は引かない ―― 角を開けたままにする
          }
          const from = toXY(axis.unit, axis.value);
          const to = toXY(next.unit, next.value);
          return (
            <line
              key={`edge-${axis.key}`}
              x1={from.x}
              y1={from.y}
              x2={to.x}
              y2={to.y}
              strokeWidth={2}
              strokeLinecap="round"
              strokeDasharray={style.dash}
              className={style.stroke}
            />
          );
        })
      )}

      {axes.map((axis) =>
        axis.value === null ? null : (
          <Vertex
            key={axis.key}
            series={series}
            axisKey={axis.key}
            value={axis.value}
            point={toXY(axis.unit, axis.value)}
          />
        ),
      )}
    </g>
  );
}

/**
 * 4観点を重ねたレーダー(多角形)チャート。中心が0、外周が100。
 * 数値そのものは各観点の本文テキストと数値表で読めるため、図には頂点の番号
 * (下の観点に対応)だけを添える。
 */
function PerspectiveRadar({ rows, hasCompetitor }: { rows: ComparisonRow[]; hasCompetitor: boolean }) {
  const count = rows.length;
  if (count === 0) {
    return null;
  }

  const selfAxes: RadarAxis[] = rows.map((row, index) => ({
    key: row.key,
    index,
    unit: axisUnit(index, count),
    value: row.self.value,
  }));
  const competitorAxes: RadarAxis[] | null = hasCompetitor
    ? rows.map((row, index) => ({
        key: row.key,
        index,
        unit: axisUnit(index, count),
        value: row.competitor?.value ?? null,
      }))
    : null;

  return (
    <svg
      aria-hidden
      viewBox={`0 0 ${VIEWBOX} ${VIEWBOX}`}
      className="mx-auto block h-auto w-full max-w-[280px]"
    >
      {/* グリッド(25刻みの同心多角形) */}
      {RING_TICKS.map((tick) => (
        <polygon key={tick} points={pointsAttr(selfAxes, tick)} fill="none" strokeWidth={1} className={GRID_STROKE} />
      ))}

      {/* 各軸のスポーク(中心→外周) */}
      {selfAxes.map((axis) => {
        const tip = toXY(axis.unit, 100);
        return <line key={`spoke-${axis.key}`} x1={CENTER} y1={CENTER} x2={tip.x} y2={tip.y} strokeWidth={1} className={GRID_STROKE} />;
      })}

      {/* 競合を先に描いて自社を上に重ねる */}
      {competitorAxes && <RadarSeries axes={competitorAxes} series="competitor" />}
      <RadarSeries axes={selfAxes} series="self" />

      {/* 軸番号(下の各観点に対応) */}
      {selfAxes.map((axis) => {
        const tip = {
          x: CENTER + axis.unit.x * (RADIUS + LABEL_OFFSET),
          y: CENTER + axis.unit.y * (RADIUS + LABEL_OFFSET),
        };
        return (
          <text
            key={`num-${axis.key}`}
            x={tip.x}
            y={tip.y}
            textAnchor="middle"
            dominantBaseline="central"
            className="fill-current text-[11px] font-medium text-muted-foreground"
          >
            {axis.index + 1}
          </text>
        );
      })}
    </svg>
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

/** 観点1つ分の達成度(数値)。数値なしの観点は0点として読ませない文言にする。 */
function PerspectiveValue({ row }: { row: ComparisonRow }) {
  return (
    <p className="text-xs text-muted-foreground">
      {SELF_LABEL} <span className="tabular-nums">{row.self.value ?? "数値なし"}</span>
      {row.competitor && (
        <>
          {" ／ "}
          {COMPETITOR_LABEL} <span className="tabular-nums">{row.competitor.value ?? "数値なし"}</span>
        </>
      )}
    </p>
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

  // 頂点を開けている(数値なしの)観点が1つでもあるか ―― あれば図の下で明示する。
  const hasOpenVertex = rows.some((row) => row.self.value === null || (row.competitor && row.competitor.value === null));

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
        <LegendSwatch label={`${SELF_LABEL}(実線・丸)`} barClass={SELF_BAR} />
        {competitor && <LegendSwatch label={`${COMPETITOR_LABEL}(点線・ひし形)`} barClass={COMPETITOR_BAR} />}
        <span>中心が0・外周が100(外側ほど高い/25刻み)</span>
      </div>

      <PerspectiveRadar rows={rows} hasCompetitor={competitor !== null} />

      <div className="space-y-1 text-xs text-muted-foreground">
        <p>各頂点の番号①〜④は、下に並ぶ4つの観点にそのまま対応しています。</p>
        <p>
          4つの観点は独立した項目で、軸の並び順に特別な意味はありません。多角形の面積は全体像をつかむための目安で、面積の大きさそのものが企業の優劣を示すものではありません。
        </p>
        {hasOpenVertex && (
          <p>数値が取得できなかった観点は、頂点を置かずに線を開けています(0点ではありません)。</p>
        )}
      </div>

      {/* 画面が広いときは2列に折り返す ―― 1列のままだと観点ごとの注記が積み上がり、
          PCでは縦に間延びして全体像が1画面に収まらない(2026-07-30のユーザー指摘)。
          先頭の番号はレーダーの軸番号と対応する。 */}
      <div className="grid gap-x-8 gap-y-5 lg:grid-cols-2">
        {rows.map((row, index) => (
          <div key={row.key} className="space-y-1.5">
            <p className="flex items-center gap-1.5 text-sm font-medium">
              <span
                aria-hidden
                className="inline-flex size-4 shrink-0 items-center justify-center rounded-full border text-[10px] font-normal text-muted-foreground"
              >
                {index + 1}
              </span>
              {row.heading}
            </p>

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

            <PerspectiveValue row={row} />

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

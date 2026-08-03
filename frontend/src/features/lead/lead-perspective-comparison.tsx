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
 * 1. 分母はconfigured_max_scoreではなくmax_available_scoreを使う。
 *    configured_max_scoreは「このリリースで何点満点か」という設計上固定の値で、
 *    実測できたかどうかに左右されない ―― これを分母にすると、取得できなかった
 *    項目がある分だけ達成度が目減りし、「未取得の指標を0点として扱わない」
 *    という原則を集計レベルで破ることになる。
 * 2. 取得できなかった観点を0として描かない。レーダーでは特に危険で、
 *    中心に落ちた頂点は「その観点が壊滅的に悪い」と読まれる。実際は
 *    「測れなかった」であって「0」ではないため、その軸は図から除外し、
 *    除外したこと自体を図の下に明記する。
 * 3. 2は同時に別の問題を生む。自社のカバー率50%・競合95%のとき、それぞれ
 *    自分の取得範囲で正規化した数値が同じ軸に並び、直接比較できる形で見えて
 *    しまう。そのためカバー率を必ず併記し、差が大きい場合は注記を出す。
 * 4. 色だけで系列を区別しない。凡例に加えて、観点ごとに「自社」「競合」の
 *    ラベルと数値をテキストで併記する。良し悪しを示す緑・赤は使わない
 *    (点数の高低は情報の置き方の話であり、企業としての優劣ではない)。
 * 5. 注記(note)と要約(summary)は折りたたみの外に置く。④の「配色やレイアウトの
 *    見た目そのものの印象は自動判定の対象外」のような但し書きが隠れていると、
 *    数値をデザインの評価だと誤読される。
 * 6. 軸の並び順はバックエンドのperspectives順に固定する。レーダーは軸の
 *    並べ替えで面積が変わるため、順序を可変にすると見え方を操作できてしまう。
 */

// 2系統(自社/競合)の識別色。色覚特性のシミュレーションを含む検証済みの値で、
// ブランド・ホイールのヘキサゴンと同一 ―― 片方だけ変更しないこと。
const SELF_MARK = "bg-[#2a78d6] dark:bg-[#3987e5]";
const COMPETITOR_MARK = "bg-[#eb6834] dark:bg-[#d95926]";
const SELF_SVG = "fill-[#2a78d6] stroke-[#2a78d6] dark:fill-[#3987e5] dark:stroke-[#3987e5]";
const COMPETITOR_SVG = "fill-[#eb6834] stroke-[#eb6834] dark:fill-[#d95926] dark:stroke-[#d95926]";
const RING_SVG = "stroke-[#e1e0d9] dark:stroke-[#2c2c2a]";
const SURFACE_SVG = "stroke-[#fcfcfb] dark:stroke-[#1a1a19]";

/** 達成度を数値で出せるのはこの3状態のときだけ(それ以外は未取得系)。 */
const MEASURED_STATUSES: readonly LeadPerspectiveStatus[] = ["good", "needs_review", "needs_improvement"];

/** カバー率がこれ以上離れていたら、単純比較できない旨を注記する。 */
export const COVERAGE_GAP_THRESHOLD = 20;

const SELF_LABEL = "自社";
const COMPETITOR_LABEL = "競合";

/** 図の頂点と観点の対応を示す番号(見出しは長いので図には出さない)。 */
const AXIS_NUMBERS = ["①", "②", "③", "④", "⑤", "⑥"] as const;

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
  /**
   * この観点が「そもそも点数を持たない」ものかどうか。
   *
   * ①(書くべきことが書けているか)に属する指標はすべてscoring_type=not_scored /
   * points=0で登録されており、configured_max_scoreが常に0になる。
   * 「測れなかったので数値が出ない」のとは意味がまったく違うため、
   * 表示でも図でも区別する(誠実性の維持と同じ考え方)。
   */
  unscored: boolean;
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
 * その観点が設計上そもそも点数を持たないか。configured_max_scoreは
 * 「このリリースで何点満点か」という固定値なので、これが0ということは
 * 採点対象の指標が1件も割り当てられていないことを意味する。
 */
export function isUnscoredPerspective(website: LeadWebsiteResult, perspective: LeadPerspective): boolean {
  const category = website.score.category_scores.find((score) => score.key === perspective.key);

  return category !== undefined && category.configured_max_score <= 0;
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
      self: {
        perspective,
        value: normalizedValue(self, perspective),
        unscored: isUnscoredPerspective(self, perspective),
      },
      competitor:
        competitor && counterpart
          ? {
              perspective: counterpart,
              value: normalizedValue(competitor, counterpart),
              unscored: isUnscoredPerspective(competitor, counterpart),
            }
          : null,
    };
  });
}

// --- レーダー図 ---

const RADAR_CX = 150;
const RADAR_CY = 138;
const RADAR_R = 92;
const RADAR_RINGS = [25, 50, 75, 100] as const;

/** 軸iの、中心からの割合value(0〜100)にあたる座標。0時方向を起点に時計回り。 */
export function radarPoint(index: number, axisCount: number, value: number): { x: number; y: number } {
  const angle = ((-90 + (360 / axisCount) * index) * Math.PI) / 180;
  const radius = (RADAR_R * Math.max(0, Math.min(100, value))) / 100;

  return { x: RADAR_CX + Math.cos(angle) * radius, y: RADAR_CY + Math.sin(angle) * radius };
}

function toPoints(coords: { x: number; y: number }[]): string {
  return coords.map(({ x, y }) => `${x.toFixed(1)},${y.toFixed(1)}`).join(" ");
}

/**
 * 系列1つ分の面。数値のない軸は頂点を作らない ―― 0として描くと
 * 「その観点が壊滅的に悪い」と読まれるため。残った頂点が2点以下のときは
 * 面にせず、線または点のまま描く(存在しない面積を作らない)。
 */
function RadarSeries({
  values,
  colorClass,
  series,
}: {
  values: (number | null)[];
  colorClass: string;
  series: "self" | "competitor";
}) {
  const coords = values
    .map((value, index) => (value === null ? null : { index, ...radarPoint(index, values.length, value) }))
    .filter((point): point is { index: number; x: number; y: number } => point !== null);

  if (coords.length === 0) {
    return null;
  }

  return (
    <g data-testid={`radar-${series}`} data-points={coords.length}>
      {coords.length >= 3 ? (
        <polygon
          points={toPoints(coords)}
          className={colorClass}
          fillOpacity={0.18}
          strokeWidth={2}
          strokeLinejoin="round"
        />
      ) : (
        <polyline points={toPoints(coords)} className={colorClass} fill="none" strokeWidth={2} />
      )}
      {coords.map((point) => (
        <circle key={point.index} cx={point.x} cy={point.y} r={3.5} className={colorClass} strokeWidth={0} />
      ))}
      {coords.map((point) => (
        <circle
          key={`ring-${point.index}`}
          cx={point.x}
          cy={point.y}
          r={3.5}
          fill="none"
          className={SURFACE_SVG}
          strokeWidth={1.5}
        />
      ))}
    </g>
  );
}

function RadarChart({ rows, hasCompetitor }: { rows: ComparisonRow[]; hasCompetitor: boolean }) {
  const axisCount = rows.length;

  if (axisCount < 3) {
    return null;
  }

  const selfValues = rows.map((row) => row.self.value);
  const competitorValues = rows.map((row) => row.competitor?.value ?? null);
  const missing = rows
    .map((row, index) => ({ row, index }))
    .filter(({ row }) => row.self.value === null && (row.competitor === null || row.competitor.value === null));
  const unscored = missing.filter(({ row }) => row.self.unscored);
  const unmeasured = missing.filter(({ row }) => !row.self.unscored);

  return (
    <div className="space-y-2">
      <svg
        viewBox="0 0 300 276"
        role="img"
        aria-label="4つの観点の達成度を自社と競合で重ねた図。各観点の数値は下の一覧に記載しています。"
        className="mx-auto h-auto w-full max-w-[300px]"
      >
        {RADAR_RINGS.map((ring) => (
          <polygon
            key={ring}
            points={toPoints(rows.map((_, index) => radarPoint(index, axisCount, ring)))}
            fill="none"
            className={RING_SVG}
            strokeWidth={1}
          />
        ))}
        {rows.map((_, index) => {
          const outer = radarPoint(index, axisCount, 100);

          return (
            <line
              key={index}
              x1={RADAR_CX}
              y1={RADAR_CY}
              x2={outer.x}
              y2={outer.y}
              className={RING_SVG}
              strokeWidth={1}
            />
          );
        })}

        <RadarSeries values={selfValues} colorClass={SELF_SVG} series="self" />
        {hasCompetitor && <RadarSeries values={competitorValues} colorClass={COMPETITOR_SVG} series="competitor" />}

        {rows.map((_, index) => {
          const outer = radarPoint(index, axisCount, 118);

          return (
            <text
              key={index}
              x={outer.x}
              y={outer.y + 4}
              textAnchor="middle"
              className="fill-muted-foreground text-[11px]"
            >
              {AXIS_NUMBERS[index]}
            </text>
          );
        })}
      </svg>

      {unscored.length > 0 && (
        <p className="text-center text-xs text-muted-foreground">
          {unscored.map(({ index }) => AXIS_NUMBERS[index]).join("")}
          は点数をつけない観点のため、図には含めていません（内容は下に記載しています）。
        </p>
      )}
      {unmeasured.length > 0 && (
        <p className="text-center text-xs text-muted-foreground">
          {unmeasured.map(({ index }) => AXIS_NUMBERS[index]).join("")}
          は数値が出ていないため、図に含まれていません。
        </p>
      )}
    </div>
  );
}

function LegendSwatch({ label, markClass }: { label: string; markClass: string }) {
  return (
    <span className="flex items-center gap-1.5">
      <span aria-hidden className={cn("size-2.5 rounded-[2px]", markClass)} />
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
        <LegendSwatch label={SELF_LABEL} markClass={SELF_MARK} />
        {competitor && <LegendSwatch label={COMPETITOR_LABEL} markClass={COMPETITOR_MARK} />}
        <span>外側ほど高く、目盛りは25刻みです(0〜100)</span>
      </div>

      {/* 画面が広いときは図を左、観点ごとの内訳を右に置く ―― 図を単独で中央に
          置くと左右に大きな余白ができ、そのぶん縦に伸びる(2026-07-30のユーザー指摘)。
          図と観点の対応は番号(①〜④)で示す。見出しをそのまま軸ラベルにすると
          長すぎて図の外へはみ出すうえ、内部名(label)は画面に出さない方針のため。 */}
      <div className="grid items-start gap-6 lg:grid-cols-[300px_1fr]">
        <RadarChart rows={rows} hasCompetitor={competitor !== null} />

        <div className="space-y-5">
          {rows.map((row, index) => (
              <div key={row.key} className="space-y-1.5">
              <p className="text-sm font-medium">
                <span className="text-muted-foreground">{AXIS_NUMBERS[index]}</span>
                {row.heading}
              </p>

              {/* 定性判定はバックエンドのstatusLabel()と同じ文言をそのまま出す。
                  spanで囲むのは、画面とWord/PDFレポートで文言が食い違っていないことを
                  テストから1語単位で検証できるようにするため。 */}
              <p className="text-xs text-muted-foreground">
                {SELF_LABEL}: <span>{PERSPECTIVE_STATUS_LABELS[row.self.perspective.status]}</span>(
                <span data-testid={`value-self-${row.key}`}>
                  {row.self.value === null ? "数値なし" : row.self.value}
                </span>
                )
                {row.competitor && (
                  <>
                    {" ／ "}
                    {COMPETITOR_LABEL}: <span>{PERSPECTIVE_STATUS_LABELS[row.competitor.perspective.status]}</span>(
                    <span data-testid={`value-competitor-${row.key}`}>
                      {row.competitor.value === null ? "数値なし" : row.competitor.value}
                    </span>
                    )
                  </>
                )}
              </p>

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

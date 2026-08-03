import { cn } from "@/lib/utils";
import type {
  BrandWheelAxis,
  BrandWheelComparison,
  BrandWheelGroup,
  BrandWheelResult,
  LeadWebsiteResult,
} from "@/types/lead";

/**
 * 採用ブランドの6軸(ブランド・ホイール)で自社と競合を比較するブロック。
 * リード向け診断画面の主役。社内資料のスライド構成をそのまま画面にしている。
 *
 * 設計上の制約:
 *
 * 1. 軸の満点(max_count)はAPIが軸ごとに返す値をそのまま使う。
 *    config/brand_wheel.phpのsub_elements件数から算出されるため、
 *    将来4から5に変わり得るし、軸ごとに異なり得る。
 *    フロント側で4や5を固定値として持たない。
 * 2. status !== "success" のとき図を描かない。6軸すべて0件の図は
 *    「魅力のない会社」に見えるが、実際は「読み取れなかった」であり、
 *    まったく別のことを言っている(この診断で最も誤解を招く箇所)。
 *    理由の文言(status_message)はバックエンドが唯一の定義元。
 * 3. 軸の並び順はAPIが返す順序をそのまま使う。レーダーは軸を並べ替えると
 *    面積が変わるため、フロント側で並べ替えない。
 * 4. 件数は図だけでなくテキストでも必ず出す。色と面積だけで伝えない。
 * 5. 【自社ページ】【他社ページ】【ワンポイント】はバックエンドが件数から
 *    機械的に導出したものをそのまま表示する。フロント側で文言を作らない。
 */

// 3グループの識別色。社内資料の青／緑／赤に対応する。
// 系列色(自社=青・競合=橙)とは役割が違うため、グループ色は
// 見出しの帯にだけ使い、レーダーの面には使わない。
const GROUP_STYLES: Record<BrandWheelGroup, { label: string; band: string; bar: string }> = {
  company_appeal: { label: "青／会社の魅力", band: "bg-[#3f6fa3]", bar: "bg-[#3f6fa3]" },
  company_distance: { label: "緑／会社との距離", band: "bg-[#4a7d5f]", bar: "bg-[#4a7d5f]" },
  job_appeal: { label: "赤／仕事の魅力", band: "bg-[#a3413c]", bar: "bg-[#a3413c]" },
};

const GROUP_ORDER: BrandWheelGroup[] = ["company_appeal", "company_distance", "job_appeal"];

// 2系統(自社/競合)の識別色。ブランド・ホイールのヘキサゴン・4観点の図と同一。
const SELF_HEX = "#2a78d6";
const COMPETITOR_HEX = "#eb6834";
const NAVY = "bg-[#33587f]";

const CX = 190;
const CY = 138;
const R = 100;
const RINGS = 4;

/** 軸iの、達成割合ratio(0〜1)にあたる座標。0時方向を起点に時計回り。 */
export function wheelPoint(index: number, axisCount: number, ratio: number): { x: number; y: number } {
  const angle = ((-90 + (360 / axisCount) * index) * Math.PI) / 180;
  const radius = R * Math.max(0, Math.min(1, ratio));

  return { x: CX + Math.cos(angle) * radius, y: CY + Math.sin(angle) * radius };
}

/**
 * 軸ごとの達成割合。max_countが0の軸は割合を出せないので0として扱わず、
 * 図では中心に落とさない(呼び出し側でnullを弾く)。
 */
export function axisRatio(axis: BrandWheelAxis): number | null {
  if (axis.max_count <= 0) {
    return null;
  }

  return axis.matched_count / axis.max_count;
}

function polygonPoints(axes: BrandWheelAxis[], ratios: (number | null)[]): string | null {
  const coords = ratios
    .map((ratio, index) => (ratio === null ? null : wheelPoint(index, axes.length, ratio)))
    .filter((point): point is { x: number; y: number } => point !== null);

  if (coords.length < 3) {
    return null;
  }

  return coords.map(({ x, y }) => `${x.toFixed(1)},${y.toFixed(1)}`).join(" ");
}

function WheelSeries({ axes, color, series }: { axes: BrandWheelAxis[]; color: string; series: string }) {
  const ratios = axes.map(axisRatio);
  const points = polygonPoints(axes, ratios);

  if (points === null) {
    return null;
  }

  return (
    <g data-testid={`wheel-${series}`}>
      <polygon
        points={points}
        fill={color}
        fillOpacity={0.16}
        stroke={color}
        strokeWidth={2}
        strokeLinejoin="round"
      />
      {ratios.map((ratio, index) => {
        if (ratio === null) return null;
        const { x, y } = wheelPoint(index, axes.length, ratio);

        return (
          <g key={index}>
            <circle cx={x} cy={y} r={3.2} fill={color} />
            <circle cx={x} cy={y} r={3.2} fill="none" stroke="#ffffff" strokeWidth={1.4} />
          </g>
        );
      })}
    </g>
  );
}

function Wheel({ axes, competitorAxes }: { axes: BrandWheelAxis[]; competitorAxes: BrandWheelAxis[] | null }) {
  const count = axes.length;

  return (
    <svg
      viewBox="0 0 380 276"
      role="img"
      aria-label="採用ブランドの6軸について、該当する内容が何件読み取れたかを自社と競合で重ねた図。件数は下の一覧に記載しています。"
      className="mx-auto h-auto w-full max-w-[380px]"
    >
      {Array.from({ length: RINGS }, (_, i) => (i + 1) / RINGS).map((ratio) => (
        <polygon
          key={ratio}
          points={axes
            .map((_, index) => {
              const { x, y } = wheelPoint(index, count, ratio);

              return `${x.toFixed(1)},${y.toFixed(1)}`;
            })
            .join(" ")}
          fill="none"
          stroke="#d8d8d8"
          strokeWidth={1}
        />
      ))}
      {axes.map((_, index) => {
        const outer = wheelPoint(index, count, 1);

        return <line key={index} x1={CX} y1={CY} x2={outer.x} y2={outer.y} stroke="#d8d8d8" strokeWidth={1} />;
      })}

      <WheelSeries axes={axes} color={SELF_HEX} series="self" />
      {competitorAxes && <WheelSeries axes={competitorAxes} color={COMPETITOR_HEX} series="competitor" />}

      {axes.map((axis, index) => {
        const label = wheelPoint(index, count, 1.28);
        const cos = Math.cos(((-90 + (360 / count) * index) * Math.PI) / 180);
        const anchor = Math.abs(cos) < 0.2 ? "middle" : cos > 0 ? "start" : "end";

        return (
          <text key={axis.key} x={label.x} y={label.y + 4} textAnchor={anchor} fontSize={10.5} fill="#5b5b5b">
            {axis.name}
          </text>
        );
      })}
    </svg>
  );
}

function LegendSwatch({ label, color }: { label: string; color: string }) {
  return (
    <span className="flex items-center gap-1.5">
      <span aria-hidden className="size-2.5 rounded-[2px]" style={{ backgroundColor: color }} />
      {label}
    </span>
  );
}

/**
 * 軸1つ分のセル。見出しはスライドと同じ淡いグレー地にする ―― グループの色は
 * 上のグループ帯が担う。ただしグループ帯は6列に並ぶ画面幅でしか出せないため、
 * 帯が消える幅でもどのグループの軸かが分かるよう、軸名の前にグループ色の
 * 小さな四角を置く(スライドからの唯一の意図的な差分)。
 */
function AxisCell({ axis }: { axis: BrandWheelAxis }) {
  return (
    <div className="flex flex-col overflow-hidden rounded-md border">
      <p className="flex items-center justify-center gap-1.5 border-b bg-muted px-2 py-1.5 text-center text-xs font-semibold">
        <span aria-hidden className={cn("size-2 shrink-0 rounded-[2px] lg:hidden", GROUP_STYLES[axis.group].bar)} />
        {axis.name}
      </p>
      <div className="flex flex-1 flex-col gap-1.5 p-2.5">
        <p className="text-[11px] text-muted-foreground">
          読み取れた内容{" "}
          <span data-testid={`wheel-count-${axis.key}`} className="tabular-nums">
            {axis.matched_count} / {axis.max_count}件
          </span>
        </p>
        {axis.matched_sub_elements.length > 0 ? (
          <ul className="list-disc space-y-0.5 pl-4 text-[11px] leading-relaxed">
            {axis.matched_sub_elements.map((sub) => (
              <li key={sub.key}>{sub.name}</li>
            ))}
          </ul>
        ) : (
          <p className="text-[11px] text-muted-foreground">読み取れた内容はありません</p>
        )}
      </div>
    </div>
  );
}

/** 図を出せない場合。理由はバックエンドの文言をそのまま出す。 */
function UnavailableNotice({ result }: { result: BrandWheelResult }) {
  return (
    <div data-testid="wheel-unavailable" className="rounded-md border border-dashed p-4">
      <p className="text-sm">{result.status_message}</p>
      <p className="mt-2 text-xs text-muted-foreground">
        6つの項目すべてが0件の図は「魅力のない会社」という意味に読めてしまうため、この場合は図を出していません。
      </p>
    </div>
  );
}

export function LeadBrandWheel({
  websites,
  comparison,
}: {
  websites: LeadWebsiteResult[];
  comparison?: BrandWheelComparison | null;
}) {
  const self = websites.find((w) => w.is_primary) ?? websites[0];
  const competitor = websites.find((w) => !w.is_primary) ?? null;
  const selfWheel = self?.brand_wheel ?? null;
  const competitorWheel = competitor?.brand_wheel ?? null;

  if (!self || !selfWheel) {
    return null;
  }

  const readable = selfWheel.status === "success" && selfWheel.axes.length > 0;
  const competitorAxes =
    competitorWheel && competitorWheel.status === "success" && competitorWheel.axes.length > 0
      ? competitorWheel.axes
      : null;

  return (
    <div className="space-y-4">
      <div className="space-y-4 rounded-md border p-4">
        <p className="text-sm font-medium">自社ページの分析結果</p>

        {!readable ? (
          <UnavailableNotice result={selfWheel} />
        ) : (
          <>
            <div className="grid items-center gap-5 lg:grid-cols-[140px_1fr_380px]">
              <p
                className={cn(
                  "mx-auto flex h-[126px] w-[140px] items-center justify-center px-4 text-center text-[10px] leading-relaxed text-white",
                  NAVY,
                  "[clip-path:polygon(25%_0,75%_0,100%_50%,75%_100%,25%_100%,0_50%)]",
                )}
              >
                各項目について
                <br />
                該当する内容が
                <br />
                何件読み取れたかを
                <br />
                集計しています
              </p>

              <div>
                <p className="text-xs font-semibold">サマリー</p>
                <ul className="mt-1 list-disc space-y-1 pl-4 text-xs leading-relaxed">
                  {selfWheel.analyzed_url && <li>解析したURL：{selfWheel.analyzed_url}</li>}
                  {(comparison?.self_points ?? []).map((point, i) => (
                    <li key={i}>{point}</li>
                  ))}
                </ul>
              </div>

              <div className="space-y-1">
                <Wheel axes={selfWheel.axes} competitorAxes={competitorAxes} />
                <div className="flex justify-center gap-4 text-[11px] text-muted-foreground">
                  <LegendSwatch label="自社サイト" color={SELF_HEX} />
                  {competitorAxes && <LegendSwatch label="競合サイト" color={COMPETITOR_HEX} />}
                </div>
              </div>
            </div>

            {/* グループの帯は6列に並ぶときだけ意味を持つ。狭い画面では各セルの
                見出しの色がグループを表すため、帯は出さない。 */}
            <div className="hidden gap-2 lg:grid lg:grid-cols-6">
              {GROUP_ORDER.map((group) => (
                <p
                  key={group}
                  className={cn(
                    "col-span-2 rounded-sm py-1.5 text-center text-xs font-semibold text-white",
                    GROUP_STYLES[group].band,
                  )}
                >
                  {GROUP_STYLES[group].label}
                </p>
              ))}
            </div>

            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
              {selfWheel.axes.map((axis) => (
                <AxisCell key={axis.key} axis={axis} />
              ))}
            </div>

            {(selfWheel.key_message || selfWheel.impression) && (
              <div className={cn("space-y-1.5 rounded-md p-3.5 text-white", NAVY)}>
                <p className="text-xs font-semibold">このページの内容から想像するキーメッセージと印象</p>
                {selfWheel.key_message && (
                  <p className="text-xs leading-relaxed">
                    <span className="font-semibold">キーメッセージ：</span>
                    {selfWheel.key_message}
                  </p>
                )}
                {selfWheel.impression && (
                  <p className="text-xs leading-relaxed">
                    <span className="font-semibold">AI解析による印象：</span>
                    {selfWheel.impression}
                  </p>
                )}
              </div>
            )}
          </>
        )}
      </div>

      {comparison && competitor && (
        <div className="space-y-3 rounded-md border p-4" data-testid="wheel-comparison">
          <p className="text-sm font-medium">他社ページ比較とのまとめ</p>

          <div className="grid gap-3 md:grid-cols-2">
            <div className="rounded-md border border-[#33587f] p-3" data-testid="wheel-self-points">
              <p className="text-xs font-semibold">【自社ページ】</p>
              <ul className="mt-1.5 list-disc space-y-1 pl-4 text-xs leading-relaxed">
                {comparison.self_points.map((point, i) => (
                  <li key={i}>{point}</li>
                ))}
              </ul>
            </div>
            <div className="rounded-md border border-[#33587f] p-3" data-testid="wheel-competitor-points">
              <p className="text-xs font-semibold">【他社ページ】</p>
              <ul className="mt-1.5 list-disc space-y-1 pl-4 text-xs leading-relaxed">
                {comparison.competitor_points.map((point, i) => (
                  <li key={i}>{point}</li>
                ))}
              </ul>
            </div>
          </div>

          {comparison.one_point && (
            <>
              {/* 2社の所見からワンポイントを導いている、という流れを示す矢印
                  (スライドと同じ)。装飾なのでaria-hiddenにする。 */}
              <div aria-hidden className="flex justify-center py-1">
                <svg width="42" height="26" viewBox="0 0 54 34" className="fill-[#33587f]">
                  <polygon points="16,0 38,0 38,16 54,16 27,34 0,16 16,16" />
                </svg>
              </div>
              <div className="rounded-md border p-3">
                <p className="text-xs font-semibold">【ワンポイント】</p>
                <p className="mt-1.5 text-xs leading-relaxed" data-testid="wheel-one-point">
                  {comparison.one_point.text}
                </p>
              </div>
            </>
          )}
        </div>
      )}

      <p className="text-xs leading-relaxed text-muted-foreground">
        ブランド・ホイールは本来、サイトだけでなくグループインタビュー・口コミ・内定者/辞退者インタビュー・説明会・SNSなども併せて構築するものです。ここではそのうちサイトのみを見ています。キーメッセージと印象の読み取りにはAIを使用しています。
      </p>
    </div>
  );
}

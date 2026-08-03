<?php

namespace App\Services\BrandWheel;

/**
 * リード向けレポート(Word/PDF)の「自社×競合を重ねたレーダー図」のSVGを
 * 組み立てる。BrandWheelHexagonSvgBuilder(社員向けメール、1サイト分の
 * 6扇形・軸ごとの状態のみ)とは別物 ―― こちらは2サイトの件数を重ねて
 * 比較する図が必要なため、流用せず独立したクラスにする
 * (2026-08-04のユーザー指摘)。
 *
 * 幾何計算はfrontend/src/features/lead/lead-brand-wheel.tsxの
 * wheelPoint()/axisRatio()と同じ式(0時方向起点・時計回り、半径は
 * matched_count/max_count)。画面(旧)とレポートで図の形が食い違わないよう、
 * 値だけでなく式そのものを合わせている。
 *
 * max_countが0の軸は0として描かず(中心に落とさず)点自体を打たない ――
 * 「読み取れなかった」を「0点」として扱わないという既存方針(採点しない)
 * をレーダー図でも維持する。
 *
 * 目盛りリングの本数はaxes(自社側)のmax_countから動的に決める
 * (4や5を固定値で書かない)。競合側はグリッド・軸の基準には使わず、
 * 上に重ねるデータ系列としてのみ扱う(自社と軸数・順序が異なる想定は無い)。
 */
class BrandWheelRadarSvgBuilder
{
    private const float CX = 190;

    private const float CY = 138;

    private const float R = 100;

    private const int VIEWBOX_WIDTH = 380;

    private const int VIEWBOX_HEIGHT = 276;

    private const string SELF_COLOR = '#2a78d6';

    private const string COMPETITOR_COLOR = '#eb6834';

    private const string GRID_COLOR = '#d8d8d8';

    private const string LABEL_COLOR = '#5b5b5b';

    private const string BG_COLOR = '#ffffff';

    private const string FONT_FAMILY = 'IPAexGothic, sans-serif';

    /**
     * @param  list<array{key: string, name: string, matched_count: int, max_count: int}>  $axes  自社(グリッド・軸の基準)
     * @param  ?list<array{key: string, name: string, matched_count: int, max_count: int}>  $competitorAxes  競合。status!=='success'ならnullを渡す(ここでは判定しない、呼び出し側の責務)
     */
    public function build(array $axes, ?array $competitorAxes): string
    {
        $count = count($axes);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d">',
            self::VIEWBOX_WIDTH, self::VIEWBOX_HEIGHT, self::VIEWBOX_WIDTH, self::VIEWBOX_HEIGHT,
        );
        $svg .= sprintf('<rect x="0" y="0" width="%d" height="%d" fill="%s" />', self::VIEWBOX_WIDTH, self::VIEWBOX_HEIGHT, self::BG_COLOR);

        if ($count === 0) {
            return $svg.'</svg>';
        }

        $svg .= $this->grid($axes, $count);
        $svg .= $this->series($axes, self::SELF_COLOR);

        if ($competitorAxes !== null) {
            $svg .= $this->series($competitorAxes, self::COMPETITOR_COLOR);
        }

        $svg .= $this->labels($axes, $count);
        $svg .= '</svg>';

        return $svg;
    }

    /**
     * 目盛りリング(max_count分)+中心からの軸線。
     *
     * @param  list<array{max_count: int}>  $axes
     */
    private function grid(array $axes, int $count): string
    {
        $maxCounts = array_column($axes, 'max_count');
        $ringCount = $maxCounts === [] ? 1 : max(1, max($maxCounts));

        $rings = '';
        for ($ring = 1; $ring <= $ringCount; $ring++) {
            $points = [];
            for ($i = 0; $i < $count; $i++) {
                $p = $this->wheelPoint($i, $count, $ring / $ringCount);
                $points[] = sprintf('%.2f,%.2f', $p['x'], $p['y']);
            }
            $rings .= sprintf('<polygon points="%s" fill="none" stroke="%s" stroke-width="1" />', implode(' ', $points), self::GRID_COLOR);
        }

        $spokes = '';
        for ($i = 0; $i < $count; $i++) {
            $outer = $this->wheelPoint($i, $count, 1.0);
            $spokes .= sprintf(
                '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="1" />',
                self::CX, self::CY, $outer['x'], $outer['y'], self::GRID_COLOR,
            );
        }

        return $rings.$spokes;
    }

    /**
     * @param  list<array{matched_count: int, max_count: int}>  $axes
     */
    private function series(array $axes, string $color): string
    {
        $count = count($axes);
        $points = [];
        $dots = '';

        foreach (array_values($axes) as $i => $axis) {
            $ratio = $this->axisRatio($axis);

            if ($ratio === null) {
                continue;
            }

            $p = $this->wheelPoint($i, $count, $ratio);
            $points[] = $p;
            $dots .= sprintf(
                '<circle cx="%.2f" cy="%.2f" r="3.2" fill="%s" /><circle cx="%.2f" cy="%.2f" r="3.2" fill="none" stroke="#ffffff" stroke-width="1.4" />',
                $p['x'], $p['y'], $color, $p['x'], $p['y'],
            );
        }

        if (count($points) < 3) {
            return '';
        }

        $polygon = implode(' ', array_map(fn (array $p) => sprintf('%.2f,%.2f', $p['x'], $p['y']), $points));

        return sprintf(
            '<polygon points="%s" fill="%s" fill-opacity="0.16" stroke="%s" stroke-width="2" stroke-linejoin="round" />',
            $polygon, $color, $color,
        ).$dots;
    }

    /**
     * @param  list<array{name: string}>  $axes
     */
    private function labels(array $axes, int $count): string
    {
        $labels = '';

        foreach (array_values($axes) as $i => $axis) {
            $label = $this->wheelPoint($i, $count, 1.28);
            $angleRad = deg2rad(-90 + (360 / $count) * $i);
            $cos = cos($angleRad);
            $anchor = abs($cos) < 0.2 ? 'middle' : ($cos > 0 ? 'start' : 'end');

            $labels .= sprintf(
                '<text x="%.2f" y="%.2f" text-anchor="%s" font-family="%s" font-size="10.5" fill="%s">%s</text>',
                $label['x'], $label['y'] + 4, $anchor, self::FONT_FAMILY, self::LABEL_COLOR,
                htmlspecialchars((string) $axis['name'], ENT_XML1),
            );
        }

        return $labels;
    }

    /**
     * frontend/src/features/lead/lead-brand-wheel.tsxのwheelPoint()と同じ式。
     *
     * @return array{x: float, y: float}
     */
    private function wheelPoint(int $index, int $axisCount, float $ratio): array
    {
        $angle = deg2rad(-90 + (360 / $axisCount) * $index);
        $radius = self::R * max(0.0, min(1.0, $ratio));

        return ['x' => self::CX + cos($angle) * $radius, 'y' => self::CY + sin($angle) * $radius];
    }

    /**
     * frontend/src/features/lead/lead-brand-wheel.tsxのaxisRatio()と同じ式。
     *
     * @param  array{matched_count: int, max_count: int}  $axis
     */
    private function axisRatio(array $axis): ?float
    {
        if ($axis['max_count'] <= 0) {
            return null;
        }

        return $axis['matched_count'] / $axis['max_count'];
    }
}

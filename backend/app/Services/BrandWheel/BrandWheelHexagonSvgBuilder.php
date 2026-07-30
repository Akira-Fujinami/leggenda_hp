<?php

namespace App\Services\BrandWheel;

use App\Models\BrandWheelAnalysisResult;

/**
 * ブランド・ホイール6軸の判定結果からヘキサゴン図のSVGを組み立てる。
 * レーダーチャートにはしない ―― 軸ごとに値をプロットすることは採点になり、
 * このプロジェクト全体の設計原則(ブランド・ホイールは採点しない)と矛盾する。
 *
 * 状態はfill/ハッチング/輪郭のみで塗り分け、色だけで区別しない(各扇形に
 * 軸名+状態のテキストラベルを必ず併記する)。良し悪しを示す緑・赤は使わない
 * (自社サイトの識別色1色のみを使う ―― 状態の違いはfillパターンで表現する)。
 *
 * ライトモード用パレット固定(ダークモード版は作らない、2026-07-30の指示)。
 * PNG化する側(BrandWheelHexagonRenderer)が不透過の背景色を焼き込むため、
 * Outlook/Apple Mail等のダークモード自動反転が起きても判読可能なままになる。
 *
 * 幾何仕様(依頼者提供、変更しないこと): R_OUT=112, R_IN=46, 中心(190,158)、
 * viewBox「0 0 380 316」。i=0(活動的魅力、上部)から時計回りに角度を進める。
 */
class BrandWheelHexagonSvgBuilder
{
    private const float CX = 190;

    private const float CY = 158;

    private const float R_OUT = 112;

    private const float R_IN = 46;

    private const string SELF_COLOR = '#2a78d6';

    private const string OUTLINE_COLOR = '#898781';

    private const string BG_COLOR = '#fcfcfb';

    private const string TEXT_PRIMARY = '#0b0b0b';

    private const string TEXT_SECONDARY = '#52514e';

    private const string FONT_FAMILY = 'IPAexGothic, sans-serif';

    /**
     * @var array<string, string>
     */
    private const STATE_LABELS = [
        'read' => '読み取れました',
        'partial' => '一部読み取れました',
        'unread' => '読み取れませんでした',
    ];

    public function build(BrandWheelAnalysisResult $result): string
    {
        $axesConfig = (array) config('brand_wheel.axes', []);
        $axisKeys = array_keys($axesConfig);
        $axesByKey = collect((array) ($result->axes ?? []))->keyBy('axis_key');

        $shapes = '';
        $labels = '';

        foreach (array_values($axisKeys) as $i => $axisKey) {
            $axisResult = $axesByKey->get($axisKey);
            $state = is_array($axisResult) && in_array($axisResult['state'] ?? null, ['read', 'partial', 'unread'], true)
                ? $axisResult['state']
                : 'unread';
            $nameJa = (string) ($axesConfig[$axisKey]['name_ja'] ?? $axisKey);

            $shapes .= $this->segmentShape($i, $state);
            $labels .= $this->segmentLabel($i, $nameJa, $state);
        }

        $coreValue = $this->buildCoreValue((bool) $result->core_value_readable);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 380 316" width="380" height="316">'
            .'<defs><pattern id="hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">'
            .sprintf('<line x1="0" y1="0" x2="0" y2="6" stroke="%s" stroke-width="2" />', self::SELF_COLOR)
            .'</pattern></defs>'
            .sprintf('<rect x="0" y="0" width="380" height="316" fill="%s" />', self::BG_COLOR)
            .$shapes
            .$coreValue
            .$labels
            .'</svg>';
    }

    private function segmentShape(int $index, string $state): string
    {
        $path = $this->segmentPath($index);

        return match ($state) {
            'read' => sprintf(
                '<path d="%s" fill="%s" fill-opacity="0.92" stroke="%s" stroke-width="1.5" />',
                $path, self::SELF_COLOR, self::SELF_COLOR,
            ),
            'partial' => sprintf(
                '<path d="%s" fill="url(#hatch)" stroke="%s" stroke-width="1.5" />',
                $path, self::SELF_COLOR,
            ),
            default => sprintf(
                '<path d="%s" fill="none" stroke="%s" stroke-width="1.5" />',
                $path, self::OUTLINE_COLOR,
            ),
        };
    }

    private function segmentLabel(int $index, string $nameJa, string $state): string
    {
        $angle = -90 + $index * 60;
        $labelR = (self::R_OUT + self::R_IN) / 2;
        [$x, $y] = $this->polar($labelR, $angle);
        $stateLabel = self::STATE_LABELS[$state];

        return sprintf(
            '<text x="%.2f" y="%.2f" text-anchor="middle" font-family="%s"><tspan x="%.2f" dy="-6" font-size="13" font-weight="bold" fill="%s">%s</tspan><tspan x="%.2f" dy="15" font-size="11" fill="%s">%s</tspan></text>',
            $x, $y, self::FONT_FAMILY,
            $x, self::TEXT_PRIMARY, htmlspecialchars($nameJa, ENT_XML1),
            $x, self::TEXT_SECONDARY, htmlspecialchars($stateLabel, ENT_XML1),
        );
    }

    private function buildCoreValue(bool $readable): string
    {
        $path = $this->coreValueHexagonPath();

        $shape = $readable
            ? sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="2" />', $path, self::SELF_COLOR)
            : sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="2" stroke-dasharray="4 3" />', $path, self::OUTLINE_COLOR);

        $label = sprintf(
            '<text x="%.2f" y="%.2f" text-anchor="middle" font-family="%s" font-size="11" fill="%s">Core Value</text>',
            self::CX, self::CY, self::FONT_FAMILY, self::TEXT_SECONDARY,
        );

        return $shape.$label;
    }

    private function coreValueHexagonPath(): string
    {
        $points = [];
        for ($k = 0; $k < 6; $k++) {
            $angle = -90 - 30 + $k * 60;
            $points[] = $this->polar(self::R_IN, $angle);
        }

        $segments = array_map(fn (array $p) => sprintf('%.2f %.2f', $p[0], $p[1]), $points);

        return 'M '.implode(' L ', $segments).' Z';
    }

    private function segmentPath(int $index): string
    {
        $angle = -90 + $index * 60;
        $a0 = $angle - 30;
        $a1 = $angle + 30;

        $p0 = $this->polar(self::R_OUT, $a0);
        $p1 = $this->polar(self::R_OUT, $a1);
        $p2 = $this->polar(self::R_IN, $a1);
        $p3 = $this->polar(self::R_IN, $a0);

        return sprintf(
            'M %.2f %.2f L %.2f %.2f L %.2f %.2f L %.2f %.2f Z',
            $p0[0], $p0[1], $p1[0], $p1[1], $p2[0], $p2[1], $p3[0], $p3[1],
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function polar(float $radius, float $degrees): array
    {
        $rad = deg2rad($degrees);

        return [self::CX + $radius * cos($rad), self::CY + $radius * sin($rad)];
    }
}

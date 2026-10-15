<?php

namespace App\Services\StrengthWeaknessSentence;

use App\Models\Analysis;
use App\Services\ComparisonCommentary\ComparisonCommentaryMaterialBuilder;
use App\Services\ComparisonCommentary\StrengthWeaknessDetailComposer;

/**
 * 依頼CV-1: 強み・弱みの一文の材料(AIを使わない)。取り上げる「1項目・1社」を、依頼CU追補の決まりのまま機械が選び
 * (StrengthWeaknessDetailComposer::pick())、その根拠の文(全文)に、項目名・定義・競合の社数を添える。
 *
 *  強み: 自社の項目(自社だけの強み → 競合の○が半数ちょうど)と、自社の根拠の文
 *  弱み: 自社の穴の項目と、その項目が○の競合のうち比較表の並び順で最初の会社と、その会社の根拠の文
 *
 * 対象が無い側はnull(その側は依頼CT-1の文)。両方とも無ければ、材料を作らない(status=skipped、AIも呼ばない)。
 * 自社の判定が成立していない・競合がいない・読み取れた記述が少ない会社がある・競合の判定が成立していない
 * ときも、材料を作らない(ComparisonCommentaryMaterialBuilderと同じ)。
 *
 * 会社名の一覧(自社と競合、法人格を除いた言い換えを含む)は、サーバー側の確認(会社名だけの引用を落とす・渡していない
 * 会社名を文に出さない)に使う。
 */
class StrengthWeaknessSentenceMaterialBuilder
{
    public function __construct(
        private readonly ComparisonCommentaryMaterialBuilder $base,
        private readonly StrengthWeaknessDetailComposer $picker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Analysis $analysis): array
    {
        $analysis->loadMissing(['websiteAnalyses.website']);
        $selfWebsite = $analysis->websiteAnalyses->first(fn ($wa) => (bool) $wa->website?->is_primary)?->website;

        return $this->fromBase($this->base->build($analysis), array_filter([(string) ($selfWebsite?->name ?? '')]));
    }

    /**
     * @param  array<string, mixed>  $base  ComparisonCommentaryMaterialBuilder::build()の戻り値
     * @param  list<string>  $extraSelfNames  自社の呼び名(自社サイトの名前など)
     * @return array<string, mixed>
     */
    public function fromBase(array $base, array $extraSelfNames = []): array
    {
        if (($base['status'] ?? null) !== 'ok') {
            return $this->skipped((string) $base['reason'], (string) ($base['self_name'] ?? ''));
        }

        $picked = $this->picker->pick($base);
        if ($picked['strength'] === null && $picked['weakness'] === null) {
            return $this->skipped('no_target', (string) $base['self_name']);
        }

        $n = (int) $base['competitor_count'];
        $competitors = array_map(fn (array $c) => $c + ['variants' => $this->variants((string) $c['name'])], (array) $base['competitors']);
        $symbolMode = $competitors !== [] && $competitors[0]['label'] !== $competitors[0]['name'];

        $strength = null;
        if ($picked['strength'] !== null) {
            $item = $picked['strength']['item'];
            $strength = [
                'key' => (string) $item['key'],
                'axis' => (string) $item['axis'],
                'name' => (string) $item['name'],
                'definition' => $this->definition((string) $item['key']),
                'evidence' => trim((string) $item['self_evidence']),
                'competitor_count' => $n,
                'matched' => (int) $item['competitor_matched_count'],
            ];
        }

        $weakness = null;
        if ($picked['weakness'] !== null) {
            $item = $picked['weakness']['item'];
            $label = (string) $picked['weakness']['company']['label'];
            $name = (string) (array_column($competitors, 'name', 'label')[$label] ?? $label);
            $weakness = [
                'key' => (string) $item['key'],
                'axis' => (string) $item['axis'],
                'name' => (string) $item['name'],
                'definition' => $this->definition((string) $item['key']),
                'company' => $symbolMode ? sprintf((string) config('strength_weakness_sentence.symbol_label_template'), $label) : $label,
                'company_label' => $label,
                'company_name' => $name,
                'evidence' => trim((string) $picked['weakness']['company']['evidence']),
                'competitor_count' => $n,
                'matched' => (int) $item['competitor_matched_count'],
            ];
        }

        $selfNames = [];
        foreach (array_merge([(string) $base['self_name']], array_values($extraSelfNames)) as $name) {
            array_push($selfNames, ...$this->variants($name));
        }

        return [
            'status' => 'ok',
            'reason' => null,
            'self_name' => (string) $base['self_name'],
            'self_names' => array_values(array_unique($selfNames)),
            'competitor_count' => $n,
            'competitors' => $competitors,
            'symbol_mode' => $symbolMode,
            'strength' => $strength,
            'weakness' => $weakness,
        ];
    }

    /**
     * プロンプトに載せる部分(検証用の会社名の一覧などは含めない)。
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public function visible(array $material): array
    {
        return [
            'self_name' => $material['self_name'],
            'competitor_count' => $material['competitor_count'],
            'strength' => $material['strength'],
            'weakness' => $material['weakness'],
        ];
    }

    /**
     * 会社名と、法人格を除いた言い換え(2文字以上のものだけ)。
     *
     * @return list<string>
     */
    public function variants(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $stripped = $name;
        foreach ((array) config('strength_weakness_sentence.company_legal_forms', []) as $form) {
            $stripped = str_replace((string) $form, '', $stripped);
        }
        $stripped = trim((string) preg_replace('/\s+/u', '', $stripped));

        return array_values(array_unique(array_filter([$name, $stripped], fn (string $v) => mb_strlen($v) >= 2)));
    }

    private function definition(string $subKey): string
    {
        foreach ((array) config('brand_wheel.axes') as $axis) {
            if (isset($axis['sub_element_definitions'][$subKey])) {
                return (string) $axis['sub_element_definitions'][$subKey];
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function skipped(string $reason, string $selfName): array
    {
        return ['status' => 'skipped', 'reason' => $reason, 'self_name' => $selfName, 'self_names' => [], 'competitor_count' => 0, 'competitors' => [], 'symbol_mode' => false, 'strength' => null, 'weakness' => null];
    }
}

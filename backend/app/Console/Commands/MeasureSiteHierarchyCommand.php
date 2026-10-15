<?php

namespace App\Console\Commands;

use App\Models\WebsiteAnalysis;
use App\Services\Analysis\CrawlOriginScopeResolver;
use App\Services\CorporateTop\CorporateTopAnalyzer;
use App\Services\CorporateTop\CorporateTopStore;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use App\Services\Report\PageKindClassifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 依頼CY: 階層図の、第1階層(コーポレートの他メニュー)と第3階層(情報の種類)を、保存済みの巡回・判定の結果で確かめる(AIなし・通信しない)。
 *  - CY-1: コーポレートTOPのHTML(静的・描画済み)から取れたメニュー(どの取り方で取れたか)と、除いた項目・理由の件数
 *  - CY-2: 各枝のページ名 → 付いた種類(ページ名 / 判定の根拠の文 / 両方)と、「その他」になったページの数・割合
 * --out-dir を指定すると、階層図のスライド(pptx)を書き出す(PowerPointで画像にして確かめる用)。
 *
 * 開発・検証専用。ページ名・メニューの文字を出す(本文は出さない)。本番で実行されうる経路には登録しない。
 */
#[Signature('site-hierarchy:measure
    {--wa= : 自社。ラベル:website_analysis_id をカンマ区切り (例 サイボウズ:776,カヤック:780)}
    {--out-dir= : 階層図のスライド(pptx)の書き出し先}
    {--bw-cache= : 保存していない判定結果(JSON。website_analysis_id => {axes, ...})をカンマ区切りで。根拠の文でページに種類を付けるときに使う(測定専用)}
')]
#[Description('非本番・開発専用: 階層図の他メニュー(第1階層)と情報の種類(第3階層)を保存済みの結果で確かめる(依頼CY)')]
class MeasureSiteHierarchyCommand extends Command
{
    public function handle(
        AdminComparisonSiteHierarchyBuilder $builder,
        CorporateTopStore $store,
        CorporateTopAnalyzer $analyzer,
        CrawlOriginScopeResolver $scopeResolver,
        PageKindClassifier $classifier,
        AdminComparisonPptxGenerator $generator,
    ): int {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $targets = [];
        foreach (array_filter(explode(',', (string) $this->option('wa'))) as $pair) {
            [$label, $id] = array_pad(explode(':', trim($pair), 2), 2, null);
            if ($label === null || $label === '' || $id === null || ! ctype_digit($id)) {
                $this->error("--wa は ラベル:id の形で指定してください: {$pair}");

                return self::FAILURE;
            }
            $targets[] = [$label, WebsiteAnalysis::query()->with('website')->findOrFail((int) $id)];
        }
        if ($targets === []) {
            $this->error('--wa を指定してください。');

            return self::FAILURE;
        }

        $cache = [];
        foreach (array_filter(explode(',', (string) $this->option('bw-cache'))) as $file) {
            if (is_file($file)) {
                $cache = (array) json_decode((string) file_get_contents($file), true) + $cache;
            }
        }

        $outDir = $this->option('out-dir') ? rtrim((string) $this->option('out-dir'), '/\\') : null;
        if ($outDir !== null && ! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        foreach ($targets as [$label, $wa]) {
            $this->info("=== {$label} (website_analysis_id={$wa->id}) ===");
            $analysisId = (int) $wa->analysis_id;
            $waId = (int) $wa->id;

            // CY-1
            $meta = $store->readMeta($analysisId, $waId);
            $static = $store->readHtml($analysisId, $waId);
            $rendered = $store->readRenderedHtml($analysisId, $waId);
            $scope = $scopeResolver->resolveScope($wa);
            $this->line('[CY-1] コーポレートTOP: '.($meta['status'] ?? '(未取得)').' '.($meta['url'] ?? '').' / 静的HTML '.($static !== null ? strlen($static).'バイト' : 'なし').' / 描画済みHTML '.($rendered !== null ? strlen($rendered).'バイト' : 'なし').' (rendered='.($meta['rendered'] ?? '-').')');
            if ($static !== null && $scope !== null) {
                $menu = $analyzer->otherMenu($static, $rendered, (string) $meta['url'], $scope, $meta['recruit_label'] ?? null);
                $this->line('  取り方ごとのリンク数(除く前): '.json_encode($menu['raw_counts'], JSON_UNESCAPED_UNICODE));
                $this->line('  除いた項目と理由の件数: '.json_encode($menu['excluded'], JSON_UNESCAPED_UNICODE));
                $this->line('  取れたメニュー('.count($menu['labels']).'件、取り方: '.($menu['source'] ?? '取れず').'): '.implode(' / ', $menu['labels']));
                $this->diagnoseHtml('静的HTML', $static);
                if ($rendered !== null) {
                    $this->diagnoseHtml('描画済みHTML', $rendered);
                }
            }

            // CY-2
            $record = isset($cache[(string) $waId]) ? (new \App\Models\BrandWheelAnalysisResult)->forceFill($cache[(string) $waId]) : null;
            $builder->useBrandWheelRecord($record);
            $this->line('  根拠の文による種類の付与: '.($record !== null ? '判定結果(キャッシュ)を使う' : ($wa->brandWheelAnalysisResults()->latest('id')->first()?->status === 'success' ? '保存済みの判定結果を使う' : '判定結果なし(ページ名の言葉だけ)')));
            $tree = $builder->buildTree($wa);
            $corporate = $tree['corporate'] ?? null;
            $this->line('  図に出る第1階層: 採用情報'.($corporate !== null ? ' + 他メニュー '.count($corporate['other_menu']).'件'.($corporate['other_menu_more'] > 0 ? "(ほか{$corporate['other_menu_more']})" : '').' [取り方: '.($corporate['other_menu_source'] ?? '取れず').']: '.implode(' / ', $corporate['other_menu']) : '(コーポレートTOPなし)'));
            $totalPages = 0;
            $otherPages = 0;
            foreach ($tree['branches'] as $branch) {
                $this->line("[CY-2] 枝: {$branch['name']} ({$branch['path']}) ページ数 {$branch['page_count']}");
                foreach ((array) ($branch['page_kinds'] ?? []) as $page) {
                    $totalPages++;
                    if ($page['kinds'] === []) {
                        $otherPages++;
                        $this->line("    {$page['name']}  →  その他");

                        continue;
                    }
                    $this->line("    {$page['name']}  →  ".implode('、', array_map(fn (string $kind, string $basis) => $classifier->shortName($kind).'('.match ($basis) { 'title' => 'ページ名', 'evidence' => '根拠の文', default => '両方' }.')', array_keys($page['kinds']), array_values($page['kinds']))));
                }
                $this->line('    → 図の行: '.implode(' / ', $branch['pages']).($branch['other_page_count'] > 0 ? " / ほか{$branch['other_page_count']}ページ" : ''));
            }
            $this->line(sprintf('  「その他」のページ: %d / %d (%s)', $otherPages, $totalPages, $totalPages > 0 ? round($otherPages / $totalPages * 100).'%' : '-'));

            if ($outDir !== null) {
                file_put_contents("{$outDir}/{$label}.pptx", $generator->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));
            }
        }

        return self::SUCCESS;
    }

    /** HTMLの形の診断: header / nav / noscript の数と、nav風のclass/idの数。 */
    private function diagnoseHtml(string $name, string $html): void
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $count = fn (string $q) => $xpath->query($q)->length;
        $this->line(sprintf('  %s: header %d / nav %d / mainの外のheader %d / noscript %d / script %d / aタグ %d', $name, $count('//header'), $count('//nav'), $count('//header[not(ancestor::main)]'), $count('//noscript'), $count('//script'), $count('//a')));
    }
}

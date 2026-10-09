<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SeedsAndCrawlsMeasurementSites;
use App\Models\AnalysisCrawledPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\CorporateTop\CorporateTopService;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * 依頼CR: 階層図のTOP(コーポレートサイト)と、第2階層の枝名(リンクの文字)を、実際のサイトで測る。
 * 巡回は会社ごとに1回(--reuseで前回の結果を使い回せる)、コーポレートTOPの取得は会社ごとに1回。
 * AIは使わない。出力は、候補ごとの結果・リンクの文字・枝名の前後(サイトのメニューの言葉)と件数だけで、
 * ページの本文は出さない。階層図のスライド(pptx)は--out-dirへ書き出す。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('corporate-top:measure
    {--sites= : 対象サイトキーのカンマ区切り(既定: kayac_recruit,moneyforward,cybozu)}
    {--site-url=* : 追加のサイト。キー=URL(例 orbray=https://orbray.com/recruit/)}
    {--reuse= : 巡回し直さず、巡回済みのWebsiteAnalysisを使う。サイトキー:website_analysis_idのカンマ区切り}
    {--out-dir= : 階層図のスライド(pptx)の書き出し先}
')]
#[Description('非本番・開発専用: 階層図のコーポレートTOPと第2階層の枝名を、実際のサイトで測る(依頼CR、AIは使わない)')]
class MeasureCorporateTopCommand extends Command
{
    use SeedsAndCrawlsMeasurementSites;

    public function handle(
        AnalysisPipeline $pipeline,
        CorporateTopService $corporateTop,
        AdminComparisonSiteHierarchyBuilder $builder,
        AdminComparisonPptxGenerator $generator,
    ): int {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $sites = self::SITES;
        foreach ((array) $this->option('site-url') as $pair) {
            [$key, $url] = array_pad(explode('=', (string) $pair, 2), 2, '');
            if ($key === '' || ! preg_match('#^https?://#', $url)) {
                $this->error("--site-url は キー=URL の形で指定してください: {$pair}");

                return self::FAILURE;
            }
            $sites[$key] = ['label' => $key, 'homepage_url' => $url, 'recruit_url' => $url];
        }

        $keys = $this->option('sites') !== null
            ? array_values(array_filter(explode(',', (string) $this->option('sites'))))
            : ['kayac_recruit', 'moneyforward', 'cybozu'];
        foreach ($keys as $key) {
            if (! isset($sites[$key])) {
                $this->error("未定義のサイトキーです: {$key}（定義済み: ".implode(',', array_keys($sites)).'）');

                return self::FAILURE;
            }
        }

        $reuse = [];
        foreach (array_filter(explode(',', (string) $this->option('reuse'))) as $pair) {
            [$k, $v] = array_pad(explode(':', trim($pair), 2), 2, null);
            if ($k !== null && $v !== null) {
                $reuse[$k] = (int) $v;
            }
        }

        $outDir = $this->option('out-dir') ? rtrim((string) $this->option('out-dir'), '/\\') : null;
        if ($outDir !== null && ! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        Queue::fake();

        foreach ($keys as $key) {
            $site = $sites[$key];
            $this->info("=== {$site['label']} ({$key}) ===");

            if (isset($reuse[$key])) {
                $wa = WebsiteAnalysis::findOrFail($reuse[$key]);
                $this->line("  reuse: website_analysis_id={$wa->id}(巡回し直さない)");
            } else {
                [$analysisId, $waId] = $this->seedWebsiteAnalysis($key, $site, true);
                $this->runCrawlChain($pipeline, $analysisId, $waId, true);
                $wa = WebsiteAnalysis::findOrFail($waId);
            }

            $fetched = AnalysisCrawledPage::query()->where('website_analysis_id', $wa->id)->where('status', 'fetched')->count();
            $this->line("  巡回: fetched={$fetched}  website_analysis_id={$wa->id}");

            // コーポレートTOPの取得(1回)。
            $meta = $corporateTop->run($wa, force: true);
            $this->line(sprintf(
                '  コーポレートTOP: %s  対象外の理由=%s',
                $meta['status'] === 'found' ? '決まった' : '決まらなかった',
                $meta['skipped'] ?? '-',
            ));
            foreach ((array) ($meta['attempts'] ?? []) as $attempt) {
                $this->line("    候補 {$attempt['candidate']} → {$attempt['outcome']}");
            }
            if ($meta['status'] === 'found') {
                $this->line(sprintf('    採用: %s  採用へのリンクの文字=「%s」  リンク先=%s', $meta['url'], $meta['recruit_label'] ?? '(空 → 既定の文言)', $meta['recruit_url']));
            }

            // 第2階層の枝名: リンクの文字にする前(従来: ページのtitle)と後。
            config(['admin_comparison_pptx.site_hierarchy_link_names_enabled' => false]);
            $before = $builder->buildTree($wa);
            config(['admin_comparison_pptx.site_hierarchy_link_names_enabled' => true]);
            $after = $builder->buildTree($wa);

            $this->line('  描き方: mode='.$after['mode'].'  コーポレートTOP='.($after['corporate'] !== null ? '決まった(4列)' : '決まらない(3列)').'  注記='.($after['corporate_missing'] ? 'あり' : 'なし'));
            if ($after['corporate'] !== null) {
                $this->line(sprintf('  第1階層: 採用の箱「%s」 path=%s  他のメニュー=%s  ほか%d', $after['corporate']['recruit_label'], $after['recruit_path'], implode('/', $after['corporate']['other_menu']), $after['corporate']['other_menu_more']));
            }
            $this->line('  枝名(前=ページのtitle): '.implode(' / ', array_column($before['branches'], 'name')));
            $this->line('  枝名(後=リンクの文字): '.implode(' / ', array_column($after['branches'], 'name')));
            $this->line('  枝のpath: '.implode(' / ', array_map(fn ($b) => (string) ($b['path'] ?? '-'), $after['branches'])));

            if ($outDir !== null) {
                file_put_contents("{$outDir}/{$key}.pptx", $generator->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $after));
            }
        }

        return self::SUCCESS;
    }
}

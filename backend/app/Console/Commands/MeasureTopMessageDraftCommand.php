<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SeedsAndCrawlsMeasurementSites;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\TopMessageDraft\TopMessageDraftBuilder;
use App\Services\TopMessageDraft\TopMessageDraftExtractor;
use App\Services\TopMessageInsight\TopMessagePageFinder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * 依頼CS: トップメッセージと制度の素材ページ(社内用・下書き)を、実際のサイトで測る。
 *
 * AIは呼ばない。保存済みのHTMLから機械的に抜き出すだけ。--reuseで巡回済みの結果を使い回せる
 * (使い回さない会社だけ巡回する)。出力は件数とURLだけ ―― メッセージの文・制度の文は
 * 標準出力にもログにも出さない(スライド(pptx)だけを--out-dirへ書き出す)。
 *
 * 開発・検証専用。本番で実行されうる経路には登録しない。
 */
#[Signature('top-message-draft:measure
    {--sites= : 対象サイトキーのカンマ区切り(既定: orbray,kayac,moneyforward,cybozu,smarthr,freee)}
    {--site-url=* : 追加のサイト。キー=URL(例 orbray=https://orbray.com/recruit/)}
    {--reuse= : 巡回し直さず、巡回済みのWebsiteAnalysisを使う。サイトキー:website_analysis_idのカンマ区切り}
    {--expected=* : 見本に載っている制度名。素材ページの候補に何件入ったかを数える(キー=名前|名前|…)}
    {--out-dir= : スライド(pptx)の書き出し先}
')]
#[Description('非本番・開発専用: トップメッセージと制度の素材ページを、実際のサイトで測る(依頼CS、AIは呼ばない)')]
class MeasureTopMessageDraftCommand extends Command
{
    use SeedsAndCrawlsMeasurementSites;

    private const DEFAULT_SITES = ['orbray', 'kayac', 'moneyforward', 'cybozu', 'smarthr', 'freee'];

    public function handle(
        AnalysisPipeline $pipeline,
        TopMessagePageFinder $finder,
        TopMessageDraftBuilder $builder,
        TopMessageDraftExtractor $extractor,
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
            : self::DEFAULT_SITES;
        foreach ($keys as $key) {
            if (! isset($sites[$key])) {
                $this->error("未定義のサイトキーです: {$key}（定義済み: ".implode(',', array_keys($sites)).'）');

                return self::FAILURE;
            }
        }

        $reuse = [];
        foreach (array_filter(explode(',', (string) $this->option('reuse'))) as $pair) {
            [$key, $id] = array_pad(explode(':', $pair, 2), 2, '');
            $reuse[$key] = (int) $id;
        }

        $expected = [];
        foreach ((array) $this->option('expected') as $pair) {
            [$key, $names] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $expected[$key] = array_values(array_filter(explode('|', $names)));
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
                $this->line("  巡回: website_analysis_id={$wa->id}");
            }

            $page = $builder->build($wa, $site['label']);
            $selection = $finder->find($wa);

            $this->line('  メッセージのページ: '.($selection->message?->url ?? '見つからない'));
            $this->line(sprintf(
                '  メッセージの候補: %d件(ほか%d件)',
                count($page['message']['lines'] ?? []),
                $page['message']['hidden'] ?? 0,
            ));
            $this->line(sprintf(
                '  制度の候補: %d件(ほか%d件)',
                count($page['programs']['rows'] ?? []),
                $page['programs']['hidden'] ?? 0,
            ));
            $this->line('  使った制度のページ: '.implode(' ', array_map(fn ($u) => "[{$u}]", $page['programs']['page_urls'] ?? [])));

            if (isset($expected[$key])) {
                $names = array_map(fn (array $r) => $r['name'], $page['programs']['rows'] ?? []);
                $hits = [];
                foreach ($expected[$key] as $want) {
                    $hits[$want] = $this->contains($names, $want);
                }
                // 上限(1社あたりの件数)で切る前の、全候補にも当たるか。
                $allNames = array_map(
                    fn (array $r) => $r['name'],
                    $extractor->programCandidates(array_slice($selection->programPages, 0, (int) config('top_message_draft.program_pages_max'))),
                );
                $this->line(sprintf('  見本との重なり: 上限で切った後 %d / %d件、切る前(全候補%d件) %d / %d件(指定した名前のうち)', count(array_filter($hits)), count($hits), count($allNames), count(array_filter(array_map(fn ($w) => $this->contains($allNames, $w), $expected[$key]))), count($hits)));
                foreach ($hits as $want => $hit) {
                    $this->line('    '.($hit ? '○' : '×').($this->contains($allNames, $want) ? '(全候補には有)' : '(全候補にも無)').' '.$want);
                }
            }

            if ($page === null) {
                $this->line('  → 候補が1件も無いため、ページは作られない。');

                continue;
            }
            if ($outDir !== null) {
                file_put_contents("{$outDir}/{$key}.pptx", $generator->generateTopMessageDraftSlide($page));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $names
     */
    private function contains(array $names, string $want): bool
    {
        $normalize = fn (string $s) => mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $s));
        foreach ($names as $name) {
            if (str_contains($normalize($name), $normalize($want))) {
                return true;
            }
        }

        return false;
    }
}

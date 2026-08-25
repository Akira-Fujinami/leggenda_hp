<?php

namespace App\Console\Commands;

use App\Models\Analysis;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 依頼D-5。現状Analysis.crawl_site=trueにする経路が存在しない
 * (マイグレーションの既定値falseと$fillableがあるだけで、管理画面にも
 * 他のコマンドにも起動経路が無かった)ため新設する。
 *
 * このコマンドは診断そのものは起動しない ―― フラグを立てるだけで、
 * 実際の巡回はAnalysisPipeline::dispatchBrandWheelAnalysisIfDue()
 * (RenderPageJobの終端から呼ばれる、既存のライブ診断パイプライン)が
 * このフラグを見て自然に起動する。したがって、このコマンドは
 * RenderPageJobがまだ終端していない(=診断がまだ実行中の)Analysisに
 * 対して、その前に実行する必要がある。
 *
 * 既存のbrand-wheel:runコマンド(既存のWebsiteAnalysisに対してAI呼び出し
 * だけを単体実行する)とは役割が異なる ―― こちらはフラグを立てるだけの
 * 単純なコマンドに留め、クロール自体のオーケストレーション(D-1〜D-4)は
 * 一切再実装しない(既存のライブパイプラインをそのまま使う)。
 */
#[Signature('brand-wheel:enable-crawl {analysis_id : クロールを有効化する対象のAnalysis ID}')]
class EnableCrawlSiteCommand extends Command
{
    public function handle(): int
    {
        $analysisId = (int) $this->argument('analysis_id');
        $analysis = Analysis::find($analysisId);

        if ($analysis === null) {
            $this->error("Analysis(id={$analysisId})が見つかりません。");

            return self::FAILURE;
        }

        if ($analysis->crawl_site === true) {
            $this->info("Analysis(id={$analysisId})は既にcrawl_site=trueです。");

            return self::SUCCESS;
        }

        $analysis->update(['crawl_site' => true]);
        $this->info("Analysis(id={$analysisId})をcrawl_site=trueに設定しました。");
        $this->line('この診断のWebsiteAnalysis(自社・競合それぞれ)がRenderPageJobを完了した時点で、');
        $this->line('CrawlWebsiteJobが自動的に起動されます(既にRenderPageJobが完了済みの場合は起動されません)。');

        return self::SUCCESS;
    }
}

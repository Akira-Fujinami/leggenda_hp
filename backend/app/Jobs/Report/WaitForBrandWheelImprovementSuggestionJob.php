<?php

namespace App\Jobs\Report;

use App\Models\Analysis;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\Report;
use App\Services\Lead\LeadReportDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 依頼AM-1(2026-08-28、本番analysis_id=110): GenerateLeadReportJobは
 * ViewModelを1回だけ組み立ててWord/PDF両方に使い回すため、改善提案
 * (BrandWheelImprovementSuggestion)がまだpendingのまま組み立てると、
 * 理由・中長期の差別化ポイントが入らないレポートが完成してしまう。
 *
 * LeadReportDispatchService::createPendingReportsAndDispatch()は、
 * 改善提案がまだ確定していない(isBrandWheelImprovementSuggestionSettled()
 * =false)場合、Report行を作らずこのJobをdispatchする。このJobは短い間隔で
 * 確定を待ち、確定した時点(またはリトライ上限に達した時点)で
 * createPendingReportsAndDispatch()を強行モード
 * (forceWithoutImprovementSuggestion=true)で呼び直す ―― レポート生成
 * そのものを失敗させない(依頼AF-2・AA-3と同じ方針)ため、永久に待たず、
 * 上限到達後は改善提案なし(依頼AF-3の代替文言が出る状態)でレポートを
 * 完成させる。
 *
 * GenerateLeadReportJob自体(ShouldBeUnique、$tries=2、uniqueFor=600)には
 * 一切手を入れない ―― 待機・リトライのロジックはこの独立したJobに閉じ、
 * GenerateLeadReportJobは「レポートを作る」責務だけを保ったまま、確定後に
 * 1回だけ起動される(このJob自身がShouldBeUniqueなので、リードの
 * ポーリングによる重複dispatchも1個に収束する)。
 *
 * $tries×release()の間隔は本番実測(改善提案の生成が約7〜8秒で完了した
 * ケース)に十分な余裕を持たせつつ、リードを長く待たせすぎない範囲
 * (合計で概ね1分程度)にした。ShouldBeUniqueのロックはrelease()を挟んでも
 * 解放されず、TTLは最初の取得時点から固定で延長されない
 * (GenerateBrandWheelImprovementSuggestionJobやGenerateBrandWheelAnalysisJobの
 * コンストラクタで既に確認済みの事実)ため、uniqueForはリトライ窓全体を
 * 覆う値にする必要がある。
 */
class WaitForBrandWheelImprovementSuggestionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 6;

    public $timeout = 30;

    /**
     * 各リトライの間隔(秒)。$this->attempts()は1始まりのため、
     * release(self::RETRY_DELAY_SECONDS)を最大5回(tries-1回)呼ぶ
     * ―― 合計の待機時間は概ね5×15=75秒。
     */
    private const RETRY_DELAY_SECONDS = 15;

    public $uniqueFor = 300;

    public function __construct(public readonly int $analysisId) {}

    public function uniqueId(): string
    {
        return "wait-for-brand-wheel-suggestion:{$this->analysisId}";
    }

    public function handle(LeadReportDispatchService $reportDispatch): void
    {
        $analysis = Analysis::find($this->analysisId);

        if ($analysis === null) {
            return;
        }

        // 既に別経路(リードのポーリング等)でReport行が作られていれば
        // 何もしない(LeadReportDispatchService::createPendingReportsAndDispatch()
        // 自体が呼び出し元を問わずこの確認を行うが、無駄なAnalysisロード・
        // 判定を避けるためここでも早期に確認する)。
        if (Report::query()->where('analysis_id', $analysis->id)->exists()) {
            return;
        }

        $settled = $reportDispatch->isBrandWheelImprovementSuggestionSettled($analysis);
        $triesExhausted = $this->attempts() >= $this->tries;

        if (! $settled && ! $triesExhausted) {
            $this->release(self::RETRY_DELAY_SECONDS);

            return;
        }

        if (! $settled) {
            // 依頼AN-1(2026-08-28): 上限到達は正常系ではない ―― 改善提案が
            // 正常に失敗(status='error')した場合は終端のためisBrandWheel
            // ImprovementSuggestionSettled()が真になりこの分岐に来ない。
            // ここに来るのは「$tries×release()の待機時間を経ても pending の
            // まま」という、想定していない状態だけであり、依頼AF〜AMで
            // 直してきた「理由・中長期が入ったレポート」がそのまま失われる
            // (依頼AF-3の代替文言が出る状態に戻る)。依頼AL-1の原則
            // (正常な非生成=info、異常=warning)に照らし、これはinfoではなく
            // warningにする ―― 依頼AN-2(上限75秒が5件同時実行時にも足りるか
            // の実測)の判断材料として、実際に何秒待ったかも記録する。
            $suggestion = BrandWheelImprovementSuggestion::query()->where('analysis_id', $analysis->id)->first();
            $waitedSeconds = $suggestion?->created_at !== null ? abs(now()->diffInSeconds($suggestion->created_at)) : null;

            Log::warning('Giving up waiting for the brand wheel improvement suggestion; generating the lead report without it', [
                'analysis_id' => $this->analysisId,
                'attempts' => $this->attempts(),
                'waited_seconds_since_suggestion_created' => $waitedSeconds,
            ]);
        }

        $reportDispatch->createPendingReportsAndDispatch($analysis, forceWithoutImprovementSuggestion: true);
    }
}

<?php

namespace App\Http\Controllers\Api\Lead;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Report\GenerateLeadReportJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\Project;
use App\Models\Report;
use App\Models\WebsiteAnalysis;
use App\Exceptions\Analysis\AnalysisException;
use App\Services\Analysis\AnalysisService;
use App\Services\Analysis\SafeHttpFetcher;
use App\Services\BrandWheel\BrandWheelCompletionNotifier;
use App\Services\BrandWheel\BrandWheelComparisonSummaryComposer;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\BrandWheel\BrandWheelReportEligibility;
use App\Services\Lead\LeadNotificationService;
use App\Services\Lead\LeadPerspectiveComposer;
use App\Services\Lead\LeadRecommendationComposer;
use App\Services\Lead\LeadScoreCalculator;
use App\Services\Lead\LeadCompanyResolver;
use App\Services\Lead\LeadSessionService;
use App\Services\WebsiteService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * リード向け簡易分析(自社1件+競合1件、または自社のみ)。既存の
 * AnalysisService::start()/StartAnalysisJob/AnalysisPipelineをそのまま
 * 再利用する ―― 分析ロジックを別系統で作らない。認可はProjectPolicy等の
 * Gateを使わず、LeadSession(ResolveLeadTokenミドルウェアが解決済み)と
 * 対象リソースのlead_session_idが一致するかをここで直接確認する。
 * リード分析ではCaptureScreenshotJob自体を省略するため(skip_screenshots)、
 * スクリーンショット配信エンドポイントは持たない。
 */
class LeadAnalysisController extends Controller
{
    public function __construct(
        private readonly LeadSessionService $leadSessions,
        private readonly LeadCompanyResolver $leadCompanies,
        private readonly WebsiteService $websites,
        private readonly AnalysisService $analyses,
        private readonly LeadNotificationService $notifications,
        private readonly LeadRecommendationComposer $recommendationComposer,
        private readonly BrandWheelLeadResponseComposer $brandWheelComposer,
        private readonly BrandWheelComparisonSummaryComposer $brandWheelSummaryComposer,
        private readonly BrandWheelCompletionNotifier $brandWheelNotifier,
        private readonly SafeHttpFetcher $safeHttpFetcher,
    ) {}

    /**
     * 画面向けの「特に改善効果が見込まれる項目」の件数。Word/PDFレポート
     * (ReportViewModelBuilder::MAX_RECOMMENDATIONS)とは意図的に別値 ――
     * 画面は要点のみ、レポートは持ち帰り資料として少し多めに載せる。
     */
    private const TOP_RECOMMENDATIONS_LIMIT = 3;

    public function store(Request $request): JsonResponse
    {
        /** @var LeadSession $leadSession */
        $leadSession = $request->attributes->get('leadSession');

        if (! $this->leadSessions->canStartAnalysis($leadSession)) {
            // 「開けない」という問い合わせの原因切り分け用(2026-07-29対応)。
            // トークン値・会社名等の個人情報は記録しない。
            Log::info('Lead analysis start rejected: quota exceeded', ['lead_session_id' => $leadSession->id]);

            return response()->json([
                'message' => 'このリンクでの診断は既にご利用いただいております。追加のご相談はお問い合わせフォームからお願いいたします。',
                'errors' => [],
                'error_code' => 'LEAD_ANALYSIS_QUOTA_EXCEEDED',
            ], 403);
        }

        if ($this->leadSessions->hasExceededMaxAttempts($leadSession)) {
            // 2026-08-24追加: 成果を受け取れていない(analyses_used未消費)
            // まま試行だけを繰り返しているケースの歯止め(白紙レポート防止に
            // 伴う無制限リトライ対策、依頼者指定)。
            Log::info('Lead analysis start rejected: max attempts exceeded', ['lead_session_id' => $leadSession->id]);

            return response()->json([
                'message' => 'このリンクでの診断は何度かお試しいただいておりますが、結果をご用意できておりません。お手数ですがお問い合わせフォームからご連絡ください。',
                'errors' => [],
                'error_code' => 'LEAD_ANALYSIS_MAX_ATTEMPTS_EXCEEDED',
            ], 403);
        }

        if ($this->leadSessions->hasAnalysisInProgress($leadSession)) {
            // 診断回数の消費(recordAnalysisStarted())を開始直後ではなく
            // 自社サイトの本文取得成功時点へ遅らせているため、消費されるまでの
            // 間はcanStartAnalysis()が通り続ける。同一トークンでの多重受付を
            // ここで別途防ぐ(LeadSessionService::hasAnalysisInProgress()参照)。
            Log::info('Lead analysis start rejected: already in progress', ['lead_session_id' => $leadSession->id]);

            return response()->json([
                'message' => '既に診断を実行中です。完了までしばらくお待ちください。',
                'errors' => [],
                'error_code' => 'LEAD_ANALYSIS_IN_PROGRESS',
            ], 409);
        }

        if ($this->isCongested()) {
            // トークンを消費してはいけない(混雑は本人の責任ではないため)。
            return response()->json([
                'message' => '現在混み合っています。しばらくしてから再度お試しください。',
                'errors' => [],
                'error_code' => 'LEAD_ANALYZER_BUSY',
            ], 503);
        }

        $data = $request->validate([
            'self_url' => ['required', 'string', 'max:2048'],
            'competitor_url' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($this->isSelfUrlUnreachable($data['self_url'])) {
            // 401/403/429で自社サイトを読み取れなかった場合は、Project/Website/
            // Analysisのいずれも作らず、トークンも一切消費しない(#B-1)。
            // URL自体は個人情報ではないがログには含めない(既存の他の拒否ログと
            // 同じ方針)。
            Log::info('Lead analysis start rejected: self url unreachable', ['lead_session_id' => $leadSession->id]);

            return response()->json([
                'message' => 'このURLは読み取れませんでした。別のURLをお試しください。なお、この診断はご利用回数に含まれておりません。',
                'errors' => [],
                'error_code' => 'SELF_URL_UNREACHABLE',
            ], 422);
        }

        // 2026-08-24追加: ここから先は実際に診断を開始する(=試行1回として
        // 数える)。SELF_URL_UNREACHABLE(上記)はこの前でreturnしているため
        // カウントされない。
        $this->leadSessions->recordAnalysisAttempted($leadSession);

        $sentinelUser = $this->leadSessions->sentinelUser();

        $project = new Project(['name' => "{$leadSession->company_name} 様セルフ診断"]);
        $project->user_id = $sentinelUser->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $selfWebsite = $this->websites->create($project, ['name' => '自社サイト', 'url' => $data['self_url'], 'is_primary' => true]);

        // 管理者ダッシュボード(営業リード管理)向け。相談リクエストの有無に
        // 関わらず、診断を実行した時点で企業として蓄積する(依頼者指定)。
        // 失敗しても診断そのものは止めない(既存の通知送信と同じ方針)。
        try {
            $leadCompany = $this->leadCompanies->resolveForDiagnosis($leadSession, $data['self_url']);
            $project->lead_company_id = $leadCompany->id;
            $project->save();
        } catch (Throwable $e) {
            report($e);
            Log::warning('Lead company resolution failed', ['lead_session_id' => $leadSession->id]);
        }

        if (! empty($data['competitor_url'])) {
            $this->websites->create($project, ['name' => '比較サイト', 'url' => $data['competitor_url'], 'is_primary' => false]);
        }

        $analysis = $this->analyses->start($project, [
            'max_websites' => (int) config('lead.max_websites'),
            'skip_lighthouse' => (bool) config('lead.skip_lighthouse'),
            // CaptureScreenshotJobは77指標のうち1つも記録しないため、
            // リード分析では撮影自体を省略する(採点への影響はゼロ)。
            'skip_screenshots' => (bool) config('lead.skip_screenshots'),
            // ブランド・ホイール(6軸)分析は診断実行時に自社・競合の両方で
            // 生成する(2026-08-03、相談ボタン起点のディスパッチは廃止)。
            // skip_brand_wheelの既定はtrue(実行しない)なので、リード側だけが
            // 明示的にfalseを渡す。
            'skip_brand_wheel' => false,
            // 依頼L-1(2026-08-25): LEAD_CRAWL_SITE(既定false)で本番の
            // リード診断における巡回・条件付きレンダリングを制御する。
            // env未設定なら従来どおりcrawl_site=false。
            'crawl_site' => (bool) config('lead.crawl_site'),
        ], $sentinelUser);

        // 2026-08-22: 実行回数の消費(recordAnalysisStarted())はここでは行わない。
        // 自社サイトの本文取得成功(2xx かつ 文字数閾値以上)が確定した時点
        // (GenerateBrandWheelAnalysisJob::maybeConsumeLeadQuota())へ後ろに
        // ずらした(#B-2、依頼者との合意事項)。ここで即座に消費すると、
        // 取得自体に失敗した場合(=B-1のチェックをすり抜けた一時的な失敗等)にも
        // リードの唯一の診断回数が失われてしまうため。

        // 通知はnotificationsキュー経由の非同期送信のため、ここでの失敗は
        // このレスポンス(=リードの診断開始そのもの)に一切影響しない。
        $rawToken = (string) $request->attributes->get('leadToken');
        $this->notifications->notifyAnalysisStarted($leadSession, $analysis->id, $rawToken);

        return $this->success(['analysis_id' => $analysis->id], [], null, 201);
    }

    public function progress(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);
        $this->maybeDispatchReportGeneration($analysis, $request);

        return $this->success([
            'percent' => $analysis->progress,
            'status' => $this->leadFacingStatus($analysis->status),
            'message' => $this->leadFacingMessage($analysis->status),
        ]);
    }

    public function results(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);
        $this->maybeDispatchReportGeneration($analysis, $request);

        $analysis->load([
            'websiteAnalyses.website',
            'websiteAnalyses.recommendations.metricResult.metricDefinition',
            'websiteAnalyses.metricResults.metricDefinition',
            // is_mock/status以外に絞る必要はない(1件のみ使うため軽量)。
            // latest('id')でwebsite_analysis_idあたり1件(最新)に絞る。
            'websiteAnalyses.brandWheelAnalysisResults' => fn ($query) => $query->latest('id')->limit(1),
        ]);

        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();
        $calculator = app(LeadScoreCalculator::class);
        $perspectiveComposer = app(LeadPerspectiveComposer::class);

        $brandWheelByWebsiteAnalysisId = $analysis->websiteAnalyses->mapWithKeys(
            fn ($wa) => [$wa->id => $this->brandWheelComposer->compose($wa->brandWheelAnalysisResults->first(), $wa->website)],
        );

        $websites = $analysis->websiteAnalyses->map(function ($wa) use ($activeDefinitions, $calculator, $perspectiveComposer, $brandWheelByWebsiteAnalysisId) {
            // 社内版(OverallScoreCalculator、7カテゴリ100点)とは別建ての
            // リード向け点数 ―― 4観点(LeadMetricCatalog)に表示している
            // 指標だけを対象に算出するため、画面の4観点表示と数値が
            // 完全に一致する(2026-07-28のユーザー指摘への対応)。
            // 社内版のスコアとは満点も内訳も異なるため、商談時に
            // 混同しないこと。
            $score = $calculator->calculate($activeDefinitions, $wa->metricResults);

            return [
                'website_name' => $wa->website?->name,
                'is_primary' => (bool) $wa->website?->is_primary,
                'score' => $score->toArray(),
                // 採用担当向けの4観点(書くべきこと/メッセージ/導線/見やすさ)。
                // 内部の7カテゴリ(technical_seo等)は意図的に含めない
                // ―― 採用担当の関心に沿わない情報量を増やさないため。
                'perspectives' => $perspectiveComposer->compose($wa->metricResults),
                // 指標77件の詳細一覧・Job名・エラーコード・内部IDは
                // 意図的に含めない(社内担当が説明する余地を残すため)。
                // title/descriptionはLeadRecommendationComposer経由 ―― 4観点
                // (LeadMetricCatalog)に無いキー(例: アクセス解析タグ検出)は
                // ここで絞り込まれ、一切表示されない(2026-08-03)。
                'top_recommendations' => collect($this->recommendationComposer->compose(
                    $wa->recommendations,
                    self::TOP_RECOMMENDATIONS_LIMIT,
                ))->map(fn (array $row) => [
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'priority' => $row['recommendation']->priority->value,
                    'impact' => $row['recommendation']->impact->value,
                    'effort' => $row['recommendation']->effort->value,
                ])->values(),
                // ブランド・ホイール(6軸)。2026-08-03からリード向け画面の主役。
                // evidence(原文の抜粋)は含めない ―― 社員向けメールには必要だが、
                // 画面に出す必要はなく、含めればそれだけ他社サイトの本文が
                // 外部に出る。
                'brand_wheel' => $brandWheelByWebsiteAnalysisId->get($wa->id),
            ];
        })->values();

        return $this->success([
            'status' => $this->leadFacingStatus($analysis->status),
            'reports' => $this->reportStatusPayload($analysis),
            'websites' => $websites,
            'brand_wheel_comparison' => $this->composeBrandWheelComparison($analysis, $brandWheelByWebsiteAnalysisId),
        ]);
    }

    /**
     * 【自社ページ】【他社ページ】【ワンポイント】。いずれもAIには書かせず、
     * BrandWheelLeadResponseComposerが組み立てたaxes(status!=='success'なら
     * 空配列)の件数から機械的に導出する(2026-08-03のユーザー指摘)。
     * ワンポイントは自社サイトの軸のみを対象に判定する(競合の状態では
     * 分岐させない)。
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $brandWheelByWebsiteAnalysisId
     * @return array{self_points: list<string>, competitor_points: list<string>, one_point: array{key: string, text: string}|null}
     */
    private function composeBrandWheelComparison(Analysis $analysis, $brandWheelByWebsiteAnalysisId): array
    {
        $selfWa = $analysis->websiteAnalyses->first(fn ($wa) => (bool) $wa->website?->is_primary);
        $competitorWa = $analysis->websiteAnalyses->first(fn ($wa) => ! (bool) $wa->website?->is_primary);

        $selfAxes = $selfWa !== null ? (array) ($brandWheelByWebsiteAnalysisId->get($selfWa->id)['axes'] ?? []) : [];
        $competitorAxes = $competitorWa !== null ? (array) ($brandWheelByWebsiteAnalysisId->get($competitorWa->id)['axes'] ?? []) : [];

        return [
            'self_points' => $this->brandWheelSummaryComposer->points($selfAxes),
            'competitor_points' => $this->brandWheelSummaryComposer->points($competitorAxes),
            'one_point' => $this->brandWheelSummaryComposer->onePoint($selfAxes),
        ];
    }

    /**
     * リード向けレポート(Word/PDF)のダウンロード。既存のAnalysisController::
     * screenshot()と同じ方針(Storageへ直接アクセスさせず、DBが指す
     * storage_pathのみ配信)を踏襲する。storage_path・error_message等の
     * 内部情報はレスポンスに一切含めない。
     */
    public function downloadReport(Request $request, Analysis $analysis, string $format): StreamedResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);

        $formatEnum = ReportFormat::tryFrom($format);

        if ($formatEnum === null) {
            abort(404);
        }

        $report = Report::query()
            ->where('analysis_id', $analysis->id)
            ->where('format', $formatEnum->value)
            ->first();

        if ($report === null || $report->status !== ReportGenerationStatus::Completed) {
            throw new HttpResponseException(response()->json([
                'message' => 'レポートはまだ準備中です。しばらくしてから再度お試しください。',
                'errors' => [],
                'error_code' => 'REPORT_NOT_READY',
            ], 409));
        }

        if (! Storage::disk('analysis')->exists($report->storage_path)) {
            abort(404);
        }

        $filename = '診断レポート.'.$formatEnum->fileExtension();

        return Storage::disk('analysis')->download($report->storage_path, $filename, [
            'Content-Type' => $formatEnum->contentType(),
        ]);
    }

    /**
     * 「もっと他社と比較したい/相談したい」ボタン。二重送信防止は
     * LeadSessionService::recordConsultationRequested()の条件付きUPDATEに
     * 一任する(このメソッド自身はread-then-writeの判定を行わない)。
     * 2回目以降の押下もエラーにはせず、既に送信済みである旨を返す
     * (ユーザーから見て連打が失敗として見えないようにするため)。
     *
     * 2026-08-03: ブランド・ホイール(6軸)分析Jobのdispatchはここでは
     * 行わない(診断実行時にAnalysisPipeline::dispatchWebsiteFanOut()から
     * 自社・競合の両方について既に生成済み、または生成中)。ここでは
     * 生成済みの結果を読み、2通目メールを送ってよいか
     * (BrandWheelCompletionNotifier)を判定するだけ ―― まだ生成が完了して
     * いなければ、Job側の終端時に同じ判定が再度行われ、その時点で送られる
     * (どちらが先に起きても送られる、2026-08-03のユーザー指摘)。
     */
    public function requestConsultation(Request $request, Analysis $analysis): JsonResponse
    {
        $this->authorizeLeadOwnsAnalysis($request, $analysis);

        /** @var LeadSession $leadSession */
        $leadSession = $request->attributes->get('leadSession');

        $isFirstRequest = $this->leadSessions->recordConsultationRequested($leadSession);

        if ($isFirstRequest) {
            $rawToken = (string) $request->attributes->get('leadToken');
            $this->notifications->notifyConsultationRequested(
                $leadSession,
                $analysis->id,
                $rawToken,
                $this->scoreSummaryFor($analysis),
            );

            $this->notifyBrandWheelCompletionIfReady($analysis);
        }

        return $this->success(['already_requested' => ! $isFirstRequest]);
    }

    /**
     * 自社サイトのBrandWheelAnalysisResultを読み、2通目メールを送ってよいかを
     * BrandWheelCompletionNotifierに判定させる。失敗してもこのレスポンス・
     * 1通目メールには一切影響させない(例外はここで握りつぶし、ログにのみ残す)。
     */
    private function notifyBrandWheelCompletionIfReady(Analysis $analysis): void
    {
        try {
            $analysis->loadMissing('websiteAnalyses.website');
            $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn ($wa) => (bool) $wa->website?->is_primary);

            if ($selfWebsiteAnalysis === null) {
                // 通常の経路(store())では自社サイトのWebsiteは必ずis_primary=trueで
                // 作られるため、ここに来るのは想定外のデータ不整合である可能性が
                // 高い。運用上検知されるべき障害としてLog::errorで記録する
                // (2026-07-29の指摘によりLog::warningから格上げ)。
                Log::error('Brand wheel completion check skipped: no primary WebsiteAnalysis found', [
                    'analysis_id' => $analysis->id,
                ]);

                return;
            }

            $record = BrandWheelAnalysisResult::query()
                ->where('website_analysis_id', $selfWebsiteAnalysis->id)
                ->latest('id')
                ->first();

            if ($record === null) {
                // skip_brand_wheelがtrueだった等、生成そのものが行われて
                // いない(通常のリード診断経路では起こらないはずの状態不整合)。
                Log::warning('Brand wheel completion check skipped: no BrandWheelAnalysisResult found', [
                    'analysis_id' => $analysis->id,
                ]);

                return;
            }

            $this->brandWheelNotifier->notifyIfReady($record);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Brand wheel completion check failed', ['analysis_id' => $analysis->id]);
        }
    }

    /**
     * 通知メール本文向けの一文サマリー。LeadScoreCalculator(4観点スコープの
     * リード向けスコア)を再利用し、社内版のOverallScoreCalculatorには
     * 一切触れない。
     */
    private function scoreSummaryFor(Analysis $analysis): string
    {
        $analysis->loadMissing(['websiteAnalyses.website', 'websiteAnalyses.metricResults.metricDefinition']);

        $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn ($wa) => (bool) $wa->website?->is_primary);

        if ($selfWebsiteAnalysis === null) {
            return '(診断結果を取得できませんでした)';
        }

        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();
        $score = app(LeadScoreCalculator::class)->calculate($activeDefinitions, $selfWebsiteAnalysis->metricResults);

        return sprintf(
            '%d点 / %d点(測定カバー率%s%%)',
            $score->displayScore,
            (int) round($score->configuredMaxScore),
            number_format($score->coverageRate, 1),
        );
    }

    /**
     * 結果が終端状態(completed/partial)になった最初のポーリングで一度だけ
     * レポート生成Jobを起動する。Reportテーブルの行をこの場で即座に作って
     * から冪等性のガードにするため、Job自体がまだ実行されていない
     * (=行がまだ無い)間に2回目のポーリングが来ても二重dispatchしない。
     * 同時リクエストでの競合は(analysis_id, format)のunique制約違反として
     * 検出し、その場合は既に他方が作成済みとみなして何もしない。
     * failedの分析にはレポートを生成しない(表示できる結果が何もないため)。
     * 既存のFinalizeWebsiteAnalysisJob/FinalizeAnalysisJob等の共有
     * パイプラインには一切触れない。
     *
     * 2026-08-24追加、2026-08-25に閾値を引き上げ: Analysis.status自体が
     * completed/partialでも、自社サイトのブランド・ホイール判定が
     * error/insufficient_input、またはmatched件数が
     * config('brand_wheel.report_eligibility_min_matched')(既定6)未満の
     * 場合はレポートの該当セクションが実質「白紙」または顧客提出可能な
     * 品質に届かない(8/24 shinkin.co.jpの調査、8/24発行レポート33で判明)。
     * この場合はReport行こそ作るが
     * status=SkippedのままGenerateLeadReportJobを起動しない ―― 行を作らないと
     * singleReportStatus()が永久に'processing'を返し画面が固まったままに
     * なるため(依頼者指摘)。判定基準はGenerateBrandWheelAnalysisJob::
     * maybeConsumeLeadQuota()(診断回数消費の可否)と同じ
     * BrandWheelReportEligibility を共用する。Skippedになった場合は
     * リード本人・社内スタッフへそれぞれ1回だけ通知する(Report行の
     * 作成自体が(analysis_id, format)のunique制約で1回しか成功しないため、
     * この通知も自然に1診断につき1回に収まる)。
     */
    private function maybeDispatchReportGeneration(Analysis $analysis, Request $request): void
    {
        $status = $this->leadFacingStatus($analysis->status);

        if (! in_array($status, ['completed', 'partial'], true)) {
            return;
        }

        if (Report::query()->where('analysis_id', $analysis->id)->exists()) {
            return;
        }

        $selfResult = $this->selfBrandWheelResult($analysis);
        $reportable = app(BrandWheelReportEligibility::class)->isReportable($selfResult);

        try {
            foreach ([ReportFormat::Docx, ReportFormat::Pdf] as $format) {
                Report::query()->create([
                    'analysis_id' => $analysis->id,
                    'format' => $format->value,
                    'storage_path' => '',
                    'status' => $reportable ? ReportGenerationStatus::Pending->value : ReportGenerationStatus::Skipped->value,
                    'error_message' => $reportable ? null : '自社サイトのブランド・ホイール分析がレポートに値する結果を持たなかったため、生成を見送りました。',
                ]);
            }
        } catch (QueryException $e) {
            // 想定しているのは(analysis_id, format)の一意制約違反(23505、
            // 457行目のexists()チェックと後続のcreate()の間で別プロセスが
            // 先にReportを作成した場合)のみ。それ以外(カラム欠落等の本当の
            // スキーマ不一致)を同じ握りつぶしに含めると、8月の障害同様に
            // 無言で通過してしまう(2026-08-24修正)。ここは進捗/結果ポーリング
            // エンドポイントの副作用のため、再スローはせずログのみに留める
            // (173行目の通知送信と同じ方針: このJobの失敗でレスポンス自体を
            // 壊さない)。
            if ((string) $e->getCode() !== '23505') {
                Log::error('Failed to create lead report rows', [
                    'analysis_id' => $analysis->id,
                    'sqlstate' => $e->getCode(),
                    'exception_message' => $e->getMessage(),
                ]);
            }

            return;
        }

        if ($reportable) {
            GenerateLeadReportJob::dispatch($analysis->id)->onQueue('reports');

            return;
        }

        $this->notifyDiagnosisUnavailable($analysis, $selfResult, $request);
    }

    /**
     * @param  ?BrandWheelAnalysisResult  $selfResult
     */
    private function notifyDiagnosisUnavailable(Analysis $analysis, ?BrandWheelAnalysisResult $selfResult, Request $request): void
    {
        /** @var ?LeadSession $leadSession */
        $leadSession = $request->attributes->get('leadSession');

        if ($leadSession === null) {
            return;
        }

        $rawToken = (string) $request->attributes->get('leadToken');

        $this->notifications->notifyDiagnosisUnavailableToLead($leadSession, $rawToken);

        $this->notifications->notifyDiagnosisUnavailableToStaff(
            $leadSession,
            $analysis->id,
            $this->diagnosisUnavailableReasonSummary($selfResult),
            $this->adminAnalysisUrl($analysis->id),
        );
    }

    /**
     * 内部のstatus文字列をそのまま渡さず、営業が読める日本語の要約に変換する
     * (スタックトレース・例外メッセージは一切含めない)。
     */
    private function diagnosisUnavailableReasonSummary(?BrandWheelAnalysisResult $selfResult): string
    {
        if ($selfResult === null) {
            return '自社サイトの分析結果が見つかりませんでした。';
        }

        return match ($selfResult->status) {
            'error' => '自社サイトの分析処理でエラーが発生しました。',
            'insufficient_input' => '自社サイトから十分な文章量を読み取れませんでした。',
            'success' => '自社サイトから該当する記述が見つかりませんでした。',
            default => '診断結果をご用意できませんでした。',
        };
    }

    private function adminAnalysisUrl(int $analysisId): string
    {
        return route('admin.analyses.show', $analysisId);
    }

    /**
     * 自社サイト(is_primary=true)の最新BrandWheelAnalysisResultを取得する。
     * GenerateBrandWheelAnalysisJob::maybeConsumeLeadQuota()と同じ「自社
     * サイトのみを見る」スコープ(競合サイト側の結果は一切影響させない)。
     */
    private function selfBrandWheelResult(Analysis $analysis): ?BrandWheelAnalysisResult
    {
        $selfWebsiteAnalysis = WebsiteAnalysis::query()
            ->where('analysis_id', $analysis->id)
            ->whereHas('website', fn ($q) => $q->where('is_primary', true))
            ->first();

        if ($selfWebsiteAnalysis === null) {
            return null;
        }

        return BrandWheelAnalysisResult::query()
            ->where('website_analysis_id', $selfWebsiteAnalysis->id)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{docx: string, pdf: string}
     */
    private function reportStatusPayload(Analysis $analysis): array
    {
        $reports = Report::query()->where('analysis_id', $analysis->id)->get()->keyBy('format');

        return [
            'docx' => $this->singleReportStatus($reports->get(ReportFormat::Docx->value)),
            'pdf' => $this->singleReportStatus($reports->get(ReportFormat::Pdf->value)),
        ];
    }

    private function singleReportStatus(?Report $report): string
    {
        if ($report === null) {
            return 'processing';
        }

        return match ($report->status) {
            ReportGenerationStatus::Completed => 'ready',
            ReportGenerationStatus::Failed => 'unavailable',
            // Skipped(自社サイトのブランド・ホイール判定に実質的な中身が
            // 無かったための意図的な見送り)は、Failed(本当の生成失敗、
            // 診断回数は消費済み)とは別の'skipped'として区別する
            // (2026-08-24変更)。診断回数を消費していないため、フロント側は
            // 「別のURLで再挑戦できる」導線を出す必要があり、Failedと
            // 同じ'unavailable'に丸めると区別できなくなる。
            ReportGenerationStatus::Skipped => 'skipped',
            ReportGenerationStatus::Pending => 'processing',
        };
    }

    private function authorizeLeadOwnsAnalysis(Request $request, Analysis $analysis): void
    {
        /** @var LeadSession $leadSession */
        $leadSession = $request->attributes->get('leadSession');

        $analysis->loadMissing('project');

        if ($analysis->project?->lead_session_id !== $leadSession->id) {
            abort(404);
        }
    }

    /**
     * 自社サイト(self_url)への1回きりの到達性チェック(#B-1)。本番の実取得
     * (FetchStaticPageJob)と完全に同じ経路(backend/PHPからSafeHttpFetcher、
     * 同じallowed content-type)で1回だけ取得する ―― analyzer(Node)は
     * homepage.html/recruit.htmlの取得には使われていないため、そちらから
     * チェックすると外向きIPの違いにより「チェックは通ったのに本番の取得は
     * 403」という食い違いが起こり得る(依頼者との合意事項)。
     *
     * 401/403/429のみを「読み取れなかった」として扱う。タイムアウト・
     * 接続エラー・5xx等(SafeHttpFetcherが例外を投げるケースを含む)は
     * 相手側の一時的な事情の可能性があるため、保守的に「通す」側へ倒す
     * (診断自体は止めない)。リトライはしない(相手サイトへの負荷を
     * 増やさないため)。
     *
     * 2026-08-22追加: この呼び出しはリードが送信ボタンを押したまま待つ
     * 同期処理のため、本番の実取得(config('analysis.http.total_timeout_seconds')、
     * 既定20秒)より短いconfig('lead.self_url_reachability_check_timeout_seconds')
     * (既定6秒)で打ち切る。超過時の扱い(保守的に通す)は変わらないため、
     * 短くしても誤って診断をブロックすることはなく、待ち時間だけ減る。
     */
    private function isSelfUrlUnreachable(string $selfUrl): bool
    {
        try {
            $result = $this->safeHttpFetcher->fetch(
                $selfUrl,
                ['text/html', 'application/xhtml+xml'],
                totalTimeoutSeconds: (int) config('lead.self_url_reachability_check_timeout_seconds'),
            );
        } catch (AnalysisException) {
            return false;
        }

        return in_array($result->httpStatus, [401, 403, 429], true);
    }

    /**
     * 2026-08-22追加: LeadSessionService::hasAnalysisInProgress()と同じ理由
     * (停止したAnalysisを対象から除外する)。除外しないと、停止した1件が
     * max_concurrent_analyses(既定1)に達したまま居座り、全リードが
     * 503で無期限にブロックされ得る(依頼者指摘)。閾値の根拠は
     * config/lead.phpのコメント参照。
     */
    private function isCongested(): bool
    {
        $inFlight = Analysis::query()
            ->whereHas('project', fn ($q) => $q->whereNotNull('lead_session_id'))
            ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Running])
            ->where('created_at', '>=', now()->subMinutes((int) config('lead.stale_analysis_after_minutes')))
            ->count();

        return $inFlight >= (int) config('lead.max_concurrent_analyses');
    }

    private function leadFacingStatus(AnalysisStatus $status): string
    {
        return match ($status) {
            AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Running => 'processing',
            AnalysisStatus::Completed => 'completed',
            AnalysisStatus::Partial => 'partial',
            AnalysisStatus::Failed, AnalysisStatus::Cancelled => 'failed',
        };
    }

    private function leadFacingMessage(AnalysisStatus $status): string
    {
        return match ($status) {
            AnalysisStatus::Pending, AnalysisStatus::Queued => '診断の準備をしています…',
            AnalysisStatus::Running => 'サイトを診断しています。しばらくお待ちください…',
            AnalysisStatus::Completed => '診断が完了しました。',
            AnalysisStatus::Partial => '診断が完了しました(一部のデータは取得できませんでした)。',
            AnalysisStatus::Failed, AnalysisStatus::Cancelled => '診断に失敗しました。時間をおいて再度お試しください。',
        };
    }
}

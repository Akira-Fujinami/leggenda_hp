<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Report\ComparisonSlideInsertionException;
use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Services\Admin\AdminComparisonService;
use App\Services\Admin\AnalysisAttachmentService;
use App\Services\Report\AdminComparisonPptxInserter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * 依頼AB(2026-08-27): 無料診断を起点に、管理画面から自社+競合3〜5社の
 * 比較を実行する。起点は既存の無料診断詳細画面(admin.analyses.show)のみ
 * ―― 独立した起票フォームは作らない(依頼者指定)。
 *
 * 依頼BI(2026-09-08): 起票フォームに、営業資料(PPTX)の添付を追加した。
 * 差し込みが行えない資料(スライドサイズ不一致・参照元ページなし)を
 * 送信時点で弾く ―― 比較(自社+競合3〜5社、それぞれ最大50ページ巡回、
 * 数十分かかる)を実行してから気づく事故を防ぐため(依頼BI-3、主目的)。
 *
 * 依頼BP(2026-09-10): 会社名から起点の無料診断を探して選べる、チャット風
 * ウィザード(admin.comparisons.wizard)を追加した。AIは使わない(決まった
 * 順番の質問を1問ずつ出すだけ、自由文の解釈はしない、依頼者指定)。
 * 送信の最終経路はstore()のまま変更しない ―― ウィザードはJSで
 * `<form>`のactionを`/admin/analyses/{id}/compare`に組み立てて、既存の
 * create.blade.phpと同じ<form>をPOSTするだけ(比較を作る処理を別に
 * 書かない、依頼者最重要指定)。search()はJSON専用の読み取り専用エンドポイント
 * ―― 比較を作る処理には一切関与しない。
 */
class ComparisonController extends Controller
{
    /**
     * 依頼BW-2(2026-09-11): 比較レポート一覧のページング件数。診断管理
     * (AnalysisController::PER_PAGE=30)より少なくしている ―― 比較は
     * 無料診断全体よりずっと少数(自社+競合3〜5社の起票のみ)で、営業が
     * 日常的に見返す一覧のため、1ページで見渡しやすい件数を優先する。
     */
    private const PER_PAGE = 20;

    public function __construct(
        private readonly AdminComparisonService $comparisons,
        private readonly AnalysisAttachmentService $attachments,
        private readonly AdminComparisonPptxInserter $pptxInserter,
    ) {}

    /**
     * 依頼BW-2(この依頼で新設): 比較レポートの一覧。source_analysis_idが
     * 非nullのAnalysisのみを対象にする(依頼AB-2と同じ既存方針、サイト数
     * からの推測はしない)。営業が日常的に使う画面(依頼者指定)のため、
     * ダッシュボード・サイドバーの両方からここへ導線を張る(BW-3)。
     *
     * N+1を避けるため、一覧に必要な関連(自社企業名・競合社数・PPTX添付
     * 有無)をすべてwith()で先読みする。
     */
    public function index(Request $request): View
    {
        $comparisons = Analysis::query()
            ->whereNotNull('source_analysis_id')
            ->with(['project.leadCompany', 'project.websites', 'attachments', 'reports'])
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            // scheme+hostを含まないパスに固定する(AnalysisController::index()
            // と同じ理由)。
            ->setPath($request->getPathInfo());

        return view('admin.comparisons.index', [
            'comparisons' => $comparisons,
        ]);
    }

    /**
     * 比較の起票フォーム。自社URLは起点の無料診断から引き継いで初期値に
     * するが、編集可能にする(依頼者への提案どおり ―― 診断後にサイトの
     * URLが変わっている場合等に対応するため)。競合URLの1件目には、起点の
     * 無料診断で使った競合URLがあれば初期値として入れる(提案どおり)。
     */
    public function create(Analysis $analysis): View
    {
        abort_unless($analysis->project?->lead_company_id !== null, 404);
        abort_if($analysis->source_analysis_id !== null, 404, '比較を起点に、さらに比較を作ることはできません。');

        $analysis->loadMissing(['project.websites', 'project.leadCompany']);

        $selfWebsite = $analysis->project->websites->firstWhere('is_primary', true);
        $existingCompetitorUrl = $analysis->project->websites->firstWhere('is_primary', false)?->url;

        // 依頼BI-1: 20MB・スライドサイズ・検出語は画面に直書きせず、
        // configから出す(依頼者指定)。EMU→cmの換算はここで行い、
        // blade側には計算を持たせない(1EMU=1/360000cm)。
        $requiredCx = (int) config('admin_comparison_pptx.required_slide_width_emu');
        $requiredCy = (int) config('admin_comparison_pptx.required_slide_height_emu');

        return view('admin.comparisons.create', [
            'analysis' => $analysis,
            'selfUrl' => $selfWebsite?->url,
            'existingCompetitorUrl' => $existingCompetitorUrl,
            'minCompetitors' => (int) config('analysis.admin_comparison.min_competitors', 3),
            'maxCompetitors' => (int) config('analysis.admin_comparison.max_competitors', 5),
            'salesDeckMaxSizeMb' => (int) round((int) config('analysis_attachment.max_file_size_bytes') / 1024 / 1024),
            'salesDeckSlideSizeLabel' => sprintf('%.2f × %.2f cm', $requiredCx / 360000, $requiredCy / 360000),
            'salesDeckReferenceKeywords' => (array) config('admin_comparison_pptx.reference_page_keywords'),
        ]);
    }

    /**
     * 依頼BP-1: 会社名で起点の診断を探すところから始める、チャット風の
     * ウィザード。会社を選ぶ前(起点のAnalysisが未定)にアクセスするため、
     * ルートパラメータを取らない ―― create()/store()の
     * `/admin/analyses/{analysis}/compare` とは別のURL
     * (`/admin/comparisons/wizard`)にする。
     *
     * 送信に失敗して戻ってきた場合(バリデーションエラー)、STEP 1〜3の
     * 入力を失わないこと(依頼者指定)。競合URL/企業名/ファイル欄は
     * 通常のold()でフォームが復元されるが、STEP 1(検索文言)・STEP 2
     * (選んだ診断)はフォームの入力欄ではなくJS側の状態でしか保持されて
     * いないため、hidden inputとして`company_query`/`source_analysis_id`を
     * POSTに含め、old()から読み戻して再現する(依頼者指定「途中状態を
     * DB・セッションに保存しない」に反しない ―― old()はリクエストの
     * バリデーション失敗時にのみ1往復だけ保持されるフラッシュセッションで、
     * 「中断された下書き」が溜まる仕組みとは異なる)。
     */
    public function wizard(Request $request): View
    {
        $selectedAnalysisId = old('source_analysis_id');
        $selectedAnalysis = null;

        if ($selectedAnalysisId !== null && $selectedAnalysisId !== '') {
            $selectedAnalysis = Analysis::query()
                ->whereHas('project', fn ($q) => $q->whereNotNull('lead_company_id'))
                ->whereNull('source_analysis_id')
                ->with(['project.leadCompany', 'project.websites'])
                ->find($selectedAnalysisId);
        }

        $requiredCx = (int) config('admin_comparison_pptx.required_slide_width_emu');
        $requiredCy = (int) config('admin_comparison_pptx.required_slide_height_emu');

        return view('admin.comparisons.wizard', [
            'selectedAnalysis' => $selectedAnalysis,
            'minCompetitors' => (int) config('analysis.admin_comparison.min_competitors', 3),
            'maxCompetitors' => (int) config('analysis.admin_comparison.max_competitors', 5),
            'salesDeckMaxSizeMb' => (int) round((int) config('analysis_attachment.max_file_size_bytes') / 1024 / 1024),
            'salesDeckSlideSizeLabel' => sprintf('%.2f × %.2f cm', $requiredCx / 360000, $requiredCy / 360000),
            'salesDeckReferenceKeywords' => (array) config('admin_comparison_pptx.reference_page_keywords'),
        ]);
    }

    /**
     * 依頼BP-2: 会社名(部分一致)で、起点にできる無料診断を探す。JSONのみ
     * 返す読み取り専用の口 ―― 比較を作る処理には一切関与しない。
     *
     * 起点にできる条件はComparisonController::create()・
     * AdminComparisonService::createFromSourceAnalysis()と同じ
     * (project.lead_company_idが非null、source_analysis_idがnull =
     * 比較自身は候補に出さない)。
     *
     * 返す情報は診断ID・会社名・自社サイトURLのホスト名・診断日・状態のみ
     * (依頼者指定 ―― 担当者名・メールアドレス・電話番号・トークンは
     * 返さない)。
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json(['results' => [], 'truncated' => false]);
        }

        // 依頼BP-2: 全角・半角の違いを吸収する。半角カナ→全角・全角英数記号→
        // 半角に統一する(mb_convert_kanaのK/V/a、BrandWheelAnalysisResponseParser
        // ::normalizeForEvidenceMatch()と同じ考え方)。DB側の会社名の表記
        // (全角/半角どちらで登録されているか)までは統一できないため、
        // 「検索文字列を正規化してから照合する」片側だけの対応になる ――
        // 日本の企業名は全角で登録されるのが通例のため、半角カナで検索
        // されたときに全角の登録名へ一致させる向きを優先した(逆向き
        // (全角で検索して半角登録の名前に当てる)は実例が乏しいと判断)。
        $normalizedQuery = mb_strtolower(mb_convert_kana($query, 'KVa', 'UTF-8'), 'UTF-8');

        $limit = (int) config('analysis.admin_comparison.search_result_limit', 20);

        $matches = Analysis::query()
            ->whereHas('project', fn ($q) => $q->whereNotNull('lead_company_id'))
            ->whereNull('source_analysis_id')
            ->whereHas('project.leadCompany', function ($q) use ($normalizedQuery) {
                // 依頼BP-2: 大文字小文字を無視する。LIKEの大文字小文字の
                // 扱いはDBエンジンごとに異なる(Postgresは既定で大文字小文字を
                // 区別する)ため、両辺をLOWER()に通してDBに依存しない形にする。
                $q->whereRaw('LOWER(company_name) LIKE ?', ['%'.$normalizedQuery.'%']);
            })
            ->with(['project.leadCompany', 'project.websites'])
            ->orderByDesc('created_at')
            // 依頼BP-2: 超過判定のためlimit+1件だけ取得する(件数を数える
            // 追加クエリを避ける)。超過時は候補を1件も返さず、絞り込みを促す
            // (依頼者指定 ―― 上限ぎりぎりの一覧を出すより、まず絞り込ませる)。
            ->limit($limit + 1)
            ->get();

        if ($matches->count() > $limit) {
            return response()->json(['results' => [], 'truncated' => true]);
        }

        $results = $matches->map(function (Analysis $analysis) {
            $selfWebsite = $analysis->project?->websites?->firstWhere('is_primary', true);
            $selfHost = $selfWebsite?->url !== null
                ? strtolower((string) parse_url($selfWebsite->url, PHP_URL_HOST))
                : null;

            return [
                'id' => $analysis->id,
                'company_name' => $analysis->project?->leadCompany?->company_name,
                'self_host' => $selfHost !== '' ? $selfHost : null,
                'analyzed_at' => $analysis->created_at?->format('Y年n月j日'),
                'status' => $analysis->status->value,
            ];
        })->values();

        return response()->json(['results' => $results, 'truncated' => false]);
    }

    public function store(Request $request, Analysis $analysis): RedirectResponse
    {
        abort_unless($analysis->project?->lead_company_id !== null, 404);

        $data = $request->validate([
            'self_url' => ['nullable', 'string', 'max:2048'],
            'competitor_urls' => ['required', 'array'],
            'competitor_urls.*' => ['nullable', 'string', 'max:2048'],
            'competitor_names' => ['nullable', 'array'],
            'competitor_names.*' => ['nullable', 'string', 'max:255'],
            // 依頼BR-1(2026-09-11): sales_deckに'file'ルールを含めない。
            // PHP層(upload_max_filesize)で弾かれたアップロードは、Laravel
            // 標準の'file'ルールだと英語の汎用メッセージで落ちてしまう
            // (AnalysisAttachmentService::assertUploadSucceeded()参照、
            // 下のvalidateSalesDeck()呼び出し前で日本語のまま判定する)。
        ]);

        // 依頼BM-3: URLを入力した行は、企業名も必須にする(空欄だとホスト名の
        // 自動生成に頼ることになり、比較レポート・営業資料差し込み用
        // スライドの列見出しが「hello-world.smarthr.co.…」のような読めない
        // 表記になっていたため、依頼者指摘)。URLが空の行は企業名も空でよい
        // (=行自体が未使用)。$request->validate()の配列内でCloudureルール
        // として書くと、"nullable"が先に評価され、空文字列は
        // ConvertEmptyStringsToNullミドルウェアでnullに変換済みのため
        // 後続のClosureが一切呼ばれず素通りしてしまう(実機で確認した
        // Laravelのnullableの仕様) ―― そのため$request->validate()の外側で、
        // 検証済みの$dataに対して独立してチェックする。
        $this->assertCompetitorNamesGivenWhenUrlPresent($data['competitor_urls'], $data['competitor_names'] ?? []);

        // 依頼BI-3(この依頼の主目的、必須の順序): 比較を作成する前に、
        // 添付予定の営業資料を検証する。比較は自社+競合3〜5社をそれぞれ
        // 最大50ページ巡回し、数十分かかる ―― 差し込めない資料のために
        // それを走らせてから気づくのが最悪の出方(依頼者指摘)。
        $salesDeck = $request->file('sales_deck');
        // 依頼BR-1: PHP層でのアップロード失敗(ini_size等)を、
        // 拡張子・構造の検証より先に日本語のまま弾く。
        $this->attachments->assertUploadSucceeded($salesDeck, 'sales_deck');
        if ($salesDeck !== null) {
            $this->validateSalesDeck($salesDeck);
        }

        $comparison = $this->comparisons->createFromSourceAnalysis(
            $analysis,
            $data['self_url'] ?? null,
            $data['competitor_urls'],
            $data['competitor_names'] ?? [],
        );

        if ($salesDeck !== null) {
            // 依頼BI-2: 添付先は「新しく作られた比較Analysis」(起点の診断
            // ではない) ―― 差し込みの導線は比較側の詳細画面に出るため。
            try {
                $this->attachments->store($comparison, $salesDeck);
            } catch (Throwable $e) {
                report($e);
                // 依頼BI-2: 保存(手順3)に失敗しても、既に走り始めた比較は
                // 止めない。詳細画面の添付欄から再アップロードしてもらう。
                Log::warning('Failed to attach the sales deck after starting a comparison analysis', [
                    'analysis_id' => $comparison->id,
                ]);

                return redirect()
                    ->route('admin.analyses.show', $comparison->id)
                    ->with('status', "比較(診断ID: {$comparison->id})を開始しましたが、資料の添付に失敗しました。この画面の添付欄からもう一度アップロードしてください。");
            }
        }

        return redirect()
            ->route('admin.analyses.show', $comparison->id)
            ->with('status', "比較(診断ID: {$comparison->id})を開始しました。");
    }

    /**
     * 依頼BM-3: URLを入力した行(=使う行)は、企業名も必須にする。URLが
     * 空の行(=未使用の行)は企業名も空でよい。入力済みの値を失わないよう
     * (依頼BIと同じ方針)、ValidationExceptionで戻す ―― 通常の
     * $request->validate()と同じくold()で復元される。
     *
     * @param  list<string|null>  $urls
     * @param  list<string|null>  $names
     */
    private function assertCompetitorNamesGivenWhenUrlPresent(array $urls, array $names): void
    {
        $errors = [];

        foreach ($urls as $index => $url) {
            $url = trim((string) $url);
            $name = trim((string) ($names[$index] ?? ''));

            if ($url !== '' && $name === '') {
                $errors["competitor_names.{$index}"] = ['URLを入力した行には、企業名も入力してください。'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * 依頼BI-3: 二重実装しない ―― 拡張子・実際の中身(マジックバイト)・
     * サイズは既存のAnalysisAttachmentService::assertBasicUploadIsValid()、
     * スライドサイズ・参照元ページはAdminComparisonPptxInserter::validate()
     * (依頼BG/BHの差し込み判定そのもの)をそのまま呼ぶ。
     *
     * この比較作成フォームはPPTX専用(差し込み専用の欄のため) ―― PDF/DOCXは
     * ここでは拒否する(詳細画面の添付欄は引き続き3拡張子とも受け付ける、
     * 依頼者指定)。
     */
    private function validateSalesDeck(UploadedFile $file): void
    {
        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($extension !== 'pptx') {
            throw ValidationException::withMessages([
                'sales_deck' => ['営業資料はPowerPoint(.pptx)のみ添付できます。'],
            ]);
        }

        try {
            // 依頼BI-3: assertBasicUploadIsValid()はAnalysisAttachmentController
            // (フィールド名"file")と共用のため、エラーを常に"file"キーで
            // 投げる。この画面のフィールド名は"sales_deck"のため、詰め替えて
            // 投げ直す(そのままだと画面にエラーが表示されない、依頼者指摘の
            // 「入力が消えたまま原因不明になる」事故そのもの)。
            $this->attachments->assertBasicUploadIsValid($file);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'sales_deck' => $e->errors()['file'] ?? ['営業資料を確認してください。'],
            ]);
        }

        try {
            $this->pptxInserter->validate($file->getRealPath());
        } catch (ComparisonSlideInsertionException $e) {
            throw ValidationException::withMessages(['sales_deck' => [$e->getMessage()]]);
        }
    }
}

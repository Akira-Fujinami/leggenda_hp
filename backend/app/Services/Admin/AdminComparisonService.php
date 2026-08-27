<?php

namespace App\Services\Admin;

use App\Enums\AnalysisStatus;
use App\Exceptions\InvalidUrlException;
use App\Models\Analysis;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Services\Analysis\AnalysisService;
use App\Services\Lead\LeadCompanyResolver;
use App\Services\UrlNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 依頼AB(2026-08-27): 管理画面から、無料診断(自社1×競合1)を起点に
 * 自社+競合3〜5社(合計4〜6サイト)の比較を実行する。
 *
 * 【新しいジョブ体系は作らない】既存のAnalysisService::start()
 * (既にwebsite_ids/max_websitesを受け取れる、N社対応済み)へそのまま乗せる
 * ―― ここで行うのは「起点の無料診断から自社URL・企業情報を引き継ぎ、
 * 新しいProject/Websitesを組み立てて渡す」ことだけ。
 *
 * 【Projectを分ける】既存のProjectへwebsitesを追加する方式は採らない
 * (依頼者指定 ―― 既存の集計・表示が2サイト前提のまま壊れる恐れがある)。
 * 新しいProjectを作り、起点と同じlead_company_idを設定することで、
 * /admin/companies/{company}に無料診断と比較が自然に並ぶ
 * (lead_session_idは引き継がない ―― 紐づけの主軸はlead_company_idにする、
 * 依頼者指定。lead:purge-expired-sessionsでlead_sessionが消えてもlead_company
 * は残るため)。lead_session_idをnullのままにすることで、
 * GenerateBrandWheelAnalysisJob::maybeConsumeLeadQuota()のガード
 * ($analysis->project->lead_session_id !== nullが前提)により、
 * リードの診断回数は自動的に消費されない(新しいバイパスフラグは不要)。
 *
 * 【起点への紐づけ】analyses.source_analysis_id(自己参照、nullOnDelete)に
 * 起点のAnalysis IDを明示的に記録する。サイト数からの暗黙の判別はしない。
 */
class AdminComparisonService
{
    public function __construct(
        private readonly UrlNormalizer $urlNormalizer,
        private readonly AnalysisService $analyses,
        private readonly LeadCompanyResolver $leadCompanyResolver,
    ) {}

    /**
     * @param  list<string>  $competitorUrls  未検証・未正規化の生URL
     * @param  list<string|null>  $competitorNames  $competitorUrlsと同じ添字に対応する、
     *                                              管理者入力の企業名(任意)。空欄の要素は
     *                                              URLのドメインから自動生成する(依頼AC)。
     */
    public function createFromSourceAnalysis(Analysis $sourceAnalysis, ?string $selfUrl, array $competitorUrls, array $competitorNames = []): Analysis
    {
        // 依頼AB-3: 管理者起点の比較は同時に1件まで。サイト数からの推測では
        // なく、source_analysis_idが非nullの実行中Analysisの有無で判定する
        // (=「比較であること」を明示的に表すこの依頼の設計そのものを、
        // 同時実行ガードにもそのまま使う)。
        $inProgress = Analysis::query()
            ->whereNotNull('source_analysis_id')
            ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Running])
            ->exists();

        if ($inProgress) {
            throw ValidationException::withMessages([
                'competitor_urls' => ['他の比較が実行中です。完了してから開始してください(管理者起点の比較は同時に1件までです)。'],
            ]);
        }

        // 比較の起点にできるのは、比較でも比較の起点でもない通常の無料診断
        // (企業に紐づくもの)のみ。比較から比較は作れない。
        if ($sourceAnalysis->source_analysis_id !== null) {
            throw ValidationException::withMessages([
                'source_analysis_id' => ['比較を起点に、さらに比較を作ることはできません。'],
            ]);
        }

        $leadCompanyId = $sourceAnalysis->project?->lead_company_id;
        if ($leadCompanyId === null) {
            throw ValidationException::withMessages([
                'source_analysis_id' => ['この診断には企業情報が紐づいていないため、比較を作成できません。'],
            ]);
        }

        $min = (int) config('analysis.admin_comparison.min_competitors', 3);
        $max = (int) config('analysis.admin_comparison.max_competitors', 5);

        // 依頼AC: competitor_namesはcompetitor_urlsと同じ添字に対応する
        // (フォームの並び順)。空URLを間引く際、名前も同じ添字集合で
        // 間引いて対応関係を保つ(単純にarray_valuesし直すと片方だけ
        // ずれて誤った企業名がURLに紐づく恐れがあるため)。
        $trimmedUrls = array_map('trim', $competitorUrls);
        $nonEmptyKeys = array_keys(array_filter($trimmedUrls, fn (string $u) => $u !== ''));
        $competitorUrls = array_values(array_map(fn ($k) => $trimmedUrls[$k], $nonEmptyKeys));
        $competitorNames = array_values(array_map(
            fn ($k) => trim((string) ($competitorNames[$k] ?? '')),
            $nonEmptyKeys,
        ));

        if (count($competitorUrls) < $min || count($competitorUrls) > $max) {
            throw ValidationException::withMessages([
                'competitor_urls' => ["競合サイトは{$min}〜{$max}件で入力してください。"],
            ]);
        }

        $resolvedSelfUrl = $selfUrl !== null && trim($selfUrl) !== ''
            ? $selfUrl
            : $sourceAnalysis->project?->websites?->firstWhere('is_primary', true)?->url;

        if ($resolvedSelfUrl === null) {
            throw ValidationException::withMessages([
                'self_url' => ['自社サイトのURLを取得できませんでした。'],
            ]);
        }

        // URLの形式・スキーム検証(UrlNormalizer、依頼者指定 ――
        // 管理者入力だからといって省かない)+ 正規化ホストでの重複検出
        // (自社・競合すべてを合わせて、同一ホストの重複を弾く)。
        $normalizedSelfUrl = $this->normalizeOrFail($resolvedSelfUrl, 'self_url');
        $allUrls = array_merge([$normalizedSelfUrl], array_map(
            fn (string $u, int $i) => $this->normalizeOrFail($u, "competitor_urls.{$i}"),
            $competitorUrls,
            array_keys($competitorUrls),
        ));

        $hosts = array_map(fn (string $u) => (string) parse_url($u, PHP_URL_HOST), $allUrls);
        if (count($hosts) !== count(array_unique($hosts))) {
            throw ValidationException::withMessages([
                'competitor_urls' => ['同一ホストのURLが重複しています(自社・競合を含む)。'],
            ]);
        }

        return DB::transaction(function () use ($sourceAnalysis, $leadCompanyId, $normalizedSelfUrl, $resolvedSelfUrl, $competitorUrls, $competitorNames) {
            $sentinelUser = $this->sentinelUser();

            $project = new Project(['name' => "比較: {$sourceAnalysis->project?->leadCompany?->company_name}"]);
            $project->user_id = $sentinelUser->id;
            $project->lead_company_id = $leadCompanyId;
            // lead_session_idは意図的に設定しない(クラスdocblock参照)。
            $project->save();

            Website::query()->create([
                'project_id' => $project->id,
                'name' => '自社サイト',
                'url' => trim($resolvedSelfUrl),
                'normalized_url' => $normalizedSelfUrl,
                'is_primary' => true,
                'display_order' => 0,
            ]);

            foreach (array_values($competitorUrls) as $i => $rawUrl) {
                Website::query()->create([
                    'project_id' => $project->id,
                    'name' => $this->competitorLabel($competitorNames[$i] ?? '', $rawUrl, $i),
                    'url' => trim($rawUrl),
                    'normalized_url' => $this->urlNormalizer->normalize($rawUrl),
                    'is_primary' => false,
                    // 依頼AB-3: display_orderを入力順のまま保持する(レポートの
                    // 列順に使う、依頼者指定)。自社=0、競合=1始まり。
                    'display_order' => $i + 1,
                ]);
            }

            $analysis = $this->analyses->start($project, [
                // 依頼AB-3: config('analysis.max_websites_per_analysis')
                // (既定5)は自社+競合5社の合計6件より小さいことがあるため、
                // ここで明示的に渡す(既存のconfig既定値は変更しない)。
                'max_websites' => $project->websites()->count(),
                'skip_lighthouse' => false,
                'skip_screenshots' => false,
                // ブランド・ホイールは必ず実行する(比較の中核のため)。
                'skip_brand_wheel' => false,
                // 依頼W-1の調査結果を踏まえ、既定はconfig('lead.crawl_site')に
                // 揃える(リード向けと同じ巡回方針)。
                'crawl_site' => (bool) config('lead.crawl_site'),
            ], $sentinelUser);

            $analysis->update(['source_analysis_id' => $sourceAnalysis->id]);

            return $analysis->fresh(['websiteAnalyses', 'project.websites']);
        });
    }

    /**
     * 依頼AC-2: 比較レポートの表の列見出しには実際の社名が必要
     * (「競合1」等の記号表記は不可、依頼者指定)。依頼AB-1の起票フォームは
     * URLしか集めていなかったため、管理者が任意で入力した企業名を優先し、
     * 空欄ならLeadCompanyResolver::extractDomain()と同じ方法でURLの
     * ドメインから自動生成する(依頼Rで既に確立した重複パターンを再利用)。
     * ドメイン抽出にも失敗した場合のみ、従来の記号表記にフォールバックする。
     */
    private function competitorLabel(string $adminProvidedName, string $rawUrl, int $index): string
    {
        if ($adminProvidedName !== '') {
            return $adminProvidedName;
        }

        $domain = $this->leadCompanyResolver->extractDomain($rawUrl);

        return $domain ?? '競合サイト'.($index + 1);
    }

    private function normalizeOrFail(string $rawUrl, string $field): string
    {
        try {
            return $this->urlNormalizer->normalize($rawUrl);
        } catch (InvalidUrlException $e) {
            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }
    }

    /**
     * 依頼AB-0: 管理画面はDBにユーザーを作らない共有アカウント方式
     * (admin.auth、Laravel Authのユーザーが存在しない)。リード経路
     * (LeadSessionService::sentinelUser())と同じfirstOrCreateの仕組みを
     * 使うが、projects.user_id/analyses.created_byの表示上、リードの
     * 診断と混同しないよう別のsentinelユーザーとして区別する。
     */
    private function sentinelUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'admin-comparison@internal.invalid'],
            ['name' => 'Admin Multi-Competitor Comparison (System)', 'password' => Hash::make(Str::random(64))],
        );
    }
}

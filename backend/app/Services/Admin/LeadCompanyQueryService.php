<?php

namespace App\Services\Admin;

use App\Models\Analysis;
use App\Models\LeadCompany;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 診断企業一覧(管理者ダッシュボード)の検索・絞り込み・並び替え・集計を
 * 1クエリで行う。企業が数千社になってもN+1を起こさないよう、診断回数・
 * 初回/最終診断日はwithCount/withMin/withMaxで、最新診断結果は相関
 * サブクエリでまとめて取得する(依頼#25)。
 */
class LeadCompanyQueryService
{
    private const PER_PAGE = 30;

    /**
     * @param  array{search?: ?string, sales_status?: ?string, diagnosis_status?: ?string, re_diagnosed?: ?string, sort?: ?string, direction?: ?string}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applySearch($query, $filters['search'] ?? null);
        $this->applySalesStatusFilter($query, $filters['sales_status'] ?? null);
        $this->applyDiagnosisStatusFilter($query, $filters['diagnosis_status'] ?? null);
        $this->applyReDiagnosedFilter($query, $filters['re_diagnosed'] ?? null);
        $this->applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null);

        // setPath()でscheme+hostを含まないパスに固定する ―― admin.guest.blade.php
        // のfetch URLと同じ理由(Renderがtrust proxies未設定でX-Forwarded-Protoを
        // 信頼しないため、絶対URL生成だとhttp://になりmixed contentで
        // ブロックされうる)。JSON API(Api\ProjectController等)のページネーション
        // には影響しない(呼び出し経路が別)。
        return $query->paginate(self::PER_PAGE)->withQueryString()->setPath(request()->getPathInfo());
    }

    /**
     * @return Builder<LeadCompany>
     */
    private function baseQuery(): Builder
    {
        return LeadCompany::query()
            ->withCount('analyses')
            ->withMin('analyses', 'created_at')
            ->withMax('analyses', 'created_at')
            ->addSelect(['latest_analysis_status' => Analysis::query()
                ->select('status')
                ->join('projects', 'projects.id', '=', 'analyses.project_id')
                ->whereColumn('projects.lead_company_id', 'lead_companies.id')
                ->orderByDesc('analyses.created_at')
                ->limit(1),
            ]);
    }

    /**
     * @param  Builder<LeadCompany>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $q) use ($search) {
            $q->where('company_name', 'like', "%{$search}%")
                ->orWhere('primary_contact_name', 'like', "%{$search}%")
                ->orWhere('primary_contact_email', 'like', "%{$search}%")
                ->orWhere('normalized_domain', 'like', "%{$search}%");
        });
    }

    /**
     * @param  Builder<LeadCompany>  $query
     */
    private function applySalesStatusFilter(Builder $query, ?string $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('sales_status', $status);
        }
    }

    /**
     * 「診断結果」フィルタは、その企業が対象ステータスの診断を1件でも
     * 持つかで絞る(直近の1件だけに厳密に限定しない、MVPの割り切り)。
     * 一覧の「最新診断結果」列は別途baseQuery()の相関サブクエリで最新の
     * ものだけを表示する。
     *
     * @param  Builder<LeadCompany>  $query
     */
    private function applyDiagnosisStatusFilter(Builder $query, ?string $status): void
    {
        if ($status !== null && $status !== '') {
            $query->whereHas('analyses', fn (Builder $q) => $q->where('status', $status));
        }
    }

    /**
     * @param  Builder<LeadCompany>  $query
     */
    private function applyReDiagnosedFilter(Builder $query, ?string $reDiagnosed): void
    {
        if ($reDiagnosed === 'yes') {
            $query->has('analyses', '>=', 2);
        } elseif ($reDiagnosed === 'no') {
            $query->has('analyses', '<', 2);
        }
    }

    /**
     * @param  Builder<LeadCompany>  $query
     */
    private function applySort(Builder $query, ?string $sort, ?string $direction): void
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'diagnosis_count' => $query->orderBy('analyses_count', $direction),
            'first_diagnosed_at' => $query->orderBy('analyses_min_created_at', $direction),
            default => $query->orderBy('analyses_max_created_at', $direction),
        };
    }
}

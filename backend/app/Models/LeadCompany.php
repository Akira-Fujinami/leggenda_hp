<?php

namespace App\Models;

use Database\Factories\LeadCompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * 無料診断を実行した企業を営業リードとして蓄積する台帳(管理者ダッシュボード
 * MVP)。App\Services\Lead\LeadCompanyResolver が診断実行時(analyses起点)に
 * find-or-createする。会社名・担当者名・メールアドレスを保持するため、
 * ログ・例外メッセージへ直接出力しないこと(App\Models\LeadSessionと同じ方針)。
 */
#[Fillable(['company_name', 'normalized_domain', 'primary_contact_name', 'primary_contact_email', 'sales_status', 'sales_note'])]
class LeadCompany extends Model
{
    /** @use HasFactory<LeadCompanyFactory> */
    use HasFactory;

    /**
     * analyses_min_created_at/analyses_max_created_at は実カラムではなく
     * withMin/withMax('analyses', 'created_at')(App\Services\Admin\
     * LeadCompanyQueryService/DashboardMetricsService)が付与する集計値。
     * Eloquentはこれらをデフォルトでは生の文字列のまま返すため、Bladeで
     * ->format()を呼べるようdatetimeにキャストする。
     */
    protected function casts(): array
    {
        return [
            'analyses_min_created_at' => 'datetime',
            'analyses_max_created_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * 診断回数・初回/最終診断日はここから都度集計する(冗長にDB保持しない、
     * lead_companiesマイグレーションのコメント参照)。withCount/withMin/
     * withMaxで一覧・ダッシュボードのN+1を避ける。
     *
     * @return HasManyThrough<Analysis, Project, $this>
     */
    public function analyses(): HasManyThrough
    {
        return $this->hasManyThrough(Analysis::class, Project::class);
    }
}

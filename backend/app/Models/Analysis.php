<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Database\Factories\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'source_analysis_id', 'created_by', 'status', 'progress', 'started_at', 'completed_at', 'failed_at', 'error_summary', 'skip_lighthouse', 'skip_screenshots', 'skip_brand_wheel', 'lead_quota_consumed_at', 'crawl_site', 'lead_diagnosis_completed_notified_at'])]
class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'progress' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'skip_lighthouse' => 'boolean',
            'skip_screenshots' => 'boolean',
            'skip_brand_wheel' => 'boolean',
            'lead_quota_consumed_at' => 'datetime',
            'crawl_site' => 'boolean',
            'lead_diagnosis_completed_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * 依頼AB-2(2026-08-27): この診断が管理者起点の多社比較である場合、
     * 起点となった無料診断。通常の無料診断・比較でない診断は常にnull。
     *
     * @return BelongsTo<Analysis, $this>
     */
    public function sourceAnalysis(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_analysis_id');
    }

    /**
     * 依頼AB-2(2026-08-27): この診断(無料診断)を起点に作られた、
     * 管理者起点の多社比較の一覧(逆参照)。通常は0件。
     *
     * @return HasMany<Analysis, $this>
     */
    public function comparisons(): HasMany
    {
        return $this->hasMany(self::class, 'source_analysis_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<WebsiteAnalysis, $this>
     */
    public function websiteAnalyses(): HasMany
    {
        return $this->hasMany(WebsiteAnalysis::class);
    }

    /**
     * @return HasMany<AnalysisJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(AnalysisJob::class);
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * 依頼AD-1(2026-08-27): 商談相手ごとの既存資料。現時点ではアプリ層で
     * 1件に制限している(AnalysisAttachmentServiceのdocblock参照)が、
     * リレーション自体はhasManyのまま(将来複数件に対応する場合の
     * マイグレーション不要のため)。
     *
     * @return HasMany<AnalysisAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(AnalysisAttachment::class);
    }
}

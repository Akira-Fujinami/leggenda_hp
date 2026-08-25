<?php

namespace App\Models;

use Database\Factories\AnalysisCrawledPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * サイト全ページ巡回(依頼C・Phase 1)で新規発見したページ。既存の
 * `analysis_pages`(homepage/robots/sitemap/recruit、1種類1行)には含めない
 * ―― 詳細はマイグレーションのdocblock参照。
 *
 * @property string $url
 * @property string $url_hash
 */
#[Fillable([
    'website_analysis_id', 'url', 'final_url', 'http_status', 'content_type', 'content_length',
    'raw_html_path', 'rendered_html_path', 'title', 'depth', 'discovered_via', 'status',
    'render_candidate', 'fetched_at',
])]
class AnalysisCrawledPage extends Model
{
    /** @use HasFactory<AnalysisCrawledPageFactory> */
    use HasFactory;

    // status(依頼D、フロンティアの状態)。
    public const STATUS_PENDING = 'pending';

    public const STATUS_FETCHED = 'fetched';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXCLUDED_BY_PATTERN = 'excluded_by_pattern';

    public const STATUS_EXCLUDED_BY_ROBOTS = 'excluded_by_robots';

    public const STATUS_EXCLUDED_BY_SCOPE = 'excluded_by_scope';

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'content_length' => 'integer',
            'depth' => 'integer',
            'render_candidate' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * url_hashの生成箇所をここに集約する ―― 呼び出し側でhash()を書かせない
     * (依頼者指定)。urlを設定するたびに自動的に追随する。
     *
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => [
                'url' => $value,
                'url_hash' => hash('sha256', $value),
            ],
        );
    }

    /**
     * @return BelongsTo<WebsiteAnalysis, $this>
     */
    public function websiteAnalysis(): BelongsTo
    {
        return $this->belongsTo(WebsiteAnalysis::class);
    }
}

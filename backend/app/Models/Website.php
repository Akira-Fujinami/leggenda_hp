<?php

namespace App\Models;

use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'name', 'url', 'normalized_url', 'is_primary', 'display_order'])]
class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    /**
     * 依頼DW(2026-11-26): ページを取りに行くURL。normalized_url は重複の判定のために末尾の「/」を落としているが、
     * 入力したURLが「/」で終わっていたときは「/」を付けて取りに行く。
     *
     * 本番の産経新聞社(analysis_id=214)で、https://www.sankei.jp/saiyo/ を https://www.sankei.jp/saiyo として取りに行き、
     * Amazon S3 の配信が「/」なしのURLに中身もページの種類(Content-Type)も無い応答を返して、取得に失敗した
     * (ブラウザ・「/」付きのURLでは普通に表示される)。多くのサイトは「/」なしを「/」付きへ転送するので、他のサイトの結果は変わらない。
     */
    public function fetchUrl(): string
    {
        $normalized = (string) $this->normalized_url;
        $rawPath = (string) (parse_url(trim((string) $this->url), PHP_URL_PATH) ?? '');
        $normalizedPath = (string) (parse_url($normalized, PHP_URL_PATH) ?? '');
        if ($normalized === '' || ! str_ends_with($rawPath, '/') || $normalizedPath === '' || str_ends_with($normalizedPath, '/')) {
            return $normalized;
        }
        if (parse_url($normalized, PHP_URL_QUERY) !== null || parse_url($normalized, PHP_URL_FRAGMENT) !== null) {
            return $normalized;
        }

        return $normalized.'/';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

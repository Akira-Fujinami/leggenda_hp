<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'industry', 'purpose'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * nullの場合は社内ユーザーが通常操作で作成したProject。非nullの場合、
     * リード獲得フォーム経由でLeadSessionServiceが内部生成したProjectであり、
     * 社内画面の一覧には出さない(既存コントローラのuser_idスコープにより、
     * 専用のsentinelユーザーが所有する分だけで自然に除外される)。
     *
     * @return BelongsTo<LeadSession, $this>
     */
    public function leadSession(): BelongsTo
    {
        return $this->belongsTo(LeadSession::class);
    }

    /**
     * @return HasMany<Website, $this>
     */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class)->orderBy('display_order');
    }

    /**
     * @return HasMany<Analysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }
}

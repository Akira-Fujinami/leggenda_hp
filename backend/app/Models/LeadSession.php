<?php

namespace App\Models;

use Database\Factories\LeadSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * リード獲得フォームから発行されるワンタイムセッション。会社名・担当者名・
 * メールアドレス等の個人情報を保持するため、ログ・エラーメッセージ・
 * レポート・API応答にこのモデルの属性を直接出力しないこと。
 */
#[Fillable(['company_name', 'contact_name', 'email', 'phone', 'industry', 'employee_range', 'token_hash', 'expires_at', 'analyses_used'])]
class LeadSession extends Model
{
    /** @use HasFactory<LeadSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'analyses_used' => 'integer',
            'consultation_requested_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

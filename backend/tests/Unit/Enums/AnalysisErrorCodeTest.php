<?php

namespace Tests\Unit\Enums;

use App\Enums\AnalysisErrorCode;
use Tests\TestCase;

class AnalysisErrorCodeTest extends TestCase
{
    /**
     * 2026-08-24追加: SchemaMismatch(マイグレーション未適用等の定義不一致)は
     * リトライしても解決しないため非リトライ対象。8月の障害では、これが
     * 区別されておらずattempts:2で同じ失敗が8件記録された。
     */
    public function test_schema_mismatch_is_not_retryable(): void
    {
        $this->assertFalse(AnalysisErrorCode::SchemaMismatch->isRetryable());
    }

    /**
     * DatabaseError(デッドロック・接続断等)は一過性の可能性があるため
     * リトライ対象のままにする。
     */
    public function test_database_error_is_retryable(): void
    {
        $this->assertTrue(AnalysisErrorCode::DatabaseError->isRetryable());
    }
}

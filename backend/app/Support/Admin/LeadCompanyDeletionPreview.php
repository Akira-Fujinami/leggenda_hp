<?php

namespace App\Support\Admin;

/**
 * 依頼CI-2(2026-10-01): 会社単位の物理削除の確認画面(admin/companies/
 * delete.blade.php)が必要とする情報と、2つのガードの判定結果をまとめたもの。
 * App\Services\Admin\LeadCompanyDeletionService::preview()が組み立てる。
 *
 * destroy()は実際に削除する直前に同じpreview()をもう一度呼び、
 * isBlocked()を再確認する(画面を経由しない呼び出しからの保護 ――
 * 「画面でボタンを隠すだけで、サーバー側の判定を省くこと」の禁止に対応)。
 */
readonly class LeadCompanyDeletionPreview
{
    /**
     * @param  list<array{original_filename: string, size_bytes: int}>  $attachmentFiles
     * @param  list<array{id: int, company_name: string}>  $blockingCompanies  ガード2(セッションの又がり)で見つかった、またがっている相手の会社。空ならガード2は該当しない。
     */
    public function __construct(
        public int $sessionsCount,
        public int $diagnosesCount,
        public int $comparisonsCount,
        public int $reportFilesCount,
        public array $attachmentFiles,
        public int $totalBytes,
        public bool $hasRunningAnalyses,
        public array $blockingCompanies,
    ) {}

    public function isBlocked(): bool
    {
        return $this->hasRunningAnalyses || $this->blockingCompanies !== [];
    }
}

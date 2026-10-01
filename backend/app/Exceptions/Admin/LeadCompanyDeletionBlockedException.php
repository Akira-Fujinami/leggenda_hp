<?php

namespace App\Exceptions\Admin;

use App\Support\Admin\LeadCompanyDeletionPreview;

/**
 * 依頼CI-2(2026-10-01): 2つのガード(実行中の診断がある/セッションが
 * 別の会社にもまたがっている)のいずれかに該当する場合、
 * App\Services\Admin\LeadCompanyDeletionService::destroy()がこれを投げる。
 * $previewを保持することで、呼び出し元(CompanyController::destroy())が
 * config('lead_company_deletion')の文言のどちらを表示すべきか判断できる。
 */
class LeadCompanyDeletionBlockedException extends \RuntimeException
{
    public function __construct(public readonly LeadCompanyDeletionPreview $preview)
    {
        parent::__construct('Lead company deletion is blocked by a safety guard.');
    }
}

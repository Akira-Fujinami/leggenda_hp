<?php

namespace App\Services\Admin;

use App\Exceptions\Admin\LeadCompanyDeletionBlockedException;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\LeadCompany;
use App\Models\LeadCompanyDeletion;
use App\Models\LeadSession;
use App\Models\Project;
use App\Support\Admin\LeadCompanyDeletionPreview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 依頼CI-2(2026-10-01): 管理画面から、お客様(会社)のデータを物理削除する。
 * 削除の単位は「会社」(lead_companies 1行と、それに紐づくすべて)。
 *
 * 削除対象の特定はprojects.lead_company_id(lead_session_idではない) ――
 * App\Services\Admin\AdminComparisonServiceが作る多社比較のProjectは
 * lead_session_id=nullのため、lead_session_id起点では辿れない
 * (AdminComparisonService::createFromSourceAnalysis()のdocblock参照)。
 *
 * ファイルの収集・削除の実際の機構はApp\Services\Admin\
 * LeadDataDeletionServiceを再利用する(依頼CI-1、「コピーして2か所に
 * 持たない」要件)。
 */
class LeadCompanyDeletionService
{
    public function __construct(private readonly LeadDataDeletionService $deletionService) {}

    public function preview(LeadCompany $company): LeadCompanyDeletionPreview
    {
        $projects = $this->targetProjects($company);
        $analyses = $projects->flatMap(fn (Project $p) => $p->analyses);
        $sessionIds = $this->targetSessionIds($projects);

        $attachmentFiles = AnalysisAttachment::query()
            ->whereIn('analysis_id', $analyses->pluck('id'))
            ->get(['original_filename', 'size_bytes'])
            ->map(fn (AnalysisAttachment $a) => ['original_filename' => $a->original_filename, 'size_bytes' => $a->size_bytes])
            ->all();

        $fileTargets = $this->deletionService->collectFileTargets($analyses);
        $totalBytes = array_sum(array_column($fileTargets['storage_targets'], 'size'))
            + array_sum(array_column($fileTargets['attachment_targets'], 'size'));

        return new LeadCompanyDeletionPreview(
            sessionsCount: $sessionIds->count(),
            diagnosesCount: $projects->whereNotNull('lead_session_id')->count(),
            comparisonsCount: $projects->whereNull('lead_session_id')->count(),
            reportFilesCount: count($fileTargets['report_paths']),
            attachmentFiles: $attachmentFiles,
            totalBytes: $totalBytes,
            hasRunningAnalyses: $analyses->contains(fn (Analysis $a) => ! $a->status->isTerminal()),
            blockingCompanies: $this->blockingCompanies($company, $sessionIds),
        );
    }

    /**
     * @throws LeadCompanyDeletionBlockedException  ガード(実行中の診断/セッションの又がり)に該当する場合。
     * @throws ValidationException  入力された会社名が一致しない場合。
     */
    public function destroy(LeadCompany $company, string $confirmedName): LeadCompanyDeletion
    {
        // 依頼CI-2必須: 画面を経由しない直接呼び出しからも守るため、
        // ここでガードを再確認する(「画面でボタンを隠すだけで、サーバー側の
        // 判定を省くこと」の禁止に対応)。
        $preview = $this->preview($company);
        if ($preview->isBlocked()) {
            throw new LeadCompanyDeletionBlockedException($preview);
        }

        // 依頼CI-2必須(会社名の照合): 前後の空白はtrim()で無視するが、
        // 全角/半角の正規化は行わない ―― 正規化すると、表記ゆれで
        // たまたま似た別の会社名と一致してしまう余地が生まれる。不可逆な
        // 操作のため、曖昧な一致より厳格な完全一致(前後空白のみ無視)を
        // 選ぶ(表示された会社名をそのまま貼り付ければ必ず一致する)。
        if (trim($confirmedName) !== trim($company->company_name)) {
            throw ValidationException::withMessages([
                'confirmation_company_name' => [(string) config('lead_company_deletion.name_mismatch_error')],
            ]);
        }

        $projects = $this->targetProjects($company);
        $analyses = $projects->flatMap(fn (Project $p) => $p->analyses);
        $sessionIds = $this->targetSessionIds($projects);
        $sessions = LeadSession::query()->whereIn('id', $sessionIds)->get();

        // 依頼CI-1: DB削除の前にファイルパスを集めておく(cascadeで行が
        // 消えた後では辿れない)。
        $fileTargets = $this->deletionService->collectFileTargets($analyses);

        $diagnosesCount = $projects->whereNotNull('lead_session_id')->count();
        $comparisonsCount = $projects->whereNull('lead_session_id')->count();
        $companyId = $company->id;

        $record = DB::transaction(function () use ($projects, $sessions, $company, $diagnosesCount, $comparisonsCount, $companyId, $fileTargets) {
            foreach ($projects as $project) {
                $project->delete();
            }
            foreach ($sessions as $session) {
                $session->delete();
            }
            $company->delete();

            // 依頼CI-3: disk_bytes_freed/file_deletion_failures_countは
            // ファイル削除(トランザクションの外、コミット後)の結果を
            // あとから埋める。ここでは件数だけを先に確定させる。
            return LeadCompanyDeletion::create([
                'lead_company_id' => $companyId,
                'sessions_deleted_count' => $sessions->count(),
                'diagnoses_deleted_count' => $diagnosesCount,
                'comparisons_deleted_count' => $comparisonsCount,
                'report_files_deleted_count' => count($fileTargets['report_paths']),
                'attachment_files_deleted_count' => count($fileTargets['attachment_targets']),
                'analysis_directories_deleted_count' => count($fileTargets['storage_targets']),
                'disk_bytes_freed' => 0,
                'file_deletion_failures_count' => 0,
            ]);
        });

        // ここに到達した時点でDBのコミットは完了している。ファイル削除は
        // ベストエフォート(LeadDataDeletionService::deleteFiles()参照)。
        $deleted = $this->deletionService->deleteFiles(
            $fileTargets['report_paths'],
            $fileTargets['storage_targets'],
            $fileTargets['attachment_targets'],
        );

        $record->update([
            'disk_bytes_freed' => $deleted['freed_bytes'],
            'file_deletion_failures_count' => $deleted['failed_count'],
        ]);

        // 依頼CI(禁止事項): 会社名・担当者名・メール・電話・URL・添付資料の
        // ファイル名をログに出さない ―― 件数とID(いずれも個人情報ではない)
        // のみ。
        Log::warning('Admin deleted a lead company and all its data', [
            'lead_company_id' => $companyId,
            'lead_company_deletion_id' => $record->id,
            'sessions_deleted_count' => $sessions->count(),
            'diagnoses_deleted_count' => $diagnosesCount,
            'comparisons_deleted_count' => $comparisonsCount,
            'report_files_deleted_count' => count($fileTargets['report_paths']),
            'attachment_files_deleted_count' => count($fileTargets['attachment_targets']),
            'analysis_directories_deleted_count' => count($fileTargets['storage_targets']),
            'disk_bytes_freed' => $deleted['freed_bytes'],
            'file_deletion_failures_count' => $deleted['failed_count'],
        ]);

        return $record->fresh();
    }

    /**
     * @return Collection<int, Project>
     */
    private function targetProjects(LeadCompany $company): Collection
    {
        return Project::query()
            ->where('lead_company_id', $company->id)
            ->with(['analyses.reports'])
            ->get();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, int>
     */
    private function targetSessionIds(Collection $projects): Collection
    {
        return $projects->pluck('lead_session_id')->filter()->unique()->values();
    }

    /**
     * 依頼CI-0確認済みの前提: LeadCompanyResolverは診断ごとに解決するため、
     * 1つのLeadSessionが複数のLeadCompanyへ跨りうる。対象会社のセッションが
     * 他の(識別済みの)会社のProjectにも使われている場合、どちらの会社の
     * データかが一部重なるため自動では処理しない(依頼者指定)。
     * lead_company_id=nullのProject(解決失敗)は「別の会社」として
     * 数えない。
     *
     * @param  Collection<int, int>  $sessionIds
     * @return list<array{id: int, company_name: string}>
     */
    private function blockingCompanies(LeadCompany $company, Collection $sessionIds): array
    {
        if ($sessionIds->isEmpty()) {
            return [];
        }

        $others = Project::query()
            ->whereIn('lead_session_id', $sessionIds)
            ->where('lead_company_id', '!=', $company->id)
            ->whereNotNull('lead_company_id')
            ->with('leadCompany')
            ->get()
            ->pluck('leadCompany')
            ->filter()
            ->unique('id')
            ->values();

        return $others->map(fn (LeadCompany $other) => ['id' => $other->id, 'company_name' => $other->company_name])->all();
    }
}

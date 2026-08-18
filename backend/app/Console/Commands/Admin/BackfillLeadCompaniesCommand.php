<?php

namespace App\Console\Commands\Admin;

use App\Models\LeadSession;
use App\Models\Project;
use App\Services\Lead\LeadCompanyResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 管理者ダッシュボード導入前から存在していたリード診断(projects.
 * lead_company_idが未設定のもの)へ、LeadCompanyResolverを使って
 * 遡及的にLeadCompanyを紐付ける(依頼#26「既存データ移行」)。
 * 新規診断時と全く同じ判定ロジック(LeadCompanyResolver)を再利用するため、
 * 「バックフィル用の別ロジック」を持たない(ロジックの二重管理・乖離を防ぐ)。
 *
 * 既存データは一切削除・変更しない(projects.lead_company_idを埋めるだけ)。
 * デフォルトはdry-run(件数のプレビューのみ)、--executeで実際に更新する
 * (PurgeExpiredLeadSessionsコマンドと同じ安全設計)。
 */
#[Signature('admin:backfill-lead-companies {--execute : 実際にprojects.lead_company_idを更新する(指定しない場合は常にdry-run)}')]
#[Description('既存のリード診断(projects)へ、企業集約(lead_companies)を遡及的に紐付ける')]
class BackfillLeadCompaniesCommand extends Command
{
    public function __construct(private readonly LeadCompanyResolver $resolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $targets = Project::query()
            ->whereNotNull('lead_session_id')
            ->whereNull('lead_company_id')
            ->with(['leadSession', 'websites'])
            ->get();

        $this->line("対象project(lead_company_id未設定のリード診断): {$targets->count()}件");

        if ($targets->isEmpty()) {
            $this->info('バックフィル対象がありません。');

            return self::SUCCESS;
        }

        if (! $execute) {
            $this->newLine();
            $this->info('dry-runモードのため、何も更新していません。実際に更新するには --execute を指定してください。');

            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($targets, &$updated, &$skipped) {
            foreach ($targets as $project) {
                /** @var LeadSession|null $leadSession */
                $leadSession = $project->leadSession;

                if ($leadSession === null) {
                    // lead_session_idはnullOnDeleteのため、既にpurge済みの
                    // セッションを指していた場合はここに来る。会社名等の
                    // 個人情報がもう残っていないため、企業として復元できない。
                    $skipped++;

                    continue;
                }

                $selfWebsite = $project->websites->firstWhere('is_primary', true);
                $company = $this->resolver->resolveForDiagnosis($leadSession, $selfWebsite?->url);

                $project->lead_company_id = $company->id;
                $project->save();
                $updated++;
            }
        });

        $this->newLine();
        $this->info('バックフィルが完了しました。');
        $this->line("更新: {$updated}件");
        $this->line("スキップ(lead_session削除済み): {$skipped}件");

        return self::SUCCESS;
    }
}

<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisStatus;
use App\Exceptions\Analysis\AnalysisAlreadyRunningException;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalysisService
{
    /**
     * @param  array{website_ids?: array<int, int>}  $data
     */
    public function start(Project $project, array $data, User $user): Analysis
    {
        return DB::transaction(function () use ($project, $data, $user) {
            // 同時リクエストでも「実行中の分析は1件まで」を守るため、
            // プロジェクト行をロックしてから既存の実行中Analysisを確認する。
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();

            $alreadyRunning = Analysis::query()
                ->where('project_id', $lockedProject->id)
                ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Running])
                ->exists();

            if ($alreadyRunning) {
                throw new AnalysisAlreadyRunningException;
            }

            $websites = $this->resolveTargetWebsites($lockedProject, $data);

            $analysis = Analysis::query()->create([
                'project_id' => $lockedProject->id,
                'created_by' => $user->id,
                'status' => AnalysisStatus::Pending,
                'progress' => 0,
            ]);

            foreach ($websites as $website) {
                $analysis->websiteAnalyses()->create([
                    'website_id' => $website->id,
                    'status' => \App\Enums\WebsiteAnalysisStatus::Pending,
                    'progress' => 0,
                ]);
            }

            StartAnalysisJob::dispatch($analysis->id)->onQueue('analysis');

            return $analysis->fresh(['websiteAnalyses']);
        });
    }

    /**
     * @param  array{website_ids?: array<int, int>}  $data
     * @return \Illuminate\Support\Collection<int, \App\Models\Website>
     */
    private function resolveTargetWebsites(Project $project, array $data): \Illuminate\Support\Collection
    {
        $maxWebsites = (int) config('analysis.max_websites_per_analysis');

        if (isset($data['website_ids']) && $data['website_ids'] !== []) {
            $requestedIds = array_values(array_unique($data['website_ids']));

            $websites = $project->websites()->whereIn('id', $requestedIds)->get();

            if ($websites->count() !== count($requestedIds)) {
                throw ValidationException::withMessages([
                    'website_ids' => ['指定されたサイトの中に、このプロジェクトに属さないものが含まれています。'],
                ]);
            }

            if ($websites->count() > $maxWebsites) {
                throw ValidationException::withMessages([
                    'website_ids' => ["一度に分析できるサイトは{$maxWebsites}件までです。"],
                ]);
            }

            return $websites;
        }

        $websites = $project->websites()->orderBy('display_order')->limit($maxWebsites)->get();

        if ($websites->isEmpty()) {
            throw ValidationException::withMessages([
                'website_ids' => ['分析対象のサイトが登録されていません。先にサイトを登録してください。'],
            ]);
        }

        return $websites;
    }
}

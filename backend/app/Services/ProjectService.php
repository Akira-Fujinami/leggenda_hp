<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;

class ProjectService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Project
    {
        return $user->projects()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'industry' => $data['industry'] ?? null,
            'purpose' => $data['purpose'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        $project->fill($data)->save();

        return $project;
    }

    /**
     * Websiteは外部キーのcascade deleteで自動的に削除される。
     * 将来、分析結果やスクリーンショットファイル等の後始末が増えた場合に
     * 備え、削除処理はこのService層に集約する。
     */
    public function delete(Project $project): void
    {
        $project->delete();
    }
}

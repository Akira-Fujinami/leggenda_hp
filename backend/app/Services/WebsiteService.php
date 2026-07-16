<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebsiteService
{
    private const MAX_WEBSITES_PER_PROJECT = 5;

    public function __construct(private readonly UrlNormalizer $normalizer)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): Website
    {
        $normalizedUrl = $this->normalizeOrFail($data['url']);
        $isPrimary = (bool) ($data['is_primary'] ?? false);

        return DB::transaction(function () use ($project, $data, $normalizedUrl, $isPrimary) {
            // 同時リクエストでも件数・主サイト制限を守るため、プロジェクト行を
            // ロックしてから件数・重複・主サイトの有無を確認する。
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();

            // Eloquentのリレーションクエリビルダは where() 等を呼ぶたびに
            // 同一インスタンスへ条件が蓄積されてしまうため、チェックのたびに
            // $lockedProject->websites() を呼び直して必ず新しいビルダを使う。
            if ($lockedProject->websites()->count() >= self::MAX_WEBSITES_PER_PROJECT) {
                throw ValidationException::withMessages([
                    'url' => ['1つのプロジェクトに登録できるサイトは'.self::MAX_WEBSITES_PER_PROJECT.'件までです。'],
                ]);
            }

            if ($lockedProject->websites()->where('normalized_url', $normalizedUrl)->exists()) {
                throw ValidationException::withMessages([
                    'url' => ['このURLは既に登録されています。'],
                ]);
            }

            if ($isPrimary && $lockedProject->websites()->where('is_primary', true)->exists()) {
                throw ValidationException::withMessages([
                    'is_primary' => ['自社サイトは既に登録されています。'],
                ]);
            }

            $nextOrder = ((int) $lockedProject->websites()->max('display_order')) + 1;

            return $lockedProject->websites()->create([
                'name' => $data['name'],
                'url' => trim($data['url']),
                'normalized_url' => $normalizedUrl,
                'is_primary' => $isPrimary,
                'display_order' => $nextOrder,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Website $website, array $data): Website
    {
        return DB::transaction(function () use ($website, $data) {
            $lockedProject = Project::whereKey($website->project_id)->lockForUpdate()->firstOrFail();

            $attributes = [];

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
            }

            if (array_key_exists('url', $data)) {
                $normalizedUrl = $this->normalizeOrFail($data['url']);

                if (
                    $lockedProject->websites()
                        ->where('normalized_url', $normalizedUrl)
                        ->where('id', '!=', $website->id)
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'url' => ['このURLは既に登録されています。'],
                    ]);
                }

                $attributes['url'] = trim($data['url']);
                $attributes['normalized_url'] = $normalizedUrl;
            }

            if (array_key_exists('is_primary', $data)) {
                $isPrimary = (bool) $data['is_primary'];

                if (
                    $isPrimary
                    && $lockedProject->websites()
                        ->where('is_primary', true)
                        ->where('id', '!=', $website->id)
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'is_primary' => ['自社サイトは既に登録されています。'],
                    ]);
                }

                $attributes['is_primary'] = $isPrimary;
            }

            $website->fill($attributes)->save();

            return $website;
        });
    }

    /**
     * 採用方針: 削除後もdisplay_orderは振り直さない（欠番を許容する）。
     * 一覧はdisplay_orderの昇順で並べるだけなので欠番があっても表示順は
     * 崩れず、削除のたびに残り全件をロックして採番し直す必要がなくなる。
     * 1プロジェクト最大5件という制約上、値が肥大化する心配もない。
     */
    public function delete(Website $website): void
    {
        $website->delete();
    }

    private function normalizeOrFail(string $url): string
    {
        try {
            return $this->normalizer->normalize($url);
        } catch (\App\Exceptions\InvalidUrlException $e) {
            throw ValidationException::withMessages([
                'url' => [$e->getMessage()],
            ]);
        }
    }
}

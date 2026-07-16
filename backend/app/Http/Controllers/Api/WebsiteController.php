<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Websites\StoreWebsiteRequest;
use App\Http\Requests\Websites\UpdateWebsiteRequest;
use App\Http\Resources\WebsiteResource;
use App\Models\Project;
use App\Models\Website;
use App\Services\WebsiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteController extends Controller
{
    public function __construct(private readonly WebsiteService $websites)
    {
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $websites = $project->websites()->get();

        return $this->success(WebsiteResource::collection($websites));
    }

    public function store(StoreWebsiteRequest $request, Project $project): JsonResponse
    {
        $website = $this->websites->create($project, $request->validated());

        return $this->success(new WebsiteResource($website), [], '登録しました。', 201);
    }

    public function update(UpdateWebsiteRequest $request, Website $website): JsonResponse
    {
        $website = $this->websites->update($website, $request->validated());

        return $this->success(new WebsiteResource($website), [], '更新しました。');
    }

    public function destroy(Request $request, Website $website): JsonResponse
    {
        $this->authorize('delete', $website);

        $this->websites->delete($website);

        return $this->success([], [], '削除しました。');
    }
}

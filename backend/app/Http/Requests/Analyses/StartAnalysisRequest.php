<?php

namespace App\Http\Requests\Analyses;

use Illuminate\Foundation\Http\FormRequest;

class StartAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\Project $project */
        $project = $this->route('project');

        return $this->user()?->can('update', $project) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'website_ids' => ['sometimes', 'array'],
            'website_ids.*' => ['integer'],
        ];
    }
}

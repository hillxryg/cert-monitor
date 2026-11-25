<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/** Shared by campaign create and update. Ownership is enforced by CampaignPolicy in the controller. */
class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Unchecked checkboxes are absent from the payload.
        $this->merge(['allow_muted_playback' => $this->boolean('allow_muted_playback')]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'platform_name' => ['nullable', 'string', 'max:255'],
            'target_url_pattern' => ['required', 'string', 'max:255', 'regex:/^(https?|\*):\/\/\S+$/i'],
            'required_completion_percent' => ['required', 'integer', 'between:1,100'],
            'min_integrity_score' => ['required', 'integer', 'between:0,100'],
            'expected_lessons' => ['nullable', 'integer', 'between:1,500'],
            'max_playback_speed' => ['required', 'numeric', 'between:0.25,4'],
            'max_skip_seconds' => ['required', 'integer', 'between:0,3600'],
            'allow_muted_playback' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_url_pattern.regex' => 'Use a URL pattern such as https://*.example.com/course/*',
        ];
    }
}

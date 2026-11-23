<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum on the route; enrollment ownership is checked in the controller
    }

    public function rules(): array
    {
        return [
            'campaign_id' => ['required', 'integer'],
            'client_timestamp' => ['required', 'integer', 'min:0'], // epoch milliseconds

            'lesson_key' => ['required', 'string', 'max:255'],
            'lesson_title' => ['nullable', 'string', 'max:255'],
            'lesson_url' => ['nullable', 'url', 'max:2048'],
            'content_type' => ['required', Rule::in(['video', 'reading'])],

            'tab_focused' => ['required', 'boolean'],
            'tab_visible' => ['required', 'boolean'],

            // Video telemetry
            'segment_start' => ['required_if:content_type,video', 'numeric', 'min:0', 'max:86400'],
            'segment_end' => ['required_if:content_type,video', 'numeric', 'gte:segment_start', 'max:86400'],
            'current_time' => ['required_if:content_type,video', 'numeric', 'min:0', 'max:86400'],
            'duration' => ['required_if:content_type,video', 'numeric', 'gt:0', 'max:86400'],
            'playback_rate' => ['required_if:content_type,video', 'numeric', 'between:0.0625,16'],
            'muted' => ['required_if:content_type,video', 'boolean'],
            'is_playing' => ['required_if:content_type,video', 'boolean'],

            // Reading telemetry
            'scroll_percent' => ['required_if:content_type,reading', 'integer', 'between:0,100'],
        ];
    }
}

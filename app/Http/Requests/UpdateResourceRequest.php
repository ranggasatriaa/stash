<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Partial update (web + API). `version` is the optimistic-concurrency precondition. */
class UpdateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['nullable', 'integer', 'min:1'],
            'type' => ['sometimes', 'string', 'in:link,article,image'],
            'url' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'title' => ['sometimes', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'personal_notes' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:64'],
            'project_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'read_later' => ['sometimes', 'boolean'],
            'reading_status' => ['sometimes', 'nullable', 'string', 'in:unread,read'],
        ];
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        return $this->safe()->except('version');
    }

    public function expectedVersion(): ?int
    {
        $version = $this->validated('version');

        return $version === null ? null : (int) $version;
    }
}

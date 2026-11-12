<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shared validation for saving a URL or uploading an image (web + API). */
class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) ceil((int) config('stash.uploads.max_bytes') / 1024);

        return [
            'file' => ['nullable', 'file', 'max:'.$maxKb],
            'url' => ['nullable', 'string', 'max:4096', 'required_without:file'],
            'type' => ['nullable', 'string', 'in:link,article,image'],
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'personal_notes' => ['nullable', 'string', 'max:100000'],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:64'],
            'project_id' => ['nullable', 'string', 'size:26'],
            'read_later' => ['nullable', 'boolean'],
            'reading_status' => ['nullable', 'string', 'in:unread,read'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $mb = round((int) config('stash.uploads.max_bytes') / 1048576, 1);

        return [
            'file.max' => "The image must be at most {$mb} MB.",
            'file.uploaded' => "The upload failed. The image must be at most {$mb} MB.",
            'url.required_without' => 'Enter a URL or choose an image to upload.',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->safe()->except('file');
    }
}

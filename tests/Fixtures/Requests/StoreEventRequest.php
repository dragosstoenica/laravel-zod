<?php

declare(strict_types=1);

namespace LaravelZod\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LaravelZod\Attributes\ZodSchema;

#[ZodSchema]
final class StoreEventRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Pick a name for your event.',
        ];
    }
}

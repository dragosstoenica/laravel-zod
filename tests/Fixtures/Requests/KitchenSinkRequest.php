<?php

declare(strict_types=1);

namespace LaravelZod\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LaravelZod\Attributes\ZodSchema;
use LaravelZod\Tests\Fixtures\Enums\StatusEnum;

#[ZodSchema]
final class KitchenSinkRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Presence
            'name' => ['required', 'string', 'max:160'],
            'nickname' => ['nullable', 'string'],
            'optional_field' => ['sometimes', 'string'],
            'must_be_present' => ['present'],
            'filled_field' => ['filled', 'string'],
            'bail_field' => ['bail', 'required', 'string'],

            // Conditional presence
            'required_if_x' => ['required_if:name,Bob'],
            'required_unless_x' => ['required_unless:name,Bob'],
            'required_with_x' => ['required_with:nickname'],
            'required_with_all_x' => ['required_with_all:nickname,name'],
            'required_without_x' => ['required_without:nickname'],
            'required_without_all_x' => ['required_without_all:nickname,name'],
            'required_if_accepted_x' => ['required_if_accepted:agree'],
            'required_if_declined_x' => ['required_if_declined:agree'],
            'required_array_keys_x' => ['array', 'required_array_keys:a,b'],

            // Missing / prohibited / exclude
            'must_be_missing' => ['missing'],
            'missing_if_x' => ['missing_if:name,Bob'],
            'missing_unless_x' => ['missing_unless:name,Bob'],
            'missing_with_x' => ['missing_with:nickname'],
            'missing_with_all_x' => ['missing_with_all:nickname,name'],
            'prohibited_field' => ['prohibited'],
            'prohibited_if_x' => ['prohibited_if:name,Bob'],
            'prohibited_unless_x' => ['prohibited_unless:name,Bob'],
            'prohibits_x' => ['prohibits:nickname'],
            'exclude_field' => ['exclude'],
            'exclude_if_x' => ['exclude_if:name,Bob'],
            'exclude_unless_x' => ['exclude_unless:name,Bob'],
            'exclude_with_x' => ['exclude_with:nickname'],
            'exclude_without_x' => ['exclude_without:nickname'],

            // Accepted / declined
            'agree' => ['accepted'],
            'reject' => ['declined'],
            'accept_if_x' => ['accepted_if:name,Bob'],
            'decline_if_x' => ['declined_if:name,Bob'],

            // Types
            'age' => ['required', 'integer', 'min:0', 'max:150'],
            'score' => ['required', 'numeric', 'between:0,100'],
            'price' => ['required', 'decimal:2'],
            'active' => ['required', 'boolean'],
            'tags' => ['required', 'array'],
            'tag_list' => ['required', 'list'],
            'avatar' => ['nullable', 'file', 'mimes:jpg,png'],
            'photo' => ['nullable', 'image', 'mimes:jpg,png', 'extensions:jpg,png'],
            'photo_typed' => ['nullable', 'image', 'mimetypes:image/jpeg,image/png'],
            'photo_dims' => ['nullable', 'image', 'dimensions:min_width=100,max_width=2000'],
            'metadata' => ['nullable', 'json'],

            // String constraints
            'alpha_field' => ['required', 'alpha'],
            'alpha_dash_field' => ['required', 'alpha_dash'],
            'alpha_num_field' => ['required', 'alpha_num'],
            'ascii_field' => ['required', 'ascii'],
            'lower_field' => ['required', 'lowercase'],
            'upper_field' => ['required', 'uppercase'],
            'starts_field' => ['required', 'starts_with:foo,bar'],
            'doesnt_start_field' => ['required', 'doesnt_start_with:foo,bar'],
            'ends_field' => ['required', 'ends_with:.com,.net'],
            'doesnt_end_field' => ['required', 'doesnt_end_with:.com,.net'],
            'contains_field' => ['required', 'string'],
            'doesnt_contain_field' => ['required', 'string'],
            'color' => ['required', 'hex_color'],
            'pattern' => ['required', 'regex:/^[A-Z]{3}$/'],
            'not_pattern' => ['required', 'not_regex:/^[A-Z]{3}$/'],

            // Numeric size + comparisons
            'priority' => ['required', 'integer', 'gt:0', 'lt:11'],
            'pages' => ['required', 'integer', 'gte:1', 'lte:1000'],
            'step' => ['required', 'integer', 'multiple_of:5'],
            'pin' => ['required', 'digits:4'],
            'longish' => ['required', 'digits_between:6,12'],
            'max_dig' => ['required', 'max_digits:6'],
            'min_dig' => ['required', 'min_digits:2'],
            'size_field' => ['required', 'string', 'size:8'],

            // Dates
            'date_field' => ['required', 'date'],
            'date_fmt_field' => ['required', 'date_format:Y-m-d'],
            'date_eq_field' => ['required', 'date_equals:2024-01-01'],
            'after_today' => ['required', 'date', 'after:today'],
            'after_or_today' => ['required', 'date', 'after_or_equal:today'],
            'before_today' => ['required', 'date', 'before:today'],
            'before_or_today' => ['required', 'date', 'before_or_equal:today'],
            'tz' => ['required', 'timezone'],

            // Format
            'email' => ['required', 'email'],
            'website' => ['nullable', 'url'],
            'live_url' => ['nullable', 'active_url'],
            'uuid_field' => ['required', 'uuid'],
            'ulid_field' => ['required', 'ulid'],
            'ip_field' => ['required', 'ip'],
            'ipv4_field' => ['required', 'ipv4'],
            'ipv6_field' => ['required', 'ipv6'],
            'mac_field' => ['required', 'mac_address'],

            // Same / different / confirmed / in_array / distinct
            'password' => ['required', 'string', 'confirmed'],
            'same_as_name' => ['required', 'same:name'],
            'different_from_name' => ['required', 'different:name'],
            'in_tags' => ['required', 'in_array:tags.*'],
            'unique_values' => ['required', 'array', 'distinct'],

            // Membership
            'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])],
            'forbidden_role' => ['required', 'not_in:owner,root'],
            'status' => ['required', Rule::enum(StatusEnum::class)],

            // Server-only (skipped with comment)
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'username' => ['required', 'string', 'unique:users,username'],
            'old_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            '*.required' => ':attribute must be provided.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'full name',
        ];
    }
}

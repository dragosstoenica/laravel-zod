# dragosstoenica/laravel-zod

[![Packagist Version](https://img.shields.io/packagist/v/dragosstoenica/laravel-zod.svg?style=flat-square)](https://packagist.org/packages/dragosstoenica/laravel-zod)
[![PHP Version](https://img.shields.io/packagist/php-v/dragosstoenica/laravel-zod.svg?style=flat-square)](https://packagist.org/packages/dragosstoenica/laravel-zod)
[![License](https://img.shields.io/github/license/dragosstoenica/laravel-zod.svg?style=flat-square)](LICENSE)

Generate **Zod 4** schemas from Laravel `FormRequest` classes (input) and Spatie [`laravel-data`](https://spatie.be/docs/laravel-data) DTOs (output). PHP stays the source of truth; TypeScript gets runtime parsing **and** inferred types from a single file.

**Compatibility**: PHP 8.3 / 8.4 / 8.5 · Laravel 11 / 12 / 13 · Zod 4.x · Spatie Data 4.22+

```ts
// On a real server response — catches backend drift before it hits your render layer
const event = EventDataSchema.parse(await fetch('/api/events/1').then((r) => r.json()).then((j) => j.data));

// On form submit — reject before round-trip
const result = StoreEventRequestSchema.safeParse(formValues);
```

## Why this exists

Other generators have these problems:

| Pattern | Other generators | This package |
|---|---|---|
| `?UserData $host` on a Data class | inlined `z.object({...})` (no schema reuse) | `host: UserDataSchema.nullable()` ✓ |
| `EventAttendeeData[]` array | inlined repeatedly | `z.array(EventAttendeeDataSchema)` ✓ |
| Required string | `z.string({error}).trim().refine(...).min(1)` (belt-and-suspenders) | `z.string().trim().min(1, '...')` ✓ |
| Output schema | dragged form-error messages | type narrowing + nullability only ✓ |
| Schema declaration order | filenames or random | topologically sorted ✓ |
| Circular refs (self / mutual) | TDZ error at runtime | `z.lazy(() => Schema)` on back-edges ✓ |
| Cross-field rules (`after:other`, `required_if`, …) | partial / inline | one `.superRefine()` block at schema bottom ✓ |
| Locale | hardcoded English | `--locale=ro` + Laravel lang file fallback chain ✓ |

## Install

```bash
composer require dragosstoenica/laravel-zod
php artisan vendor:publish --tag=laravel-zod-config
```

The package's service provider auto-registers under Laravel's package discovery — no manual provider entry needed.

If you're consuming via a Composer path repository (recommended while iterating):

```jsonc
// composer.json
"repositories": [{ "type": "path", "url": "../packages/laravel-zod" }],
"require": { "dragosstoenica/laravel-zod": "@dev" }
```

## Usage

### 1. Mark classes with `#[ZodSchema]`

```php
use LaravelZod\Attributes\ZodSchema;

// Output — inferred from PHP property types. No validation rules read.
#[ZodSchema]
class UserData extends \Spatie\LaravelData\Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
    ) {}
}

#[ZodSchema]
class EventData extends \Spatie\LaravelData\Data
{
    public function __construct(
        public int $id,
        public string $title,
        public ?UserData $host,                                // → host: UserDataSchema.nullable()
        /** @var EventAttendeeData[]|null */
        public ?array $attendees,                              // → attendees: z.array(EventAttendeeDataSchema).nullable()
        public \Carbon\CarbonImmutable $starts_at,             // → starts_at: z.string()
    ) {}
}

// Input — Laravel rules() drive everything: type, constraints, cross-field, messages.
#[ZodSchema]
class StoreEventRequest extends \Illuminate\Foundation\Http\FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return ['title.required' => 'Pick a name for your event.'];
    }
}
```

### 2. Generate

```bash
php artisan zod:generate            # writes the configured output path
php artisan zod:generate --dry-run  # print to stdout
php artisan zod:generate --locale=ro # use lang/ro/validation.php for defaults
```

### 3. Consume from TypeScript

```ts
import {
  EventDataSchema,           // .parse()-able output schema
  StoreEventRequestSchema,   // .parse()-able input schema
} from '@shared/schemas';
import type { z } from 'zod';

export type EventData = z.infer<typeof EventDataSchema>;            // { id; title; host; attendees; starts_at; … }
export type StoreEventRequest = z.infer<typeof StoreEventRequestSchema>;
```

The generated file exports `*Schema` constants and `*SchemaType` aliases (the latter is a `z.infer<>` for convenience).

## Configuration

`config/laravel-zod.php`:

```php
return [
    'output'  => base_path('../packages/shared-types/schemas.ts'),
    'scan'    => [app_path()],
    'locale'  => null,                            // null → app()->getLocale(), then 'en'
    'suffix'  => 'Schema',                        // ClassName + suffix → export const

    'server_only_rules'      => ['exists', 'unique', 'current_password'],
    'server_only_behaviour'  => 'comment',        // 'comment' | 'fail'
    'custom_rules_strict'    => false,            // true → fail when a custom Rule has no toZod()

    'header' => [
        '// AUTO-GENERATED by dragosstoenica/laravel-zod. Do not edit by hand.',
        '// Run `php artisan zod:generate` to refresh.',
    ],
];
```

## Locales / messages

Resolution order, per (field, rule):

1. **`FormRequest::messages()` exact key** — `'title.required' => 'Pick a name.'`
2. **Wildcard** — `'*.required' => ':attribute is mandatory.'`
3. **Locale validation file** — `lang/<locale>/validation.php` (`validation.required`)
4. **English fallback** — `lang/en/validation.php`
5. **Humanised fallback** — `Headline-cased validation failed.`

Placeholders filled: `:attribute` (from `attributes()` or the field name), `:min`, `:max`, `:size`, `:other`, `:value`, `:date`, `:format`, `:digits`, `:decimal`, `:values`.

For sub-keyed rules (`min`/`max`/`between`/`size`/`gt`/`gte`/`lt`/`lte`), the package picks the correct sub-key based on the field's inferred type:

```php
'max' => [
    'string'  => 'The :attribute field must not be greater than :max characters.',
    'numeric' => 'The :attribute field must not be greater than :max.',
    'array'   => 'The :attribute field must not have more than :max items.',
    'file'    => 'The :attribute field must not be greater than :max kilobytes.',
],
```

To add a non-English locale:

```bash
php artisan lang:publish               # exposes Laravel's defaults under lang/
cp -r lang/en lang/ro                  # then translate lang/ro/validation.php
php artisan zod:generate --locale=ro
```

## Custom Rule classes

Any value in `rules()` can be a Rule object. Two ways the package handles them:

### Opt-in: implement `HasZodSchema`

```php
use LaravelZod\Contracts\HasZodSchema;
use Illuminate\Contracts\Validation\ValidationRule;

class StartsWithPlus implements ValidationRule, HasZodSchema
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! str_starts_with($value, '+')) $fail('Must start with +.');
    }

    public function toZod(): string
    {
        // Either a chain fragment (starts with `.`) or a full expression.
        return ".refine((v) => typeof v === 'string' && v.startsWith('+'), 'Must start with +')";
    }
}

// Use it:
'phone' => ['required', new StartsWithPlus],
```

### Stringifiable rules (e.g. `Rule::in([...])`, `Rule::enum(MyEnum::class)`)

The package recognises Laravel's built-in stringifiable Rule objects and re-runs them through the rule translator.

### Everything else

A custom Rule object that implements neither `HasZodSchema` nor a recognised `__toString` is **skipped with a warning** and a `// custom rule skipped: …` comment in the schema. Set `'custom_rules_strict' => true` in the config to fail-loud instead.

## Circular dependencies

Self-references (`Comment.parent: ?Comment`) and mutual references (`Author.posts: Post[]` ↔ `Post.author: Author`) are detected during topological sort. The back-edge gets wrapped in `z.lazy(() => Schema)` automatically:

```ts
export const PostDataSchema = z.object({
  id: z.number().int(),
  title: z.string(),
  author: z.lazy(() => AuthorDataSchema),                 // ← back-edge
});

export const AuthorDataSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  posts: z.array(PostDataSchema).nullable(),               // ← forward-edge, no lazy needed
});

export const CommentDataSchema = z.object({
  id: z.number().int(),
  body: z.string(),
  parent: z.lazy(() => CommentDataSchema).nullable(),      // ← self-reference
  replies: z.array(z.lazy(() => CommentDataSchema)).nullable(),
});
```

You don't need to do anything in PHP — annotate the relations as plain nullable types (`?UserData`, `?array` with `@var X[]`) and let the generator pick the right side to defer.

## Rule coverage

All rules are translated to client-side Zod where the semantics map. Cross-field rules become `.superRefine()` blocks. DB-backed rules emit a `// server-only` comment.

| Family | Rules |
|---|---|
| Presence | `required`, `nullable`, `sometimes`, `present`, `filled`, `bail` |
| Conditional presence | `required_if`, `required_unless`, `required_if_accepted`, `required_if_declined`, `required_with`, `required_with_all`, `required_without`, `required_without_all`, `required_array_keys` |
| Missing / prohibited | `missing`, `missing_if`, `missing_unless`, `missing_with`, `missing_with_all`, `prohibited`, `prohibited_if`, `prohibited_if_accepted`, `prohibited_unless`, `prohibits` |
| Exclusion | `exclude`, `exclude_if`, `exclude_unless`, `exclude_with`, `exclude_without` |
| Accepted / declined | `accepted`, `accepted_if`, `declined`, `declined_if` |
| Types | `string`, `integer`, `numeric`, `decimal`, `boolean`, `array`, `list`, `file`, `image`, `json` |
| String constraints | `alpha`, `alpha_dash`, `alpha_num`, `ascii`, `lowercase`, `uppercase`, `starts_with`, `doesnt_start_with`, `ends_with`, `doesnt_end_with`, `contains`, `doesnt_contain`, `hex_color`, `regex`, `not_regex` |
| Sized | `min`, `max`, `between`, `size` (polymorphic — string length / numeric value / array length) |
| Numeric | `gt`, `gte`, `lt`, `lte` (literal **or** field reference), `multiple_of`, `digits`, `digits_between`, `max_digits`, `min_digits` |
| Dates | `date`, `date_format`, `date_equals`, `after`, `after_or_equal`, `before`, `before_or_equal`, `timezone` (handles `now` / `today` / `tomorrow` / `yesterday` aliases and field references) |
| Format | `email`, `url`, `active_url`, `uuid`, `ulid`, `ip`, `ipv4`, `ipv6`, `mac_address` |
| Membership | `in`, `not_in`, `enum` (resolves backed PHP enums to `z.enum([...])`) |
| Cross-field | `same`, `different`, `confirmed`, `in_array`, `distinct` |
| File | `mimes`, `mimetypes`, `extensions`, `dimensions` (server-side image-dim check is deferred with a comment) |
| Server-only | `exists`, `unique`, `current_password` (skipped with comment) |

Anything else triggers an `Unhandled rule '<name>'…` warning at generate time and is skipped.

## Limitations

Honest list of what's not done:

- **Nested input shapes.** Dotted FormRequest rules like `items.*.qty` are skipped — the generated schema treats `items` as an unspecified array. To validate nested input shapes, point the field at a Data class and `request->validate()` server-side, then have the client construct the same Data via its own schema.
- **`active_url` DNS check** is server-only. The package emits `.url()` plus a `// active_url: DNS-resolution check is server-only` comment.
- **`dimensions` for images** needs an async `Image()` load to verify width/height. Currently emitted as a no-op refine with a server-side-only comment. Use `mimes`/`extensions` for client gating.
- **Locale fallbacks** only walk validation.php's exact key + en. Custom locale message overrides via `validation-inline.php` aren't read.
- **TypeScript inference for circular schemas** can fall back to `unknown` in deep cases. Zod 4 handles most cases via `z.lazy()`, but if you hit a stubborn one, declare the recursive type alias by hand.

## Architecture

```
src/
├── Attributes/ZodSchema.php                 # marker — `#[ZodSchema]`
├── Console/GenerateZodSchemasCommand.php    # `php artisan zod:generate`
├── Contracts/HasZodSchema.php               # opt-in for custom Rule classes
├── Discovery/ClassDiscoverer.php            # scan paths for the attribute
├── Analyzers/
│   ├── DataClassAnalyzer.php                # PHP types → PropertySchema
│   └── FormRequestAnalyzer.php              # rules() + messages() → translator pipeline
├── Schema/
│   ├── PropertyType.php                     # STRING|NUMBER|INTEGER|BOOLEAN|ARRAY|OBJECT|FILE|DATE|ENUM|REF|ANY
│   ├── Constraint.php                       # one Zod chain link
│   ├── CrossFieldRefine.php                 # one `.superRefine` body
│   ├── PropertySchema.php                   # name, type, constraints[], rawSuffixes[], nullable, optional, useLazyReference, …
│   ├── ObjectSchema.php                     # exportName, sourceClass, properties[], crossFieldRefines[]
│   └── SchemaRegistry.php                   # FQN → "UserDataSchema"
├── Translation/
│   ├── MessageResolver.php                  # custom + wildcard + lang/<locale>.validation + en + headline fallback
│   └── RuleTranslator.php                   # one method per Laravel rule
├── Rendering/ZodRenderer.php                # ObjectSchema[] → schemas.ts string
└── ZodSchemasServiceProvider.php
```

Two-pass generation:

1. **Discovery pass** — walk `scan` paths, find every `#[ZodSchema]`-attributed class, register `<FQN> → <ExportName>` in the `SchemaRegistry`.
2. **Render pass** — analyze each class (Data → reflection of typed props; FormRequest → `rules()` array fed through `RuleTranslator`), build an `ObjectSchema`, topologically sort with cycle detection, mark back-edges with `useLazyReference`, render.

## Contributing

Bug reports and PRs welcome. Things that would be useful next:

- Nested FormRequest input schemas (`items.*.qty`)
- A way to attach a hand-written `superRefine` to a Data class (cross-field on outputs)
- Pluggable writers (Yup, Valibot, ArkType, …) — the renderer is the only thing that changes

## License

MIT.

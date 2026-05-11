<?php

declare(strict_types=1);

use LaravelZod\Rendering\ZodRenderer;
use LaravelZod\Schema\Constraint;
use LaravelZod\Schema\CrossFieldRefine;
use LaravelZod\Schema\ObjectSchema;
use LaravelZod\Schema\PropertySchema;
use LaravelZod\Schema\PropertyType;

it('renders a simple object schema', function (): void {
    $o = new ObjectSchema('UserSchema', 'App\\Data\\User');
    $o->addProperty(new PropertySchema('id', PropertyType::INTEGER));
    $o->addProperty(new PropertySchema('name', PropertyType::STRING));

    $out = (new ZodRenderer)->render([$o]);

    expect($out)->toContain('export const UserSchema = z');
    expect($out)->toContain('id: z.number().int(),');
    expect($out)->toContain('name: z.string(),');
    expect($out)->toContain('export type UserSchemaType = z.infer<typeof UserSchema>;');
});

it('renders nullable + optional in correct order', function (): void {
    $o = new ObjectSchema('UserSchema', 'App\\Data\\User');
    $p = new PropertySchema('nickname', PropertyType::STRING);
    $p->nullable = true;
    $p->optional = true;
    $o->addProperty($p);

    $out = (new ZodRenderer)->render([$o]);
    expect($out)->toContain('nickname: z.string().nullable().optional()');
});

it('renders a reference', function (): void {
    $o = new ObjectSchema('EventSchema', 'App\\Data\\Event');
    $p = new PropertySchema('host', PropertyType::REF);
    $p->reference = 'UserSchema';
    $o->addProperty($p);

    $out = (new ZodRenderer)->render([$o]);
    expect($out)->toContain('host: UserSchema,');
});

it('wraps lazy references in z.lazy', function (): void {
    $o = new ObjectSchema('CommentSchema', 'App\\Data\\Comment');
    $p = new PropertySchema('parent', PropertyType::REF);
    $p->reference = 'CommentSchema';
    $p->useLazyReference = true;
    $p->nullable = true;
    $o->addProperty($p);

    $out = (new ZodRenderer)->render([$o]);
    expect($out)->toContain('parent: z.lazy(() => CommentSchema).nullable()');
});

it('renders enum values', function (): void {
    $o = new ObjectSchema('StatusSchema', 'App\\Data\\X');
    $p = new PropertySchema('status', PropertyType::ENUM);
    $p->enumValues = ['draft', 'published'];
    $o->addProperty($p);

    $out = (new ZodRenderer)->render([$o]);
    expect($out)->toContain('status: z.enum(["draft","published"])');
});

it('renders array with item ref', function (): void {
    $o = new ObjectSchema('EventSchema', 'App\\Data\\Event');
    $p = new PropertySchema('attendees', PropertyType::ARRAY);
    $item = new PropertySchema('attendeesItem', PropertyType::REF);
    $item->reference = 'UserSchema';
    $p->arrayItem = $item;
    $o->addProperty($p);

    $out = (new ZodRenderer)->render([$o]);
    expect($out)->toContain('attendees: z.array(UserSchema)');
});

it('emits a superRefine block when cross-field refines are present', function (): void {
    $o = new ObjectSchema('PwdSchema', 'App\\Data\\Pwd');
    $o->addProperty(new PropertySchema('password', PropertyType::STRING));
    $o->addRefine(new CrossFieldRefine('password', "if (data['password'] !== data['password_confirmation']) ctx.addIssue({ code: 'custom' });"));

    $out = (new ZodRenderer)->render([$o]);
    expect($out)->toContain('.superRefine((data: any, ctx) => {');
    expect($out)->toContain('password_confirmation');
});

it('emits regex constraint with two args', function (): void {
    $o = new ObjectSchema('FSchema', 'App\\F');
    $p = new PropertySchema('code', PropertyType::STRING);
    $p->addConstraint(new Constraint('regex', ['/^[A-Z]+$/'], 'Must be uppercase letters.'));
    $o->addProperty($p);

    $out = (new ZodRenderer)->render([$o]);
    expect($out)->toContain('.regex(/^[A-Z]+$/, "Must be uppercase letters.")');
});

it('prepends header lines', function (): void {
    $o = new ObjectSchema('XSchema', 'App\\X');
    $out = (new ZodRenderer(['// generated', '// do not edit']))->render([$o]);
    expect($out)->toStartWith("// generated\n// do not edit");
});

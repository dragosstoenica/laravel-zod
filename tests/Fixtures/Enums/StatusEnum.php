<?php

declare(strict_types=1);

namespace LaravelZod\Tests\Fixtures\Enums;

enum StatusEnum: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}

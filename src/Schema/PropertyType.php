<?php

declare(strict_types=1);

namespace LaravelZod\Schema;

enum PropertyType: string
{
    case STRING = 'string';
    case NUMBER = 'number';
    case INTEGER = 'integer';
    case BOOLEAN = 'boolean';
    case ARRAY = 'array';
    case OBJECT = 'object';
    case FILE = 'file';
    case DATE = 'date';
    case ENUM = 'enum';
    case REF = 'ref';
    case ANY = 'any';
}

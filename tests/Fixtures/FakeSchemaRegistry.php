<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Schema\ZeroModelSchema;

final class FakeSchemaRegistry implements ZeroSchemaRegistry
{
    public function model(string $modelClass): ZeroModelSchema
    {
        return new ZeroModelSchema($modelClass, 'parties', 'party', [
            'id' => 'id', 'user_id' => 'userId', 'display_name' => 'displayName', 'reference_code' => 'referenceCode',
        ], ['id']);
    }

    public function models(): iterable
    {
        yield Party::class => $this->model(Party::class);
    }
}

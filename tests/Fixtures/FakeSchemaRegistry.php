<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Schema\ZeroModelSchema;
use NickWelsh\LaravelZero\Schema\ZeroRelationshipSchema;

final class FakeSchemaRegistry implements ZeroSchemaRegistry
{
    public function model(string $modelClass): ZeroModelSchema
    {
        if ($modelClass === EmailAddress::class) {
            return new ZeroModelSchema($modelClass, 'email_addresses', 'emailAddress', [
                'id' => 'id', 'party_id' => 'partyId', 'is_primary' => 'isPrimary',
            ], ['id']);
        }

        return new ZeroModelSchema($modelClass, 'parties', 'party', [
            'id' => 'id', 'user_id' => 'userId', 'display_name' => 'displayName', 'reference_code' => 'referenceCode',
        ], ['id'], [
            'emailAddresses' => new ZeroRelationshipSchema('emailAddresses', EmailAddress::class, ['id'], ['party_id']),
        ]);
    }

    public function models(): iterable
    {
        yield Party::class => $this->model(Party::class);
    }
}

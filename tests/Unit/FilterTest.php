<?php

use Illuminate\Validation\ValidationException;
use NickWelsh\LaravelZero\Filters\ZeroFilterBuilder;
use NickWelsh\LaravelZero\Filters\ZeroFilterDefinition;
use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\PartyFilters;
use NickWelsh\LaravelZero\Tests\Fixtures\PartyGridInput;

function partyFilterTree(): array
{
    return [
        'type' => 'group',
        'combinator' => 'and',
        'children' => [
            ['type' => 'condition', 'field' => 'name', 'operator' => 'contains', 'value' => 'Acme'],
            [
                'type' => 'group',
                'combinator' => 'or',
                'children' => [
                    ['type' => 'condition', 'field' => 'kind', 'operator' => 'equals', 'value' => 'person'],
                    ['type' => 'condition', 'field' => 'kind', 'operator' => 'equals', 'value' => 'company'],
                ],
            ],
            [
                'type' => 'relationship',
                'relationship' => 'emails',
                'quantifier' => 'some',
                'filter' => ['type' => 'condition', 'field' => 'primary', 'operator' => 'equals', 'value' => true],
            ],
        ],
    ];
}

it('defines labeled UI metadata and finite field values', function (): void {
    $schema = ZeroFilterDefinition::make(PartyFilters::class)->schema(new FakeSchemaRegistry);

    expect($schema->field('name')->label)->toBe('Party name')
        ->and($schema->field('name')->column)->toBe('display_name')
        ->and($schema->field('kind')->label)->toBe('Party type')
        ->and($schema->field('kind')->values)->toBe([
            ['value' => 'person', 'label' => 'Person'],
            ['value' => 'company', 'label' => 'Company'],
            ['value' => 'household', 'label' => 'Household'],
        ])
        ->and($schema->relationship('emails')->label)->toBe('Email addresses');
});

it('rejects labels and finite values that do not match their field contract', function (): void {
    $filters = new ZeroFilterBuilder;

    expect(fn () => $filters->string('status')->label(''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $filters->string('priority')->values([1]))
        ->toThrow(InvalidArgumentException::class);
});

it('hydrates valid recursive filters and rejects private fields', function (): void {
    $input = PartyGridInput::from(['filter' => partyFilterTree(), 'limit' => 25]);

    expect($input->filter)->toBe(partyFilterTree())
        ->and($input->limit)->toBe(25);

    expect(fn () => PartyGridInput::from([
        'filter' => ['type' => 'condition', 'field' => 'user_id', 'operator' => 'equals', 'value' => 'attacker'],
        'limit' => 25,
    ]))->toThrow(ValidationException::class);
});

it('enforces recursive complexity limits', function (): void {
    $filter = ['type' => 'condition', 'field' => 'name', 'operator' => 'equals', 'value' => 'Acme'];
    for ($depth = 0; $depth < 4; $depth++) {
        $filter = ['type' => 'group', 'combinator' => 'and', 'children' => [$filter]];
    }

    expect(fn () => PartyGridInput::from(['filter' => $filter, 'limit' => 25]))
        ->toThrow(ValidationException::class);
});

it('renders nested boolean and relationship filters as Zero AST', function (): void {
    $ast = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->where('user_id', 'user-1')
        ->applyFilter(partyFilterTree(), PartyFilters::class)
        ->limit(25)
        ->toAst();

    expect($ast['where']['type'])->toBe('and')
        ->and($ast['where']['conditions'][0]['left']['name'])->toBe('user_id')
        ->and($ast['where']['conditions'][1])->toMatchArray([
            'type' => 'simple',
            'op' => 'ILIKE',
            'left' => ['type' => 'column', 'name' => 'display_name'],
            'right' => ['type' => 'literal', 'value' => '%Acme%'],
        ])
        ->and($ast['where']['conditions'][2]['type'])->toBe('or')
        ->and($ast['where']['conditions'][2]['conditions'][0]['left']['name'])->toBe('reference_code')
        ->and($ast['where']['conditions'][3]['type'])->toBe('correlatedSubquery')
        ->and($ast['where']['conditions'][3]['related']['subquery']['where']['left']['name'])->toBe('is_primary')
        ->and($ast['limit'])->toBe(25);
});

<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use ZeroForLaravel\Support\Input;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

test('objects reject numeric property names', function (): void {
    Input::object((object) ['12' => 'value'], 'args');
})->throws(ValidationException::class, 'Must use string field names.');

test('protocol identity rejects non scalar trusted context', function (): void {
    (new ZeroContext(['user_id' => []]))->userId();
})->throws(AuthorizationException::class);

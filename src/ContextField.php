<?php

declare(strict_types=1);

namespace LaravelZero;

enum ContextField: string
{
    case UserId = 'user_id';
    case TenantId = 'tenant_id';
}

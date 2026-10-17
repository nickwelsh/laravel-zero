<?php

declare(strict_types=1);

namespace ZeroForLaravel;

enum ContextField: string
{
    case UserId = 'user_id';
    case TenantId = 'tenant_id';
}

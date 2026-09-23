<?php

namespace App\Support\Core;

/**
 * Write kinds stored on core.actions. Fetches never appear here.
 */
final class CoreActionType
{
    public const ADD = 'add';

    public const EDIT = 'edit';

    public const DELETE = 'delete';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::ADD, self::EDIT, self::DELETE];
    }
}

<?php

namespace App\Support\Portal;

/**
 * HTTP verbs stored on portal.logs.action. The readable sentence lives in message.
 */
final class PortalLogAction
{
    public const INSERT = 'INSERT';

    public const PATCH = 'PATCH';

    public const DELETE = 'DELETE';

    public const POST = 'POST';

    // Reading something sensitive, such as a person's private employee information.
    public const VIEW = 'VIEW';
}

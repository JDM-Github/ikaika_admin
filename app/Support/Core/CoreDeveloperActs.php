<?php

namespace App\Support\Core;

/**
 * Which writes are a developer's act in Developer mode. The ledger serves every product, so the
 * product that has such a mode answers it; without one bound, no write is.
 */
interface CoreDeveloperActs
{
    public function isDevelopersAct(int $actorId): bool;
}

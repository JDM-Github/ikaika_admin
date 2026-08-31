<?php

namespace App\Modules\Core;

use App\Modules\Core\Models\Action;
use App\Modules\Core\Models\Recycle;
use App\Modules\Core\Models\Setting;
use App\Modules\Support\ProductModule;

class CoreModule implements ProductModule
{
    public function key(): string
    {
        return 'core';
    }

    public function name(): string
    {
        return 'Core Platform';
    }

    public function resources(): array
    {
        return [
            'actions' => [
                'model' => Action::class,
                'label' => 'Actions',
                'searchable' => ['product', 'action_type', 'database_target', 'recycle_key', 'record_id'],
            ],
            'recycle' => [
                'model' => Recycle::class,
                'label' => 'Recycle',
                'searchable' => ['product', 'recycle_key', 'database_target', 'record_id'],
            ],
            'settings' => [
                'model' => Setting::class,
                'label' => 'Settings',
                'searchable' => ['product', 'setting_key'],
            ],
        ];
    }

    public function sections(): array
    {
        return [];
    }

    public function authOperations(): array
    {
        return [];
    }

    public function utilities(): array
    {
        return [];
    }
}

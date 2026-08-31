<?php

namespace App\Modules\ProjectEstimator;

use App\Modules\ProjectEstimator\Models\Project;
use App\Modules\Support\ProductModule;

/**
 * Stub module. Same short model name as Portal\Models\Project is intentional —
 * namespaces keep them from colliding. Enable via ESTIMATOR_ENABLED=true
 * after the estimator database exists.
 */
class ProjectEstimatorModule implements ProductModule
{
    public function key(): string
    {
        return 'project-estimator';
    }

    public function name(): string
    {
        return 'Project Estimator';
    }

    public function resources(): array
    {
        return [
            'projects' => [
                'model' => Project::class,
                'label' => 'Estimates / Projects',
                'searchable' => ['name'],
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

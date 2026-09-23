<?php

namespace App\Modules\ProjectEstimator\Models;

use App\Modules\Support\ProductModel;

/**
 * Lives in a different namespace and database than Portal\Models\Project.
 * Replace this placeholder once the estimator schema is migrated.
 */
class Project extends ProductModel
{
    protected $table = 'projects';

    public static function productKey(): string
    {
        return 'project-estimator';
    }
}

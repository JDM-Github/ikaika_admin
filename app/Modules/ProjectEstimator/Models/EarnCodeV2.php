<?php

namespace App\Modules\ProjectEstimator\Models;

use App\Modules\Support\ProductModel;

/**
 * The estimator's own earn-code list, kept apart from the portal's earn_codes table because a
 * holiday's pay variants are scheduled here. Its description carries the numbered label the
 * holiday classification is read from, e.g. "13 Regular Holiday".
 */
class EarnCodeV2 extends ProductModel
{
    protected $table = 'earn_codes_v2';

    public static function productKey(): string
    {
        return 'project-estimator';
    }
}

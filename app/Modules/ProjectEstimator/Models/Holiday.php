<?php

namespace App\Modules\ProjectEstimator\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The company holiday calendar. Owned by the estimator, which schedules against it, and read
 * by the portal, which marks attendance against it. Two rows carry a null holiday_date --
 * "Regular" and "Rest Day" -- because the source table doubles as the day-type legend; they
 * are not holidays and every reader must exclude them.
 */
class Holiday extends ProductModel
{
    protected $table = 'holidays';

    public static function productKey(): string
    {
        return 'project-estimator';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'holiday_date' => 'date:Y-m-d',
        ];
    }

    public function earnCodes(): BelongsToMany
    {
        return $this->belongsToMany(
            EarnCodeV2::class,
            'holidays_earn_codes_v2',
            'holiday_id',
            'earn_code_v2_id',
        )->withPivot('variant');
    }
}

<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LeaveRequest extends ProductModel
{
    protected $table = 'requests';

    public static function productKey(): string
    {
        return 'portal';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_date' => 'date:Y-m-d',
            'original_work_day' => 'date:Y-m-d',
            'offset_work_day' => 'date:Y-m-d',
            'date_created' => 'datetime',
            'no_of_hours' => 'float',
            'offset_hrs' => 'float',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'projects_requests');
    }
}

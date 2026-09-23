<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UserReport extends ProductModel
{
    protected $table = 'user_reports';

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
            'report_date' => 'date:Y-m-d',
            'actual_work_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'date_created' => 'datetime',
            'hours_rendered' => 'float',
            'change_in_elements' => 'float',
            'progress_per_activity_pct' => 'float',
            'duration' => 'float',
        ];
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employees_user_reports');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'projects_user_reports');
    }
}

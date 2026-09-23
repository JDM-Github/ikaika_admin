<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Employee extends ProductModel
{
    protected $table = 'employees';

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
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'date_of_birth' => 'date:Y-m-d',
            'sl_credits' => 'float',
            'remaining_sl' => 'float',
            'vl_credits' => 'float',
            'remaining_vl' => 'float',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'employees_projects')
            ->withPivot('role_on_project');
    }

    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(UserReport::class, 'employees_user_reports');
    }
}

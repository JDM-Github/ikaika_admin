<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Project extends ProductModel
{
    protected $table = 'projects';

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
            'is_renamed_with_acc_number' => 'boolean',
            'is_ledger_moved_to_for_submission' => 'boolean',
            'is_ledger_details_updated' => 'boolean',
            'progress_pct' => 'float',
            'area_sqft' => 'float',
            'due_date' => 'date:Y-m-d',
            'date_done' => 'datetime',
            'date_closed' => 'datetime',
            'date_modified' => 'datetime',
        ];
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employees_projects')
            ->withPivot('role_on_project');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'projects_clients');
    }

    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(UserReport::class, 'projects_user_reports');
    }
}

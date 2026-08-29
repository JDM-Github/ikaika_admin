<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Reimbursement extends ProductModel
{
    protected $table = 'reimbursements';

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
            'reimb_date' => 'date:Y-m-d',
            'date_created' => 'datetime',
            'cost' => 'float',
            'qty' => 'float',
        ];
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employees_reimbursements');
    }
}

<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalLog extends ProductModel
{
    protected $table = 'logs';

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
            'employee_id' => 'integer',
            'payload' => 'array',
            'location_lat' => 'float',
            'location_lng' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

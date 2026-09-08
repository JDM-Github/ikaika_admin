<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalNotification extends ProductModel
{
    protected $table = 'notifications';

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
            'actor_id' => 'integer',
            'payload' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_id');
    }
}

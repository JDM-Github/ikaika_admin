<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailBlock extends ProductModel
{
    protected $table = 'email_blocks';

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
            'created_by' => 'integer',
            'date_created' => 'datetime',
            'date_updated' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}

<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessage extends ProductModel
{
    protected $table = 'email_messages';

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
            'footer_id' => 'integer',
            'audience_filter' => 'array',
            'recipient_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'failures' => 'array',
            'sent_by' => 'integer',
            'date_sent' => 'datetime',
            'date_created' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sent_by');
    }

    public function footer(): BelongsTo
    {
        return $this->belongsTo(EmailBlock::class, 'footer_id');
    }
}

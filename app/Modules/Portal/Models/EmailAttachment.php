<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file attached in Send Email: staged while message_id is null, then the outbox row's.
 */
class EmailAttachment extends ProductModel
{
    protected $table = 'email_attachments';

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
            'message_id' => 'integer',
            'size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'date_created' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'message_id');
    }
}

<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Client extends ProductModel
{
    protected $table = 'clients';

    public static function productKey(): string
    {
        return 'portal';
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'projects_clients');
    }
}

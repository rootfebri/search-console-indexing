<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Apikey extends Model
{
    protected $table = 'apikeys';
    protected $fillable = ['service_account_id', 'data', 'used'];

    public function serviceAccount(): BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class);
    }
}

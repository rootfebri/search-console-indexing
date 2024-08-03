<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthModel extends Model
{
    protected $table = 'oauths';
    protected $fillable = [
        'client_id',
        'project_id',
        'client_secret',
        'refresh_token',
        'service_account_id',
        'limit',
        'refresh_time',
    ];

    public function serviceAccount(): BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class);
    }
}

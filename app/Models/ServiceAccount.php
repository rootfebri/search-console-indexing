<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceAccount extends Model
{
    protected $table = 'service_accounts';
    protected $fillable = ['email'];

    public function apikeys(): HasMany
    {
        return $this->hasMany(Apikey::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceAccount extends Model
{
    protected $table = 'service_accounts';
    protected $fillable = ['email', 'google_verifcation'];

    public function apikeys(): HasMany
    {
        return $this->hasMany(Apikey::class);
    }

    public function oauths(): HasMany
    {
        return $this->hasMany(OAuthModel::class);
    }

    public function resetOAuths(): void
    {
        foreach ($this->oauths as $oauth) {
            $oauth->reset();
        }
        $this->refresh();
    }
}

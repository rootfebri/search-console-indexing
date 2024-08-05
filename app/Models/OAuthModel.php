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

    private function shouldReset(): bool
    {
        if (!$this->refresh_time) {
            $this->refresh_time = time();
            $this->save();
            return false;
        }
        $refreshOn = $this->refresh_time + 24 * 60 * 60;
        if (time() > $refreshOn) {
            return true;
        }
        return false;
    }

    public function reset(): static
    {
        if ($this->shouldReset()) {
            $this->refresh_time = time();
            $this->limit = 200;
            $this->save();
        }

        return $this;
    }

    public function usable(): bool
    {
        return $this->limit > 0;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Indexed extends Model
{
    protected $table = 'indexeds';
    protected $fillable = ['sitemap_url', 'url', 'success'];

    protected $casts = [
        'success' => 'boolean',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
        ];
    }

    protected $appends = ['last_update'];

    public function getLastUpdateAttribute(): float
    {
        return Carbon::now()->diffInHours($this->updated_at);
    }
}

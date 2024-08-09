<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    public $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $table = 'sites';
    protected $fillable = ['url', 'success', 'request_on'];
    protected $primaryKey = 'url';
    protected $casts = ['success' => 'boolean'];
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
        ];
    }
    public function overHours(int $hours = 24): bool
    {
        return now()->timestamp($this->request_on)->diffInHours(null, true) >= $hours;
    }
}

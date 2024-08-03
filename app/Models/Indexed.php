<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Indexed extends Model
{
    protected $table = 'indexeds';
    protected $fillable = ['sitemap_url', 'url', 'success'];
}

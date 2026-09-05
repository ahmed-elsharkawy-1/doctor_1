<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $fillable = [
        'key',
        'category',
        'is_broadcast',
        'body_ar',
        'provider_template_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_broadcast' => 'boolean',
        ];
    }
}

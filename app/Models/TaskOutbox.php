<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskOutbox extends Model
{
    protected $table = 'task_outbox';

    protected $fillable = [
        'task_name',
        'site_key',
        'payload',
        'status',
        'attempts',
        'last_error',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}

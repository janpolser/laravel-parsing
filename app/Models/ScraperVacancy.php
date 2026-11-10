<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScraperVacancy extends Model
{
    protected $table = 'vacancies';

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'title',
        'company',
        'location',
        'description',
        'contacts',
        'salary_value',
        'salary_currency',
        'job_type',
        'level',
        'skills',
        'posted_at',
        'source_url',
        'scraped_at',
        'first_seen_at',
        'last_seen_at',
        'is_active',
        'content_hash',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'contacts' => 'array',
            'skills' => 'array',
            'salary_value' => 'float',
            'scraped_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ScraperSite::class, 'site_id');
    }
}

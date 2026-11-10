<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeRun extends Model
{
    protected $table = 'scrape_runs';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'site_id',
        'started_at',
        'finished_at',
        'status',
        'pages_scanned',
        'jobs_found',
        'jobs_upserted',
        'error_message',
        'llm_provider',
        'llm_model',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pages_scanned' => 'integer',
            'jobs_found' => 'integer',
            'jobs_upserted' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ScraperSite::class, 'site_id');
    }
}

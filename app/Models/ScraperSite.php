<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScraperSite extends Model
{
    protected $table = 'sites';

    protected $fillable = [
        'site_key',
        'domain',
        'base_url',
        'company_name',
        'company_contact',
        'company_emails',
        'company_phones',
        'company_sites',
        'region',
        'city',
        'address',
        'career_url',
        'parse_status',
        'status',
        'empty_runs',
        'last_http_status',
        'last_modified_at',
        'last_scraped_at',
        'next_scrape_at',
        'fail_count',
    ];

    protected function casts(): array
    {
        return [
            'company_emails' => 'array',
            'company_phones' => 'array',
            'company_sites' => 'array',
            'empty_runs' => 'integer',
            'last_http_status' => 'integer',
            'last_modified_at' => 'datetime',
            'last_scraped_at' => 'datetime',
            'next_scrape_at' => 'datetime',
            'fail_count' => 'integer',
        ];
    }

    public function vacancies(): HasMany
    {
        return $this->hasMany(ScraperVacancy::class, 'site_id');
    }

    public function scrapeRuns(): HasMany
    {
        return $this->hasMany(ScrapeRun::class, 'site_id');
    }
}

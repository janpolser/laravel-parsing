<?php

namespace App\Services\UniversalScraper;

use App\Models\ScraperVacancy;
use Illuminate\Support\Facades\Storage;
use XMLWriter;

class FeedXmlRenderer
{
    public function writeFile(?string $path = null, ?int $limit = null, ?string $disk = null): int
    {
        $path ??= (string) config('universal_scraper.feed.output', 'universal/feed.xml');
        $disk ??= (string) config('universal_scraper.feed.disk', 'public');
        $xml = $this->render($limit);

        Storage::disk($disk)->put($path, $xml['content']);

        return $xml['count'];
    }

    /**
     * @return array{content: string, count: int}
     */
    public function render(?int $limit = null): array
    {
        $limit ??= (int) config('universal_scraper.feed.limit', 0);
        $query = ScraperVacancy::query()
            ->where('is_active', true)
            ->orderByDesc('last_seen_at');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('jobs');

        $count = 0;

        foreach ($query->cursor() as $vacancy) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            $this->writeJob($writer, $vacancy);
            $count++;
        }

        $writer->endElement();
        $writer->endDocument();

        return ['content' => $writer->outputMemory(), 'count' => $count];
    }

    private function writeJob(XMLWriter $writer, ScraperVacancy $vacancy): void
    {
        $writer->startElement('job');
        $this->text($writer, 'title', $vacancy->title);
        $this->text($writer, 'company', $vacancy->company);
        $this->text($writer, 'location', $vacancy->location);
        $this->text($writer, 'description', $vacancy->description);
        $this->array($writer, 'contacts', 'contact', $vacancy->contacts ?? []);

        if ($vacancy->salary_value !== null || $vacancy->salary_currency !== null) {
            $writer->startElement('salary');
            $this->text($writer, 'value', $vacancy->salary_value);
            $this->text($writer, 'currency', $vacancy->salary_currency);
            $writer->endElement();
        }

        $this->text($writer, 'type', $vacancy->job_type);
        $this->text($writer, 'level', $vacancy->level);
        $this->array($writer, 'skills', 'skill', $vacancy->skills ?? []);
        $this->text($writer, 'posted_at', $vacancy->posted_at);
        $this->text($writer, 'source_url', $vacancy->source_url);
        $this->text($writer, 'scraped_at', $vacancy->scraped_at?->toISOString());
        $writer->endElement();
    }

    private function text(XMLWriter $writer, string $name, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $writer->writeElement($name, (string) $value);
    }

    /**
     * @param list<string> $values
     */
    private function array(XMLWriter $writer, string $root, string $item, array $values): void
    {
        if ($values === []) {
            return;
        }

        $writer->startElement($root);

        foreach ($values as $value) {
            $this->text($writer, $item, $value);
        }

        $writer->endElement();
    }
}

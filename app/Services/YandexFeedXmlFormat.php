<?php

namespace App\Services;

use DateTime;
use Exception;
use Illuminate\Support\Facades\Log;
use XMLWriter;

class YandexFeedXmlFormat
{
    private array $mandatoryFields = [
        'url', 'creation_date', 'job_name', 'description', 'company_name',
    ];

    public function createXmlFeed(array $entities, string $hostName, string $filePath): void
    {
        date_default_timezone_set('Europe/Moscow');
        $this->ensureDirectory($filePath);

        $tmpPath = $filePath . '.tmp';
        if (is_file($tmpPath)) {
            unlink($tmpPath);
        }

        $writer = new XMLWriter();
        if (!$writer->openURI($tmpPath)) {
            throw new Exception("Cannot open XML file for writing: {$tmpPath}");
        }

        try {
            $writer->startDocument('1.0', 'utf-8');
            $writer->startElement('source');
            $writer->writeAttribute('creation-time', (new DateTime)->format('Y-m-d H:i:s') . ' GMT+3');
            $writer->writeAttribute('host', $hostName);
            $writer->startElement('vacancies');

            foreach ($entities as $entity) {
                try {
                    $this->validateVacancy($entity);
                    $this->addVacancyToXml($writer, $entity);
                } catch (Exception $e) {
                    $this->logSkippedVacancy($entity, $e);
                }
            }

            $writer->endElement(); // vacancies
            $writer->endElement(); // source
            $writer->endDocument();
            $writer->flush();

            $this->publishTmpFile($tmpPath, $filePath);
        } catch (\Throwable $e) {
            $writer->flush();
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }

            throw $e;
        }
    }

    private function validateVacancy(array $vacancy): void
    {
        foreach ($this->mandatoryFields as $field) {
            if (empty($vacancy[$field])) {
                throw new Exception("Missing mandatory field: {$field}");
            }
        }
    }

    private function addVacancyToXml(XMLWriter $writer, array $v): void
    {
        $writer->startElement('vacancy');

        $this->writeTextElement($writer, 'url', $v['url']);
        $this->writeTextElement($writer, 'mobile-url', $v['mobile_url'] ?? null);
        $this->writeTextElement($writer, 'creation-date', $v['creation_date']);
        $this->writeTextElement($writer, 'update-date', $v['update_date'] ?? null);

        $this->writeTextElement($writer, 'salary', $v['salary'] ?? null);
        $this->writeTextElement($writer, 'currency', $v['currency'] ?? null);

        if (!empty($v['category'])) {
            $writer->startElement('category');
            $this->writeTextElement($writer, 'industry', $v['category']['industry'] ?? null);
            $this->writeTextElement($writer, 'specialization', $v['category']['specialization'] ?? null);
            $writer->endElement();
        }

        $this->writeTextElement($writer, 'job-name', $v['job_name']);
        $this->writeTextElement($writer, 'employment', $v['employment'] ?? null);
        $this->writeTextElement($writer, 'schedule', $v['schedule'] ?? null);
        $this->writeTextElement($writer, 'description', $v['description']);
        $this->writeTextElement($writer, 'duty', $v['duty'] ?? null);

        if (!empty($v['term'])) {
            $writer->startElement('term');
            $this->writeTextElement($writer, 'contract', $v['term']['contract'] ?? null);
            $this->writeTextElement($writer, 'text', $v['term']['text'] ?? null);
            $writer->endElement();
        }

        if (!empty($v['requirement'])) {
            $writer->startElement('requirement');
            $this->writeTextElement($writer, 'age', $v['requirement']['age'] ?? null);
            $this->writeTextElement($writer, 'sex', $v['requirement']['sex'] ?? null);
            $this->writeTextElement($writer, 'education', $v['requirement']['education'] ?? null);
            $this->writeTextElement($writer, 'experience', $v['requirement']['experience'] ?? null);
            $this->writeTextElement($writer, 'qualification', $v['requirement']['qualification'] ?? null);
            $writer->endElement();
        }

        if (!empty($v['addresses'])) {
            $this->writeAddresses($writer, $v['addresses']);
        }

        $writer->startElement('company');
        $this->writeTextElement($writer, 'name', $v['company_name']);
        $this->writeTextElement($writer, 'description', $v['company_description'] ?? null);
        $this->writeTextElement($writer, 'logo', $v['company_logo'] ?? null);
        $this->writeTextElement($writer, 'site', $v['company_site'] ?? null);

        foreach (['email', 'phone', 'fax'] as $contactType) {
            if (!empty($v["company_$contactType"])) {
                foreach ((array) $v["company_$contactType"] as $val) {
                    $this->writeTextElement($writer, $contactType, $val);
                }
            }
        }

        $this->writeTextElement(
            $writer,
            'hr-agency',
            array_key_exists('hr_agency', $v) ? $this->booleanText($v['hr_agency']) : null
        );
        $this->writeTextElement($writer, 'contact-name', $v['contact_name'] ?? null);
        $writer->endElement();

        $this->writeTextElement($writer, 'campaign', $v['campaign'] ?? null);

        $writer->endElement();
    }

    private function writeAddresses(XMLWriter $writer, array $addresses): void
    {
        if (isset($addresses['address'])) {
            $addresses = [$addresses['address']];
        } elseif ($this->isSingleAddress($addresses)) {
            $addresses = [$addresses];
        }

        $writer->startElement('addresses');
        foreach ($addresses as $addrData) {
            if (!is_array($addrData)) {
                continue;
            }

            $writer->startElement('address');
            $this->writeTextElement($writer, 'location', $addrData['location'] ?? null);
            if (!empty($addrData['metro'])) {
                foreach ((array) $addrData['metro'] as $m) {
                    $this->writeTextElement($writer, 'metro', $m);
                }
            }
            $this->writeTextElement($writer, 'lng', $addrData['lng'] ?? null);
            $this->writeTextElement($writer, 'lat', $addrData['lat'] ?? null);
            $writer->endElement();
        }
        $writer->endElement();
    }

    private function writeTextElement(XMLWriter $writer, string $name, $value): void
    {
        if ($value !== null && $value !== '') {
            $writer->startElement($name);
            $writer->text((string) $value);
            $writer->endElement();
        }
    }

    private function isSingleAddress(array $addresses): bool
    {
        return array_intersect(['location', 'metro', 'lng', 'lat'], array_keys($addresses)) !== [];
    }

    private function booleanText($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true) ? 'true' : 'false';
        }

        return $value ? 'true' : 'false';
    }

    private function logSkippedVacancy(array $entity, Exception $exception): void
    {
        try {
            Log::warning('Skipping invalid vacancy for XML feed', [
                'reason' => $exception->getMessage(),
                'url' => $entity['url'] ?? null,
                'job_name' => $entity['job_name'] ?? null,
            ]);
        } catch (\RuntimeException) {
            // Unit contexts may instantiate this service without a Laravel facade root.
        }
    }

    private function ensureDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function publishTmpFile(string $tmpPath, string $filePath): void
    {
        if (!rename($tmpPath, $filePath)) {
            if (is_file($filePath)) {
                unlink($filePath);
            }

            if (!rename($tmpPath, $filePath)) {
                throw new Exception("Cannot publish XML file: {$filePath}");
            }
        }
    }
}

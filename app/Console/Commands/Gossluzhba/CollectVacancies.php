<?php

namespace App\Console\Commands\Gossluzhba;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use XMLWriter;

class CollectVacancies extends Command
{
    private const BASE_URL = 'https://gossluzhba.gov.ru';

    private const LIST_URL = self::BASE_URL . '/api/vacancy/data/get-vacancies';

    private const DETAIL_URL = self::BASE_URL . '/api/vacancy/data/';

    private const CLIENT_CONFIG_URL = self::BASE_URL . '/api/config/client/private-get';

    private const DEFAULT_FILE_STORAGE_DOWNLOAD_URL = 'https://files.gossluzhba.gov.ru/49309a89-3c66-408c-805a-2d42b28e89c9/download/';

    private const HOST = 'gossluzhba.gov.ru';

    private const TIMEZONE = 'Europe/Moscow';

    protected $signature = 'gossluzhba:collect-vacancies
        {--outfile=GossluzhbaVacancies : Base XML file name without date and extension}
        {--page-size=1000 : List API page size}
        {--max-pages=0 : Max list pages to fetch, 0 means all pages}
        {--details-limit=0 : Max detail API requests per run, 0 means no limit}
        {--sleep-ms=1500 : Delay between detail requests, ms}
        {--list-sleep-ms=500 : Delay between list page requests, ms}
        {--refresh-days=30 : Refresh cached details after N days}
        {--region= : Optional okatoregion UUID filter}
        {--area= : Optional okatoarea UUID filter}
        {--proxy=* : Optional HTTP/SOCKS proxy URL, can be repeated}
        {--allow-summary-fallback : Write summary-only vacancies when detail is not available}
        {--without-tls-verify : Disable TLS verification for local debugging only}';

    protected $description = 'Collects gossluzhba.gov.ru vacancies and writes Yandex-compatible XML in a streaming mode.';

    private int $detailRequests = 0;

    private array $state = [];

    private string $fileStorageDownloadUrl = self::DEFAULT_FILE_STORAGE_DOWNLOAD_URL;

    private ?string $lastRequestError = null;

    private array $proxies = [];

    private int $proxyCursor = 0;

    public function handle(): int
    {
        $pageSize = max(1, (int) $this->option('page-size'));
        $maxPages = max(0, (int) $this->option('max-pages'));
        $detailsLimit = max(0, (int) $this->option('details-limit'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $listSleepMs = max(0, (int) $this->option('list-sleep-ms'));
        $refreshDays = max(1, (int) $this->option('refresh-days'));
        $allowSummaryFallback = (bool) $this->option('allow-summary-fallback');
        $filters = $this->requestFilters();
        $this->proxies = $this->resolveProxies();

        $this->state = $this->loadState();
        $this->fileStorageDownloadUrl = $this->fetchFileStorageDownloadUrl()
            ?? self::DEFAULT_FILE_STORAGE_DOWNLOAD_URL;

        $outFileName = (string) $this->option('outfile') . Carbon::now(self::TIMEZONE)->toDateString() . '.xml';
        $outPath = storage_path('app/public/gossluzhba/' . $outFileName);
        $tmpPath = $outPath . '.tmp';

        $this->ensureDirectory($outPath);
        if (is_file($tmpPath)) {
            unlink($tmpPath);
        }

        $writer = $this->openWriter($tmpPath);
        $written = 0;
        $skipped = 0;
        $missingDetails = 0;
        $page = 1;
        $pageCount = null;
        $seenIds = [];

        $this->info(sprintf(
            'Start gossluzhba parser: page_size=%d, details_limit=%d, detail_delay=%dms, proxies=%d, allow_summary_fallback=%s',
            $pageSize,
            $detailsLimit,
            $sleepMs,
            count($this->proxies),
            $allowSummaryFallback ? 'yes' : 'no'
        ));

        try {
            while (true) {
                if ($maxPages > 0 && $page > $maxPages) {
                    $this->warn("Reached max-pages={$maxPages}");
                    break;
                }

                $list = $this->fetchListPage($page, $pageSize, $filters);
                if ($list === null) {
                    $reason = $this->lastRequestError ? ': '.$this->lastRequestError : '';

                    throw new \RuntimeException("Cannot fetch list page {$page}{$reason}");
                }

                $vacancies = $list['vacancies'] ?? [];
                if (!is_array($vacancies)) {
                    throw new \RuntimeException("Invalid vacancies payload on page {$page}");
                }

                $pageCount = (int) ($list['pageCount'] ?? $pageCount ?? 1);
                $total = (int) ($list['total'] ?? 0);

                $this->line(sprintf(
                    'Page %d/%d: %d vacancies, total=%d',
                    $page,
                    $pageCount,
                    count($vacancies),
                    $total
                ));

                foreach ($vacancies as $summary) {
                    if (!is_array($summary)) {
                        continue;
                    }

                    $id = $this->normalizeId($summary['id'] ?? null);
                    if ($id === null || isset($seenIds[$id])) {
                        continue;
                    }
                    $seenIds[$id] = true;

                    $summaryHash = $this->summaryHash($summary);
                    $detail = $this->resolveDetail(
                        $id,
                        $summaryHash,
                        $detailsLimit,
                        $sleepMs,
                        $refreshDays
                    );

                    if (empty($detail) && !$allowSummaryFallback) {
                        $missingDetails++;
                        $skipped++;
                        Log::warning('Skipping gossluzhba vacancy without detail payload', [
                            'id' => $id,
                            'summary_hash' => $summaryHash,
                        ]);

                        continue;
                    }

                    $entity = $this->mapToEntity($summary, $detail);
                    if (!$this->isValidEntity($entity)) {
                        $skipped++;
                        Log::warning('Skipping invalid gossluzhba vacancy', [
                            'id' => $id,
                            'url' => $entity['url'] ?? null,
                            'job_name' => $entity['job_name'] ?? null,
                        ]);

                        continue;
                    }

                    $this->writeVacancyXml($writer, $entity);
                    $written++;

                    $this->state[$id] = array_merge($this->state[$id] ?? [], [
                        'summary_hash' => $summaryHash,
                        'last_seen_at' => Carbon::now(self::TIMEZONE)->toIso8601String(),
                    ]);

                    if ($written % 100 === 0) {
                        $writer->flush();
                        $this->line(sprintf(
                            'Written %d vacancies, detail_requests=%d',
                            $written,
                            $this->detailRequests
                        ));
                    }
                }

                if ($page >= $pageCount || empty($vacancies)) {
                    break;
                }

                $page++;
                if ($listSleepMs > 0) {
                    usleep($listSleepMs * 1000);
                }
            }

            $this->closeWriter($writer);
            $this->publishTmpFile($tmpPath, $outPath);
            $this->writeState($this->state);

            $this->info(sprintf(
                'XML generated: %s, vacancies=%d, skipped=%d, missing_details=%d, detail_requests=%d',
                $outPath,
                $written,
                $skipped,
                $missingDetails,
                $this->detailRequests
            ));

            return self::SUCCESS;
        } catch (GossluzhbaProtectionException $e) {
            $writer->flush();
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
            $this->writeState($this->state);
            $this->error('Stopped to avoid blocking: ' . $e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $writer->flush();
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
            $this->writeState($this->state);
            $this->error('Gossluzhba parser failed: ' . $e->getMessage());
            Log::error('Gossluzhba parser failed', ['exception' => $e]);

            return self::FAILURE;
        }
    }

    private function fetchListPage(int $page, int $pageSize, array $filters): ?array
    {
        $payload = array_filter([
            'page' => $page,
            'pageSize' => $pageSize,
            'sort' => 0,
            'direction' => 1,
            'isTargetedTraining' => false,
            'regions' => $filters['regions'] ?? null,
            'areas' => $filters['areas'] ?? null,
        ], static fn ($value) => $value !== null);

        $response = $this->sendJsonRequest('post', self::LIST_URL, $payload);
        if ($response === null) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    private function resolveDetail(
        string $id,
        string $summaryHash,
        int $detailsLimit,
        int $sleepMs,
        int $refreshDays
    ): array {
        $cached = $this->loadDetailCache($id);
        $state = $this->state[$id] ?? [];
        $cachedHash = $state['summary_hash'] ?? null;
        $fetchedAt = $state['detail_fetched_at'] ?? null;

        $needsFetch = empty($cached)
            || $cachedHash !== $summaryHash
            || $this->isStale($fetchedAt, $refreshDays);

        if (!$needsFetch) {
            return $cached;
        }

        if ($detailsLimit > 0 && $this->detailRequests >= $detailsLimit) {
            return $cached;
        }

        if ($sleepMs > 0 && $this->detailRequests > 0) {
            usleep($sleepMs * 1000);
        }

        $detail = $this->fetchDetail($id);
        if (!empty($detail)) {
            $this->writeDetailCache($id, $detail);
            $this->state[$id] = array_merge($this->state[$id] ?? [], [
                'summary_hash' => $summaryHash,
                'detail_fetched_at' => Carbon::now(self::TIMEZONE)->toIso8601String(),
            ]);

            return $detail;
        }

        return $cached;
    }

    private function fetchDetail(string $id): array
    {
        $this->detailRequests++;

        $response = $this->sendJsonRequest('get', self::DETAIL_URL . rawurlencode($id));
        if ($response === null) {
            return [];
        }

        $json = $response->json();
        if (!is_array($json)) {
            return [];
        }

        $vacancy = $json['vacancy'] ?? [];

        return is_array($vacancy) ? $vacancy : [];
    }

    private function fetchFileStorageDownloadUrl(): ?string
    {
        $response = $this->sendJsonRequest('get', self::CLIENT_CONFIG_URL);
        if ($response === null) {
            return null;
        }

        $json = $response->json();
        if (!is_array($json)) {
            return null;
        }

        $url = $this->cleanText($json['fileStorageDownloadUrl'] ?? null);

        return $url ? rtrim($url, '/') . '/' : null;
    }

    private function sendJsonRequest(string $method, string $url, array $payload = []): ?Response
    {
        $maxRetries = max(3, count($this->proxies));
        $this->lastRequestError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $proxy = $this->nextProxy();

            try {
                $request = Http::timeout(35)
                    ->connectTimeout(15)
                    ->withOptions($this->requestOptions($proxy))
                    ->acceptJson()
                    ->withHeaders($this->requestHeaders());

                if ($this->option('without-tls-verify')) {
                    $request = $request->withoutVerifying();
                }

                $response = $method === 'post'
                    ? $request->post($url, $payload)
                    : $request->get($url);
            } catch (\Throwable $e) {
                $this->lastRequestError = $e->getMessage();
                Log::warning('Gossluzhba network error', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'proxy' => $this->maskProxy($proxy),
                    'message' => $e->getMessage(),
                ]);

                if ($attempt >= $maxRetries) {
                    return null;
                }

                $this->sleepBackoff($attempt);

                continue;
            }

            $status = $response->status();
            if ($response->successful()) {
                $this->lastRequestError = null;

                return $response;
            }

            $this->lastRequestError = "HTTP {$status}: ".mb_substr($response->body(), 0, 500);

            Log::warning('Gossluzhba non-200 response', [
                'url' => $url,
                'attempt' => $attempt,
                'proxy' => $this->maskProxy($proxy),
                'status' => $status,
                'body_snippet' => mb_substr($response->body(), 0, 500),
            ]);

            if ($status === 403 || $status === 429) {
                if ($this->proxies !== [] && $attempt < $maxRetries) {
                    $this->sleepBackoff($attempt);

                    continue;
                }

                throw new GossluzhbaProtectionException("HTTP {$status} for {$url}");
            }

            if ($status < 500 || $attempt >= $maxRetries) {
                return null;
            }

            $this->sleepBackoff($attempt);
        }

        return null;
    }

    private function requestOptions(?string $proxy): array
    {
        $options = [
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ];

        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        return $options;
    }

    private function mapToEntity(array $summary, array $detail): array
    {
        $id = $this->normalizeId($summary['id'] ?? $detail['id'] ?? null) ?? '';
        $url = self::BASE_URL . '/vacancy/' . $id;
        $title = $this->cleanText($detail['caption'] ?? $detail['position'] ?? $summary['caption'] ?? '');
        $company = $this->resolveCompanyName($summary, $detail);
        $createdAt = $this->formatDate($detail['announcementDate'] ?? $summary['announcementDate'] ?? null);
        $updatedAt = $this->formatDate($detail['announcementDate'] ?? $summary['announcementDate'] ?? null);
        $description = $this->composeDescription($summary, $detail);
        $duty = $this->composeDutyText($detail);

        $phones = $this->collectPhones($detail);
        $address = $this->firstNonEmpty([
            $detail['workPlaceAddress'] ?? null,
            $summary['fullAddress'] ?? null,
            $detail['contactAddress'] ?? null,
            $detail['registrationAddress'] ?? null,
            trim(($summary['regionName'] ?? '') . ', ' . ($summary['areaName'] ?? '')),
        ]);

        $category = array_filter([
            'industry' => $this->cleanText($detail['jobTypeName'] ?? $summary['jobTypeName'] ?? null),
            'specialization' => $this->composeInlineList([
                $detail['positionCategoryName'] ?? $summary['positionCategoryName'] ?? null,
                $detail['positionGroupName'] ?? $summary['positionGroupName'] ?? null,
                $detail['departmentName'] ?? $summary['departmentName'] ?? null,
            ]),
        ], fn ($value) => $this->hasValue($value));

        $entity = [
            'url' => $url,
            'mobile_url' => $url,
            'creation_date' => $createdAt,
            'update_date' => $updatedAt,
            'salary' => $this->formatSalary($detail['salaryFrom'] ?? $summary['salaryFrom'] ?? null, $detail['salaryTo'] ?? $summary['salaryTo'] ?? null),
            'currency' => 'RUR',
            'category' => $category,
            'job_name' => $title,
            'schedule' => $this->cleanText($detail['workScheduleName'] ?? null),
            'description' => $description,
            'duty' => $duty,
            'term' => array_filter([
                'contract' => $this->cleanText($detail['isIndefiniteContractTerm'] ?? null) === '1' ? 'indefinite' : null,
                'text' => $this->composeTermText($detail),
            ], fn ($value) => $this->hasValue($value)),
            'requirement' => array_filter([
                'education' => $this->cleanText($detail['educationLevelName'] ?? null),
                'experience' => $this->cleanText($detail['governmentExperienceName'] ?? $summary['governmentExperienceName'] ?? null),
                'qualification' => $this->composeList(array_merge(
                    $this->collectTextItems($detail['qualificationRequirements'] ?? null),
                    $this->collectTextItems($detail['knowledgeRequirements'] ?? null),
                    $this->collectTextItems($detail['skillRequirements'] ?? null),
                    $this->collectTextItems($detail['additionalRequirements'] ?? null)
                )),
            ], fn ($value) => $this->hasValue($value)),
            'addresses' => $address ? [[
                'location' => $address,
                'lng' => null,
                'lat' => null,
            ]] : null,
            'company_name' => $company,
            'company_site' => $this->cleanText($detail['contactWeb'] ?? null),
            'company_email' => $this->cleanText($detail['contactEmail'] ?? null),
            'company_phone' => $phones,
            'hr_agency' => false,
            'contact_name' => $this->cleanText($detail['contactPerson'] ?? null),
            'campaign' => $company,
        ];

        return array_filter($entity, fn ($value) => $this->hasValue($value));
    }

    private function composeDescription(array $summary, array $detail): string
    {
        $parts = [];

        $summaryText = $this->composeList([
            $detail['workTypeName'] ?? null,
            $summary['jobTypeName'] ?? null,
            $detail['okatoRegionName'] ?? $summary['regionName'] ?? null,
            $detail['okatoAreaName'] ?? $summary['areaName'] ?? null,
            $summary['departmentName'] ?? null,
            $summary['positionCategoryName'] ?? null,
            $summary['positionGroupName'] ?? null,
            $detail['profile'] ?? null,
            $detail['workPlaceAddress'] ?? $summary['fullAddress'] ?? null,
        ]);
        if ($summaryText) {
            $parts[] = $summaryText;
        }

        $description = $this->normalizeMultilineText(implode("\n\n", array_unique(array_filter($parts))));

        return $description !== ''
            ? $description
            : 'Actual vacancy from gossluzhba.gov.ru. See source URL for details.';
    }

    private function composeDutyText(array $detail): ?string
    {
        return $this->composeList(array_merge(
            $this->collectTextItems($detail['jobResponsibilities'] ?? null),
            $this->collectTextItems($detail['jobResponsibilitiyFromVacancy'] ?? null)
        ));
    }

    private function composeTermText(array $detail): ?string
    {
        $parts = [];

        foreach ([
            'additionalInformationAboutPosition' => 'Дополнительная информация о должности',
            'workScheduleName' => 'График работы',
            'businessTripName' => 'Командировки',
            'socialPackage' => 'Социальный пакет',
            'additionalInformation' => 'Дополнительная информация',
        ] as $field => $label) {
            $text = $this->cleanText($detail[$field] ?? null);
            if ($text !== null && $text !== '') {
                $parts[] = $label.': '.$text;
            }
        }

        $documentsReception = $this->composeDocumentsReceptionText($detail);
        if ($documentsReception) {
            $parts[] = $documentsReception;
        }

        $attachmentLinks = $this->composeAttachmentLinks($detail);
        if ($attachmentLinks) {
            $parts[] = "Документы:\n".$attachmentLinks;
        }

        return $this->composeList($parts);
    }

    private function composeDocumentsReceptionText(array $detail): ?string
    {
        $parts = [];

        $registrationAddress = $this->cleanText($detail['registrationAddress'] ?? null);
        if ($registrationAddress) {
            $parts[] = 'Адрес приема документов: '.$registrationAddress;
        }

        $registrationTime = $this->cleanText($detail['registrationTime'] ?? null);
        if ($registrationTime) {
            $parts[] = 'Время приема документов: '.$registrationTime;
        }

        if (array_key_exists('isElectronicForm', $detail)) {
            $parts[] = 'Электронная форма подачи документов: '.($detail['isElectronicForm'] ? 'да' : 'нет');
        }

        return $this->composeList($parts);
    }

    private function composeAttachmentLinks(array $detail): ?string
    {
        $fields = [
            'positionRuleAttachments' => 'Должностной регламент',
            'socialPackageAttachments' => 'Социальный пакет',
            'additionalInformationAboutPositionAttachments' => 'Дополнительная информация о должности',
            'additionalInformationAttachments' => 'Документы для участия',
            'knowledgeAttachments' => 'Требования к знаниям',
            'evaluationAttachments' => 'Оценочные материалы',
            'jobResponsibilitiesAttachments' => 'Должностные обязанности',
            'targetedTrainingSupportInformationAttachments' => 'Меры поддержки',
            'targetedTrainingTrialTaskDescriptionAttachments' => 'Пробное задание',
        ];

        $links = [];
        foreach ($fields as $field => $label) {
            foreach ($this->collectAttachmentLinks($detail[$field] ?? null, $label) as $link) {
                $links[] = $link;
            }
        }

        $links = array_values(array_unique($links));

        return $links ? implode("\n", $links) : null;
    }

    private function collectAttachmentLinks(mixed $attachments, string $label): array
    {
        if (!is_array($attachments)) {
            return [];
        }

        $links = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $id = $this->normalizeId($attachment['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $name = $this->cleanText($attachment['originalName'] ?? null) ?: $id;
            $links[] = $label . ': ' . $name . ' - ' . $this->attachmentDownloadUrl($id);
        }

        return $links;
    }

    private function attachmentDownloadUrl(string $id): string
    {
        return rtrim($this->fileStorageDownloadUrl, '/') . '/' . rawurlencode($id);
    }

    private function hasAttachments(mixed $attachments): bool
    {
        return is_array($attachments) && !empty($attachments);
    }

    private function writeVacancyXml(XMLWriter $writer, array $v): void
    {
        $writer->startElement('vacancy');

        $this->writeTextElement($writer, 'url', $v['url'] ?? null);
        $this->writeTextElement($writer, 'mobile-url', $v['mobile_url'] ?? null);
        $this->writeTextElement($writer, 'creation-date', $v['creation_date'] ?? null);
        $this->writeTextElement($writer, 'update-date', $v['update_date'] ?? null);

        $this->writeTextElement($writer, 'salary', $v['salary'] ?? null);
        $this->writeTextElement($writer, 'currency', $v['currency'] ?? null);

        if (!empty($v['category'])) {
            $writer->startElement('category');
            $this->writeTextElement($writer, 'industry', $v['category']['industry'] ?? null);
            $this->writeTextElement($writer, 'specialization', $v['category']['specialization'] ?? null);
            $writer->endElement();
        }

        $this->writeTextElement($writer, 'job-name', $v['job_name'] ?? null);
        $this->writeTextElement($writer, 'employment', $v['employment'] ?? null);
        $this->writeTextElement($writer, 'schedule', $v['schedule'] ?? null);
        $this->writeTextElement($writer, 'description', $v['description'] ?? null);
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
            $writer->startElement('addresses');
            foreach ($v['addresses'] as $address) {
                if (!is_array($address)) {
                    continue;
                }

                $writer->startElement('address');
                $this->writeTextElement($writer, 'location', $address['location'] ?? null);
                $this->writeTextElement($writer, 'metro', $address['metro'] ?? null);
                $this->writeTextElement($writer, 'lng', $address['lng'] ?? null);
                $this->writeTextElement($writer, 'lat', $address['lat'] ?? null);
                $writer->endElement();
            }
            $writer->endElement();
        }

        $writer->startElement('company');
        $this->writeTextElement($writer, 'name', $v['company_name'] ?? null);
        $this->writeTextElement($writer, 'description', $v['company_description'] ?? null);
        $this->writeTextElement($writer, 'logo', $v['company_logo'] ?? null);
        $this->writeTextElement($writer, 'site', $v['company_site'] ?? null);

        foreach (['email', 'phone', 'fax'] as $contactType) {
            if (!empty($v["company_{$contactType}"])) {
                foreach ((array) $v["company_{$contactType}"] as $value) {
                    $this->writeTextElement($writer, $contactType, $value);
                }
            }
        }

        if (array_key_exists('hr_agency', $v)) {
            $this->writeTextElement($writer, 'hr-agency', $v['hr_agency'] ? 'true' : 'false');
        }
        $this->writeTextElement($writer, 'contact-name', $v['contact_name'] ?? null);
        $writer->endElement();

        $this->writeTextElement($writer, 'campaign', $v['campaign'] ?? null);

        $writer->endElement();
    }

    private function openWriter(string $tmpPath): XMLWriter
    {
        $writer = new XMLWriter;
        if (!$writer->openURI($tmpPath)) {
            throw new \RuntimeException("Cannot open XML file: {$tmpPath}");
        }

        $writer->startDocument('1.0', 'utf-8');
        $writer->startElement('source');
        $writer->writeAttribute('creation-time', Carbon::now(self::TIMEZONE)->format('Y-m-d H:i:s') . ' GMT+3');
        $writer->writeAttribute('host', self::HOST);
        $writer->startElement('vacancies');

        return $writer;
    }

    private function closeWriter(XMLWriter $writer): void
    {
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    private function writeTextElement(XMLWriter $writer, string $name, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $writer->startElement($name);
            $writer->text((string) $value);
            $writer->endElement();
        }
    }

    private function isValidEntity(array $entity): bool
    {
        foreach (['url', 'creation_date', 'job_name', 'description', 'company_name'] as $field) {
            if (empty($entity[$field])) {
                return false;
            }
        }

        return true;
    }

    private function resolveProxies(): array
    {
        $configured = [];
        $optionProxies = $this->option('proxy');

        if (is_array($optionProxies)) {
            $configured = array_merge($configured, $optionProxies);
        } elseif (is_string($optionProxies) && trim($optionProxies) !== '') {
            $configured[] = $optionProxies;
        }

        $configured = array_merge(
            $configured,
            $this->parseProxyList($this->environmentValue('GOSSLUZHBA_PROXIES'))
        );

        $proxies = [];
        foreach ($configured as $proxy) {
            if (!is_string($proxy)) {
                continue;
            }

            $proxy = trim($proxy);
            if ($proxy === '') {
                continue;
            }

            if (!$this->isSupportedProxy($proxy)) {
                $this->warn('Skipping unsupported proxy: ' . $this->maskProxy($proxy));

                continue;
            }

            $proxies[$proxy] = $proxy;
        }

        return array_values($proxies);
    }

    private function parseProxyList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return preg_split('/[\r\n,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function environmentValue(string $key): ?string
    {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function isSupportedProxy(string $proxy): bool
    {
        $scheme = parse_url($proxy, PHP_URL_SCHEME);

        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https', 'socks5', 'socks5h'], true);
    }

    private function nextProxy(): ?string
    {
        if ($this->proxies === []) {
            return null;
        }

        $proxy = $this->proxies[$this->proxyCursor % count($this->proxies)];
        $this->proxyCursor++;

        return $proxy;
    }

    private function maskProxy(?string $proxy): ?string
    {
        if ($proxy === null) {
            return null;
        }

        $parts = parse_url($proxy);
        if (!is_array($parts) || empty($parts['host'])) {
            return '[proxy hidden]';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'proxy';
        $auth = isset($parts['user']) ? '***:***@' : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$auth}{$parts['host']}{$port}";
    }

    private function requestHeaders(): array
    {
        return [
            'User-Agent' => 'vacancy-feed-laravel/1.0 (+https://gossluzhba.gov.ru)',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Origin' => self::BASE_URL,
            'Referer' => self::BASE_URL . '/vacancy/tab1',
        ];
    }

    private function requestFilters(): array
    {
        $filters = [];
        $region = $this->normalizeId($this->option('region'));
        $area = $this->normalizeId($this->option('area'));

        if ($region !== null) {
            $filters['regions'] = [$region];
        }

        if ($area !== null) {
            $filters['areas'] = [$area];
        }

        return $filters;
    }

    private function resolveCompanyName(array $summary, array $detail): ?string
    {
        $company = $this->firstNonEmpty([
            $detail['organizationName'] ?? null,
            $summary['organizationName'] ?? null,
            $detail['employerName'] ?? null,
        ]);

        if ($company === null) {
            $organizations = $this->collectTextItems($detail['organizations'] ?? null);
            $company = !empty($organizations) ? end($organizations) : null;
        }

        return $company !== null ? $this->stripTrailingUuid($company) : null;
    }

    private function stripTrailingUuid(string $text): string
    {
        $cleaned = preg_replace(
            '/\s+[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iu',
            '',
            $text
        );

        return trim($cleaned ?? $text);
    }

    private function summaryHash(array $summary): string
    {
        $stable = [];
        foreach ([
            'id',
            'caption',
            'announcementDate',
            'expireDate',
            'salaryFrom',
            'salaryTo',
            'organizationName',
            'regionId',
            'regionName',
            'areaName',
            'departmentName',
            'registrationAddress',
            'fullAddress',
            'jobTypeName',
            'positionCategoryName',
            'positionGroupName',
            'type',
            'kind',
        ] as $field) {
            $stable[$field] = $summary[$field] ?? null;
        }

        return hash('sha256', json_encode($stable, JSON_UNESCAPED_UNICODE));
    }

    private function loadState(): array
    {
        return $this->readJsonFile($this->statePath());
    }

    private function writeState(array $state): void
    {
        $this->writeJsonFile($this->statePath(), $state);
    }

    private function loadDetailCache(string $id): array
    {
        return $this->readJsonFile($this->detailPath($id));
    }

    private function writeDetailCache(string $id, array $detail): void
    {
        $this->writeJsonFile($this->detailPath($id), $detail);
    }

    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeJsonFile(string $path, array $payload): void
    {
        $this->ensureDirectory($path);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json !== false) {
            file_put_contents($path, $json);
        }
    }

    private function detailPath(string $id): string
    {
        return storage_path('app/gossluzhba/cache/details/' . $id . '.json');
    }

    private function statePath(): string
    {
        return storage_path('app/gossluzhba/state/summaries.json');
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
                throw new \RuntimeException("Cannot publish XML file: {$filePath}");
            }
        }
    }

    private function isStale(?string $value, int $refreshDays): bool
    {
        if (!$value) {
            return true;
        }

        try {
            return Carbon::parse($value)->lt(Carbon::now(self::TIMEZONE)->subDays($refreshDays));
        } catch (\Throwable) {
            return true;
        }
    }

    private function normalizeId(mixed $id): ?string
    {
        if (!is_string($id) && !is_numeric($id)) {
            return null;
        }

        $id = trim((string) $id);

        return $id !== '' ? $id : null;
    }

    private function formatDate(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value, self::TIMEZONE)
                    ->setTimezone(self::TIMEZONE)
                    ->format('Y-m-d H:i:s') . ' GMT+3';
            } catch (\Throwable) {
                // Fallback below.
            }
        }

        return Carbon::now(self::TIMEZONE)->format('Y-m-d H:i:s') . ' GMT+3';
    }

    private function formatSalary(mixed $from, mixed $to): ?string
    {
        $from = is_numeric($from) ? (float) $from : null;
        $to = is_numeric($to) ? (float) $to : null;
        $fromLabel = "\u{043E}\u{0442}";
        $toLabel = "\u{0434}\u{043E}";

        if ($from !== null && $to !== null) {
            return $fromLabel . ' ' . number_format($from, 0, '', ' ') . ' ' . $toLabel . ' ' . number_format($to, 0, '', ' ');
        }

        if ($from !== null) {
            return $fromLabel . ' ' . number_format($from, 0, '', ' ');
        }

        if ($to !== null) {
            return $toLabel . ' ' . number_format($to, 0, '', ' ');
        }

        return null;
    }

    private function collectPhones(array $detail): array
    {
        $phones = [];
        foreach (['contactPhone1', 'contactPhone2', 'contactPhone3'] as $field) {
            $phone = $this->cleanText($detail[$field] ?? null);
            if ($phone !== null && $phone !== '') {
                $phones[] = $phone;
            }
        }

        return array_values(array_unique($phones));
    }

    private function collectTextItems(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_scalar($value)) {
            $text = $this->cleanText((string) $value);

            return $text ? [$text] : [];
        }

        if (!is_array($value)) {
            return [];
        }

        foreach (['text', 'name', 'title', 'value', 'description'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                $text = $this->cleanText((string) $value[$key]);

                return $text ? [$text] : [];
            }
        }

        $items = [];
        foreach ($value as $entry) {
            $items = array_merge($items, $this->collectTextItems($entry));
        }

        return array_values(array_unique(array_filter($items)));
    }

    private function composeList(array $items): ?string
    {
        $clean = [];
        foreach ($items as $item) {
            $text = $this->cleanText($item);
            if ($text !== null && $text !== '') {
                $clean[] = rtrim($text, " \t\n\r\0\x0B;,");
            }
        }

        $clean = array_values(array_unique(array_filter($clean)));

        return $clean ? implode(";\n", $clean) : null;
    }

    private function composeInlineList(array $items): ?string
    {
        $clean = [];
        foreach ($items as $item) {
            $text = $this->cleanText($item);
            if ($text !== null && $text !== '') {
                $clean[] = $text;
            }
        }

        $clean = array_values(array_unique($clean));

        return $clean ? implode(', ', $clean) : null;
    }

    private function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return null;
        }

        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*br\s*\/?>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\/\s*(p|div|li|ul|ol|h[1-6]|section|article|tr)\s*>/iu', "\n", $text) ?? $text;
        $text = strip_tags($text);

        return $this->normalizeMultilineText($text);
    }

    private function normalizeMultilineText(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r", "\xC2\xA0", "\u{00A0}", "\u{202F}"], ["\n", "\n", ' ', ' ', ' '], $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $text = $this->cleanText($value);
            if ($text !== null && $text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }

    private function sleepBackoff(int $attempt): void
    {
        $sleepMs = min(30000, (int) (1500 * (2 ** ($attempt - 1)))) + random_int(100, 900);
        usleep($sleepMs * 1000);
    }
}

final class GossluzhbaProtectionException extends \RuntimeException {}

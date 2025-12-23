<?php

namespace App\Services;

use DateTime;
use Exception;
use SimpleXMLElement;

class YandexFeedXmlFormat
{
    private array $mandatoryFields = [
        'url', 'creation_date', 'job_name', 'description', 'company_name',
    ];

    public function createXmlFeed(array $entities, string $hostName, string $filePath): void
    {
        date_default_timezone_set('Europe/Moscow');
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><source></source>');
        $xml->addAttribute('creation-time', (new DateTime)->format('Y-m-d H:i:s') . ' GMT+3');
        $xml->addAttribute('host', $hostName);

        $vacanciesNode = $xml->addChild('vacancies');

        foreach ($entities as $entity) {
            $this->validateVacancy($entity);
            $this->addVacancyToXml($vacanciesNode, $entity);
        }

        $xml->asXML($filePath);
    }

    private function validateVacancy(array $vacancy): void
    {
        foreach ($this->mandatoryFields as $field) {
            if (empty($vacancy[$field])) {
                throw new Exception("Missing mandatory field: {$field}");
            }
        }
    }

    private function addVacancyToXml(SimpleXMLElement $parent, array $v): void
    {
        $node = $parent->addChild('vacancy');

        // Базовые поля
        $this->addTextChild($node, 'url', $v['url']);
        $this->addTextChild($node, 'mobile-url', $v['mobile_url'] ?? null);
        $this->addTextChild($node, 'creation-date', $v['creation_date']);
        $this->addTextChild($node, 'update-date', $v['update_date'] ?? null);

        // Зарплата
        $this->addTextChild($node, 'salary', $v['salary'] ?? null);
        $this->addTextChild($node, 'currency', $v['currency'] ?? null);

        // Категории (Industry & Specialization)
        if (!empty($v['category'])) {
            $cat = $node->addChild('category');
            $this->addTextChild($cat, 'industry', $v['category']['industry'] ?? null);
            $this->addTextChild($cat, 'specialization', $v['category']['specialization'] ?? null);
        }

        $this->addTextChild($node, 'job-name', $v['job_name']);
        $this->addTextChild($node, 'employment', $v['employment'] ?? null);
        $this->addTextChild($node, 'schedule', $v['schedule'] ?? null);
        $this->addTextChild($node, 'description', $v['description']);
        $this->addTextChild($node, 'duty', $v['duty'] ?? null);

        // Условия (Term)
        if (!empty($v['term'])) {
            $term = $node->addChild('term');
            $this->addTextChild($term, 'contract', $v['term']['contract'] ?? null);
            $this->addTextChild($term, 'text', $v['term']['text'] ?? null);
        }

        // Требования (Requirement)
        if (!empty($v['requirement'])) {
            $req = $node->addChild('requirement');
            $this->addTextChild($req, 'age', $v['requirement']['age'] ?? null);
            $this->addTextChild($req, 'sex', $v['requirement']['sex'] ?? null);
            $this->addTextChild($req, 'education', $v['requirement']['education'] ?? null);
            $this->addTextChild($req, 'experience', $v['requirement']['experience'] ?? null);
            $this->addTextChild($req, 'qualification', $v['requirement']['qualification'] ?? null);
        }

        // Адреса (поддержка массива адресов)
        if (!empty($v['addresses'])) {
            $addresses = $node->addChild('addresses');
            foreach ($v['addresses'] as $addrData) {
                $address = $addresses->addChild('address');
                $this->addTextChild($address, 'location', $addrData['location'] ?? null);
                if (!empty($addrData['metro'])) {
                    foreach ((array) $addrData['metro'] as $m) {
                        $this->addTextChild($address, 'metro', $m);
                    }
                }
                $this->addTextChild($address, 'lng', $addrData['lng'] ?? null);
                $this->addTextChild($address, 'lat', $addrData['lat'] ?? null);
            }
        }

        // Компания
        $comp = $node->addChild('company');
        $this->addTextChild($comp, 'name', $v['company_name']);
        $this->addTextChild($comp, 'description', $v['company_description'] ?? null);
        $this->addTextChild($comp, 'logo', $v['company_logo'] ?? null);
        $this->addTextChild($comp, 'site', $v['company_site'] ?? null);

        foreach (['email', 'phone', 'fax'] as $contactType) {
            if (!empty($v["company_$contactType"])) {
                foreach ((array) $v["company_$contactType"] as $val) {
                    $this->addTextChild($comp, $contactType, $val);
                }
            }
        }
        $this->addTextChild($comp, 'hr-agency', isset($v['hr_agency']) ? ($v['hr_agency'] ? 'true' : 'false') : null);
        $this->addTextChild($comp, 'contact-name', $v['contact_name'] ?? null);

        $this->addTextChild($node, 'campaign', $v['campaign'] ?? null);
    }

    private function addTextChild(SimpleXMLElement $parent, string $name, $value): void
    {
        if ($value !== null && $value !== '') {
            $parent->addChild($name, htmlspecialchars((string) $value));
        }
    }
}

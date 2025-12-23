<?php

namespace App\Services\Yandex;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class YandexCaptchaSolver
{
    public function get(string $url, int $timeout = 120): ?string
    {
        $nodeScript = <<<'JS'
const fs = require('fs');
const { chromium } = require('playwright');

(async () => {
    try {
        const browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage']
        });

        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            viewport: { width: 1366, height: 768 },
            locale: 'ru-RU'
        });

        const page = await context.newPage();

        await page.addInitScript(() => {
            delete window.cdc_adoQpoasnfa76pfcZLmcfl_Array;
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
        });

        const targetUrl = process.env.TARGET_URL;
        console.log('🚀 Загрузка:', targetUrl);

        await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.waitForTimeout(5000);

        try {
            const captchaButton = await page.waitForSelector('#js-button, .CheckboxCaptcha-Button', { timeout: 3000 });
            console.log('✅ CAPTCHA найдена!');
            await captchaButton.click({ force: true });
            await page.waitForTimeout(20000);
        } catch (e) {
            console.log('ℹ️ CAPTCHA не найдена');
        }

        await page.waitForTimeout(5000);

        const content = await page.content();
        const title = await page.title();
        const finalUrl = page.url();

        const hasCaptcha = content.includes('не робот') || content.includes('not a robot');
        const is404 = title.includes('404') || title.includes('Ошибка 404');

        const result = {
            success: true,
            html: content,
            finalUrl: finalUrl,
            title: title,
            hasCaptcha: hasCaptcha,
            length: content.length,
            statusCode: is404 ? 404 : 200
        };

        fs.writeFileSync('/tmp/playwright-result.json', JSON.stringify(result));
        console.log('✅ JSON сохранен в /tmp/playwright-result.json');

        await browser.close();
        process.exit(0);
    } catch (e) {
        fs.writeFileSync('/tmp/playwright-result.json', JSON.stringify({success: false, error: e.message}));
        process.exit(1);
    }
})();
JS;

        $process = Process::fromShellCommandline('node -e ' . escapeshellarg($nodeScript));
        $process->setEnv(['TARGET_URL' => $url]);
        $process->setTimeout($timeout);
        $process->setWorkingDirectory(base_path());
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $jsonPath = '/tmp/playwright-result.json';
        if (!file_exists($jsonPath)) {
            throw new \Exception('JSON файл не создан: ' . $jsonPath);
        }

        $result = json_decode(file_get_contents($jsonPath), true);
        unlink($jsonPath);

        return $result['html'];
    }
}

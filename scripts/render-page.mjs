import { chromium } from 'playwright';

const [, , url, timeoutArg] = process.argv;
const timeoutSeconds = Number(timeoutArg || 25);

if (!url) {
  console.error('Usage: node scripts/render-page.mjs <url> [timeoutSeconds]');
  process.exit(2);
}

let browser;

try {
  browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({
    userAgent: process.env.SCRAPER_USER_AGENT || 'jobscraper/laravel',
  });

  const timeout = Math.max(5000, timeoutSeconds * 1000);

  try {
    await page.goto(url, { waitUntil: 'commit', timeout });
  } catch {
    // Keep the partial DOM. Many target pages are slow but still expose useful markup.
  }

  await page.waitForTimeout(2000);

  try {
    await page.waitForLoadState('domcontentloaded', { timeout: 5000 });
  } catch {
    // Partial DOM is acceptable.
  }

  const html = await page.content();
  const finalUrl = page.url();

  process.stdout.write(JSON.stringify({ html, finalUrl }));
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
} finally {
  if (browser) {
    await browser.close();
  }
}

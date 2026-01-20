const { chromium } = require('playwright');

const targetUrl = process.argv[2] || 'https://kuper.ru/rabota/velokurer';
const timeoutMs = Number(process.argv[3] || 60000);

function resolveChunkUrl(file) {
  if (file.startsWith('http')) return file;
  if (file.startsWith('/')) return `https://kuper.ru${file}`;
  return `https://kuper.ru/rabota/_next/static/chunks/${file.replace(/^\/+/, '')}`;
}

function extractCitiesFromChunk(chunk) {
  const pattern = /(\w+)\s*=\s*JSON\.parse\s*\(\s*(["'])(.*?)\2\s*\)/gs;
  const matches = [...chunk.matchAll(pattern)];
  if (!matches.length) return null;

  for (const match of matches) {
    const raw = match[3];
    if (!raw.includes('"abakan"')) continue;
    try {
      const jsonString = JSON.parse(`\"${raw.replace(/\"/g, '\\\\\"')}\"`);
      const decoded = JSON.parse(jsonString);
      if (decoded && typeof decoded === 'object') return decoded;
    } catch (err) {
      // ignore parsing errors for unrelated chunks
    }
  }

  return null;
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.goto(targetUrl, { waitUntil: 'networkidle', timeout: timeoutMs });
  await page.waitForTimeout(1000);

  const html = await page.content();
  const chunkRe = /\/chunks\/([A-Za-z0-9-]+\.js)/g;
  const files = new Set();
  let match;
  while ((match = chunkRe.exec(html)) !== null) {
    files.add(match[1]);
  }

  for (const file of files) {
    const url = resolveChunkUrl(file);
    const response = await context.request.get(url, {
      headers: { Referer: targetUrl },
      timeout: timeoutMs,
    });
    if (!response.ok()) {
      continue;
    }
    const body = await response.text();
    const cities = extractCitiesFromChunk(body);
    if (cities) {
      await browser.close();
      process.stdout.write(JSON.stringify(cities));
      return;
    }
  }

  await browser.close();
  throw new Error('Не удалось найти список городов в chunk-файлах.');
}

main().catch((err) => {
  process.stderr.write(err && err.message ? err.message : String(err));
  process.exit(1);
});

<?php

return [
    'defaults' => [
        'listing_link_pattern' => '/vacancy',
        'needs_js' => false,
        'js_fallback' => false,
        'throttle_seconds' => 2,
        'max_pages' => 50,
        'pagination_selector' => '',
        'concurrency' => 5,
        'nav_keywords' => [
            'ваканс',
            'работа',
            'career',
            'jobs',
            'карьера',
            'работайте с нами',
            'стать частью команды',
            'наша команда',
        ],
        'url_patterns' => [
            '/vacancy',
            '/vacancies',
            '/career',
            '/jobs',
            '/job',
            '/about/job',
            '/rabota',
            '/work',
            '/hr',
            '/joblist',
            '/careers',
            '/company/jobs',
            '/about/career',
            '/team',
        ],
        'readmore_texts' => ['читать далее', 'подробнее', 'детали'],
        'required_fields' => ['title', 'company', 'description', 'contacts'],
        'page_prompt' => implode(' ', [
            'Найди все вакансии на странице.',
            'Верни СТРОГО JSON-массив, без markdown и без пояснений.',
            'Если вакансий нет, верни строго [] и ничего больше.',
            'Каждый элемент массива должен быть JSON-объектом с ключами:',
            'title, company, location, description, contacts, salary, type, level, skills, posted_at, source_url.',
            'For contacts: return all relevant contacts as array (emails, phones, site URLs).',
            'Do not return whatsapp/viber handles. Ignore coordinates, CSS/SVG/JS numbers, ids and percentages.',
            'Правила: contacts и skills всегда массивы; salary всегда объект с ключами value и currency;',
            'если значение неизвестно, ставь пустую строку, пустой массив или null (для salary.value).',
            'Не добавляй никакой текст до/после JSON.',
        ]),
        'prompt' => implode(' ', [
            'Извлеки одну вакансию.',
            'Верни СТРОГО JSON-объект без markdown и без пояснений.',
            'Ключи объекта: title, company, location, description, contacts, salary, type, level, skills, posted_at, source_url.',
            'For contacts: return all relevant contacts as array (emails, phones, site URLs).',
            'Do not return whatsapp/viber handles. Ignore coordinates, CSS/SVG/JS numbers, ids and percentages.',
            'Правила: contacts и skills всегда массивы; salary всегда объект с ключами value и currency;',
            'если значение неизвестно, ставь пустую строку, пустой массив или null (для salary.value).',
            'Если страница НЕ является страницей вакансии, верни строго этот объект и ничего больше:',
            '{"title":"","company":"","location":"","description":"","contacts":[],"salary":{"value":null,"currency":""},"type":"","level":"","skills":[],"posted_at":"","source_url":""}.',
        ]),
    ],

    'fetch' => [
        'timeout_seconds' => (float) env('SCRAPER_FETCH_TIMEOUT_SECONDS', 15),
        'retries' => (int) env('SCRAPER_FETCH_RETRIES', 3),
        'retry_sleep_ms' => (int) env('SCRAPER_FETCH_RETRY_SLEEP_MS', 2000),
        'user_agent' => env('SCRAPER_USER_AGENT', 'jobscraper/laravel'),
    ],

    'browser' => [
        'enabled' => env('SCRAPER_PLAYWRIGHT_ENABLED', true),
        'node_binary' => env('SCRAPER_NODE_BINARY', 'node'),
        'renderer_script' => base_path(env('SCRAPER_PLAYWRIGHT_SCRIPT', 'scripts/render-page.mjs')),
        'timeout_seconds' => (float) env('SCRAPER_PLAYWRIGHT_TIMEOUT_SECONDS', 25),
    ],

    'career_finder' => [
        'llm_enabled' => env('CAREER_FINDER_LLM_ENABLED', true),
        'llm_max_links' => (int) env('CAREER_FINDER_LLM_MAX_LINKS', 180),
        'llm_tail_links' => (int) env('CAREER_FINDER_LLM_TAIL_LINKS', 40),
        'link_text_max_chars' => (int) env('CAREER_FINDER_LLM_LINK_TEXT_MAX_CHARS', 140),
        'max_html_bytes' => (int) env('CAREER_FINDER_MAX_HTML_BYTES', 8000000),
        'playwright_fallback' => env('CAREER_FINDER_PLAYWRIGHT_FALLBACK', true),
    ],

    'llm' => [
        'provider' => env('LLM_API_PROVIDER', 'openai'),
        'api_path' => env('LLM_API_PATH', '/v1/chat/completions'),
        'api_base' => env('OPENAI_API_BASE', 'https://api.openai.com/v1'),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('OPENAI_TEMPERATURE', 0),
        'timeout_seconds' => (int) env('LLM_HTTP_TIMEOUT_SECONDS', 300),
        'source_max_chars' => (int) env('LLM_SOURCE_MAX_CHARS', env('LLM_API_PROVIDER') === 'ollama' ? 12000 : 60000),
        'model_tokens' => (int) env('OPENAI_MODEL_TOKENS', 8192),
        'ollama_keep_alive' => env('OLLAMA_KEEP_ALIVE'),
        'ollama_num_ctx' => (int) env('OLLAMA_NUM_CTX', 0),
    ],

    'local_llm' => [
        'api_key' => env('LOCAL_OPENAI_API_KEY'),
        'api_base' => env('LOCAL_OPENAI_API_BASE'),
        'api_base_cpu' => env('LOCAL_OPENAI_API_BASE_CPU'),
        'api_base_gpu' => env('LOCAL_OPENAI_API_BASE_GPU'),
        'model' => env('LOCAL_OPENAI_MODEL'),
        'model_prefix' => env('LOCAL_OPENAI_MODEL_PREFIX', 'ollama/'),
        'model_tokens' => (int) env('LOCAL_OPENAI_MODEL_TOKENS', 32768),
    ],

    'fallback_llm' => [
        'api_key' => env('OPENAI_FALLBACK_API_KEY'),
        'api_base' => env('OPENAI_FALLBACK_API_BASE'),
        'model' => env('OPENAI_FALLBACK_MODEL'),
        'model_tokens' => (int) env('OPENAI_FALLBACK_MODEL_TOKENS', 8192),
    ],

    'queue' => [
        'find_career' => env('SCRAPER_FIND_CAREER_QUEUE', 'find_career'),
        'scrape_site' => env('SCRAPER_SCRAPE_SITE_QUEUE', 'scrape_site'),
        'scrape_site_timeout' => (int) env('SCRAPE_SITE_TIME_LIMIT_SECONDS', 1800),
    ],

    'feed' => [
        'disk' => env('SCRAPER_FEED_DISK', 'public'),
        'output' => env('SCRAPER_FEED_OUTPUT', 'universal/feed.xml'),
        'limit' => (int) env('SCRAPER_FEED_LIMIT', 0),
    ],

    'schedule' => [
        'enabled' => env('UNIVERSAL_SCRAPER_SCHEDULER_ENABLED', false),
        'due_sites_limit' => (int) env('UNIVERSAL_SCRAPER_DUE_SITES_LIMIT', 100),
    ],
];

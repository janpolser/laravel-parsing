<?php

return [
    'enabled' => env('FEED_REQUEST_PACING_ENABLED', env('APP_ENV', 'production') !== 'testing'),
];

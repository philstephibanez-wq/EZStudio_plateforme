<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];

    $appId = match ($host) {
        'dev.ezstudio.local' => 'ezstudio_dev',
        'lab.ezstudio.local' => 'ezs_lab',
        'assistant.ezstudio.local' => 'ezs_assistant',
        default => $context['APP_ID'] ?? 'orchestrator',
    };

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG'], $appId);
};

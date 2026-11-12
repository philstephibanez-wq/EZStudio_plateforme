<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    $rawHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $host = strtolower(trim(explode(':', $rawHost)[0]));
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    $appId = match ($host) {
        'ezstudio.local' => 'ezstudio',
        'dev.ezstudio.local' => 'ezstudio_dev',
        'lab.ezstudio.local' => 'ezs_lab',
        'assistant.ezstudio.local' => 'ezs_assistant',
        'orchestrator.ezstudio.local' => 'orchestrator',
        default => str_starts_with($uri, '/internal/analysis/')
            ? 'ezs_lab'
            : 'orchestrator',
    };

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG'], $appId);
};
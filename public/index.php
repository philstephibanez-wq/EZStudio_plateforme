<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $context['HTTP_HOST'] ?? ''));
    $hostOnly = strtok($host, ':');

    $appId = match ($hostOnly) {
        'lab.ezstudio.local'          => 'ezs_lab',
        'assistant.ezstudio.local'    => 'ezs_assistant',
        'orchestrator.ezstudio.local' => 'orchestrator',
        'dev.ezstudio.local'          => 'ezstudio_dev',
        'ezstudio.local'              => 'ezstudio',
        default                       => $context['APP_ID'] ?? 'orchestrator',
    };

    $_SERVER['APP_ID'] = $appId;
    $_ENV['APP_ID'] = $appId;

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG'], $appId);
};

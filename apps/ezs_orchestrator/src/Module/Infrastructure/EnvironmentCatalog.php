<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

final class EnvironmentCatalog
{
    public function all(): array
    {
        return [
            ['component' => 'orchestrator', 'environment' => 'dev', 'maintenance' => false],
            ['component' => 'ezstudio', 'environment' => 'dev', 'maintenance' => false],
            ['component' => 'ezstudio', 'environment' => 'prod', 'maintenance' => true],
            ['component' => 'lab', 'environment' => 'dev', 'maintenance' => false],
            ['component' => 'assistant', 'environment' => 'dev', 'maintenance' => false],
        ];
    }
}

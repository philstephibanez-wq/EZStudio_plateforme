<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Hardware;

use Shared\Hardware\Inventory;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Explicit token guard: a localhost security rule is not authentication. */
final class HardwareInventoryController
{
    #[Route('/internal/hardware/inventory', name: 'ezs_hardware_inventory_read', methods: ['GET'])]
    #[IsGranted('ROLE_HARDWARE_READ')]
    public function read(Request $request): JsonResponse
    {
        $file = Inventory::snapshotPath();
        if (!is_string($file) || $file === '' || !is_file($file) || !is_readable($file)) {
            return new JsonResponse(['error' => 'hardware_inventory_unavailable'], 503, ['Cache-Control' => 'no-store']);
        }
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !Inventory::validate($data)) {
            return new JsonResponse(['error' => 'hardware_inventory_invalid'], 503, ['Cache-Control' => 'no-store']);
        }
        return new JsonResponse($data, 200, ['Cache-Control' => 'no-store']);
    }
}

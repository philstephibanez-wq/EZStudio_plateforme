<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/src/Hardware/Inventory.php';

use Shared\Hardware\Inventory;

/** One-shot offline snapshot generator. Does not monitor, reserve or dispatch. */
$node = getenv('EZS_NODE_ID');
$file = Inventory::snapshotPath();
if (!is_string($node) || $node === '') {
    fwrite(STDERR, "EZS_NODE_ID required\n");
    exit(2);
}
try {
    $snapshot = Inventory::collect($node);
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('inventory_destination_directory_unavailable');
    }
    $temp = tempnam($dir, '.ezs-inventory-');
    if ($temp === false) throw new RuntimeException('inventory_tempfile_failed');
    try {
        if (file_put_contents($temp, $json . "\n") === false || !rename($temp, $file)) {
            throw new RuntimeException('inventory_write_failed');
        }
    } finally { if (is_file($temp)) unlink($temp); }
    echo "EZS_HARDWARE_INVENTORY_OK schema=" . Inventory::SCHEMA . " node=" . $node . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "EZS_HARDWARE_INVENTORY_FAILED " . get_class($e) . "\n");
    exit(1);
}

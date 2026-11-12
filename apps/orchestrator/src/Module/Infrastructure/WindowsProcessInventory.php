<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/**
 * Inventaire Windows en lecture seule via WMI/COM. Sans script ni shell.
 * Une observation ne prouve pas la propriété ; aucune terminaison possible ici.
 */
final class WindowsProcessInventory
{
    /** @var ?\Closure():iterable<mixed> */
    private ?\Closure $provider;

    /** @param (callable():iterable<mixed>)|null $provider Fournisseur de test uniquement. */
    public function __construct(?callable $provider = null)
    {
        $this->provider = $provider === null ? null : \Closure::fromCallable($provider);
    }

    /**
     * @return list<array{pid:int,parent:int,created:string,executable:string,command:string,classification:?array}>
     */
    public function collect(): array
    {
        $items = $this->provider === null ? $this->windowsRows() : ($this->provider)();
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $row = self::normalize($item);
            if ($row === null) continue;
            if (isset($seen[$row['pid']])) throw new \RuntimeException('EZS_INVENTORY_DUPLICATE_PID');
            $seen[$row['pid']] = true;
            $row['classification'] = EcosystemProcessEvidence::classify($row);
            $result[] = $row;
        }
        usort($result, static fn(array $a, array $b): int => $a['pid'] <=> $b['pid']);
        return $result;
    }

    /** @param array<string,mixed> $row */
    public static function normalize(array $row): ?array
    {
        $pid = filter_var($row['ProcessId'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($pid === false) return null;
        $parent = filter_var($row['ParentProcessId'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        $created = trim((string)($row['CreationDate'] ?? ''));
        $exe = trim((string)($row['ExecutablePath'] ?? ''));
        $cmd = trim((string)($row['CommandLine'] ?? ''));
        if ($created === '' || $exe === '' || $cmd === '') return null;
        // Ne pas convertir la date WMI : le format est conservé sans perte.
        return ['pid'=>$pid,'parent'=>$parent === false ? 0 : $parent,'created'=>$created,
            'executable'=>$exe,'command'=>$cmd];
    }

    /** @return iterable<array<string,mixed>> */
    private function windowsRows(): iterable
    {
        if (PHP_OS_FAMILY !== 'Windows' || !class_exists('COM')) {
            throw new \RuntimeException('EZS_INVENTORY_WMI_COM_UNAVAILABLE');
        }
        try {
            $locator = new \COM('WbemScripting.SWbemLocator');
            $service = $locator->ConnectServer('.', 'root\\cimv2');
            $rows = $service->ExecQuery('SELECT ProcessId,ParentProcessId,CreationDate,ExecutablePath,CommandLine FROM Win32_Process');
            foreach ($rows as $item) {
                $row = [];
                foreach (['ProcessId','ParentProcessId','CreationDate','ExecutablePath','CommandLine'] as $field) {
                    try { $row[$field] = $item->$field; } catch (\Throwable) { $row[$field] = null; }
                }
                yield $row;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('EZS_INVENTORY_WMI_READ_FAILED', 0, $e);
        }
    }
}

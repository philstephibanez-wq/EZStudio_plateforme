<?php
declare(strict_types=1);

namespace Shared\Hardware;

/** Portable, one-shot hardware capabilities; no scheduling or allocation. */
final class Inventory
{
    public const SCHEMA = 'ezstudio.hardware-inventory.v1';

    public static function snapshotPath(): string
    {
        $configured = getenv('EZS_HARDWARE_INVENTORY_FILE');
        if (is_string($configured) && trim($configured) !== '') return $configured;
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR
            . 'hardware' . DIRECTORY_SEPARATOR . 'inventory.json';
    }

    /** Query injection for deterministic tests; production queries do not accept user input. */
    public static function collect(string $nodeId, ?callable $queryNvidia = null, ?callable $querySystem = null, ?callable $queryBackend = null): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $nodeId)) {
            throw new \InvalidArgumentException('invalid_node_id');
        }
        $queryNvidia ??= static function (): ?string {
            $binary = getenv('EZS_NVIDIA_SMI_BIN');
            $binary = is_string($binary) && $binary !== '' ? $binary : 'nvidia-smi';
            return self::run([$binary, '--query-gpu=uuid,name,memory.total,driver_version', '--format=csv,noheader,nounits']);
        };
        $querySystem ??= static fn(): ?string => self::queryOperatingSystem();

        $rawNvidia = $queryNvidia();
        $rawSystem = $querySystem();
        $system = is_string($rawSystem) ? json_decode($rawSystem, true) : null;
        $system = is_array($system) ? $system : null;
        $cards = self::systemGpus($system);
        $nvidia = self::nvidiaGpus($rawNvidia);
        $cards = self::mergeNvidia($cards, $nvidia);
        $queryBackend ??= static fn(): ?string => self::queryBackend();
        $backendRaw = $queryBackend();
        $backend = is_string($backendRaw) ? json_decode($backendRaw, true) : null;
        $backend = is_array($backend) ? $backend : [];
        $cpuBackends = ['CPU'];
        if (in_array('PyTorch CPU', $backend['cpu'] ?? [], true)) {
            $cpuBackends[] = 'PyTorch CPU';
        }
        $verifiedCudaIds = [];
        foreach (($backend['gpus'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            $id = $entry['nvidia_uuid'] ?? null;
            if (is_string($id) && preg_match('/^GPU-[a-f0-9-]{36}$/iD', $id)
                && in_array('PyTorch CUDA', $entry['backends'] ?? [], true)) {
                $verifiedCudaIds[strtoupper($id)] = true;
            }
        }
        // OpenCL Intel proofs are PCI VEN/DEV *type* IDs, not unique serial IDs.
        // Accept only a single physical inventory match and a single runtime proof.
        // No name-only match, ordinal inference or CPU OpenCL device association.
        $pciCounts = [];
        foreach ($cards as $card) {
            $parts = self::intelPciPair($card);
            if ($parts !== null) {
                $pciCounts[$parts] = ($pciCounts[$parts] ?? 0) + 1;
            }
        }
        $proofCounts = [];
        $unifiedProof = [];
        foreach (($backend['opencl'] ?? []) as $entry) {
            if (!is_array($entry) || !in_array('OpenCL', $entry['backends'] ?? [], true)) continue;
            $vendor = $entry['pci_vendor_id'] ?? null;
            $device = $entry['pci_device_id'] ?? null;
            if (!is_int($vendor) || $vendor !== 0x8086 || !is_int($device) || $device <= 0 || $device > 0xFFFF) continue;
            $key = sprintf('8086:%04X', $device);
            $proofCounts[$key] = ($proofCounts[$key] ?? 0) + 1;
            // OpenCL memory property; valid only with a unique PCI match and execution proof.
            $unifiedProof[$key] = ($entry['host_unified_memory'] ?? null) === true;
        }
        foreach ($cards as &$card) {
            $id = $card['nvidia_uuid'] ?? $card['device_id'] ?? '';
            $card['verified_backends'] = isset($verifiedCudaIds[strtoupper((string)$id)])
                ? ['PyTorch CUDA'] : [];
            $pci = self::intelPciPair($card);
            if ($pci !== null && ($pciCounts[$pci] ?? 0) === 1 && ($proofCounts[$pci] ?? 0) === 1) {
                $card['verified_backends'][] = 'OpenCL';
                if (($unifiedProof[$pci] ?? false) === true) {
                    $card['memory_architecture'] = 'host_unified';
                }
            }
        }
        unset($card);

        $cpuName = $system['cpu_model'] ?? null;
        $ram = $system['ram_total_mib'] ?? null;
        return [
            'schema' => self::SCHEMA,
            'node_id' => $nodeId,
            'collected_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'snapshot_type' => 'static_capabilities',
            'scope' => 'local_node_only',
            'cpu' => ['architecture' => php_uname('m'),
                'model' => is_string($cpuName) && trim($cpuName) !== '' ? mbSafe($cpuName, 140) : null,
                'verified_backends' => $cpuBackends],
            'ram_total_mib' => is_numeric($ram) && (float)$ram > 0 ? (int)round((float)$ram) : null,
            'gpus' => $cards,
            'discovery' => [
                'nvidia_smi' => is_string($rawNvidia) ? 'queried' : 'unavailable',
                'other_gpu_vendors' => $system !== null ? 'queried' : 'not_instrumented',
                'gpu_backends' => count(array_filter($cards, static fn(array $gpu): bool => !empty($gpu['verified_backends']))) > 0 ? 'partially_verified' : 'not_verified',
            ],
            'usage_or_availability' => 'not_measured',
            'resource_assignment' => 'not_supported',
        ];
    }

    private static function intelPciPair(array $card): ?string
    {
        if (($card['vendor'] ?? null) !== 'Intel') return null;
        $id = $card['device_id'] ?? null;
        if (!is_string($id)) return null;
        $prefix = 'PCI\\VEN_8086&DEV_';
        if (!str_starts_with(strtoupper($id), $prefix)) return null;
        $device = substr($id, strlen($prefix), 4);
        $after = substr($id, strlen($prefix) + 4, 1);
        if (strlen($device) !== 4 || !ctype_xdigit($device) || !in_array($after, ['&', '\\', ''], true)) return null;
        return '8086:' . strtoupper($device);
    }

    private static function run(array $cmd): ?string
    {
        $proc = @proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($proc)) return null;
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        return proc_close($proc) === 0 && is_string($out) && trim($out) !== '' ? $out : null;
    }

    private static function queryBackend(): ?string
    {
        $script = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'ezs_hardware_backend_probe.py';
        if (!is_file($script)) return null;
        $configured = getenv('EZS_HARDWARE_PYTHON_BIN');
        $python = is_string($configured) && trim($configured) !== '' ? $configured : 'python';
        return self::run([$python, $script]);
    }

    private static function queryOperatingSystem(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Fixed script, no shell interpolation or dynamic arguments.
            $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$cpu = @(Get-CimInstance Win32_Processor | Select-Object -First 1 -ExpandProperty Name)
$computer = Get-CimInstance Win32_ComputerSystem
$video = @(Get-CimInstance Win32_VideoController | Select-Object Name, PNPDeviceID, DriverVersion)
@{ cpu_model = if ($cpu.Count -gt 0) { [string]$cpu[0] } else { $null }; ram_total_mib = if ($computer.TotalPhysicalMemory) { [math]::Round([double]$computer.TotalPhysicalMemory / 1MB) } else { $null }; gpus = @($video) } | ConvertTo-Json -Compress -Depth 5
POWERSHELL;
            $utf16 = iconv('UTF-8', 'UTF-16LE', $script);
            if ($utf16 === false) return null;
            $encoded = base64_encode($utf16);
            $custom = getenv('EZS_POWERSHELL_BIN');
            $binary = is_string($custom) && trim($custom) !== '' ? $custom : 'powershell.exe';
            return self::run([$binary, '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded]);
        }
        if (PHP_OS_FAMILY === 'Linux') return self::queryLinux();
        return null; // No guessed devices on unsupported operating systems.
    }

    private static function queryLinux(): ?string
    {
        $cpuName = null;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if (is_string($cpuinfo) && preg_match('/^(?:model name|Hardware)\s*:\s*(.+)$/mi', $cpuinfo, $m)) {
                $cpuName = trim($m[1]);
            }
        }
        $ram = null;
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (is_string($meminfo) && preg_match('/^MemTotal:\s*(\d+)\s+kB/mi', $meminfo, $m)) {
                $ram = (int)round(((int)$m[1])/1024);
            }
        }
        $gpus = [];
        foreach (glob('/sys/class/drm/card[0-9]*/device') ?: [] as $devicePath) {
            $real = realpath($devicePath);
            if ($real === false || !is_readable($devicePath.'/vendor')) continue;
            $vendor = trim((string)@file_get_contents($devicePath.'/vendor'));
            $device = trim((string)@file_get_contents($devicePath.'/device'));
            $name = match (strtolower($vendor)) {
                '0x8086' => 'Intel', '0x10de' => 'NVIDIA', '0x1002' => 'AMD', default => null,
            };
            if ($name === null) continue;
            $pci = basename($real);
            $gpus[$pci] = [
                'vendor' => $name,
                'physical_name' => $name.' (PCI '.$device.')',
                'device_id' => $pci,
                'vram_total_mib' => null,
                'driver_version' => null,
            ];
        }
        return json_encode(['cpu_model'=>$cpuName,'ram_total_mib'=>$ram,'gpus'=>array_values($gpus)], JSON_UNESCAPED_UNICODE);
    }

    private static function vendorOf(string $pnp, string $model): ?string
    {
        if (preg_match('/VEN[_:]?(10DE|8086|1002)/i', $pnp, $m)) {
            return match (strtoupper($m[1])) {'10DE'=>'NVIDIA','8086'=>'Intel','1002'=>'AMD'};
        }
        if (str_starts_with(strtoupper($pnp), 'PCI\\') || preg_match('/^[0-9a-f]{4}:/i',$pnp)) {
            foreach (['NVIDIA','INTEL','AMD','RADEON'] as $term) {
                if (stripos($model,$term) !== false) return $term === 'RADEON' ? 'AMD' : $term;
            }
        }
        return null;
    }

    private static function systemGpus(?array $data): array
    {
        if (!is_array($data)) return [];
        $records = $data['gpus'] ?? [];
        if (!is_array($records)) return [];
        if (isset($records['Name']) || isset($records['device_id'])) $records = [$records];
        $out = [];
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $id = $record['PNPDeviceID'] ?? $record['device_id'] ?? null;
            $model = $record['Name'] ?? $record['physical_name'] ?? null;
            if (!is_string($id) || trim($id)==='' || !is_string($model) || trim($model)==='') continue;
            $vendor = self::vendorOf($id,$model);
            if ($vendor === null) continue; // Exclude virtual/remote and unverified adapters.
            $driver = $record['DriverVersion'] ?? $record['driver_version'] ?? null;
            $vram = $record['vram_total_mib'] ?? null;
            // Windows AdapterRAM for integrated devices is commonly shared, capped or stale.
            $out[$id] = [
                'vendor'=>$vendor, 'physical_name'=>mbSafe(trim($model),140), 'device_id'=>mbSafe($id,220),
                'vram_total_mib'=>is_numeric($vram) && (float)$vram>0 ? (int)$vram : null,
                'driver_version'=>is_string($driver) && $driver!=='' ? mbSafe($driver,80) : null,
                'backend_support'=>'not_verified',
            ];
        }
        return array_values($out);
    }

    private static function nvidiaGpus(mixed $raw): array
    {
        if (!is_string($raw)) return [];
        $out = [];
        foreach (preg_split('/\R/',trim($raw)) ?: [] as $line) {
            if ($line==='') continue;
            $fields = array_map('trim', str_getcsv($line, ',', '"', '\\'));
            if (count($fields)!==4) continue;
            [$uuid,$name,$memoryMiB,$driver] = $fields;
            if (!preg_match('/^GPU-[A-Za-z0-9-]+$/D',$uuid) || $name==='' || !is_numeric($memoryMiB)) continue;
            $out[] = [
                'vendor'=>'NVIDIA', 'device_id'=>$uuid, 'physical_name'=>mbSafe($name,140),
                'vram_total_mib'=>(int)round((float)$memoryMiB),
                'driver_version'=>mbSafe($driver,80), 'backend_support'=>'not_verified',
            ];
        }
        return $out;
    }

    private static function mergeNvidia(array $cards, array $nvidia): array
    {
        foreach ($nvidia as $gpu) {
            $matches = [];
            foreach ($cards as $i=>$other) {
                if ($other['vendor']==='NVIDIA'
                    && strcasecmp(trim($other['physical_name']),trim($gpu['physical_name']))===0
                    && !isset($other['nvidia_uuid'])) $matches[]=$i;
            }
            if (count($matches)===1) {
                $i=$matches[0];
                $cards[$i]['pci_device_id']=$cards[$i]['device_id'];
                $cards[$i]['device_id']=$gpu['device_id'];
                $cards[$i]['nvidia_uuid']=$gpu['device_id'];
                $cards[$i]['vram_total_mib']=$gpu['vram_total_mib'];
                $cards[$i]['driver_version']=$gpu['driver_version'];
            } elseif (count($matches)===0) {
                $cards[]=$gpu;
            } else {
                // Ambiguous same-name cards: do not claim a UUID belongs to a particular PCI device.
                // Keep system cards, without duplicating an unproven second adapter.
            }
        }
        usort($cards,static fn(array $a,array $b): int => strcmp($a['vendor'].' '.$a['physical_name'].' '.$a['device_id'], $b['vendor'].' '.$b['physical_name'].' '.$b['device_id']));
        return $cards;
    }

    public static function validate(array $data): bool
    {
        return ($data['schema'] ?? null) === self::SCHEMA
            && is_string($data['node_id'] ?? null)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',$data['node_id'])===1
            && ($data['snapshot_type'] ?? null)==='static_capabilities'
            && ($data['resource_assignment'] ?? null)==='not_supported'
            && is_array($data['gpus'] ?? null)
            && is_string($data['collected_at_utc'] ?? null);
    }
}

function mbSafe(string $value,int $max): string
{
    return substr(preg_replace('/[\x00-\x1F\x7F]/','',$value) ?? '',0,$max);
}

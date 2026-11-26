<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

/** Conservative guard after explicit operator stop attestation. */
final class QueueRecoveryProcessGuard
{
    /** A conservative local guard, not a general-purpose process-tree attestation. */
    public function confirms(?int $pid): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') return false;
        $script = '$ErrorActionPreference="Stop"; $rows=@(Get-CimInstance Win32_Process | Select-Object Name,ProcessId,ParentProcessId); if($rows.Count -lt 2){throw "inventory_empty"}; ConvertTo-Json -InputObject $rows -Compress';
        $p = new Process(['powershell.exe','-NoProfile','-NonInteractive','-Command',$script]);
        $p->setTimeout(10);
        $p->run();
        if (!$p->isSuccessful()) return false;
        $rows = json_decode($p->getOutput(), true);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 2) return false;
        return self::inventoryClear($rows, getmypid(), $pid);
    }

    public static function inventoryClear(array $rows, int $consumerPid, ?int $workerPid): bool
    {
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['Name'] ?? null)
                || !is_int($row['ProcessId'] ?? null) || !is_int($row['ParentProcessId'] ?? null)) return false;
            $id = $row['ProcessId'];
            if (isset($seen[$id])) return false;
            $seen[$id] = true;
            if ($workerPid !== null && $workerPid > 0 && ($id === $workerPid || $row['ParentProcessId'] === $workerPid)) return false;
            if ($id === $consumerPid) continue;
            if (preg_match('/^(?:python.*|py|php(?:-cgi)?|ffmpeg|ffprobe|demucs|uvicorn|node|java|sox|rubberband)(?:\.exe)?$/iD', $row['Name'])) return false;
        }
        return isset($seen[$consumerPid]);
    }

}

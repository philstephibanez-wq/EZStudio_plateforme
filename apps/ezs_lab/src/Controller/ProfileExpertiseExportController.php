<?php

declare(strict_types=1);
namespace EZSLab\Controller;

use EZSLab\Service\Database;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileExpertiseExportController
{
    #[Route('/workbench/profile/expertise-export', name: 'profile_expertise_export', methods: ['GET'])]
    public function export(Request $request, Database $db): Response
    {
        $song = $request->query->get('song');
        if ($song !== null && filter_var($song, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) {
            return new Response('Chanson invalide.', 400);
        }
        $root = dirname(__DIR__, 2);
        $python = (string)(getenv('EZSTUDIO_PROFILE_PYTHON') ?: ($_SERVER['EZSTUDIO_PROFILE_PYTHON'] ?? '') ?: 'H:/Python/pythoncore-3.14-64/python.exe');
        $args = [$python, '-B', $root.'/scripts/export-profile-expertise.py', '--database', $db->path()];
        if ($song !== null) { $args[] = '--song'; $args[] = (string)(int)$song; }
        try {
            $process = new Process($args, $root, ['PYTHONUTF8'=>'1','PYTHONIOENCODING'=>'utf-8']);
            $process->setTimeout(120);
            $process->mustRun();
            $output = $process->getOutput();
            if ($output === '') { throw new \RuntimeException('empty_export'); }
        } catch (\Throwable $e) {
            return new Response('Export PROFILE indisponible. Vérifier le prompt actif et les données canoniques.', 409, ['Cache-Control'=>'no-store']);
        }
        $filename = 'EZS_PROFILE_EXPERTISE_'.($song === null ? 'ALL' : (string)(int)$song).'_'.gmdate('Ymd-His').'.jsonl';
        return new Response($output, 200, [
            'Content-Type'=>'application/octet-stream',
            'Content-Length'=>(string)strlen($output),
            'Content-Disposition'=>'attachment; filename="'.$filename.'"',
            'Cache-Control'=>'no-store, private',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}

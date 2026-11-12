<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\Database;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PresetExportController extends AbstractController
{
    #[Route('/catalogue/{id<\d+>}/presets.zip', name: 'preset_export_zip', methods: ['GET'])]
    public function download(int $id, Database $db): Response
    {
        $song = $db->song($id);
        if ($song === null) return new Response('catalog_song_not_found', 404);

        $projectRoot = dirname(__DIR__, 2);
        $python = (string)(getenv('EZSTUDIO_ANALYSIS_PYTHON') ?: 'H:\\Python\\pythoncore-3.14-64\\python.exe');
        $script = $projectRoot.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'export-presets.py';
        if (!is_file($python) || !is_file($script)) return new Response('preset_export_runtime_missing', 500);

        $tmpRoot = rtrim((string)(getenv('EZSTUDIO_TMP_ROOT') ?: $projectRoot.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'tmp'), '\\/');
        $exportDir = $tmpRoot.DIRECTORY_SEPARATOR.'exports'.DIRECTORY_SEPARATOR.'presets';
        if (!is_dir($exportDir)) mkdir($exportDir, 0777, true);

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim((string)$song['title'].' '.(string)$song['artist'])) ?: 'song-'.$id;
        $output = $exportDir.DIRECTORY_SEPARATOR.'EZStudio_PRESET_'.trim($safe, '-._').'_'.gmdate('Ymd_His').'.zip';
        $command = [$python, $script, '--db', $db->path(), '--song-id', (string)$id, '--output', $output];

        $pipes = [];
        $process = proc_open($command, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $projectRoot, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return new Response('preset_export_process_start_failed', 500);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]); fclose($pipes[2]);
        $rc = proc_close($process);
        if ($rc !== 0 || !is_file($output)) {
            return new Response("preset_export_export_failed\nreturncode=".$rc."\n".$stdout."\n".$stderr, 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $response = new BinaryFileResponse($output);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition('attachment', basename($output));
        $response->deleteFileAfterSend(true);
        return $response;
    }
}

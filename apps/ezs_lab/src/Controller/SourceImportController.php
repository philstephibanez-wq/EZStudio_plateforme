<?php

namespace EZSLab\Controller;

use EZSLab\Service\Database;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SourceImportController extends AbstractController
{
    #[Route('/import/source', name: 'workbench_import_source', methods: ['POST'])]
    public function import(Request $request, Database $db): Response
    {
        /** @var UploadedFile|null $audio */
        $audio = $request->files->get('audio');
        if (!$audio instanceof UploadedFile || !$audio->isValid()) {
            return new Response('Fichier audio invalide.', 400);
        }

        $original = $audio->getClientOriginalName();
        $title = trim((string)$request->request->get('title', '')) ?: pathinfo($original, PATHINFO_FILENAME);
        $artist = trim((string)$request->request->get('artist', ''));
        $sha = hash_file('sha256', $audio->getPathname());
        if (!is_string($sha) || $sha === '') {
            return new Response('Impossible de calculer SHA-256.', 500);
        }

        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = ['mp3','wav','flac','ogg','oga','m4a','mp4','aac'];
        if (!in_array($ext, $allowed, true)) {
            return new Response('Format audio non supporté.', 415);
        }

        $projectDir = (string)$this->getParameter('kernel.project_dir');
        $targetDir = $projectDir.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'imports';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            return new Response('Impossible de créer le stockage IMPORT.', 500);
        }

        $targetPath = $targetDir.DIRECTORY_SEPARATOR.$sha.'.'.$ext;
        if (!is_file($targetPath)) {
            $audio->move($targetDir, basename($targetPath));
        }

        $songId = $db->createSong($title, $artist, $original, $sha);
        $stmt = $db->pdo()->prepare('UPDATE songs SET source_path=? WHERE id=?');
        $stmt->execute([$targetPath, $songId]);

        // Contract: IMPORT stops here. PROFILE is only the next screen.
        return new RedirectResponse($this->generateUrl('workbench_profile', ['song' => $songId]));
    }
}

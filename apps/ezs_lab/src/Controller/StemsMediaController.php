<?php

namespace EZSLab\Controller;

use EZSLab\Service\Database;
use EZSLab\Service\AnalysisRunRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class StemsMediaController extends AbstractController
{
    private const AUDIO_EXTENSIONS=['wav','mp3','flac','ogg','oga','m4a','aac'];

    #[Route('/workbench/stems/artifact/{artifactId}',name:'workbench_stems_artifact_audio',methods:['GET'])]
    public function artifact(string $artifactId,AnalysisRunRegistry $runRegistry): Response
    {
        $artifact=$runRegistry->artifact($artifactId);
        if(!$artifact || (string)$artifact['item']!=='stems') throw $this->createNotFoundException();
        $path=trim((string)($artifact['path']??''));
        $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        if($path==='' || !in_array($ext,self::AUDIO_EXTENSIONS,true) || !is_file($path)) throw $this->createNotFoundException();
        $response=new BinaryFileResponse($path);
        $response->setPrivate();
        $response->headers->set('Cache-Control','private, no-store, max-age=0');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE,basename($path));
        return $response;
    }

    #[Route('/workbench/stems/master/{songId<\d+>}',name:'workbench_stems_master_audio',methods:['GET'])]
    public function master(int $songId,Database $db): Response
    {
        $stmt=$db->pdo()->prepare('SELECT audio_sha256,source_path FROM songs WHERE id=?');
        $stmt->execute([$songId]);
        $song=$stmt->fetch();
        if(!$song) throw $this->createNotFoundException();
        $path=trim((string)($song['source_path']??''));
        if($path==='' || !is_file($path)){
            $stmt=$db->pdo()->prepare('SELECT r.input_path FROM benchmark_runs r JOIN songs s ON s.id=r.song_id WHERE s.audio_sha256=? ORDER BY r.id DESC');
            $stmt->execute([(string)$song['audio_sha256']]);
            $path='';
            foreach($stmt->fetchAll() as $row){$candidate=trim((string)($row['input_path']??''));if($candidate!==''&&is_file($candidate)){$path=$candidate;break;}}
        }
        if($path===''||!is_file($path)) throw $this->createNotFoundException();
        $response=new BinaryFileResponse($path);
        $response->setPrivate();
        $response->headers->set('Cache-Control','private, no-store, max-age=0');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE,basename($path));
        return $response;
    }
}

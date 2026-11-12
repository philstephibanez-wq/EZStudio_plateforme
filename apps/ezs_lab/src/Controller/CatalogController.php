<?php

namespace EZSLab\Controller;

use EZSLab\Service\CatalogService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    #[Route('/catalogue/{id<\d+>}/phase/{phase<profile|stems|chords|lyrics>}/delete', name: 'catalog_phase_delete', methods: ['POST'])]
    public function deletePhase(
        int $id,
        string $phase,
        CatalogService $catalog,
        LoggerInterface $logger
    ): Response {
        $logger->info('catalog_phase_delete_requested', [
            'song_id' => $id,
            'phase' => $phase,
        ]);

        try {
            $result = $catalog->abandonPhase($id, $phase);
        } catch (\RuntimeException $e) {
            $logger->warning('catalog_phase_delete_refused', [
                'song_id' => $id,
                'phase' => $phase,
                'error' => $e->getMessage(),
            ]);
            return new Response(
                'Suppression refusée : '.htmlspecialchars($e->getMessage(), ENT_QUOTES),
                409
            );
        }

        $logger->info('catalog_phase_abandon_completed', [
            'song_id' => $id,
            'phase' => $phase,
            'abandoned_phases' => $result['abandoned_phases'] ?? [],
        ]);
        return new RedirectResponse($this->generateUrl('workbench_home'));
    }

    #[Route('/catalogue/{id<\d+>}/delete', name: 'catalog_delete', methods: ['POST'])]
    public function delete(
        int $id,
        CatalogService $catalog,
        LoggerInterface $logger
    ): Response {
        $logger->info('catalog_delete_requested', ['song_id' => $id]);
        try {
            $result = $catalog->deleteGroup($id);
        } catch (\RuntimeException $e) {
            $logger->warning('catalog_delete_refused', [
                'song_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return new Response(
                'Suppression refusée : '.htmlspecialchars($e->getMessage(), ENT_QUOTES),
                409
            );
        }

        $logger->info('catalog_delete_completed', [
            'song_id' => $id,
            'deleted_song_ids' => $result['song_ids'] ?? [],
        ]);
        return new RedirectResponse($this->generateUrl('workbench_home'));
    }
}

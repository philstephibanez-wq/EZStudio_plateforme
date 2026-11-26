<?php

declare(strict_types=1);
namespace EZSLab\Controller;

use EZSLab\Service\ProfileExpertisePrompt;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileExpertisePromptController extends AbstractController
{
    #[Route('/workbench/profile/expertise-prompt', name: 'profile_expertise_prompt_save', methods: ['POST'])]
    public function save(Request $request, ProfileExpertisePrompt $prompt): Response
    {
        if ($request->headers->get('X-EZS-Expertise-Prompt') !== 'save'
            || ($request->headers->has('Origin') && $request->headers->get('Origin') !== $request->getSchemeAndHttpHost())
            || ($request->headers->has('Sec-Fetch-Site') && $request->headers->get('Sec-Fetch-Site') !== 'same-origin')) {
            return new JsonResponse(['error' => 'Requête non autorisée.'], 403);
        }
        try {
            $action = $request->request->getString('action');
            if ($action === 'create') {
                $id = $prompt->create($request->request->getString('content'));
            } elseif ($action === 'activate') {
                $id = filter_var($request->request->getString('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) throw new \InvalidArgumentException();
                $prompt->activate($id);
            } else {
                throw new \InvalidArgumentException();
            }
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'Texte ou révision invalide. Le prompt doit contenir de 1 à 65 536 octets UTF-8.'], 422);
        }
        return new JsonResponse(['id' => $id], 200, ['Cache-Control' => 'no-store']);
    }
}

<?php

namespace EZSAssistant\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'ezs_assistant_home')]
    public function index(): Response
    {
        return $this->render('index.html.twig', [
            'app_id' => 'ezs_assistant',
            'app_title' => 'EZS Assistant',
            'app_description' => "Assistant intelligent, automatisation et support interactif.",
            'app_badge' => 'bg-info text-dark',
        ]);
    }
}

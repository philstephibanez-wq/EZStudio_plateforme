<?php

namespace EZStudio\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'ezstudio_dev_home')]
    public function index(): Response
    {
        return $this->render('index.html.twig', [
            'app_id' => 'ezstudio_dev',
            'app_title' => 'EZStudio Dev',
            'app_description' => "Environnement de développement, outils et composants techniques.",
            'app_badge' => 'bg-success',
        ]);
    }
}

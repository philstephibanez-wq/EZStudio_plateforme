<?php

namespace EZSLab\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'ezs_lab_home')]
    public function index(): Response
    {
        return $this->render('index.html.twig', [
            'app_id' => 'ezs_lab',
            'app_title' => 'EZS Lab',
            'app_description' => "Laboratoire d'expérimentation, prototypes et R&D.",
            'app_badge' => 'bg-warning text-dark',
        ]);
    }
}

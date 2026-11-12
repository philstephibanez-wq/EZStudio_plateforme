<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\ProfileReportPublication;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileReportPublicationController extends AbstractController
{
    #[Route('/workbench/profile/report-publication', name: 'profile_report_publication', methods: ['POST'])]
    public function update(Request $request, ProfileReportPublication $publication): JsonResponse
    {
        $site = (string) $request->headers->get('Sec-Fetch-Site', '');
        $origin = (string) $request->headers->get('Origin', '');
        $referer = (string) $request->headers->get('Referer', '');
        $base = $request->getSchemeAndHttpHost();
        if (($site !== '' && $site !== 'same-origin')
            || ($origin !== '' && $origin !== $base)
            || ($site === '' && $origin === '' && !str_starts_with($referer, $base.'/'))
            || $request->headers->get('X-EZS-Reports-Settings') !== 'save') {
            return $this->json(['ok' => false, 'error' => 'origine_refusee'], 403);
        }
        $value = $request->request->get('enabled');
        if ($value !== '1' && $value !== '0') {
            return $this->json(['ok' => false, 'error' => 'valeur_invalide'], 422);
        }
        try {
            $publication->setEnabled($value === '1');
            return $this->json(['ok' => true, 'enabled' => $publication->enabled()]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'config_non_enregistree'], 500);
        }
    }
}

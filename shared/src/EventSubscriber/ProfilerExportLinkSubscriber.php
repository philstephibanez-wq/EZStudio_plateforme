<?php
declare(strict_types=1);
namespace Shared\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ProfilerExportLinkSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [KernelEvents::RESPONSE => ['onKernelResponse', -255]]; }
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $res = $event->getResponse();
        $tok = $res->headers->get('X-Debug-Token');
        if (!$tok || !str_contains((string)$res->headers->get('Content-Type', ''), 'text/html')) return;
        $html = $res->getContent();
        if (is_string($html) && str_contains($html, 'href="/_profiler-export"')) {
            $res->setContent(str_replace('href="/_profiler-export"', 'href="/_profiler-export?token=' . rawurlencode($tok) . '"', $html));
        }
    }
}
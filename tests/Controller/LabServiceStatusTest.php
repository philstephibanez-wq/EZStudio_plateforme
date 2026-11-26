<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use EZSLab\Controller\ServiceStatusController;
use EZSLab\Twig\LabVersionExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LabServiceStatusTest extends TestCase
{
    public function testCanonicalLabVersionAgreesWithRestStatus(): void
    {
        $request = Request::create('http://localhost/api/v1/service-status', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = (new ServiceStatusController())->index($request);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ezs.service-status.v1', $data['schema']);
        self::assertSame('OK', $data['state']);
        self::assertSame((new LabVersionExtension())->getGlobals()['lab_version'], $data['version']);
        self::assertSame('OK', $data['components'][1]['health']);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testLegacyAndHybridVersionsAndAccessRestrictions(): void
    {
        foreach (['1.5a1', '1.6.1.L0a2', '5.1.0.Lrc0'] as $version) {
            $data = ServiceStatusController::payload($version, '2026-11-26T00:00:00Z');
            self::assertSame('OK', $data['state']);
            self::assertSame($version, $data['version']);
        }
        $invalid = ServiceStatusController::payload('1.6.1.L0a', '2026-11-26T00:00:00Z');
        self::assertSame('KO', $invalid['state']);
        self::assertNull($invalid['version']);
        $remote = Request::create('http://localhost/api/v1/service-status', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        self::assertSame(403, (new ServiceStatusController())->index($remote)->getStatusCode());
    }
}

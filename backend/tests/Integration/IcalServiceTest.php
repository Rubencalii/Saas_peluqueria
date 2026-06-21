<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Calendar\IcalService;

/**
 * Feed iCal de la agenda del profesional (doc 13 §2.6).
 */
final class IcalServiceTest extends DatabaseTestCase
{
    private IcalService $ical;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var IcalService $svc */
        $svc = $this->service(IcalService::class);
        $this->ical = $svc;
    }

    public function testFeedValidoDevuelveCalendario(): void
    {
        $token = (string) $this->db->fetchOne('SELECT calendar_token FROM staff WHERE id = 1');
        $ics = $this->ical->feedForToken($token);

        self::assertNotNull($ics);
        self::assertStringStartsWith('BEGIN:VCALENDAR', $ics);
        self::assertStringContainsString('END:VCALENDAR', $ics);
        self::assertStringContainsString('X-WR-CALNAME:', $ics);
        // Líneas separadas por CRLF (RFC 5545).
        self::assertStringContainsString("\r\n", $ics);
    }

    public function testTokenInexistenteDevuelveNull(): void
    {
        self::assertNull($this->ical->feedForToken('00000000000000000000000000000000'));
    }
}

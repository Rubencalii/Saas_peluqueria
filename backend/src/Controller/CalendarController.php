<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Calendar\IcalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Feed iCal público de la agenda de un profesional (doc 13 §2.6).
 *
 * URL de solo lectura con token secreto; pensada para suscribirse desde
 * Google/Apple Calendar (que la consultan periódicamente). No lleva rate
 * limiting por eso. El token actúa de credencial: si no casa, 404.
 */
final class CalendarController extends AbstractController
{
    public function __construct(private readonly IcalService $ical)
    {
    }

    #[Route('/api/v1/calendar/{token}.ics', name: 'calendar_feed', methods: ['GET'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function feed(string $token): Response
    {
        $ics = $this->ical->feedForToken($token);
        if ($ics === null) {
            return new Response('Not found', 404);
        }

        return new Response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agenda.ics"',
            'Cache-Control' => 'no-cache',
        ]);
    }
}

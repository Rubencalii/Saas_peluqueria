<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\WhatsAppWebhookController;
use App\Service\WhatsApp\BotEngine;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contrato de la firma del webhook de WhatsApp (X-Hub-Signature-256).
 *
 * La pieza crítica es el comportamiento *fail-closed*: si no hay app secret
 * configurado, solo se acepta sin firma en desarrollo; en producción
 * (APP_DEBUG=0) se rechaza, para que nadie pueda inyectar mensajes suplantando
 * a un cliente. Con secreto configurado, exige HMAC-SHA256 válido del cuerpo.
 */
final class WhatsAppWebhookSignatureTest extends KernelTestCase
{
    private function controller(string $appSecret, bool $debug): WhatsAppWebhookController
    {
        $container = static::getContainer();

        // BotEngine y Connection son `final` (no mockeables): usamos los servicios
        // reales. La verificación de firma no los toca, así que da igual.
        return new WhatsAppWebhookController(
            $container->get(BotEngine::class),
            $container->get('doctrine.dbal.default_connection'),
            'verify-token',
            $appSecret,
            $debug,
        );
    }

    private function signatureValid(WhatsAppWebhookController $c, Request $req, string $body): bool
    {
        $m = new ReflectionMethod($c, 'signatureValid');

        return (bool) $m->invoke($c, $req, $body);
    }

    public function testSinSecretoSeAceptaEnDesarrollo(): void
    {
        $c = $this->controller('', true);
        self::assertTrue($this->signatureValid($c, new Request(), '{}'));
    }

    public function testSinSecretoSeRechazaEnProduccion(): void
    {
        // Fail-closed: sin secreto y sin debug (prod) no se acepta ningún webhook.
        $c = $this->controller('', false);
        self::assertFalse($this->signatureValid($c, new Request(), '{}'));
    }

    public function testConSecretoExigeFirmaValida(): void
    {
        $secret = 'super-secreto-whatsapp';
        $body = '{"entry":[]}';
        $c = $this->controller($secret, false);

        // Sin cabecera de firma → rechazado.
        self::assertFalse($this->signatureValid($c, new Request(), $body));

        // Firma incorrecta → rechazado.
        $bad = new Request();
        $bad->headers->set('X-Hub-Signature-256', 'sha256=' . str_repeat('0', 64));
        self::assertFalse($this->signatureValid($c, $bad, $body));

        // Firma correcta → aceptado.
        $ok = new Request();
        $ok->headers->set('X-Hub-Signature-256', 'sha256=' . hash_hmac('sha256', $body, $secret));
        self::assertTrue($this->signatureValid($c, $ok, $body));
    }

    public function testReceiveDevuelve403SinFirmaEnProduccion(): void
    {
        $c = $this->controller('', false);
        $res = $c->receive(new Request(server: [], content: '{}'));

        self::assertSame(403, $res->getStatusCode());
        self::assertStringContainsString('INVALID_SIGNATURE', (string) $res->getContent());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Payment\PaymentException;
use App\Service\Payment\PaymentService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Depósito/pago (doc 13 §2.5). En tests no hay clave Stripe, así que validamos
 * la degradación: el servicio queda desactivado y rechaza con 503.
 */
final class PaymentServiceTest extends KernelTestCase
{
    private PaymentService $payments;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var PaymentService $svc */
        $svc = static::getContainer()->get(PaymentService::class);
        $this->payments = $svc;
    }

    public function testSinClaveStripeEstaDesactivado(): void
    {
        self::assertFalse($this->payments->isEnabled());
    }

    public function testCrearDepositoDesactivadoLanza503(): void
    {
        try {
            $this->payments->createDepositIntent(1, 'cualquier-codigo');
            self::fail('Debía lanzar PaymentException con pagos desactivados.');
        } catch (PaymentException $e) {
            self::assertSame('PAYMENTS_DISABLED', $e->errorCode);
            self::assertSame(503, $e->statusCode);
        }
    }

    public function testWebhookDesactivadoLanza503(): void
    {
        $this->expectException(PaymentException::class);
        $this->payments->handleWebhook('{}', 'sig');
    }
}

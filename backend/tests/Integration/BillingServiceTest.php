<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Billing\BillingException;
use App\Service\Billing\BillingService;

/**
 * Facturación del SaaS (multi-tenant Fase 5, doc 15). En test no hay claves de
 * Stripe: checkout/portal/webhook quedan desactivados (503). La sincronización
 * de estado por evento (processEvent) sí se prueba: impago → suspendida, pago →
 * activa, y alta de suscripción → plan/estado actualizados.
 */
final class BillingServiceTest extends DatabaseTestCase
{
    private BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = $this->service(BillingService::class);
    }

    private function nuevaCuenta(): int
    {
        $accountId = (int) $this->db->fetchOne(
            "INSERT INTO account (name, slug, status) VALUES ('Billing Test', 'billing-test', 'active') RETURNING id"
        );
        $this->db->executeStatement(
            "INSERT INTO subscription (account_id, plan_code, status, stripe_customer_id)
             VALUES (?, 'free', 'trialing', 'cus_test')",
            [$accountId]
        );

        return $accountId;
    }

    public function testSinClavesElBillingEstaDesactivado(): void
    {
        $accountId = $this->nuevaCuenta();

        foreach (['startCheckout' => fn () => $this->billing->startCheckout($accountId, 'pro'),
                  'portal' => fn () => $this->billing->portal($accountId)] as $op) {
            try {
                $op();
                self::fail('Debía lanzar BILLING_DISABLED.');
            } catch (BillingException $e) {
                self::assertSame('BILLING_DISABLED', $e->errorCode);
                self::assertSame(503, $e->statusCode);
            }
        }
    }

    public function testImpagoSuspendeYPagoReactiva(): void
    {
        $accountId = $this->nuevaCuenta();

        self::assertTrue($this->billing->processEvent('invoice.payment_failed', ['customer' => 'cus_test']));
        self::assertSame('suspended', $this->db->fetchOne('SELECT status FROM account WHERE id = ?', [$accountId]));
        self::assertSame('past_due', $this->db->fetchOne('SELECT status FROM subscription WHERE account_id = ?', [$accountId]));

        self::assertTrue($this->billing->processEvent('invoice.paid', ['customer' => 'cus_test']));
        self::assertSame('active', $this->db->fetchOne('SELECT status FROM account WHERE id = ?', [$accountId]));
        self::assertSame('active', $this->db->fetchOne('SELECT status FROM subscription WHERE account_id = ?', [$accountId]));
    }

    public function testAltaDeSuscripcionActualizaPlanYEstado(): void
    {
        $accountId = $this->nuevaCuenta();
        $this->db->executeStatement("UPDATE plan SET stripe_price_id = 'price_pro' WHERE code = 'pro'");

        $ok = $this->billing->processEvent('customer.subscription.updated', [
            'metadata' => ['account_id' => (string) $accountId],
            'status' => 'active',
            'id' => 'sub_123',
            'customer' => 'cus_test',
            'items' => ['data' => [['price' => ['id' => 'price_pro']]]],
        ]);

        self::assertTrue($ok);
        $sub = $this->db->fetchAssociative('SELECT plan_code, status, stripe_subscription_id FROM subscription WHERE account_id = ?', [$accountId]);
        self::assertSame('pro', $sub['plan_code']);
        self::assertSame('active', $sub['status']);
        self::assertSame('sub_123', $sub['stripe_subscription_id']);
        self::assertSame('active', $this->db->fetchOne('SELECT status FROM account WHERE id = ?', [$accountId]));
    }
}

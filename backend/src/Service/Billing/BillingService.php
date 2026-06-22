<?php

declare(strict_types=1);

namespace App\Service\Billing;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Facturación del SaaS: la suscripción que el salón nos paga a nosotros
 * (multi-tenant Fase 5, doc 15). Es DISTINTO del depósito de cita al cliente
 * final (PaymentService): aquí el tenant paga por usar el software.
 *
 * Usa Stripe Billing (Checkout para alta/cambio de plan + Customer Portal para
 * gestionarla) y un webhook propio que sincroniza `subscription.status` y
 * `account.status`. Degrada como el resto: sin STRIPE_SECRET_KEY queda
 * desactivado (503); el webhook exige su propio secreto.
 */
final class BillingService
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly string $secretKey,
        private readonly string $billingWebhookSecret,
        private readonly string $appUrl,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * Crea una sesión de Stripe Checkout para suscribir la cuenta a un plan.
     * Devuelve la URL a la que redirigir al administrador.
     *
     * @return array{url: string}
     *
     * @throws BillingException
     */
    public function startCheckout(int $accountId, string $planCode): array
    {
        if (!$this->isEnabled()) {
            throw new BillingException('BILLING_DISABLED', 'La facturación no está disponible.', 503);
        }

        $plan = $this->db->fetchAssociative('SELECT code, name, stripe_price_id FROM plan WHERE code = ?', [$planCode]);
        if ($plan === false) {
            throw new BillingException('NOT_FOUND', 'Plan desconocido.', 404);
        }
        if (($plan['stripe_price_id'] ?? null) === null || $plan['stripe_price_id'] === '') {
            throw new BillingException('PLAN_NOT_BILLABLE', 'Ese plan no tiene precio configurado en Stripe.', 409);
        }

        $account = $this->db->fetchAssociative('SELECT name FROM account WHERE id = ?', [$accountId]);
        if ($account === false) {
            throw new BillingException('NOT_FOUND', 'Cuenta no encontrada.', 404);
        }
        $customerId = $this->db->fetchOne('SELECT stripe_customer_id FROM subscription WHERE account_id = ?', [$accountId]);

        try {
            $params = [
                'mode' => 'subscription',
                'line_items' => [['price' => (string) $plan['stripe_price_id'], 'quantity' => 1]],
                'success_url' => rtrim($this->appUrl, '/') . '/billing/ok?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => rtrim($this->appUrl, '/') . '/billing/cancel',
                'client_reference_id' => (string) $accountId,
                'subscription_data' => ['metadata' => ['account_id' => (string) $accountId]],
                'metadata' => ['account_id' => (string) $accountId],
            ];
            if (is_string($customerId) && $customerId !== '') {
                $params['customer'] = $customerId;
            }
            $session = $this->stripe()->checkout->sessions->create($params);
        } catch (\Throwable $e) {
            $this->logger->error('[Stripe:Billing] checkout error: {msg}', ['msg' => $e->getMessage()]);

            throw new BillingException('STRIPE_ERROR', 'No se pudo iniciar la suscripción.', 502);
        }

        return ['url' => (string) $session->url];
    }

    /**
     * Crea una sesión del Customer Portal de Stripe para que el salón gestione su
     * suscripción (cambiar plan, método de pago, cancelar).
     *
     * @return array{url: string}
     *
     * @throws BillingException
     */
    public function portal(int $accountId): array
    {
        if (!$this->isEnabled()) {
            throw new BillingException('BILLING_DISABLED', 'La facturación no está disponible.', 503);
        }
        $customerId = $this->db->fetchOne('SELECT stripe_customer_id FROM subscription WHERE account_id = ?', [$accountId]);
        if (!is_string($customerId) || $customerId === '') {
            throw new BillingException('NO_SUBSCRIPTION', 'Aún no hay una suscripción activa que gestionar.', 409);
        }

        try {
            $session = $this->stripe()->billingPortal->sessions->create([
                'customer' => $customerId,
                'return_url' => rtrim($this->appUrl, '/') . '/billing',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[Stripe:Billing] portal error: {msg}', ['msg' => $e->getMessage()]);

            throw new BillingException('STRIPE_ERROR', 'No se pudo abrir el portal de facturación.', 502);
        }

        return ['url' => (string) $session->url];
    }

    /**
     * Procesa un webhook de Stripe Billing (firma verificada con su secreto).
     *
     * @throws BillingException
     */
    public function handleWebhook(string $payload, string $signature): bool
    {
        if (!$this->isEnabled() || $this->billingWebhookSecret === '') {
            throw new BillingException('BILLING_DISABLED', 'Webhook no disponible.', 503);
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $this->billingWebhookSecret);
        } catch (\Throwable $e) {
            throw new BillingException('INVALID_SIGNATURE', 'Firma de webhook inválida.', 400);
        }

        /** @var array<string, mixed> $data */
        $data = $event->data->object->toArray();

        return $this->processEvent((string) $event->type, $data);
    }

    /**
     * Aplica el efecto de un evento de Stripe Billing ya decodificado. Público para
     * poder probarlo sin firmar (el webhook lo invoca tras verificar la firma).
     *
     * @param array<string, mixed> $object  el `data.object` del evento
     */
    public function processEvent(string $type, array $object): bool
    {
        $accountId = $this->resolveAccount($object);
        if ($accountId === null) {
            return false;
        }

        return match ($type) {
            'customer.subscription.created', 'customer.subscription.updated' => $this->syncSubscription($accountId, $object),
            'customer.subscription.deleted' => $this->setStatus($accountId, 'canceled', 'cancelled'),
            'invoice.payment_failed' => $this->setStatus($accountId, 'past_due', 'suspended'),
            'invoice.paid', 'invoice.payment_succeeded' => $this->setStatus($accountId, 'active', 'active'),
            default => false,
        };
    }

    /**
     * Cuenta a la que afecta el evento: por metadata.account_id, o por el
     * cliente/suscripción de Stripe ya guardados.
     *
     * @param array<string, mixed> $object
     */
    private function resolveAccount(array $object): ?int
    {
        $metaId = $object['metadata']['account_id'] ?? null;
        if (is_numeric($metaId)) {
            return (int) $metaId;
        }
        $customer = is_string($object['customer'] ?? null) ? $object['customer'] : null;
        if ($customer !== null) {
            $id = $this->db->fetchOne('SELECT account_id FROM subscription WHERE stripe_customer_id = ?', [$customer]);
            if ($id !== false) {
                return (int) $id;
            }
        }
        $subId = is_string($object['subscription'] ?? null) ? $object['subscription'] : (is_string($object['id'] ?? null) ? $object['id'] : null);
        if ($subId !== null) {
            $id = $this->db->fetchOne('SELECT account_id FROM subscription WHERE stripe_subscription_id = ?', [$subId]);
            if ($id !== false) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Sincroniza la suscripción a partir del objeto subscription de Stripe:
     * plan (por el precio), estado, ids y fin de periodo. Reactiva la cuenta.
     *
     * @param array<string, mixed> $sub
     */
    private function syncSubscription(int $accountId, array $sub): bool
    {
        $priceId = $sub['items']['data'][0]['price']['id'] ?? null;
        $planCode = is_string($priceId)
            ? $this->db->fetchOne('SELECT code FROM plan WHERE stripe_price_id = ?', [$priceId])
            : false;
        $status = is_string($sub['status'] ?? null) ? $sub['status'] : 'active';
        $accountStatus = in_array($status, ['active', 'trialing'], true) ? 'active' : 'suspended';
        $periodEnd = isset($sub['current_period_end']) && is_numeric($sub['current_period_end'])
            ? (new \DateTimeImmutable('@' . (int) $sub['current_period_end']))->format('c')
            : null;

        $this->db->executeStatement(
            'UPDATE subscription
                SET plan_code = COALESCE(?, plan_code),
                    status = ?,
                    stripe_customer_id = COALESCE(?, stripe_customer_id),
                    stripe_subscription_id = COALESCE(?, stripe_subscription_id),
                    current_period_end = ?
              WHERE account_id = ?',
            [
                $planCode === false ? null : $planCode,
                $status,
                is_string($sub['customer'] ?? null) ? $sub['customer'] : null,
                is_string($sub['id'] ?? null) ? $sub['id'] : null,
                $periodEnd,
                $accountId,
            ]
        );
        $this->db->executeStatement('UPDATE account SET status = ? WHERE id = ?', [$accountStatus, $accountId]);

        return true;
    }

    private function setStatus(int $accountId, string $subscriptionStatus, string $accountStatus): bool
    {
        $this->db->executeStatement('UPDATE subscription SET status = ? WHERE account_id = ?', [$subscriptionStatus, $accountId]);
        $this->db->executeStatement('UPDATE account SET status = ? WHERE id = ?', [$accountStatus, $accountId]);

        return true;
    }

    private function stripe(): StripeClient
    {
        return $this->client ??= new StripeClient($this->secretKey);
    }
}

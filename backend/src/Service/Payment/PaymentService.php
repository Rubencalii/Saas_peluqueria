<?php

declare(strict_types=1);

namespace App\Service\Payment;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Depósito / pago online con Stripe (doc 13 §2.5).
 *
 * Cobra un depósito ligado a una cita mediante un PaymentIntent: el cliente
 * paga desde la web y un webhook de Stripe confirma el pago. Está desacoplado
 * de la reserva (no cambia el estado de la cita) y degrada con elegancia: sin
 * STRIPE_SECRET_KEY los pagos quedan desactivados (isEnabled() == false).
 */
final class PaymentService
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly string $secretKey,
        private readonly string $webhookSecret,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * Crea (o reutiliza) el PaymentIntent del depósito de una cita y devuelve el
     * client_secret para que la web complete el pago.
     *
     * @return array{client_secret: string, amount: float, currency: string, status: string}
     *
     * @throws PaymentException
     */
    public function createDepositIntent(int $appointmentId, string $code): array
    {
        if (!$this->isEnabled()) {
            throw new PaymentException('PAYMENTS_DISABLED', 'Los pagos no están disponibles.', 503);
        }
        $code = trim($code);
        if ($code === '') {
            throw new PaymentException('VALIDATION', 'Falta el código de la cita.');
        }

        $appt = $this->db->fetchAssociative(
            'SELECT a.id, a.status, a.service_id, s.name AS service_name, s.deposit_amount
               FROM appointment a
               JOIN service s ON s.id = a.service_id
              WHERE a.id = ? AND a.public_code = ?',
            [$appointmentId, $code]
        );
        if ($appt === false) {
            throw new PaymentException('NOT_FOUND', 'Cita no encontrada.', 404);
        }
        if ($appt['deposit_amount'] === null) {
            throw new PaymentException('NO_DEPOSIT', 'Este servicio no requiere depósito.', 409);
        }
        if (in_array($appt['status'], ['cancelada'], true)) {
            throw new PaymentException('INVALID_STATE', 'La cita está cancelada.', 409);
        }

        $amount = (float) $appt['deposit_amount'];
        $existing = $this->db->fetchAssociative(
            'SELECT id, status, stripe_payment_intent_id FROM payment WHERE appointment_id = ? ORDER BY id DESC LIMIT 1',
            [$appointmentId]
        );
        if ($existing !== false && $existing['status'] === 'pagado') {
            throw new PaymentException('ALREADY_PAID', 'El depósito ya está pagado.', 409);
        }

        try {
            $intent = $this->stripe()->paymentIntents->create([
                'amount' => (int) round($amount * 100), // céntimos
                'currency' => 'eur',
                'metadata' => ['appointment_id' => (string) $appointmentId],
                'description' => sprintf('Depósito cita #%d · %s', $appointmentId, (string) $appt['service_name']),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[Stripe] error creando PaymentIntent: {msg}', ['msg' => $e->getMessage()]);

            throw new PaymentException('STRIPE_ERROR', 'No se pudo iniciar el pago.', 502);
        }

        // Una fila de pago por intento (reutiliza la pendiente si existía).
        if ($existing !== false && $existing['status'] === 'pendiente') {
            $this->db->executeStatement(
                'UPDATE payment SET amount = ?, stripe_payment_intent_id = ? WHERE id = ?',
                [$amount, $intent->id, (int) $existing['id']]
            );
        } else {
            $this->db->executeStatement(
                'INSERT INTO payment (appointment_id, amount, currency, status, stripe_payment_intent_id)
                 VALUES (?, ?, \'eur\', \'pendiente\', ?)',
                [$appointmentId, $amount, $intent->id]
            );
        }

        return [
            'client_secret' => (string) $intent->client_secret,
            'amount' => $amount,
            'currency' => 'eur',
            'status' => 'pendiente',
        ];
    }

    /**
     * Procesa un webhook de Stripe (firma verificada). Marca el pago como
     * pagado/fallido según el evento. Devuelve true si lo gestionó.
     *
     * @throws PaymentException
     */
    public function handleWebhook(string $payload, string $signature): bool
    {
        if (!$this->isEnabled() || $this->webhookSecret === '') {
            throw new PaymentException('PAYMENTS_DISABLED', 'Webhook no disponible.', 503);
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } catch (\Throwable $e) {
            throw new PaymentException('INVALID_SIGNATURE', 'Firma de webhook inválida.', 400);
        }

        $intent = $event->data->object ?? null;
        $intentId = is_object($intent) ? ($intent->id ?? null) : null;
        if (!is_string($intentId)) {
            return false;
        }

        return match ($event->type) {
            'payment_intent.succeeded' => $this->mark($intentId, 'pagado', true),
            'payment_intent.payment_failed' => $this->mark($intentId, 'fallido', false),
            default => false,
        };
    }

    private function mark(string $intentId, string $status, bool $paid): bool
    {
        $affected = $this->db->executeStatement(
            'UPDATE payment SET status = ?, paid_at = CASE WHEN ? THEN now() ELSE paid_at END
              WHERE stripe_payment_intent_id = ?',
            [$status, $paid, $intentId],
            [1 => \Doctrine\DBAL\ParameterType::BOOLEAN]
        );

        return $affected > 0;
    }

    private function stripe(): StripeClient
    {
        return $this->client ??= new StripeClient($this->secretKey);
    }
}

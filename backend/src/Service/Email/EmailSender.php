<?php

declare(strict_types=1);

namespace App\Service\Email;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Envío de correo (Symfony Mailer). Segundo canal de notificaciones además de
 * WhatsApp y vía del enlace de reset de contraseña.
 *
 * Degrada como el resto de integraciones: con `MAILER_DSN=null://...` (sin
 * transporte real) el correo se registra en el log en lugar de enviarse, de
 * modo que el backend funciona en local sin servidor de email.
 */
final class EmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $dsn,
        private readonly string $from,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->dsn !== '' && !str_starts_with($this->dsn, 'null:');
    }

    /**
     * Envía un correo de texto. Devuelve true si se envió (o se registró en
     * modo local).
     */
    public function send(string $to, string $subject, string $body): bool
    {
        if ($to === '') {
            return false;
        }

        if (!$this->isEnabled()) {
            $this->logger->info('[Email:OUT] {to} · {subject}', ['to' => $to, 'subject' => $subject]);

            return true; // modo local: se considera enviado
        }

        try {
            $this->mailer->send(
                (new Email())->from($this->from)->to($to)->subject($subject)->text($body)
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[Email:OUT] fallo al enviar a {to}: {msg}', ['to' => $to, 'msg' => $e->getMessage()]);

            return false;
        }
    }
}

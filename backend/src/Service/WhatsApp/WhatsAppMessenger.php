<?php

declare(strict_types=1);

namespace App\Service\WhatsApp;

use Psr\Log\LoggerInterface;

/**
 * Envío de mensajes salientes por la WhatsApp Cloud API de Meta (doc 06 §3).
 *
 * Si no hay credenciales configuradas (entorno local/desarrollo), en lugar de
 * llamar a Meta registra el mensaje en el log: así se puede probar el bot de
 * extremo a extremo sin una cuenta de WhatsApp Business.
 *
 * Usa cURL directamente para no añadir dependencias HTTP al proyecto.
 */
final class WhatsAppMessenger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $token,
        private readonly string $phoneNumberId,
        private readonly string $graphVersion = 'v21.0',
    ) {
    }

    public function sendText(string $to, string $body): void
    {
        $this->send($to, [
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    /**
     * Mensaje con botones de respuesta rápida (máx. 3 en la Cloud API).
     *
     * @param list<array{id: string, title: string}> $buttons
     */
    public function sendButtons(string $to, string $body, array $buttons): void
    {
        $this->send($to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => [
                    'buttons' => array_map(
                        static fn (array $b): array => [
                            'type' => 'reply',
                            'reply' => ['id' => $b['id'], 'title' => self::clip($b['title'], 20)],
                        ],
                        array_slice($buttons, 0, 3)
                    ),
                ],
            ],
        ]);
    }

    /**
     * Mensaje con lista desplegable (hasta 10 filas).
     *
     * @param list<array{id: string, title: string, description?: string}> $rows
     */
    public function sendList(string $to, string $body, string $buttonText, array $rows): void
    {
        $this->send($to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $body],
                'action' => [
                    'button' => self::clip($buttonText, 20),
                    'sections' => [[
                        'rows' => array_map(
                            static function (array $r): array {
                                $row = ['id' => $r['id'], 'title' => self::clip($r['title'], 24)];
                                if (isset($r['description']) && $r['description'] !== '') {
                                    $row['description'] = self::clip($r['description'], 72);
                                }

                                return $row;
                            },
                            array_slice($rows, 0, 10)
                        ),
                    ]],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function send(string $to, array $message): void
    {
        $payload = array_merge(
            ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to],
            $message
        );

        if ($this->token === '' || $this->phoneNumberId === '') {
            // Sin credenciales: modo local. Dejamos constancia del envío.
            $this->logger->info('[WhatsApp:OUT] {to} {payload}', [
                'to' => $to,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return;
        }

        $url = sprintf('https://graph.facebook.com/%s/%s/messages', $this->graphVersion, $this->phoneNumberId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 300) {
            $this->logger->error('[WhatsApp:OUT] fallo al enviar ({status}): {error} {response}', [
                'status' => $status,
                'error' => $error,
                'response' => is_string($response) ? $response : '',
            ]);
        }
    }

    private static function clip(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1) . '…';
    }
}

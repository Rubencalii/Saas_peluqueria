<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\WhatsApp\BotEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Webhook de WhatsApp Cloud API (docs/06-especificacion-api.md §3).
 *
 * - GET  → handshake de verificación de Meta (hub.challenge + verify_token).
 * - POST → recepción de mensajes entrantes; los pasa al bot (BotEngine).
 *
 * Siempre responde 200 al POST para que Meta no reintente la entrega; la
 * deduplicación por id de mensaje evita procesar reintentos previos.
 */
final class WhatsAppWebhookController extends AbstractController
{
    public function __construct(
        private readonly BotEngine $bot,
        private readonly Connection $db,
        private readonly string $verifyToken,
        private readonly string $appSecret,
    ) {
    }

    #[Route('/api/v1/webhooks/whatsapp', name: 'whatsapp_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query->get('hub_mode', $request->query->get('hub.mode', ''));
        $token = (string) $request->query->get('hub_verify_token', $request->query->get('hub.verify_token', ''));
        $challenge = (string) $request->query->get('hub_challenge', $request->query->get('hub.challenge', ''));

        if ($mode === 'subscribe' && $token !== '' && hash_equals($this->verifyToken, $token)) {
            return new Response($challenge, 200, ['Content-Type' => 'text/plain']);
        }

        return new Response('Forbidden', 403);
    }

    #[Route('/api/v1/webhooks/whatsapp', name: 'whatsapp_receive', methods: ['POST'])]
    public function receive(Request $request): JsonResponse
    {
        $body = $request->getContent();

        // Autenticidad: Meta firma el cuerpo con el app secret (X-Hub-Signature-256).
        // Sin firma válida, el webhook quedaría abierto a inyección de mensajes falsos.
        if (!$this->signatureValid($request, $body)) {
            return new JsonResponse(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Firma inválida.']], 403);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return $this->json(['ok' => true]); // nada que procesar
        }

        foreach ($this->extractMessages($payload) as $msg) {
            if (!$this->markProcessed($msg['id'])) {
                continue; // reintento de Meta: ya procesado
            }
            $this->bot->handle($msg['from'], $msg['text'], $msg['interactive']);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Extrae los mensajes entrantes del payload de Meta a una forma simple.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array{id: string, from: string, text: string|null, interactive: string|null}>
     */
    private function extractMessages(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $m) {
                    if (!isset($m['id'], $m['from'])) {
                        continue;
                    }
                    $out[] = [
                        'id' => (string) $m['id'],
                        'from' => (string) $m['from'],
                        'text' => isset($m['text']['body']) ? (string) $m['text']['body'] : null,
                        'interactive' => $this->interactiveId($m),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $m
     */
    private function interactiveId(array $m): ?string
    {
        if (($m['type'] ?? null) !== 'interactive') {
            // Botones de plantilla antiguos llegan como type=button.
            return isset($m['button']['payload']) ? (string) $m['button']['payload'] : null;
        }

        $interactive = $m['interactive'] ?? [];

        return match ($interactive['type'] ?? null) {
            'button_reply' => (string) ($interactive['button_reply']['id'] ?? ''),
            'list_reply' => (string) ($interactive['list_reply']['id'] ?? ''),
            default => null,
        };
    }

    /**
     * Verifica la firma HMAC-SHA256 del cuerpo (cabecera X-Hub-Signature-256).
     * Si no hay app secret configurado (entorno local), no se exige firma.
     */
    private function signatureValid(Request $request, string $body): bool
    {
        if ($this->appSecret === '') {
            return true; // modo local: sin secreto no se valida
        }

        $header = (string) $request->headers->get('X-Hub-Signature-256', '');
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $body, $this->appSecret);

        return hash_equals($expected, $header);
    }

    private function markProcessed(string $messageId): bool
    {
        $affected = $this->db->executeStatement(
            'INSERT INTO wa_processed_message (message_id) VALUES (?) ON CONFLICT DO NOTHING',
            [$messageId]
        );

        return $affected > 0;
    }
}

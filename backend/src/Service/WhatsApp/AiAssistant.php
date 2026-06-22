<?php

declare(strict_types=1);

namespace App\Service\WhatsApp;

use Anthropic\Client;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Capa de comprensión de lenguaje natural del bot (decisión abierta doc 00:
 * "bot por botones vs. IA conversacional").
 *
 * Usa Claude (SDK oficial de Anthropic) con SALIDA ESTRUCTURADA para convertir
 * un mensaje libre del cliente ("quiero cortarme el pelo mañana con Laura") en
 * una intención + datos que el BotEngine ya sabe manejar. No reemplaza la
 * máquina de estados por botones: la complementa en el arranque, dejando que la
 * lógica de reservas/disponibilidad ya probada siga siendo la fuente de verdad.
 *
 * Degrada con elegancia: sin ANTHROPIC_API_KEY (entorno local) interpret()
 * devuelve null y el bot cae en el menú por botones de siempre.
 */
final class AiAssistant
{
    /** Intenciones que el bot sabe enrutar. */
    private const INTENTS = ['reservar', 'ver_cita', 'cambiar', 'cancelar', 'hablar_humano', 'saludo', 'otro'];

    private ?Client $client = null;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Interpreta un mensaje libre y devuelve la intención y los datos detectados,
     * resueltos contra el catálogo real de la sede (ids de servicio/profesional).
     *
     * @return array{intent: string, service_id: int|null, staff_id: int|null, date: string|null, reply: string}|null
     *         null si la IA está desactivada o falla (el bot usará el menú por botones).
     */
    public function interpret(string $text, int $locationId): ?array
    {
        $text = trim($text);
        if (!$this->isEnabled() || $text === '') {
            return null;
        }

        $services = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name FROM service s
               JOIN service_location sl ON sl.service_id = s.id AND sl.location_id = ?
              WHERE s.active ORDER BY s.name',
            [$locationId]
        );
        $staff = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name FROM staff s
               JOIN staff_location sl ON sl.staff_id = s.id AND sl.location_id = ?
              WHERE s.active ORDER BY s.name',
            [$locationId]
        );
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($this->locationTz($locationId))))->format('Y-m-d');

        try {
            $result = $this->ask($text, $services, $staff, $today);
        } catch (\Throwable $e) {
            $this->logger->error('[AI] fallo al interpretar: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }

        // Validar que los ids resueltos existen de verdad en la sede (la IA podría inventarlos).
        $validServiceIds = array_map(static fn (array $r): int => (int) $r['id'], $services);
        $validStaffIds = array_map(static fn (array $r): int => (int) $r['id'], $staff);
        $serviceId = $result['service_id'] !== null && in_array($result['service_id'], $validServiceIds, true)
            ? $result['service_id'] : null;
        $staffId = $result['staff_id'] !== null && in_array($result['staff_id'], $validStaffIds, true)
            ? $result['staff_id'] : null;

        return [
            'intent' => in_array($result['intent'], self::INTENTS, true) ? $result['intent'] : 'otro',
            'service_id' => $serviceId,
            'staff_id' => $staffId,
            'date' => $result['date'],
            'reply' => $result['reply'],
        ];
    }

    /**
     * @param list<array{id: mixed, name: mixed}> $services
     * @param list<array{id: mixed, name: mixed}> $staff
     *
     * @return array{intent: string, service_id: int|null, staff_id: int|null, date: string|null, reply: string}
     */
    private function ask(string $text, array $services, array $staff, string $today): array
    {
        $catalog = "Servicios disponibles (id: nombre):\n";
        foreach ($services as $s) {
            $catalog .= "- {$s['id']}: {$s['name']}\n";
        }
        $catalog .= "Profesionales (id: nombre):\n";
        foreach ($staff as $s) {
            $catalog .= "- {$s['id']}: {$s['name']}\n";
        }

        $system = <<<SYS
            Eres el asistente de una peluquería que interpreta mensajes de WhatsApp en español.
            A partir del mensaje del cliente, deduce su intención y los datos de la cita.
            Hoy es {$today} (zona horaria de la sede). Resuelve fechas relativas ("mañana",
            "el viernes") a formato AAAA-MM-DD. Si no se menciona fecha, deja date en null.
            Mapea el servicio y el profesional SOLO a un id del catálogo; si no hay match claro,
            deja el id en null. No inventes ids.

            {$catalog}
            Intenciones posibles: reservar, ver_cita, cambiar, cancelar, hablar_humano, saludo, otro.
            "reply" es una frase breve y cálida en español confirmando lo que entendiste (sin prometer
            la cita todavía).
            SYS;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => self::INTENTS],
                'service_id' => ['type' => ['integer', 'null']],
                'staff_id' => ['type' => ['integer', 'null']],
                'date' => ['type' => ['string', 'null']],
                'reply' => ['type' => 'string'],
            ],
            'required' => ['intent', 'service_id', 'staff_id', 'date', 'reply'],
        ];

        $message = $this->client()->messages->create(
            maxTokens: 512,
            model: $this->model,
            system: $system,
            messages: [['role' => 'user', 'content' => $text]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        );

        $json = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $json .= $block->text;
            }
        }
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true) ?: [];

        return [
            'intent' => is_string($data['intent'] ?? null) ? $data['intent'] : 'otro',
            'service_id' => isset($data['service_id']) ? (int) $data['service_id'] : null,
            'staff_id' => isset($data['staff_id']) ? (int) $data['staff_id'] : null,
            'date' => is_string($data['date'] ?? null) && $data['date'] !== '' ? $data['date'] : null,
            'reply' => is_string($data['reply'] ?? null) ? $data['reply'] : '',
        ];
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: $this->apiKey);
    }

    private function locationTz(int $locationId): string
    {
        $tz = $this->db->fetchOne('SELECT timezone FROM location WHERE id = ?', [$locationId]);

        return $tz === false ? 'Europe/Madrid' : (string) $tz;
    }
}

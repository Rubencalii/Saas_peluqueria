<?php

declare(strict_types=1);

namespace App\Service\WhatsApp;

use App\Service\AppointmentException;
use App\Service\AppointmentService;
use App\Service\AvailabilityService;
use Doctrine\DBAL\Connection;

/**
 * Bot conversacional de WhatsApp (docs/03-guiones-bot-whatsapp.md).
 *
 * Máquina de estados guiada por botones/listas. Es un cliente más de la
 * lógica de negocio: reutiliza AvailabilityService y AppointmentService (los
 * mismos que la web), de modo que web y WhatsApp comparten reglas (doc 06 §1).
 * El estado del diálogo se guarda por número en la tabla `conversation`.
 */
final class BotEngine
{
    private const DAYS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

    public function __construct(
        private readonly Connection $db,
        private readonly AvailabilityService $availability,
        private readonly AppointmentService $appointments,
        private readonly WhatsAppMessenger $wa,
        private readonly AiAssistant $ai,
    ) {
    }

    /**
     * Procesa un mensaje entrante ya normalizado.
     *
     * @param string      $waId        Número del cliente (id de WhatsApp, solo dígitos).
     * @param string|null $text        Texto libre del mensaje, si lo hay.
     * @param string|null $interactive Id de la respuesta de botón/lista, si la hay.
     */
    public function handle(string $waId, ?string $text, ?string $interactive): void
    {
        $phone = '+' . ltrim($waId, '+');
        $input = $interactive ?? mb_strtolower(trim((string) $text));
        $conv = $this->load($waId);

        // Comandos globales: reinicio y atención humana, desde cualquier estado.
        if ($interactive === 'menu:human' || in_array($input, ['hablar', 'humano', 'agente'], true)) {
            $this->toHuman($waId);

            return;
        }
        if (in_array($input, ['menu', 'menú', 'hola', 'inicio', 'start', 'buenas'], true)) {
            $this->showMenu($waId, $phone);

            return;
        }

        // Comprensión de lenguaje natural: en el menú (o conversación nueva), si el
        // cliente escribe texto libre en vez de pulsar un botón, la IA deduce qué
        // quiere y arranca el flujo correspondiente. Si la IA está desactivada o no
        // lo entiende, seguimos con la máquina de estados por botones de siempre.
        if ($interactive === null && $conv['state'] === 'menu' && trim((string) $text) !== ''
            && $this->tryAi($waId, $phone, (string) $text)) {
            return;
        }

        match (true) {
            $conv['state'] === 'menu' => $this->onMenu($waId, $phone, $input),
            $conv['state'] === 'reserve:service' => $this->onService($waId, $conv, $input),
            $conv['state'] === 'reserve:staff' => $this->onStaff($waId, $conv, $input),
            $conv['state'] === 'reserve:date' => $this->onDate($waId, $conv, $input, $text),
            $conv['state'] === 'reserve:slot' => $this->onSlot($waId, $phone, $conv, $input),
            $conv['state'] === 'reserve:name' => $this->onName($waId, $phone, $conv, $text),
            $conv['state'] === 'reserve:confirm' => $this->onConfirm($waId, $phone, $conv, $input),
            $conv['state'] === 'manage:choose' => $this->onManageChoose($waId, $phone, $conv, $input),
            $conv['state'] === 'manage:cancel_confirm' => $this->onCancelConfirm($waId, $phone, $conv, $input),
            $conv['state'] === 'reschedule:date' => $this->onDate($waId, $conv, $input, $text),
            $conv['state'] === 'reschedule:slot' => $this->onSlot($waId, $phone, $conv, $input),
            $conv['state'] === 'reschedule:confirm' => $this->onRescheduleConfirm($waId, $phone, $conv, $input),
            default => $this->fallback($waId, $phone),
        };
    }

    // -----------------------------------------------------------------
    // Menú principal
    // -----------------------------------------------------------------

    private function showMenu(string $waId, string $phone): void
    {
        $customer = $this->customerByPhone($phone);
        $hi = $customer !== null ? "¡Hola de nuevo, {$customer['name']}! 👋" : '¡Hola! 👋 Soy el asistente del salón.';

        $this->save($waId, 'menu', []);
        $this->wa->sendList($waId, $hi . "\n¿Qué quieres hacer?", 'Elegir', [
            ['id' => 'menu:reserve', 'title' => '📅 Reservar cita'],
            ['id' => 'menu:view', 'title' => '🔍 Ver mi cita'],
            ['id' => 'menu:manage', 'title' => '✏️ Cambiar / cancelar'],
            ['id' => 'menu:human', 'title' => '💬 Hablar con el salón'],
        ]);
    }

    private function onMenu(string $waId, string $phone, string $input): void
    {
        match ($input) {
            'menu:reserve' => $this->startReserve($waId),
            'menu:view' => $this->showNextAppointment($waId, $phone, false),
            'menu:manage' => $this->showNextAppointment($waId, $phone, true),
            default => $this->fallback($waId, $phone),
        };
    }

    // -----------------------------------------------------------------
    // Comprensión de lenguaje natural (IA)
    // -----------------------------------------------------------------

    /**
     * Interpreta texto libre con la IA y arranca el flujo correspondiente.
     *
     * @return bool true si la IA entendió y enrutó el mensaje
     */
    private function tryAi(string $waId, string $phone, string $text): bool
    {
        if (!$this->ai->isEnabled()) {
            return false;
        }

        $locationId = $this->interpretationLocation($phone);
        if ($locationId === null) {
            return false;
        }

        $result = $this->ai->interpret($text, $locationId);
        if ($result === null || $result['intent'] === 'otro') {
            return false; // sin IA o sin intención clara → menú por botones
        }

        if ($result['reply'] !== '') {
            $this->wa->sendText($waId, $result['reply']);
        }

        match ($result['intent']) {
            'reservar' => $this->startReserveAi($waId, $locationId, $result['service_id'], $result['staff_id']),
            'ver_cita' => $this->showNextAppointment($waId, $phone, false),
            'cambiar', 'cancelar' => $this->showNextAppointment($waId, $phone, true),
            'hablar_humano' => $this->toHuman($waId),
            default => $this->showMenu($waId, $phone),
        };

        return true;
    }

    /**
     * Sede de referencia para interpretar: la de la última cita del cliente, o la
     * única sede activa. Si hay varias y no sabemos cuál, devolvemos la primera
     * (el catálogo de servicios es de cadena; la sede definitiva se elige luego).
     */
    private function interpretationLocation(string $phone): ?int
    {
        $last = $this->db->fetchOne(
            'SELECT a.location_id FROM appointment a
               JOIN customer c ON c.id = a.customer_id
              WHERE c.phone = ? ORDER BY a.start_at DESC LIMIT 1',
            [$phone]
        );
        if ($last !== false) {
            return (int) $last;
        }

        $first = $this->db->fetchOne('SELECT id FROM location WHERE active ORDER BY id LIMIT 1');

        return $first === false ? null : (int) $first;
    }

    private function startReserveAi(string $waId, int $locationId, ?int $serviceId, ?int $staffId): void
    {
        $locations = $this->db->fetchAllAssociative('SELECT id FROM location WHERE active ORDER BY id');

        // Multi-sede sin saber cuál: arrancamos el flujo normal (elige sede primero).
        if (count($locations) !== 1) {
            $this->startReserve($waId);

            return;
        }
        $locId = (int) $locations[0]['id'];

        if ($serviceId === null) {
            $this->askService($waId, ['data' => ['location_id' => $locId]]);

            return;
        }

        // Servicio detectado: si además hay profesional válido para ese servicio,
        // saltamos directos a elegir día; si no, ofrecemos la lista de profesionales.
        if ($staffId !== null && $this->staffDoesService($locId, $serviceId, $staffId)) {
            $this->askDate($waId, 'reserve:date', [
                'location_id' => $locId,
                'service_id' => $serviceId,
                'staff_id' => $staffId,
            ]);

            return;
        }

        $this->promptStaff($waId, $locId, $serviceId);
    }

    private function staffDoesService(int $locationId, int $serviceId, int $staffId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM staff_service ss
               JOIN staff_location sl ON sl.staff_id = ss.staff_id AND sl.location_id = ?
              WHERE ss.staff_id = ? AND ss.service_id = ?',
            [$locationId, $staffId, $serviceId]
        ) !== false;
    }

    // -----------------------------------------------------------------
    // Flujo: Reservar
    // -----------------------------------------------------------------

    private function startReserve(string $waId): void
    {
        $locations = $this->db->fetchAllAssociative('SELECT id, name FROM location WHERE active ORDER BY name');

        if (count($locations) === 1) {
            $this->askService($waId, ['data' => ['location_id' => (int) $locations[0]['id']]]);

            return;
        }

        // El estado queda en reserve:service: onService() distingue la
        // respuesta de sede (loc:) de la de servicio (svc:), evitando un
        // estado extra solo para elegir sede.
        $this->save($waId, 'reserve:service', []);
        $this->wa->sendList($waId, '¿En qué salón quieres tu cita?', 'Ver sedes', array_map(
            static fn (array $l): array => ['id' => 'loc:' . $l['id'], 'title' => $l['name']],
            $locations
        ));
    }

    /** @param array{data: array<string, mixed>} $conv */
    private function askService(string $waId, array $conv): void
    {
        $locationId = (int) $conv['data']['location_id'];
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name, s.duration_min, COALESCE(sl.price_override, s.price) AS price
               FROM service s
               JOIN service_location sl ON sl.service_id = s.id AND sl.location_id = ?
              WHERE s.active ORDER BY s.name',
            [$locationId]
        );

        $this->save($waId, 'reserve:service', ['location_id' => $locationId]);
        $this->wa->sendList($waId, '¿Qué servicio quieres?', 'Ver servicios', array_map(
            static fn (array $s): array => [
                'id' => 'svc:' . $s['id'],
                'title' => $s['name'],
                'description' => sprintf('%d min · %s €', (int) $s['duration_min'], rtrim(rtrim((string) $s['price'], '0'), '.')),
            ],
            $rows
        ));
    }

    /** @param array{data: array<string, mixed>} $conv */
    private function onService(string $waId, array $conv, string $input): void
    {
        // Selección de sede pendiente (multi-sede): el id llega como loc:{id}.
        if (str_starts_with($input, 'loc:')) {
            $this->askService($waId, ['data' => ['location_id' => (int) substr($input, 4)]]);

            return;
        }
        if (!str_starts_with($input, 'svc:')) {
            $this->fallback($waId, '+' . ltrim($waId, '+'));

            return;
        }

        $serviceId = (int) substr($input, 4);
        $locationId = (int) $conv['data']['location_id'];
        $this->promptStaff($waId, $locationId, $serviceId);
    }

    /**
     * Muestra la lista de profesionales para un servicio/sede y deja el flujo en
     * reserve:staff. Reutilizado por la selección de servicio y por la IA.
     */
    private function promptStaff(string $waId, int $locationId, int $serviceId): void
    {
        $staff = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name
               FROM staff s
               JOIN staff_service  ss ON ss.staff_id = s.id AND ss.service_id = ?
               JOIN staff_location sl ON sl.staff_id = s.id AND sl.location_id = ?
              WHERE s.active ORDER BY s.name',
            [$serviceId, $locationId]
        );

        $this->save($waId, 'reserve:staff', ['location_id' => $locationId, 'service_id' => $serviceId]);

        $rows = [['id' => 'staff:any', 'title' => 'Sin preferencia']];
        foreach ($staff as $s) {
            $rows[] = ['id' => 'staff:' . $s['id'], 'title' => $s['name']];
        }
        $this->wa->sendList($waId, '¿Con algún profesional en concreto?', 'Elegir', $rows);
    }

    /** @param array{data: array<string, mixed>} $conv */
    private function onStaff(string $waId, array $conv, string $input): void
    {
        if (!str_starts_with($input, 'staff:')) {
            $this->fallback($waId, '+' . ltrim($waId, '+'));

            return;
        }

        $pick = substr($input, 6);
        $data = $conv['data'];
        $data['staff_id'] = $pick === 'any' ? null : (int) $pick;
        $this->askDate($waId, 'reserve:date', $data);
    }

    /** @param array<string, mixed> $data */
    private function askDate(string $waId, string $state, array $data): void
    {
        $this->save($waId, $state, $data);
        $this->wa->sendButtons($waId, '¿Qué día te viene bien?', [
            ['id' => 'date:today', 'title' => 'Hoy'],
            ['id' => 'date:tomorrow', 'title' => 'Mañana'],
            ['id' => 'date:other', 'title' => 'Otro día'],
        ]);
    }

    /** @param array{state: string, data: array<string, mixed>} $conv */
    private function onDate(string $waId, array $conv, string $input, ?string $text): void
    {
        $data = $conv['data'];
        $tz = new \DateTimeZone($this->locationTz((int) $data['location_id']));
        $today = new \DateTimeImmutable('now', $tz);

        $date = match (true) {
            $input === 'date:today' => $today->format('Y-m-d'),
            $input === 'date:tomorrow' => $today->modify('+1 day')->format('Y-m-d'),
            $input === 'date:other' => null,
            default => $this->parseDate($text),
        };

        if ($input === 'date:other') {
            $this->wa->sendText($waId, 'Escríbeme la fecha en formato AAAA-MM-DD (por ejemplo 2026-07-15).');

            return; // seguimos en el mismo estado esperando el texto
        }
        if ($date === null) {
            $this->wa->sendText($waId, 'No reconozco esa fecha. Escríbela como AAAA-MM-DD, por favor.');

            return;
        }

        $data['date'] = $date;
        $slotState = str_starts_with($conv['state'], 'reschedule') ? 'reschedule:slot' : 'reserve:slot';
        $this->offerSlots($waId, $slotState, $data);
    }

    /** @param array<string, mixed> $data */
    private function offerSlots(string $waId, string $state, array $data): void
    {
        $staffId = isset($data['staff_id']) && $data['staff_id'] !== null ? (int) $data['staff_id'] : null;
        try {
            $offer = $this->availability->find(
                (int) $data['location_id'],
                (int) $data['service_id'],
                $staffId,
                (string) $data['date']
            );
        } catch (\InvalidArgumentException) {
            $offer = ['slots' => []];
        }

        if ($offer['slots'] === []) {
            $this->save($waId, str_starts_with($state, 'reschedule') ? 'reschedule:date' : 'reserve:date', $data);
            $this->wa->sendButtons($waId, 'Ese día está completo 😕 ¿Probamos otro día?', [
                ['id' => 'date:today', 'title' => 'Hoy'],
                ['id' => 'date:tomorrow', 'title' => 'Mañana'],
                ['id' => 'date:other', 'title' => 'Otro día'],
            ]);

            return;
        }

        $tz = new \DateTimeZone($this->locationTz((int) $data['location_id']));
        $rows = [];
        foreach (array_slice($offer['slots'], 0, 9) as $slot) {
            $local = (new \DateTimeImmutable($slot['start']))->setTimezone($tz);
            $rows[] = ['id' => 'slot:' . $slot['start'], 'title' => $local->format('H:i')];
        }

        $this->save($waId, $state, $data);
        $label = (new \DateTimeImmutable((string) $data['date']))->format('d/m');
        $this->wa->sendList($waId, "Huecos para el {$label}:", 'Ver horas', $rows);
    }

    /** @param array{state: string, data: array<string, mixed>} $conv */
    private function onSlot(string $waId, string $phone, array $conv, string $input): void
    {
        if (!str_starts_with($input, 'slot:')) {
            $this->fallback($waId, $phone);

            return;
        }

        $data = $conv['data'];
        $data['slot_iso'] = substr($input, 5);

        if (str_starts_with($conv['state'], 'reschedule')) {
            $this->confirmReschedule($waId, $data);

            return;
        }

        // Reserva: si el cliente es nuevo, pedimos el nombre antes de confirmar.
        $customer = $this->customerByPhone($phone);
        if ($customer === null) {
            $this->save($waId, 'reserve:name', $data);
            $this->wa->sendText($waId, 'Para terminar, ¿cómo te llamas?');

            return;
        }

        $data['customer_name'] = $customer['name'];
        $this->confirmReserve($waId, $data);
    }

    /** @param array{data: array<string, mixed>} $conv */
    private function onName(string $waId, string $phone, array $conv, ?string $text): void
    {
        $name = trim((string) $text);
        if ($name === '') {
            $this->wa->sendText($waId, '¿Me dices tu nombre, por favor?');

            return;
        }

        $data = $conv['data'];
        $data['customer_name'] = $name;
        $this->confirmReserve($waId, $data);
    }

    /** @param array<string, mixed> $data */
    private function confirmReserve(string $waId, array $data): void
    {
        $this->save($waId, 'reserve:confirm', $data);
        $this->wa->sendButtons($waId, "Resumen de tu cita 👇\n" . $this->summary($data) . "\n\n¿Confirmo?", [
            ['id' => 'confirm:yes', 'title' => '✅ Sí, confirmar'],
            ['id' => 'confirm:no', 'title' => '❌ No'],
        ]);
    }

    /** @param array{data: array<string, mixed>} $conv */
    private function onConfirm(string $waId, string $phone, array $conv, string $input): void
    {
        if ($input === 'confirm:no') {
            $this->wa->sendText($waId, 'Sin problema, no he reservado nada.');
            $this->showMenu($waId, $phone);

            return;
        }
        if ($input !== 'confirm:yes') {
            $this->fallback($waId, $phone);

            return;
        }

        $data = $conv['data'];
        try {
            $result = $this->appointments->create([
                'location_id' => $data['location_id'],
                'service_id' => $data['service_id'],
                'staff_id' => $data['staff_id'] ?? null,
                'start' => $data['slot_iso'],
                'customer' => ['name' => $data['customer_name'], 'phone' => $phone],
                'wa_consent' => true,
                'channel' => 'whatsapp',
            ]);
        } catch (AppointmentException $e) {
            $this->save($waId, 'menu', []);
            $msg = $e->errorCode === 'SLOT_TAKEN'
                ? '¡Vaya! Ese hueco se acaba de ocupar. Escribe "menú" y elegimos otro.'
                : 'No he podido crear la cita: ' . $e->getMessage();
            $this->wa->sendText($waId, $msg);

            return;
        }

        $this->save($waId, 'menu', []);
        $this->wa->sendText(
            $waId,
            "¡Listo! Tu cita está confirmada ✅\n" . $this->summary($data)
            . "\nCódigo: {$result['public_code']}\nTe enviaré un recordatorio el día antes."
        );
    }

    // -----------------------------------------------------------------
    // Flujo: Ver / Cambiar / Cancelar
    // -----------------------------------------------------------------

    private function showNextAppointment(string $waId, string $phone, bool $manage): void
    {
        $appt = $this->nextAppointment($phone);
        if ($appt === null) {
            $this->save($waId, 'menu', []);
            $this->wa->sendButtons($waId, 'No tienes ninguna cita próxima. ¿Quieres reservar?', [
                ['id' => 'menu:reserve', 'title' => '📅 Reservar cita'],
            ]);

            return;
        }

        $when = $this->formatLocal($appt['start_at'], $appt['timezone']);
        $body = "Tu próxima cita:\n🗓️ {$when}\n✂️ {$appt['service_name']} · 📍 {$appt['location_name']}"
            . ($appt['staff_name'] !== null ? " · 👤 {$appt['staff_name']}" : '');

        $data = [
            'appointment_id' => (int) $appt['id'],
            'appointment_code' => (string) $appt['public_code'],
            'location_id' => (int) $appt['location_id'],
            'service_id' => (int) $appt['service_id'],
            'staff_id' => $appt['staff_id'] !== null ? (int) $appt['staff_id'] : null,
        ];
        $this->save($waId, 'manage:choose', $data);
        $this->wa->sendButtons($waId, $body . "\n\n¿Qué quieres hacer?", [
            ['id' => 'manage:change', 'title' => '🔄 Cambiar hora'],
            ['id' => 'manage:cancel', 'title' => '❌ Cancelar'],
            ['id' => 'menu:back', 'title' => '↩️ Volver'],
        ]);
    }

    /** @param array{data: array<string, mixed>} $conv */
    private function onManageChoose(string $waId, string $phone, array $conv, string $input): void
    {
        match ($input) {
            'manage:change' => $this->askDate($waId, 'reschedule:date', $conv['data']),
            'manage:cancel' => $this->askCancelConfirm($waId, $conv['data']),
            'menu:back' => $this->showMenu($waId, $phone),
            default => $this->fallback($waId, $phone),
        };
    }

    /** @param array<string, mixed> $data */
    private function askCancelConfirm(string $waId, array $data): void
    {
        $this->save($waId, 'manage:cancel_confirm', $data);
        $this->wa->sendButtons($waId, '¿Seguro que quieres cancelar tu cita?', [
            ['id' => 'cancel:yes', 'title' => 'Sí, cancelar'],
            ['id' => 'cancel:no', 'title' => 'No, mantener'],
        ]);
    }

    /** @param array{data: array<string, mixed>} $conv */
    private function onCancelConfirm(string $waId, string $phone, array $conv, string $input): void
    {
        if ($input !== 'cancel:yes') {
            $this->wa->sendText($waId, 'Perfecto, mantengo tu cita.');
            $this->showMenu($waId, $phone);

            return;
        }

        $data = $conv['data'];
        try {
            $this->appointments->cancel((int) $data['appointment_id'], (string) $data['appointment_code']);
        } catch (AppointmentException $e) {
            $this->save($waId, 'menu', []);
            $msg = $e->errorCode === 'TOO_LATE'
                ? 'Tu cita es muy pronto, no puedo cancelarla por aquí. Te paso con el salón 📞'
                : 'No he podido cancelar: ' . $e->getMessage();
            $this->wa->sendText($waId, $msg);
            if ($e->errorCode === 'TOO_LATE') {
                $this->toHuman($waId);
            }

            return;
        }

        $this->save($waId, 'menu', []);
        $this->wa->sendButtons($waId, 'Tu cita ha sido cancelada. ¿Quieres reservar otra?', [
            ['id' => 'menu:reserve', 'title' => '📅 Reservar'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function confirmReschedule(string $waId, array $data): void
    {
        $this->save($waId, 'reschedule:confirm', $data);
        $when = $this->formatLocal($data['slot_iso'], $this->locationTz((int) $data['location_id']));
        $this->wa->sendButtons($waId, "Nueva hora: {$when}.\n¿Confirmo el cambio?", [
            ['id' => 'confirm:yes', 'title' => '✅ Sí, cambiar'],
            ['id' => 'confirm:no', 'title' => '❌ No'],
        ]);
    }

    /** @param array{data: array<string, mixed>} $conv */
    private function onRescheduleConfirm(string $waId, string $phone, array $conv, string $input): void
    {
        if ($input === 'confirm:no') {
            $this->wa->sendText($waId, 'Vale, dejo tu cita como estaba.');
            $this->showMenu($waId, $phone);

            return;
        }
        if ($input !== 'confirm:yes') {
            $this->fallback($waId, $phone);

            return;
        }

        $data = $conv['data'];
        try {
            $this->appointments->reschedule(
                (int) $data['appointment_id'],
                (string) $data['appointment_code'],
                (string) $data['slot_iso']
            );
        } catch (AppointmentException $e) {
            $this->save($waId, 'menu', []);
            $msg = match ($e->errorCode) {
                'SLOT_TAKEN' => '¡Vaya! Ese hueco se acaba de ocupar. Escribe "menú" para reintentar.',
                'TOO_LATE' => 'Tu cita es muy pronto para cambiarla por aquí. Te paso con el salón 📞',
                default => 'No he podido cambiar la cita: ' . $e->getMessage(),
            };
            $this->wa->sendText($waId, $msg);
            if ($e->errorCode === 'TOO_LATE') {
                $this->toHuman($waId);
            }

            return;
        }

        $when = $this->formatLocal($data['slot_iso'], $this->locationTz((int) $data['location_id']));
        $this->save($waId, 'menu', []);
        $this->wa->sendText($waId, "¡Hecho! Tu cita ha cambiado a {$when} ✅");
    }

    // -----------------------------------------------------------------
    // Atención humana y fallback
    // -----------------------------------------------------------------

    private function toHuman(string $waId): void
    {
        $this->db->executeStatement(
            'INSERT INTO conversation (wa_id, state, needs_human, updated_at)
             VALUES (?, \'human\', TRUE, now())
             ON CONFLICT (wa_id) DO UPDATE SET state = \'human\', needs_human = TRUE, updated_at = now()',
            [$waId]
        );
        $this->wa->sendText(
            $waId,
            'Te leo y aviso al equipo del salón. Cuéntame qué necesitas y te responden en cuanto puedan (L-S 9:00-20:00).'
        );
    }

    private function fallback(string $waId, string $phone): void
    {
        $this->wa->sendText($waId, 'No te he entendido del todo 🤔');
        $this->showMenu($waId, $phone);
    }

    // -----------------------------------------------------------------
    // Auxiliares de datos / formato
    // -----------------------------------------------------------------

    /** @return array{id: int, name: string}|null */
    private function customerByPhone(string $phone): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id, name FROM customer WHERE phone = ?', [$phone]);

        return $row === false ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /** @return array<string, mixed>|null */
    private function nextAppointment(string $phone): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT a.id, a.public_code, a.start_at, a.location_id, a.service_id, a.staff_id,
                    s.name AS service_name, st.name AS staff_name, l.name AS location_name, l.timezone
               FROM appointment a
               JOIN customer c ON c.id = a.customer_id
               JOIN service  s ON s.id = a.service_id
               LEFT JOIN staff st ON st.id = a.staff_id
               JOIN location l ON l.id = a.location_id
              WHERE c.phone = ?
                AND a.status IN (\'pendiente\', \'confirmada\')
                AND a.end_at >= now()
              ORDER BY a.start_at
              LIMIT 1',
            [$phone]
        );

        return $row === false ? null : $row;
    }

    private function locationTz(int $locationId): string
    {
        $tz = $this->db->fetchOne('SELECT timezone FROM location WHERE id = ?', [$locationId]);

        return $tz === false ? 'Europe/Madrid' : (string) $tz;
    }

    /** @param array<string, mixed> $data */
    private function summary(array $data): string
    {
        $svc = $this->db->fetchAssociative(
            'SELECT s.name, COALESCE(sl.price_override, s.price) AS price, s.duration_min
               FROM service s
               JOIN service_location sl ON sl.service_id = s.id AND sl.location_id = ?
              WHERE s.id = ?',
            [(int) $data['location_id'], (int) $data['service_id']]
        );
        $loc = $this->db->fetchOne('SELECT name FROM location WHERE id = ?', [(int) $data['location_id']]);
        $staff = isset($data['staff_id']) && $data['staff_id'] !== null
            ? $this->db->fetchOne('SELECT name FROM staff WHERE id = ?', [(int) $data['staff_id']])
            : 'Sin preferencia';
        $when = $this->formatLocal($data['slot_iso'], $this->locationTz((int) $data['location_id']));

        return "📍 {$loc}\n✂️ {$svc['name']} ({$svc['duration_min']} min · "
            . rtrim(rtrim((string) $svc['price'], '0'), '.') . " €)\n👤 {$staff}\n🗓️ {$when}";
    }

    private function formatLocal(string $isoUtc, string $tz): string
    {
        $dt = (new \DateTimeImmutable($isoUtc))->setTimezone(new \DateTimeZone($tz));
        $day = self::DAYS[((int) $dt->format('N')) - 1];

        return sprintf('%s %s a las %s', $day, $dt->format('d/m'), $dt->format('H:i'));
    }

    private function parseDate(?string $text): ?string
    {
        $text = trim((string) $text);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $d !== false && $d->format('Y-m-d') === $text ? $text : null;
    }

    // -----------------------------------------------------------------
    // Persistencia del estado de conversación
    // -----------------------------------------------------------------

    /** @return array{state: string, data: array<string, mixed>} */
    private function load(string $waId): array
    {
        $row = $this->db->fetchAssociative('SELECT state, data FROM conversation WHERE wa_id = ?', [$waId]);
        if ($row === false) {
            return ['state' => 'menu', 'data' => []];
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $row['data'], true) ?: [];

        return ['state' => (string) $row['state'], 'data' => $data];
    }

    /** @param array<string, mixed> $data */
    private function save(string $waId, string $state, array $data): void
    {
        $this->db->executeStatement(
            'INSERT INTO conversation (wa_id, state, data, location_id, updated_at)
             VALUES (?, ?, ?::jsonb, ?, now())
             ON CONFLICT (wa_id) DO UPDATE SET
                 state = EXCLUDED.state, data = EXCLUDED.data,
                 location_id = EXCLUDED.location_id, updated_at = now()',
            [
                $waId,
                $state,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                isset($data['location_id']) ? (int) $data['location_id'] : null,
            ]
        );
    }
}

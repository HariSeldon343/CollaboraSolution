<?php
declare(strict_types=1);

/**
 * Calendar Management Class
 *
 * Gestione completa del calendario con supporto per eventi ricorrenti,
 * notifiche, conflitti e integrazione con sistemi esterni
 *
 * @author CollaboraNexio
 * @version 1.0.0
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

class Calendar {
    private PDO $pdo;
    private int $tenant_id;
    private ?int $user_id;
    private array $cache = [];
    private const CACHE_TTL = 300; // 5 minuti

    // Costanti per ricorrenza (RFC 5545)
    private const FREQ_DAILY = 'DAILY';
    private const FREQ_WEEKLY = 'WEEKLY';
    private const FREQ_MONTHLY = 'MONTHLY';
    private const FREQ_YEARLY = 'YEARLY';

    // Costanti per notifiche
    private const NOTIFICATION_INVITE = 'invite';
    private const NOTIFICATION_REMINDER = 'reminder';
    private const NOTIFICATION_UPDATE = 'update';
    private const NOTIFICATION_CANCEL = 'cancel';

    // Strategie di risoluzione conflitti
    private const CONFLICT_STRATEGY_FORCE = 'force';
    private const CONFLICT_STRATEGY_RESCHEDULE = 'reschedule';
    private const CONFLICT_STRATEGY_NOTIFY = 'notify';

    public function __construct(PDO $pdo, int $tenant_id, ?int $user_id = null) {
        $this->pdo = $pdo;
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id ?? $_SESSION['user_id'] ?? null;
    }

    /**
     * Parse RRULE standard per eventi ricorrenti
     * Supporta RFC 5545 iCalendar specification
     */
    public function parseRecurrenceRule(string $rrule): array {
        $rules = [];
        $parts = explode(';', $rrule);

        foreach ($parts as $part) {
            if (strpos($part, '=') !== false) {
                list($key, $value) = explode('=', $part, 2);
                $rules[$key] = $value;
            }
        }

        // Validazione frequenza
        if (!isset($rules['FREQ']) || !in_array($rules['FREQ'], [
            self::FREQ_DAILY,
            self::FREQ_WEEKLY,
            self::FREQ_MONTHLY,
            self::FREQ_YEARLY
        ])) {
            throw new InvalidArgumentException('Frequenza ricorrenza non valida');
        }

        // Parse parametri opzionali
        if (isset($rules['BYDAY'])) {
            $rules['BYDAY'] = explode(',', $rules['BYDAY']);
        }

        if (isset($rules['BYMONTHDAY'])) {
            $rules['BYMONTHDAY'] = array_map('intval', explode(',', $rules['BYMONTHDAY']));
        }

        if (isset($rules['BYMONTH'])) {
            $rules['BYMONTH'] = array_map('intval', explode(',', $rules['BYMONTH']));
        }

        if (isset($rules['COUNT'])) {
            $rules['COUNT'] = (int) $rules['COUNT'];
        }

        if (isset($rules['INTERVAL'])) {
            $rules['INTERVAL'] = (int) $rules['INTERVAL'];
        } else {
            $rules['INTERVAL'] = 1;
        }

        if (isset($rules['UNTIL'])) {
            $rules['UNTIL'] = new DateTime($rules['UNTIL']);
        }

        return $rules;
    }

    /**
     * Ottieni eventi in un intervallo di date con espansione ricorrenze
     */
    public function getEventsBetween(DateTime $start, DateTime $end, ?array $filters = null): array {
        // Cache key
        $cacheKey = md5(serialize([$start, $end, $filters, $this->tenant_id, $this->user_id]));

        if (isset($this->cache[$cacheKey]) &&
            $this->cache[$cacheKey]['expires'] > time()) {
            return $this->cache[$cacheKey]['data'];
        }

        try {
            // Query principale per eventi singoli e ricorrenti
            $sql = "SELECT e.*,
                           u.name as creator_name,
                           u.email as creator_email,
                           (SELECT GROUP_CONCAT(ep.user_id)
                            FROM event_participants ep
                            WHERE ep.event_id = e.id) as participants,
                           (SELECT GROUP_CONCAT(CONCAT(er.type, ':', er.minutes_before))
                            FROM event_reminders er
                            WHERE er.event_id = e.id) as reminders
                    FROM events e
                    LEFT JOIN users u ON e.created_by = u.id
                    WHERE e.tenant_id = :tenant_id
                      AND e.deleted_at IS NULL
                      AND (
                          -- Eventi singoli nell'intervallo
                          (e.recurrence_rule IS NULL AND
                           e.start_date <= :end_date AND e.end_date >= :start_date)
                          OR
                          -- Eventi ricorrenti attivi nell'intervallo
                          (e.recurrence_rule IS NOT NULL AND
                           e.start_date <= :end_date AND
                           (e.recurrence_end IS NULL OR e.recurrence_end >= :start_date))
                      )";

            $params = [
                ':tenant_id' => $this->tenant_id,
                ':start_date' => $start->format('Y-m-d H:i:s'),
                ':end_date' => $end->format('Y-m-d H:i:s')
            ];

            // Applica filtri opzionali
            if ($filters) {
                if (isset($filters['user_id'])) {
                    $sql .= " AND (e.created_by = :user_id OR
                                   EXISTS (SELECT 1 FROM event_participants ep
                                          WHERE ep.event_id = e.id AND ep.user_id = :user_id))";
                    $params[':user_id'] = $filters['user_id'];
                }

                if (isset($filters['category'])) {
                    $sql .= " AND e.category = :category";
                    $params[':category'] = $filters['category'];
                }

                if (isset($filters['location'])) {
                    $sql .= " AND e.location = :location";
                    $params[':location'] = $filters['location'];
                }
            }

            $sql .= " ORDER BY e.start_date ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Espandi eventi ricorrenti
            $expandedEvents = [];
            foreach ($events as $event) {
                if ($event['recurrence_rule']) {
                    $instances = $this->expandRecurringEvent($event, $start, $end);
                    $expandedEvents = array_merge($expandedEvents, $instances);
                } else {
                    $expandedEvents[] = $this->formatEvent($event);
                }
            }

            // Ordina per data inizio
            usort($expandedEvents, function($a, $b) {
                return $a['start_date'] <=> $b['start_date'];
            });

            // Cache risultati
            $this->cache[$cacheKey] = [
                'data' => $expandedEvents,
                'expires' => time() + self::CACHE_TTL
            ];

            return $expandedEvents;

        } catch (Exception $e) {
            error_log("Errore getEventsBetween: " . $e->getMessage());
            throw new RuntimeException('Errore nel recupero eventi');
        }
    }

    /**
     * Crea nuovo evento con validazione completa
     */
    public function createEvent(array $data): int {
        // Validazione input
        $this->validateEventData($data);

        $this->pdo->beginTransaction();

        try {
            // Controlla conflitti se richiesto
            if ($data['check_conflicts'] ?? true) {
                $conflicts = $this->detectConflicts(
                    new DateTime($data['start_date']),
                    new DateTime($data['end_date']),
                    $data['participants'] ?? []
                );

                if (!empty($conflicts)) {
                    throw new RuntimeException('Conflitti rilevati: ' . json_encode($conflicts));
                }
            }

            // Inserisci evento principale
            $sql = "INSERT INTO events (
                        tenant_id, title, description, location, start_date, end_date,
                        all_day, category, color, recurrence_rule, recurrence_end,
                        timezone, created_by, visibility, status, metadata
                    ) VALUES (
                        :tenant_id, :title, :description, :location, :start_date, :end_date,
                        :all_day, :category, :color, :recurrence_rule, :recurrence_end,
                        :timezone, :created_by, :visibility, :status, :metadata
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $this->tenant_id,
                ':title' => $data['title'],
                ':description' => $data['description'] ?? null,
                ':location' => $data['location'] ?? null,
                ':start_date' => $data['start_date'],
                ':end_date' => $data['end_date'],
                ':all_day' => $data['all_day'] ?? false,
                ':category' => $data['category'] ?? 'general',
                ':color' => $data['color'] ?? '#3788d8',
                ':recurrence_rule' => $data['recurrence_rule'] ?? null,
                ':recurrence_end' => $data['recurrence_end'] ?? null,
                ':timezone' => $data['timezone'] ?? date_default_timezone_get(),
                ':created_by' => $this->user_id,
                ':visibility' => $data['visibility'] ?? 'private',
                ':status' => $data['status'] ?? 'confirmed',
                ':metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null
            ]);

            $eventId = (int) $this->pdo->lastInsertId();

            // Aggiungi partecipanti
            if (!empty($data['participants'])) {
                $this->inviteParticipants($eventId, $data['participants']);
            }

            // Aggiungi promemoria
            if (!empty($data['reminders'])) {
                $this->addReminders($eventId, $data['reminders']);
            }

            // Aggiungi allegati
            if (!empty($data['attachments'])) {
                $this->addAttachments($eventId, $data['attachments']);
            }

            // Log attività
            $this->logActivity('event_created', $eventId, [
                'title' => $data['title'],
                'participants' => $data['participants'] ?? []
            ]);

            $this->pdo->commit();

            // Invia notifiche asincrone
            if (!empty($data['participants'])) {
                $this->scheduleNotifications($eventId, self::NOTIFICATION_INVITE);
            }

            return $eventId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Errore creazione evento: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Aggiorna evento con rilevamento conflitti e notifiche
     */
    public function updateEvent(int $id, array $data): bool {
        // Verifica permessi
        if (!$this->canModifyEvent($id)) {
            throw new RuntimeException('Permessi insufficienti per modificare evento');
        }

        $this->pdo->beginTransaction();

        try {
            // Ottieni evento esistente
            $existingEvent = $this->getEventById($id);
            if (!$existingEvent) {
                throw new RuntimeException('Evento non trovato');
            }

            // Controlla conflitti se date modificate
            if (isset($data['start_date']) || isset($data['end_date'])) {
                $startDate = new DateTime($data['start_date'] ?? $existingEvent['start_date']);
                $endDate = new DateTime($data['end_date'] ?? $existingEvent['end_date']);

                $conflicts = $this->detectConflicts($startDate, $endDate,
                    $data['participants'] ?? $existingEvent['participants']);

                // Escludi l'evento corrente dai conflitti
                $conflicts = array_filter($conflicts, fn($c) => $c['event_id'] != $id);

                if (!empty($conflicts) && ($data['check_conflicts'] ?? true)) {
                    throw new RuntimeException('Conflitti rilevati: ' . json_encode($conflicts));
                }
            }

            // Prepara campi da aggiornare
            $updates = [];
            $params = [':id' => $id, ':tenant_id' => $this->tenant_id];

            $allowedFields = [
                'title', 'description', 'location', 'start_date', 'end_date',
                'all_day', 'category', 'color', 'recurrence_rule', 'recurrence_end',
                'timezone', 'visibility', 'status', 'metadata'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[] = "$field = :$field";
                    $params[":$field"] = $field === 'metadata' && is_array($data[$field])
                        ? json_encode($data[$field])
                        : $data[$field];
                }
            }

            if (empty($updates)) {
                $this->pdo->commit();
                return true;
            }

            // Aggiorna evento
            $sql = "UPDATE events SET " . implode(', ', $updates) . ",
                    updated_at = NOW()
                    WHERE id = :id AND tenant_id = :tenant_id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            // Aggiorna partecipanti se modificati
            if (isset($data['participants'])) {
                $this->updateParticipants($id, $data['participants']);
            }

            // Aggiorna promemoria se modificati
            if (isset($data['reminders'])) {
                $this->updateReminders($id, $data['reminders']);
            }

            // Log attività
            $this->logActivity('event_updated', $id, [
                'changes' => array_keys($data),
                'old_values' => array_intersect_key($existingEvent, $data)
            ]);

            $this->pdo->commit();

            // Notifica partecipanti delle modifiche
            $this->scheduleNotifications($id, self::NOTIFICATION_UPDATE);

            // Invalida cache
            $this->invalidateCache();

            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Errore aggiornamento evento: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Invita partecipanti con tracking RSVP
     */
    public function inviteParticipants(int $eventId, array $userIds): bool {
        if (empty($userIds)) {
            return true;
        }

        try {
            $sql = "INSERT INTO event_participants (event_id, user_id, status, invited_at)
                    VALUES (:event_id, :user_id, 'pending', NOW())
                    ON DUPLICATE KEY UPDATE
                    status = IF(status = 'declined', 'pending', status),
                    invited_at = NOW()";

            $stmt = $this->pdo->prepare($sql);

            foreach ($userIds as $userId) {
                // Valida esistenza utente
                if (!$this->userExists($userId)) {
                    continue;
                }

                $stmt->execute([
                    ':event_id' => $eventId,
                    ':user_id' => $userId
                ]);

                // Crea notifica in-app
                $this->createInAppNotification($userId, ['event_id' => $eventId], self::NOTIFICATION_INVITE);
            }

            // Invia inviti email (asincrono)
            $this->scheduleEmailInvitations($eventId, $userIds);

            return true;

        } catch (Exception $e) {
            error_log("Errore invito partecipanti: " . $e->getMessage());
            throw new RuntimeException('Errore durante invito partecipanti');
        }
    }

    /**
     * Elimina evento (soft delete) con notifica partecipanti
     */
    public function deleteEvent(int $id, bool $notifyParticipants = true): bool {
        // Verifica permessi
        if (!$this->canModifyEvent($id)) {
            throw new RuntimeException('Permessi insufficienti per eliminare evento');
        }

        $this->pdo->beginTransaction();

        try {
            // Ottieni partecipanti prima dell'eliminazione
            $participants = [];
            if ($notifyParticipants) {
                $stmt = $this->pdo->prepare(
                    "SELECT user_id FROM event_participants WHERE event_id = :event_id"
                );
                $stmt->execute([':event_id' => $id]);
                $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // Soft delete
            $sql = "UPDATE events
                    SET deleted_at = NOW(),
                        deleted_by = :user_id
                    WHERE id = :id AND tenant_id = :tenant_id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':tenant_id' => $this->tenant_id,
                ':user_id' => $this->user_id
            ]);

            // Cancella partecipazioni
            $stmt = $this->pdo->prepare(
                "UPDATE event_participants SET status = 'cancelled' WHERE event_id = :event_id"
            );
            $stmt->execute([':event_id' => $id]);

            // Log attività
            $this->logActivity('event_deleted', $id, [
                'deleted_by' => $this->user_id,
                'participants_notified' => count($participants)
            ]);

            $this->pdo->commit();

            // Notifica partecipanti
            if ($notifyParticipants && !empty($participants)) {
                $event = $this->getEventById($id, true); // Include deleted
                $this->sendCancellation($event, $participants);
            }

            // Invalida cache
            $this->invalidateCache();

            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Errore eliminazione evento: " . $e->getMessage());
            throw new RuntimeException('Errore durante eliminazione evento');
        }
    }

    /**
     * Ottieni prossimi eventi per utente
     */
    public function getUpcomingEvents(int $days = 7, ?int $userId = null): array {
        $userId = $userId ?? $this->user_id;

        if (!$userId) {
            throw new InvalidArgumentException('ID utente richiesto');
        }

        $start = new DateTime();
        $end = (clone $start)->add(new DateInterval("P{$days}D"));

        return $this->getEventsBetween($start, $end, ['user_id' => $userId]);
    }

    /**
     * Verifica disponibilità utente
     */
    public function getUserAvailability(int $userId, DateTime $date): array {
        try {
            // Ottieni eventi dell'utente per il giorno
            $dayStart = clone $date;
            $dayStart->setTime(0, 0, 0);

            $dayEnd = clone $date;
            $dayEnd->setTime(23, 59, 59);

            $events = $this->getEventsBetween($dayStart, $dayEnd, ['user_id' => $userId]);

            // Ottieni orari di lavoro dell'utente
            $workHours = $this->getUserWorkHours($userId, $date->format('N'));

            // Calcola slot liberi
            $busySlots = [];
            foreach ($events as $event) {
                if ($event['status'] !== 'cancelled') {
                    $busySlots[] = [
                        'start' => new DateTime($event['start_date']),
                        'end' => new DateTime($event['end_date'])
                    ];
                }
            }

            // Ordina slot occupati
            usort($busySlots, fn($a, $b) => $a['start'] <=> $b['start']);

            // Trova slot liberi
            $freeSlots = [];
            $currentTime = clone $workHours['start'];

            foreach ($busySlots as $busy) {
                if ($currentTime < $busy['start']) {
                    $freeSlots[] = [
                        'start' => clone $currentTime,
                        'end' => clone $busy['start']
                    ];
                }
                $currentTime = max($currentTime, $busy['end']);
            }

            // Aggiungi ultimo slot se disponibile
            if ($currentTime < $workHours['end']) {
                $freeSlots[] = [
                    'start' => clone $currentTime,
                    'end' => clone $workHours['end']
                ];
            }

            return [
                'date' => $date->format('Y-m-d'),
                'work_hours' => $workHours,
                'busy_slots' => $busySlots,
                'free_slots' => $freeSlots,
                'total_free_minutes' => $this->calculateTotalMinutes($freeSlots)
            ];

        } catch (Exception $e) {
            error_log("Errore getUserAvailability: " . $e->getMessage());
            throw new RuntimeException('Errore verifica disponibilità');
        }
    }

    /**
     * Rileva conflitti di scheduling
     */
    public function detectConflicts(DateTime $start, DateTime $end, array $participants = []): array {
        $conflicts = [];

        try {
            // Prepara query base
            $sql = "SELECT e.*, u.name as creator_name
                    FROM events e
                    LEFT JOIN users u ON e.created_by = u.id
                    WHERE e.tenant_id = :tenant_id
                      AND e.deleted_at IS NULL
                      AND e.status != 'cancelled'
                      AND (
                          (e.start_date < :end_date AND e.end_date > :start_date)
                      )";

            $params = [
                ':tenant_id' => $this->tenant_id,
                ':start_date' => $start->format('Y-m-d H:i:s'),
                ':end_date' => $end->format('Y-m-d H:i:s')
            ];

            // Se ci sono partecipanti, controlla solo i loro conflitti
            if (!empty($participants)) {
                $placeholders = array_map(fn($i) => ":user_$i", array_keys($participants));
                $sql .= " AND EXISTS (
                            SELECT 1 FROM event_participants ep
                            WHERE ep.event_id = e.id
                              AND ep.user_id IN (" . implode(',', $placeholders) . ")
                              AND ep.status != 'declined'
                          )";

                foreach ($participants as $i => $userId) {
                    $params[":user_$i"] = $userId;
                }
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Espandi eventi ricorrenti se necessario
                if ($row['recurrence_rule']) {
                    $instances = $this->expandRecurringEvent($row, $start, $end);
                    foreach ($instances as $instance) {
                        if ($this->eventsOverlap(
                            $start, $end,
                            new DateTime($instance['start_date']),
                            new DateTime($instance['end_date'])
                        )) {
                            $conflicts[] = [
                                'event_id' => $instance['id'],
                                'title' => $instance['title'],
                                'start' => $instance['start_date'],
                                'end' => $instance['end_date'],
                                'type' => 'time_conflict',
                                'severity' => 'high'
                            ];
                        }
                    }
                } else {
                    $conflicts[] = [
                        'event_id' => $row['id'],
                        'title' => $row['title'],
                        'start' => $row['start_date'],
                        'end' => $row['end_date'],
                        'type' => 'time_conflict',
                        'severity' => 'high'
                    ];
                }
            }

            // Controlla conflitti risorse (sale, attrezzature)
            if (isset($data['resources'])) {
                $resourceConflicts = $this->checkResourceConflicts($start, $end, $data['resources']);
                $conflicts = array_merge($conflicts, $resourceConflicts);
            }

            return $conflicts;

        } catch (Exception $e) {
            error_log("Errore detectConflicts: " . $e->getMessage());
            throw new RuntimeException('Errore rilevamento conflitti');
        }
    }

    /**
     * Suggerisce slot temporali liberi usando AI
     */
    public function suggestFreeSlots(
        int $duration,
        array $participants,
        array $dateRange,
        array $preferences = []
    ): array {
        $suggestions = [];

        try {
            $startDate = new DateTime($dateRange['start']);
            $endDate = new DateTime($dateRange['end']);

            // Parametri preferenze
            $preferredTimes = $preferences['preferred_times'] ?? ['09:00', '14:00'];
            $avoidLunch = $preferences['avoid_lunch'] ?? true;
            $minGap = $preferences['min_gap'] ?? 15; // Minuti tra meeting

            $current = clone $startDate;

            while ($current <= $endDate) {
                // Skip weekend se richiesto
                if (($preferences['skip_weekends'] ?? true) &&
                    in_array($current->format('N'), [6, 7])) {
                    $current->add(new DateInterval('P1D'));
                    continue;
                }

                // Ottieni disponibilità di tutti i partecipanti
                $availabilities = [];
                foreach ($participants as $userId) {
                    $availabilities[$userId] = $this->getUserAvailability($userId, $current);
                }

                // Trova intersezione degli slot liberi
                $commonSlots = $this->findCommonFreeSlots($availabilities, $duration);

                // Applica preferenze e scoring
                foreach ($commonSlots as $slot) {
                    $score = $this->calculateSlotScore($slot, $preferences);

                    if ($score > 0) {
                        $suggestions[] = [
                            'start' => $slot['start']->format('Y-m-d H:i:s'),
                            'end' => $slot['end']->format('Y-m-d H:i:s'),
                            'score' => $score,
                            'reasons' => $this->getSlotReasons($slot, $preferences),
                            'conflicts' => [],
                            'participants_available' => count($participants)
                        ];
                    }
                }

                $current->add(new DateInterval('P1D'));
            }

            // Ordina per score
            usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);

            // Limita risultati
            return array_slice($suggestions, 0, $preferences['max_suggestions'] ?? 10);

        } catch (Exception $e) {
            error_log("Errore suggestFreeSlots: " . $e->getMessage());
            throw new RuntimeException('Errore generazione suggerimenti');
        }
    }

    /**
     * Risolve conflitti con diverse strategie
     */
    public function resolveConflict(int $eventId, string $strategy, array $options = []): bool {
        try {
            $event = $this->getEventById($eventId);
            if (!$event) {
                throw new RuntimeException('Evento non trovato');
            }

            switch ($strategy) {
                case self::CONFLICT_STRATEGY_FORCE:
                    // Forza creazione ignorando conflitti
                    return $this->forceEventCreation($event, $options);

                case self::CONFLICT_STRATEGY_RESCHEDULE:
                    // Riprogramma automaticamente
                    $newSlot = $this->findNextAvailableSlot($event);
                    if ($newSlot) {
                        return $this->updateEvent($eventId, [
                            'start_date' => $newSlot['start']->format('Y-m-d H:i:s'),
                            'end_date' => $newSlot['end']->format('Y-m-d H:i:s'),
                            'check_conflicts' => false
                        ]);
                    }
                    break;

                case self::CONFLICT_STRATEGY_NOTIFY:
                    // Notifica e richiedi conferma
                    return $this->notifyConflictAndWait($event, $options);

                default:
                    throw new InvalidArgumentException('Strategia conflitto non valida');
            }

            return false;

        } catch (Exception $e) {
            error_log("Errore resolveConflict: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verifica disponibilità sala/risorsa
     */
    public function checkRoomAvailability(int $roomId, DateTime $start, DateTime $end): bool {
        try {
            $sql = "SELECT COUNT(*) as conflicts
                    FROM event_resources er
                    JOIN events e ON er.event_id = e.id
                    WHERE er.resource_id = :room_id
                      AND er.resource_type = 'room'
                      AND e.deleted_at IS NULL
                      AND e.status != 'cancelled'
                      AND e.start_date < :end_date
                      AND e.end_date > :start_date";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':room_id' => $roomId,
                ':start_date' => $start->format('Y-m-d H:i:s'),
                ':end_date' => $end->format('Y-m-d H:i:s')
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['conflicts'] == 0;

        } catch (Exception $e) {
            error_log("Errore checkRoomAvailability: " . $e->getMessage());
            throw new RuntimeException('Errore verifica disponibilità sala');
        }
    }

    /**
     * Invia promemoria email
     */
    public function sendEmailReminders(): int {
        $count = 0;

        try {
            // Trova promemoria da inviare
            $sql = "SELECT er.*, e.*, u.email, u.name
                    FROM event_reminders er
                    JOIN events e ON er.event_id = e.id
                    JOIN event_participants ep ON ep.event_id = e.id
                    JOIN users u ON ep.user_id = u.id
                    WHERE e.tenant_id = :tenant_id
                      AND e.deleted_at IS NULL
                      AND e.status = 'confirmed'
                      AND ep.status IN ('accepted', 'tentative')
                      AND er.type = 'email'
                      AND er.sent_at IS NULL
                      AND DATE_SUB(e.start_date, INTERVAL er.minutes_before MINUTE) <= NOW()";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':tenant_id' => $this->tenant_id]);

            $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($reminders as $reminder) {
                if ($this->sendReminderEmail($reminder)) {
                    // Marca come inviato
                    $updateStmt = $this->pdo->prepare(
                        "UPDATE event_reminders SET sent_at = NOW() WHERE id = :id"
                    );
                    $updateStmt->execute([':id' => $reminder['id']]);
                    $count++;
                }
            }

            return $count;

        } catch (Exception $e) {
            error_log("Errore sendEmailReminders: " . $e->getMessage());
            throw new RuntimeException('Errore invio promemoria');
        }
    }

    /**
     * Crea notifica in-app
     */
    public function createInAppNotification(int $userId, array $event, string $type): bool {
        try {
            $messages = [
                self::NOTIFICATION_INVITE => 'Sei stato invitato all\'evento: ' . ($event['title'] ?? ''),
                self::NOTIFICATION_REMINDER => 'Promemoria evento: ' . ($event['title'] ?? ''),
                self::NOTIFICATION_UPDATE => 'L\'evento è stato modificato: ' . ($event['title'] ?? ''),
                self::NOTIFICATION_CANCEL => 'L\'evento è stato cancellato: ' . ($event['title'] ?? '')
            ];

            $sql = "INSERT INTO notifications (
                        tenant_id, user_id, type, title, message,
                        data, priority, created_at
                    ) VALUES (
                        :tenant_id, :user_id, :type, :title, :message,
                        :data, :priority, NOW()
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $this->tenant_id,
                ':user_id' => $userId,
                ':type' => 'calendar_' . $type,
                ':title' => 'Notifica Calendario',
                ':message' => $messages[$type] ?? 'Notifica evento',
                ':data' => json_encode($event),
                ':priority' => $type === self::NOTIFICATION_CANCEL ? 'high' : 'normal'
            ]);

            return true;

        } catch (Exception $e) {
            error_log("Errore createInAppNotification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Processa promemoria (per cron job)
     */
    public function processReminders(): array {
        $results = [
            'email_sent' => 0,
            'app_notifications' => 0,
            'sms_sent' => 0,
            'errors' => []
        ];

        try {
            // Invia promemoria email
            $results['email_sent'] = $this->sendEmailReminders();

            // Crea notifiche in-app
            $results['app_notifications'] = $this->createAppReminders();

            // Invia SMS se configurato
            if ($this->isSmsEnabled()) {
                $results['sms_sent'] = $this->sendSmsReminders();
            }

            // Pulisci promemoria vecchi
            $this->cleanOldReminders();

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            error_log("Errore processReminders: " . $e->getMessage());
        }

        return $results;
    }

    /**
     * Invia invito calendario (formato iCal)
     */
    public function sendInvitation(array $event, array $participant): bool {
        try {
            // Genera iCal
            $ical = $this->generateICalendar([$event], 'REQUEST');

            // Prepara email
            $to = $participant['email'];
            $subject = 'Invito: ' . $event['title'];

            $message = "Sei stato invitato all'evento:\n\n";
            $message .= "Titolo: {$event['title']}\n";
            $message .= "Data: " . date('d/m/Y H:i', strtotime($event['start_date'])) . "\n";
            $message .= "Luogo: " . ($event['location'] ?? 'Da definire') . "\n\n";
            $message .= "Descrizione:\n{$event['description']}\n\n";
            $message .= "Accetta o rifiuta l'invito dal tuo calendario.";

            // Headers per allegato iCal
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: multipart/mixed; boundary="boundary123"',
                'From: noreply@' . $_SERVER['HTTP_HOST'],
                'Reply-To: ' . ($event['creator_email'] ?? 'noreply@' . $_SERVER['HTTP_HOST'])
            ];

            // Corpo email con allegato
            $body = "--boundary123\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
            $body .= $message . "\r\n\r\n";
            $body .= "--boundary123\r\n";
            $body .= "Content-Type: text/calendar; charset=UTF-8; method=REQUEST\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n";
            $body .= "Content-Disposition: attachment; filename=\"invite.ics\"\r\n\r\n";
            $body .= $ical . "\r\n";
            $body .= "--boundary123--";

            // Invia email
            return mail($to, $subject, $body, implode("\r\n", $headers));

        } catch (Exception $e) {
            error_log("Errore sendInvitation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia notifica cancellazione
     */
    public function sendCancellation(array $event, array $participants): bool {
        try {
            foreach ($participants as $userId) {
                // Notifica in-app
                $this->createInAppNotification($userId, $event, self::NOTIFICATION_CANCEL);

                // Email cancellazione
                $user = $this->getUserById($userId);
                if ($user && $user['email']) {
                    $this->sendCancellationEmail($event, $user);
                }
            }

            return true;

        } catch (Exception $e) {
            error_log("Errore sendCancellation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Esporta eventi in formato iCalendar
     */
    public function exportToICS(array $events): string {
        return $this->generateICalendar($events, 'PUBLISH');
    }

    /**
     * Importa eventi da file iCalendar
     */
    public function importFromICS(string $icsData): array {
        $imported = [];
        $errors = [];

        try {
            // Parse iCal data
            $lines = explode("\n", $icsData);
            $events = [];
            $currentEvent = null;

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === 'BEGIN:VEVENT') {
                    $currentEvent = [];
                } elseif ($line === 'END:VEVENT' && $currentEvent !== null) {
                    $events[] = $currentEvent;
                    $currentEvent = null;
                } elseif ($currentEvent !== null && strpos($line, ':') !== false) {
                    list($key, $value) = explode(':', $line, 2);
                    $currentEvent[$key] = $value;
                }
            }

            // Importa eventi
            foreach ($events as $icalEvent) {
                try {
                    $eventData = $this->parseICalEvent($icalEvent);
                    $eventId = $this->createEvent($eventData);
                    $imported[] = $eventId;
                } catch (Exception $e) {
                    $errors[] = [
                        'event' => $icalEvent['SUMMARY'] ?? 'Unknown',
                        'error' => $e->getMessage()
                    ];
                }
            }

        } catch (Exception $e) {
            error_log("Errore importFromICS: " . $e->getMessage());
            throw new RuntimeException('Errore importazione calendario');
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
            'total' => count($imported) + count($errors)
        ];
    }

    /**
     * Sincronizza con provider esterni (Google/Outlook)
     */
    public function syncWithExternal(string $provider, array $credentials): array {
        $results = [
            'imported' => 0,
            'updated' => 0,
            'errors' => []
        ];

        try {
            switch ($provider) {
                case 'google':
                    $results = $this->syncWithGoogle($credentials);
                    break;

                case 'outlook':
                    $results = $this->syncWithOutlook($credentials);
                    break;

                case 'caldav':
                    $results = $this->syncWithCalDAV($credentials);
                    break;

                default:
                    throw new InvalidArgumentException('Provider non supportato: ' . $provider);
            }

            // Log sincronizzazione
            $this->logActivity('calendar_sync', null, [
                'provider' => $provider,
                'results' => $results
            ]);

        } catch (Exception $e) {
            error_log("Errore syncWithExternal: " . $e->getMessage());
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Ottieni calendario aggregato del team
     */
    public function getTeamCalendar(int $teamId, DateTime $start, DateTime $end): array {
        try {
            // Ottieni membri del team
            $sql = "SELECT user_id FROM team_members
                    WHERE team_id = :team_id AND status = 'active'";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':team_id' => $teamId]);
            $members = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($members)) {
                return [];
            }

            // Ottieni eventi di tutti i membri
            $allEvents = [];
            foreach ($members as $userId) {
                $events = $this->getEventsBetween($start, $end, ['user_id' => $userId]);

                // Aggiungi info membro
                foreach ($events as &$event) {
                    $event['team_member_id'] = $userId;
                    $event['team_member_name'] = $this->getUserName($userId);
                }

                $allEvents = array_merge($allEvents, $events);
            }

            // Ordina per data
            usort($allEvents, fn($a, $b) => $a['start_date'] <=> $b['start_date']);

            // Aggrega statistiche
            $stats = [
                'total_events' => count($allEvents),
                'total_hours' => $this->calculateTotalHours($allEvents),
                'busiest_day' => $this->findBusiestDay($allEvents),
                'member_stats' => $this->calculateMemberStats($allEvents, $members)
            ];

            return [
                'events' => $allEvents,
                'members' => $members,
                'stats' => $stats
            ];

        } catch (Exception $e) {
            error_log("Errore getTeamCalendar: " . $e->getMessage());
            throw new RuntimeException('Errore recupero calendario team');
        }
    }

    /**
     * Ottieni festività pubbliche
     */
    public function getPublicHolidays(string $country, int $year): array {
        $cacheKey = "holidays_{$country}_{$year}";

        // Controlla cache
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        try {
            // Database festività locale
            $sql = "SELECT * FROM public_holidays
                    WHERE country = :country
                      AND YEAR(date) = :year
                    ORDER BY date ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':country' => strtoupper($country),
                ':year' => $year
            ]);

            $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Se vuoto, prova API esterna
            if (empty($holidays)) {
                $holidays = $this->fetchHolidaysFromAPI($country, $year);

                // Salva in database per cache futura
                if (!empty($holidays)) {
                    $this->saveHolidaysToDatabase($holidays, $country);
                }
            }

            // Formatta per calendario
            $formattedHolidays = [];
            foreach ($holidays as $holiday) {
                $formattedHolidays[] = [
                    'date' => $holiday['date'],
                    'name' => $holiday['name'],
                    'type' => $holiday['type'] ?? 'public',
                    'country' => $country,
                    'is_nationwide' => $holiday['is_nationwide'] ?? true
                ];
            }

            // Cache risultati
            $this->cache[$cacheKey] = $formattedHolidays;

            return $formattedHolidays;

        } catch (Exception $e) {
            error_log("Errore getPublicHolidays: " . $e->getMessage());
            return [];
        }
    }

    // ===== METODI HELPER PRIVATI =====

    /**
     * Espande evento ricorrente in istanze
     */
    private function expandRecurringEvent(array $event, DateTime $rangeStart, DateTime $rangeEnd): array {
        $instances = [];
        $rules = $this->parseRecurrenceRule($event['recurrence_rule']);

        $eventStart = new DateTime($event['start_date']);
        $eventEnd = new DateTime($event['end_date']);
        $duration = $eventStart->diff($eventEnd);

        // Determina fine ricorrenza
        $recurrenceEnd = $event['recurrence_end']
            ? new DateTime($event['recurrence_end'])
            : (clone $rangeEnd)->add(new DateInterval('P1Y'));

        if (isset($rules['UNTIL'])) {
            $recurrenceEnd = min($recurrenceEnd, $rules['UNTIL']);
        }

        $current = clone $eventStart;
        $count = 0;
        $maxCount = $rules['COUNT'] ?? PHP_INT_MAX;

        while ($current <= $recurrenceEnd && $current <= $rangeEnd && $count < $maxCount) {
            // Calcola prossima occorrenza
            $instanceEnd = clone $current;
            $instanceEnd->add($duration);

            // Verifica se l'istanza è nel range richiesto
            if ($instanceEnd >= $rangeStart && $current <= $rangeEnd) {
                // Verifica eccezioni
                if (!$this->isExceptionDate($event['id'], $current)) {
                    $instance = $event;
                    $instance['id'] = $event['id'] . '_' . $current->format('Ymd');
                    $instance['start_date'] = $current->format('Y-m-d H:i:s');
                    $instance['end_date'] = $instanceEnd->format('Y-m-d H:i:s');
                    $instance['is_recurring_instance'] = true;
                    $instance['parent_event_id'] = $event['id'];

                    $instances[] = $this->formatEvent($instance);
                }
            }

            // Calcola prossima data secondo regola
            $current = $this->getNextOccurrence($current, $rules);
            $count++;

            // Protezione loop infinito
            if ($count > 1000) {
                error_log("Loop infinito in expandRecurringEvent per evento {$event['id']}");
                break;
            }
        }

        return $instances;
    }

    /**
     * Calcola prossima occorrenza secondo regole ricorrenza
     */
    private function getNextOccurrence(DateTime $current, array $rules): DateTime {
        $next = clone $current;
        $interval = $rules['INTERVAL'] ?? 1;

        switch ($rules['FREQ']) {
            case self::FREQ_DAILY:
                $next->add(new DateInterval("P{$interval}D"));
                break;

            case self::FREQ_WEEKLY:
                if (isset($rules['BYDAY'])) {
                    // Trova prossimo giorno della settimana
                    $next = $this->getNextWeekday($next, $rules['BYDAY'], $interval);
                } else {
                    $next->add(new DateInterval("P" . ($interval * 7) . "D"));
                }
                break;

            case self::FREQ_MONTHLY:
                if (isset($rules['BYMONTHDAY'])) {
                    $next = $this->getNextMonthDay($next, $rules['BYMONTHDAY'], $interval);
                } else {
                    $next->add(new DateInterval("P{$interval}M"));
                }
                break;

            case self::FREQ_YEARLY:
                $next->add(new DateInterval("P{$interval}Y"));
                break;
        }

        return $next;
    }

    /**
     * Formatta evento per output
     */
    private function formatEvent(array $event): array {
        return [
            'id' => $event['id'],
            'title' => $event['title'],
            'description' => $event['description'],
            'location' => $event['location'],
            'start_date' => $event['start_date'],
            'end_date' => $event['end_date'],
            'all_day' => (bool) $event['all_day'],
            'category' => $event['category'],
            'color' => $event['color'],
            'status' => $event['status'],
            'visibility' => $event['visibility'],
            'creator' => [
                'id' => $event['created_by'],
                'name' => $event['creator_name'] ?? null,
                'email' => $event['creator_email'] ?? null
            ],
            'participants' => $event['participants'] ?
                array_map('intval', explode(',', $event['participants'])) : [],
            'reminders' => $event['reminders'] ?
                $this->parseReminders($event['reminders']) : [],
            'is_recurring' => !empty($event['recurrence_rule']),
            'recurrence_rule' => $event['recurrence_rule'],
            'metadata' => $event['metadata'] ?
                json_decode($event['metadata'], true) : null
        ];
    }

    /**
     * Genera contenuto iCalendar
     */
    private function generateICalendar(array $events, string $method = 'PUBLISH'): string {
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//CollaboraNexio//Calendar//IT\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:{$method}\r\n";

        foreach ($events as $event) {
            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "UID:" . md5($event['id'] . '@collaboranexio') . "\r\n";
            $ical .= "DTSTART:" . date('Ymd\THis', strtotime($event['start_date'])) . "\r\n";
            $ical .= "DTEND:" . date('Ymd\THis', strtotime($event['end_date'])) . "\r\n";
            $ical .= "SUMMARY:" . $this->escapeICalText($event['title']) . "\r\n";

            if ($event['description']) {
                $ical .= "DESCRIPTION:" . $this->escapeICalText($event['description']) . "\r\n";
            }

            if ($event['location']) {
                $ical .= "LOCATION:" . $this->escapeICalText($event['location']) . "\r\n";
            }

            if ($event['recurrence_rule']) {
                $ical .= "RRULE:" . $event['recurrence_rule'] . "\r\n";
            }

            $ical .= "STATUS:" . strtoupper($event['status'] ?? 'CONFIRMED') . "\r\n";
            $ical .= "TRANSP:" . ($event['all_day'] ? 'TRANSPARENT' : 'OPAQUE') . "\r\n";
            $ical .= "END:VEVENT\r\n";
        }

        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Escape testo per iCal
     */
    private function escapeICalText(string $text): string {
        $text = str_replace("\\", "\\\\", $text);
        $text = str_replace(",", "\\,", $text);
        $text = str_replace(";", "\\;", $text);
        $text = str_replace("\n", "\\n", $text);
        return $text;
    }

    /**
     * Valida dati evento
     */
    private function validateEventData(array &$data): void {
        // Campi obbligatori
        if (empty($data['title'])) {
            throw new InvalidArgumentException('Titolo evento obbligatorio');
        }

        if (empty($data['start_date'])) {
            throw new InvalidArgumentException('Data inizio obbligatoria');
        }

        if (empty($data['end_date'])) {
            throw new InvalidArgumentException('Data fine obbligatoria');
        }

        // Valida date
        $start = new DateTime($data['start_date']);
        $end = new DateTime($data['end_date']);

        if ($end < $start) {
            throw new InvalidArgumentException('Data fine deve essere dopo data inizio');
        }

        // Sanitizza input
        $data['title'] = htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8');

        if (isset($data['description'])) {
            $data['description'] = htmlspecialchars($data['description'], ENT_QUOTES, 'UTF-8');
        }

        if (isset($data['location'])) {
            $data['location'] = htmlspecialchars($data['location'], ENT_QUOTES, 'UTF-8');
        }

        // Valida ricorrenza se presente
        if (!empty($data['recurrence_rule'])) {
            $this->parseRecurrenceRule($data['recurrence_rule']); // Throws on invalid
        }

        // Valida colore
        if (isset($data['color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
            $data['color'] = '#3788d8';
        }

        // Valida categoria
        $validCategories = ['general', 'meeting', 'task', 'reminder', 'holiday', 'other'];
        if (isset($data['category']) && !in_array($data['category'], $validCategories)) {
            $data['category'] = 'general';
        }

        // Valida visibilità
        $validVisibility = ['private', 'public', 'team'];
        if (isset($data['visibility']) && !in_array($data['visibility'], $validVisibility)) {
            $data['visibility'] = 'private';
        }
    }

    /**
     * Verifica se utente può modificare evento
     */
    private function canModifyEvent(int $eventId): bool {
        if (!$this->user_id) {
            return false;
        }

        $sql = "SELECT created_by FROM events
                WHERE id = :id AND tenant_id = :tenant_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $eventId,
            ':tenant_id' => $this->tenant_id
        ]);

        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            return false;
        }

        // Proprietario o admin
        return $event['created_by'] == $this->user_id || $this->isAdmin();
    }

    /**
     * Verifica se utente è admin
     */
    private function isAdmin(): bool {
        if (!$this->user_id) {
            return false;
        }

        $sql = "SELECT role FROM users
                WHERE id = :id AND tenant_id = :tenant_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $this->user_id,
            ':tenant_id' => $this->tenant_id
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user && in_array($user['role'], ['admin', 'super_admin']);
    }

    /**
     * Log attività
     */
    private function logActivity(string $type, ?int $entityId, array $data): void {
        try {
            $sql = "INSERT INTO activity_logs (
                        tenant_id, user_id, type, entity_type, entity_id,
                        data, ip_address, user_agent, created_at
                    ) VALUES (
                        :tenant_id, :user_id, :type, 'event', :entity_id,
                        :data, :ip, :ua, NOW()
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $this->tenant_id,
                ':user_id' => $this->user_id,
                ':type' => $type,
                ':entity_id' => $entityId,
                ':data' => json_encode($data),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Errore log attività: " . $e->getMessage());
        }
    }

    /**
     * Invalida cache
     */
    private function invalidateCache(): void {
        $this->cache = [];
    }

    /**
     * Helper per ottenere evento by ID
     */
    private function getEventById(int $id, bool $includeDeleted = false): ?array {
        $sql = "SELECT * FROM events
                WHERE id = :id AND tenant_id = :tenant_id";

        if (!$includeDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $this->tenant_id
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Helper per verificare esistenza utente
     */
    private function userExists(int $userId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM users WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute([
            ':id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Helper per verificare sovrapposizione eventi
     */
    private function eventsOverlap(
        DateTime $start1, DateTime $end1,
        DateTime $start2, DateTime $end2
    ): bool {
        return $start1 < $end2 && $end1 > $start2;
    }
}
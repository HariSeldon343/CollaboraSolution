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
require_once __DIR__ . '/mailer.php'; // Helper email centralizzato

class Calendar {
    private PDO $pdo;
    private int $tenant_id;
    private ?int $user_id;
    private array $cache = [];
    private ?array $eventsColumnsCache = null;
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
     * Detect events table columns at runtime (schema may differ across environments).
     */
    public function eventsHasColumn(string $column): bool {
        if ($this->eventsColumnsCache === null) {
            $cols = [];
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COLUMN_NAME
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'events'"
                );
                $stmt->execute();
                $cols = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Exception $e) {
                $cols = [];
            }
            $this->eventsColumnsCache = array_fill_keys(array_map('strval', $cols), true);
        }

        return isset($this->eventsColumnsCache[$column]);
    }

    /**
     * Generic column checker for any table (cached per table)
     */
    private array $tableColumnsCache = [];
    private function tableHasColumn(string $table, string $column): bool {
        $tableKey = strtolower($table);
        if (!isset($this->tableColumnsCache[$tableKey])) {
            $cols = [];
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COLUMN_NAME
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table"
                );
                $stmt->execute([':table' => $table]);
                $cols = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Exception $e) {
                $cols = [];
            }
            $this->tableColumnsCache[$tableKey] = array_fill_keys(array_map('strval', $cols), true);
        }

        return isset($this->tableColumnsCache[$tableKey][$column]);
    }

    private function getTenantName(): string {
        $cacheKey = 'tenant_name:' . $this->tenant_id;
        if (isset($this->cache[$cacheKey]) && ($this->cache[$cacheKey]['expires'] ?? 0) > time()) {
            return (string)($this->cache[$cacheKey]['value'] ?? '');
        }

        $name = '';
        try {
            $stmt = $this->pdo->prepare("SELECT name FROM tenants WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $this->tenant_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $name = (string)($row['name'] ?? '');
        } catch (Exception $e) {
            $name = '';
        }

        $this->cache[$cacheKey] = [
            'value' => $name,
            'expires' => time() + self::CACHE_TTL
        ];

        return $name;
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
            // Ensure cancelled/deleted participants do not reappear when reloading events.
            $participantConditions = [];
            if ($this->tableHasColumn('event_participants', 'deleted_at')) {
                $participantConditions[] = "ep.deleted_at IS NULL";
            }
            if ($this->tableHasColumn('event_participants', 'status')) {
                $participantConditions[] = "(ep.status IS NULL OR ep.status <> 'cancelled')";
            }
            $participantWhere = '';
            if (!empty($participantConditions)) {
                $participantWhere = " AND " . implode(" AND ", $participantConditions);
            }

            // BUG-105 FIX: Use ONLY positional parameters (?) to avoid PDO mixed parameters error
            // Query principale per eventi singoli e ricorrenti
            $sql = "SELECT e.*,
                           u.name as organizer_name,
                           u.email as organizer_email,
                           (SELECT GROUP_CONCAT(ep.user_id)
                            FROM event_participants ep
                            WHERE ep.event_id = e.id{$participantWhere}) as participants,
                           (SELECT GROUP_CONCAT(CONCAT(er.type, ':', er.minutes_before))
                            FROM event_reminders er
                            WHERE er.event_id = e.id) as reminders
                    FROM events e
                    LEFT JOIN users u ON e.organizer_id = u.id
                    WHERE e.tenant_id = ?
                      AND e.deleted_at IS NULL
                      AND (
                          -- Eventi singoli nell'intervallo
                          (e.recurrence_rule IS NULL AND
                           e.start_datetime <= ? AND e.end_datetime >= ?)
                          OR
                          -- Eventi ricorrenti attivi nell'intervallo
                          (e.recurrence_rule IS NOT NULL AND
                           e.start_datetime <= ? AND
                           (e.recurrence_end IS NULL OR e.recurrence_end >= ?))
                      )";

            // Use positional parameters array (order matters!)
            $params = [
                $this->tenant_id,                 // ? for tenant_id (1st)
                $end->format('Y-m-d H:i:s'),      // ? for end_datetime (2nd)
                $start->format('Y-m-d H:i:s'),    // ? for start_datetime (3rd)
                $end->format('Y-m-d H:i:s'),      // ? for end_datetime recurrence (4th)
                $start->format('Y-m-d H:i:s')     // ? for start_datetime recurrence (5th)
            ];

            // Applica filtri opzionali
            $filterCategory = null;
            if ($filters) {
                // BUG-105 FIX: Support calendar_id(s) filtering
                if (isset($filters['calendar_ids']) && is_array($filters['calendar_ids']) && !empty($filters['calendar_ids'])) {
                    // Multi-calendar filtering with IN clause
                    $placeholders = implode(',', array_fill(0, count($filters['calendar_ids']), '?'));
                    $sql .= " AND e.calendar_id IN ($placeholders)";
                    // Add parameters to params array (append to existing params)
                    foreach ($filters['calendar_ids'] as $calId) {
                        $params[] = $calId;
                    }
                } elseif (isset($filters['calendar_id'])) {
                    // Single calendar filtering (backward compatibility)
                    $sql .= " AND e.calendar_id = ?";
                    $params[] = $filters['calendar_id'];
                }

                if (isset($filters['user_id'])) {
                    $sql .= " AND (e.organizer_id = ? OR
                                   EXISTS (SELECT 1 FROM event_participants ep
                                         WHERE ep.event_id = e.id AND ep.user_id = ?{$participantWhere}))";
                    $params[] = $filters['user_id'];
                    $params[] = $filters['user_id'];  // Appears twice in EXISTS subquery
                }

                if (isset($filters['category'])) {
                    // Some DBs do not have events.category; filter later using metadata fallback.
                    $filterCategory = (string)$filters['category'];
                    if ($this->eventsHasColumn('category')) {
                        $sql .= " AND e.category = ?";
                        $params[] = $filterCategory;
                        $filterCategory = null; // already applied in SQL
                    }
                }

                if (isset($filters['location'])) {
                    $sql .= " AND e.location = ?";
                    $params[] = $filters['location'];
                }
            }

            $sql .= " ORDER BY e.start_datetime ASC";

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

            // Apply category filter if schema lacks events.category
            if ($filterCategory !== null && $filterCategory !== '') {
                $expandedEvents = array_values(array_filter($expandedEvents, function($e) use ($filterCategory) {
                    return (string)($e['category'] ?? '') === $filterCategory;
                }));
            }

            // Ordina per data inizio
            usort($expandedEvents, function($a, $b) {
                return $a['start_datetime'] <=> $b['start_datetime'];
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
                    new DateTime($data['start_datetime']),
                    new DateTime($data['end_datetime']),
                    $data['participants'] ?? []
                );

                if (!empty($conflicts)) {
                    throw new RuntimeException('Conflitti rilevati: ' . json_encode($conflicts));
                }
            }

            // Inserisci evento principale (schema-aware: some DBs don't have events.category)
            $metadata = null;
            if (isset($data['metadata'])) {
                $metadata = is_array($data['metadata']) ? $data['metadata'] : $data['metadata'];
            }
            // Persist fields into metadata if the corresponding column doesn't exist
            if (!$this->eventsHasColumn('category') && isset($data['category'])) {
                $metaArr = [];
                if (is_array($metadata)) {
                    $metaArr = $metadata;
                } elseif (is_string($metadata) && $metadata !== '') {
                    $decoded = json_decode($metadata, true);
                    if (is_array($decoded)) $metaArr = $decoded;
                }
                $metaArr['category'] = $data['category'];
                $metadata = $metaArr;
            }
            if (!$this->eventsHasColumn('timezone') && isset($data['timezone'])) {
                $metaArr = [];
                if (is_array($metadata)) {
                    $metaArr = $metadata;
                } elseif (is_string($metadata) && $metadata !== '') {
                    $decoded = json_decode($metadata, true);
                    if (is_array($decoded)) $metaArr = $decoded;
                }
                $metaArr['timezone'] = $data['timezone'];
                $metadata = $metaArr;
            }
            if (!$this->eventsHasColumn('visibility') && isset($data['visibility'])) {
                $metaArr = [];
                if (is_array($metadata)) {
                    $metaArr = $metadata;
                } elseif (is_string($metadata) && $metadata !== '') {
                    $decoded = json_decode($metadata, true);
                    if (is_array($decoded)) $metaArr = $decoded;
                }
                $metaArr['visibility'] = $data['visibility'];
                $metadata = $metaArr;
            }
            if (!$this->eventsHasColumn('status') && isset($data['status'])) {
                $metaArr = [];
                if (is_array($metadata)) {
                    $metaArr = $metadata;
                } elseif (is_string($metadata) && $metadata !== '') {
                    $decoded = json_decode($metadata, true);
                    if (is_array($decoded)) $metaArr = $decoded;
                }
                $metaArr['status'] = $data['status'];
                $metadata = $metaArr;
            }

            // Build INSERT columns list based on actual schema
            $columns = ['tenant_id', 'title', 'start_datetime', 'end_datetime'];
            $optionalColumns = [
                'description', 'location', 'all_day', 'color',
                'recurrence_rule', 'recurrence_end',
                'timezone', 'organizer_id', 'visibility', 'status', 'metadata', 'calendar_id', 'category'
            ];
            foreach ($optionalColumns as $col) {
                if ($this->eventsHasColumn($col)) {
                    $columns[] = $col;
                }
            }

            $placeholders = array_map(fn($c) => ':' . $c, $columns);
            $sql = "INSERT INTO events (" . implode(', ', $columns) . ")
                    VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $this->pdo->prepare($sql);

            $params = [
                ':tenant_id' => $this->tenant_id,
                ':title' => $data['title'],
                ':start_datetime' => $data['start_datetime'],
                ':end_datetime' => $data['end_datetime'],
                ':color' => $data['color'] ?? '#3788d8',
                ':recurrence_rule' => $data['recurrence_rule'] ?? null,
                ':recurrence_end' => $data['recurrence_end'] ?? null,
                ':timezone' => $data['timezone'] ?? date_default_timezone_get(),
                ':organizer_id' => $this->user_id,
                ':visibility' => $data['visibility'] ?? 'private',
                ':status' => $data['status'] ?? 'confirmed',
                ':metadata' => $metadata !== null ? (is_array($metadata) ? json_encode($metadata) : $metadata) : null,
                ':description' => $data['description'] ?? null,
                ':location' => $data['location'] ?? null,
                ':all_day' => $data['all_day'] ?? false,
                ':calendar_id' => $data['calendar_id'] ?? null
            ];
            if ($this->eventsHasColumn('category')) $params[':category'] = $data['category'] ?? 'general';

            // IMPORTANT: execute() must receive ONLY parameters that exist in the SQL placeholders (HY093 fix)
            $execParams = [];
            foreach ($columns as $col) {
                $ph = ':' . $col;
                if (array_key_exists($ph, $params)) {
                    $execParams[$ph] = $params[$ph];
                } else {
                    $execParams[$ph] = null;
                }
            }

            $stmt->execute($execParams);

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
            if (isset($data['start_datetime']) || isset($data['end_datetime'])) {
                $startDate = new DateTime($data['start_datetime'] ?? $existingEvent['start_datetime']);
                $endDate = new DateTime($data['end_datetime'] ?? $existingEvent['end_datetime']);

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

            // Detect meaningful changes for notification gating (avoid "update" emails on no-op saves)
            $hasParticipantsPayload = array_key_exists('participants', $data);
            $hasRemindersPayload = array_key_exists('reminders', $data);

            $participantsChanged = false;
            if ($hasParticipantsPayload) {
                $incomingIds = array_values(array_unique(array_map('intval', (array)$data['participants'])));
                sort($incomingIds);

                $epHasTenant = $this->tableHasColumn('event_participants', 'tenant_id');
                $epHasDeletedAt = $this->tableHasColumn('event_participants', 'deleted_at');
                $sql = "SELECT user_id FROM event_participants WHERE event_id = :event_id";
                $paramsP = [':event_id' => $id];
                if ($epHasTenant) {
                    $sql .= " AND tenant_id = :tenant_id";
                    $paramsP[':tenant_id'] = $this->tenant_id;
                }
                if ($epHasDeletedAt) {
                    $sql .= " AND deleted_at IS NULL";
                }

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($paramsP);
                $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                $existingIds = array_values(array_unique($existingIds));
                sort($existingIds);

                $participantsChanged = ($incomingIds !== $existingIds);
            }

            $allowedFields = [
                'title', 'description', 'location', 'start_datetime', 'end_datetime',
                'all_day', 'color', 'recurrence_rule', 'recurrence_end',
                'visibility', 'status'
            ];
            // Only include metadata if the column exists in this DB schema
            $hasMetadataCol = $this->eventsHasColumn('metadata');
            if ($hasMetadataCol) {
                $allowedFields[] = 'metadata';
            }
            if ($this->eventsHasColumn('timezone')) {
                $allowedFields[] = 'timezone';
            } elseif (array_key_exists('timezone', $data)) {
                // Persist into metadata only if metadata column exists; otherwise ignore.
                if ($hasMetadataCol) {
                    $existingMeta = $existingEvent['metadata'] ?? null;
                    $metaArr = [];
                    if (is_array($existingMeta)) {
                        $metaArr = $existingMeta;
                    } elseif (is_string($existingMeta) && $existingMeta !== '') {
                        $decoded = json_decode($existingMeta, true);
                        if (is_array($decoded)) $metaArr = $decoded;
                    }
                    $metaArr['timezone'] = $data['timezone'];
                    $data['metadata'] = $metaArr;
                }
            }
            if ($this->eventsHasColumn('category')) {
                $allowedFields[] = 'category';
            } elseif (array_key_exists('category', $data)) {
                // If column doesn't exist, persist category in metadata only if metadata exists.
                if ($hasMetadataCol) {
                    $existingMeta = $existingEvent['metadata'] ?? null;
                    $metaArr = [];
                    if (is_array($existingMeta)) {
                        $metaArr = $existingMeta;
                    } elseif (is_string($existingMeta) && $existingMeta !== '') {
                        $decoded = json_decode($existingMeta, true);
                        if (is_array($decoded)) $metaArr = $decoded;
                    }
                    $metaArr['category'] = $data['category'];
                    $data['metadata'] = $metaArr;
                }
            }

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[] = "$field = :$field";
                    $params[":$field"] = $field === 'metadata' && is_array($data[$field])
                        ? json_encode($data[$field])
                        : $data[$field];
                }
            }

            $shouldNotify = (!empty($updates)) || ($hasParticipantsPayload && $participantsChanged) || $hasRemindersPayload;

            // IMPORTANT: Allow participant/reminder-only updates (no event fields changed)
            if (empty($updates)) {
                if ($hasParticipantsPayload) {
                    $this->updateParticipants($id, (array)$data['participants']);
                }
                if ($hasRemindersPayload) {
                    $this->updateReminders($id, (array)$data['reminders']);
                }

                // Log attività
                $this->logActivity('event_updated', $id, [
                    'changes' => array_keys($data),
                    'old_values' => array_intersect_key($existingEvent, $data)
                ]);

                $this->pdo->commit();

                // Notifica partecipanti delle modifiche (solo se c'è stato un cambio effettivo)
                if ($shouldNotify) {
                    $this->scheduleNotifications($id, self::NOTIFICATION_UPDATE);
                }

                // Invalida cache
                $this->invalidateCache();

                return true;
            }

            // Aggiorna evento
            $sql = "UPDATE events SET " . implode(', ', $updates);
            if ($this->eventsHasColumn('updated_at')) {
                $sql .= ", updated_at = NOW()";
            }
            $sql .= " WHERE id = :id AND tenant_id = :tenant_id";

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
            if ($shouldNotify) {
                $this->scheduleNotifications($id, self::NOTIFICATION_UPDATE);
            }

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
            // Determine if tenant_id column exists to satisfy FK
            $epHasTenant = $this->tableHasColumn('event_participants', 'tenant_id');

            $columns = ['event_id', 'user_id', 'status', 'invited_at'];
            if ($epHasTenant) {
                $columns[] = 'tenant_id';
            }

            $insertCols = implode(', ', $columns);
            $insertVals = implode(', ', array_map(fn($c) => ':' . $c, $columns));

            $sql = "INSERT INTO event_participants ({$insertCols})
                    VALUES ({$insertVals})
                    ON DUPLICATE KEY UPDATE
                    status = IF(status = 'declined', 'pending', status),
                    invited_at = NOW()";

            $stmt = $this->pdo->prepare($sql);
            $invitedUsers = [];  // Track successfully invited users

            foreach ($userIds as $userId) {
                // Valida esistenza utente
                if (!$this->userExists($userId)) {
                    error_log("[RBAC] User {$userId} does not exist, skipping invitation");
                    continue;
                }

                // RBAC validation - check if current user can invite this target user
                if (!$this->canInviteUser($userId)) {
                    error_log("[RBAC] User {$this->user_id} cannot invite user {$userId}, skipping (RBAC blocked)");
                    continue;  // Skip this user - RBAC restriction
                }

                $params = [
                    ':event_id' => $eventId,
                    ':user_id' => $userId,
                    ':status' => 'pending',
                    ':invited_at' => date('Y-m-d H:i:s')
                ];
                if ($epHasTenant) {
                    $params[':tenant_id'] = $this->tenant_id;
                }

                $stmt->execute($params);

                $invitedUsers[] = $userId;  // Track successful invitation

                // Crea notifica in-app
                $this->createInAppNotification($userId, ['event_id' => $eventId], self::NOTIFICATION_INVITE);
            }

            // Invia inviti email solo agli utenti effettivamente invitati
            if (!empty($invitedUsers)) {
                $this->scheduleEmailInvitations($eventId, $invitedUsers);
                error_log("[RBAC] Successfully invited " . count($invitedUsers) . " users to event {$eventId}");
                $this->logActivity('event_invited', $eventId, [
                    'invited_users' => array_values($invitedUsers),
                    'count' => count($invitedUsers)
                ]);
            } else {
                error_log("[RBAC] No users were invited to event {$eventId} (all blocked by RBAC or invalid)");
            }

            return true;

        } catch (Exception $e) {
            error_log("Errore invito partecipanti: " . $e->getMessage());
            throw new RuntimeException('Errore durante invito partecipanti');
        }
    }

    /**
     * Aggiorna la lista partecipanti (aggiunge nuovi, annulla rimossi)
     */
    private function updateParticipants(int $eventId, array $userIds): bool {
        // Normalizza lista
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        // Se lista vuota -> annulla tutti
        $epHasTenant = $this->tableHasColumn('event_participants', 'tenant_id');
        $paramsBase = [':event_id' => $eventId];
        if ($epHasTenant) {
            $paramsBase[':tenant_id'] = $this->tenant_id;
        }

        // Ottieni partecipanti attuali
        $sqlExisting = "SELECT user_id FROM event_participants WHERE event_id = :event_id";
        if ($epHasTenant) {
            $sqlExisting .= " AND tenant_id = :tenant_id";
        }
        $stmt = $this->pdo->prepare($sqlExisting);
        $stmt->execute($paramsBase);
        $existing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $toAdd = array_diff($userIds, $existing);
        $toRemove = array_diff($existing, $userIds);

        // Aggiungi nuovi (riusa inviteParticipants per RBAC e notifiche)
        if (!empty($toAdd)) {
            $this->inviteParticipants($eventId, $toAdd);
        }

        // Rimuovi/annulla quelli non più presenti
        if (!empty($toRemove)) {
            if ($this->tableHasColumn('event_participants', 'status')) {
                $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
                $sql = "UPDATE event_participants
                        SET status = 'cancelled'
                        WHERE event_id = ?
                          AND user_id IN ({$placeholders})";
                if ($epHasTenant) {
                    $sql .= " AND tenant_id = ?";
                }
                $stmt = $this->pdo->prepare($sql);
                $execParams = array_merge([$eventId], array_values($toRemove));
                if ($epHasTenant) {
                    $execParams[] = $this->tenant_id;
                }
                $stmt->execute($execParams);
            } else {
                $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
                $sql = "DELETE FROM event_participants
                        WHERE event_id = ?
                          AND user_id IN ({$placeholders})";
                if ($epHasTenant) {
                    $sql .= " AND tenant_id = ?";
                }
                $stmt = $this->pdo->prepare($sql);
                $execParams = array_merge([$eventId], array_values($toRemove));
                if ($epHasTenant) {
                    $execParams[] = $this->tenant_id;
                }
                $stmt->execute($execParams);
            }
        }

        return true;
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

            // Soft delete (schema-aware across environments)
            $eventsHasDeletedAt = $this->eventsHasColumn('deleted_at');
            $eventsHasDeletedBy = $this->eventsHasColumn('deleted_by');
            $eventsHasTenant = $this->eventsHasColumn('tenant_id');

            if ($eventsHasDeletedAt) {
                $setParts = ["deleted_at = NOW()"];
                if ($eventsHasDeletedBy) {
                    $setParts[] = "deleted_by = :user_id";
                }
                if ($this->eventsHasColumn('updated_at')) {
                    $setParts[] = "updated_at = NOW()";
                }

                $whereParts = ["id = :id"];
                if ($eventsHasTenant) {
                    $whereParts[] = "tenant_id = :tenant_id";
                }

                $sql = "UPDATE events SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);
                $params = [
                    ':id' => $id
                ];
                if ($eventsHasTenant) {
                    $params[':tenant_id'] = $this->tenant_id;
                }
                if ($eventsHasDeletedBy) {
                    $params[':user_id'] = $this->user_id;
                }

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                // Fallback: hard delete when schema doesn't support soft deletes
                if ($this->tableHasColumn('event_reminders', 'event_id')) {
                    $stmt = $this->pdo->prepare("DELETE FROM event_reminders WHERE event_id = :event_id");
                    $stmt->execute([':event_id' => $id]);
                }
                if ($this->tableHasColumn('event_participants', 'event_id')) {
                    $stmt = $this->pdo->prepare("DELETE FROM event_participants WHERE event_id = :event_id");
                    $stmt->execute([':event_id' => $id]);
                }
                $whereParts = ["id = :id"];
                if ($eventsHasTenant) {
                    $whereParts[] = "tenant_id = :tenant_id";
                }
                $sql = "DELETE FROM events WHERE " . implode(' AND ', $whereParts);
                $params = [':id' => $id];
                if ($eventsHasTenant) {
                    $params[':tenant_id'] = $this->tenant_id;
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            }

            // Cancella partecipazioni (best-effort, schema-aware)
            if ($this->tableHasColumn('event_participants', 'status')) {
                $stmt = $this->pdo->prepare(
                    "UPDATE event_participants SET status = 'cancelled' WHERE event_id = :event_id"
                );
                $stmt->execute([':event_id' => $id]);
            }

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
     * Cancella una singola occorrenza di un evento ricorrente aggiungendo un'eccezione
     */
    public function deleteRecurringInstance(int $id, DateTime $instanceDate, bool $notifyParticipants = true): bool {
        // L'ID è quello dell'evento padre
        if (!$this->canModifyEvent($id)) {
            throw new RuntimeException('Permessi insufficienti per eliminare evento');
        }

        $event = $this->getEventById($id);
        if (!$event) {
            throw new RuntimeException('Evento non trovato');
        }
        if (empty($event['recurrence_rule'])) {
            throw new RuntimeException('Evento non ricorrente');
        }

        $this->pdo->beginTransaction();

        try {
            // Ricava partecipanti (best-effort)
            $participants = [];
            if ($notifyParticipants) {
                $stmt = $this->pdo->prepare(
                    "SELECT user_id FROM event_participants WHERE event_id = :event_id"
                );
                $stmt->execute([':event_id' => $id]);
                $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $dateStr = $instanceDate->format('Y-m-d');

            // Decide where to store exceptions (schema differs across environments)
            $storageColumn = null;
            if ($this->eventsHasColumn('metadata')) {
                $storageColumn = 'metadata';
            } elseif ($this->eventsHasColumn('recurrence_exceptions')) {
                $storageColumn = 'recurrence_exceptions';
            } elseif ($this->eventsHasColumn('exceptions')) {
                $storageColumn = 'exceptions';
            }

            if (!$storageColumn) {
                error_log("[Calendar] deleteRecurringInstance: no exceptions storage column available for events table (id=$id tenant={$this->tenant_id})");
                throw new RuntimeException('Schema eventi non supporta eccezioni ricorrenza');
            }

            // Fetch current stored value (avoid relying on getEventById selecting columns that may not exist)
            $stmt = $this->pdo->prepare(
                "SELECT {$storageColumn} AS v
                 FROM events
                 WHERE id = :id AND tenant_id = :tenant_id
                 LIMIT 1"
            );
            $stmt->execute([':id' => $id, ':tenant_id' => $this->tenant_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $currentVal = $row['v'] ?? null;

            // Build new value
            $updateValue = null;
            if ($storageColumn === 'metadata') {
                $metadata = $currentVal ?? [];
                if (is_string($metadata) && $metadata !== '') {
                    $decoded = json_decode($metadata, true);
                    $metadata = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                }
                if (!is_array($metadata)) {
                    $metadata = [];
                }
                if (!isset($metadata['exceptions']) || !is_array($metadata['exceptions'])) {
                    $metadata['exceptions'] = [];
                }
                if (!in_array($dateStr, $metadata['exceptions'], true)) {
                    $metadata['exceptions'][] = $dateStr;
                }
                $updateValue = json_encode($metadata);
            } else {
                // recurrence_exceptions / exceptions: store JSON array for consistent parsing
                $exceptions = [];
                if (is_string($currentVal) && $currentVal !== '') {
                    $decoded = json_decode($currentVal, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $exceptions = $decoded;
                    } else {
                        $exceptions = array_map('trim', explode(',', $currentVal));
                    }
                } elseif (is_array($currentVal)) {
                    $exceptions = $currentVal;
                }
                $exceptions = array_values(array_filter(array_map(fn($v) => is_array($v) ? ($v['date'] ?? null) : (string)$v, $exceptions)));
                if (!in_array($dateStr, $exceptions, true)) {
                    $exceptions[] = $dateStr;
                }
                $updateValue = json_encode($exceptions);
            }

            // Update event with new exception list (schema-aware)
            $setParts = ["{$storageColumn} = :val"];
            if ($this->eventsHasColumn('updated_at')) {
                $setParts[] = "updated_at = NOW()";
            }
            $sql = "UPDATE events SET " . implode(', ', $setParts) . " WHERE id = :id AND tenant_id = :tenant_id";
            error_log("[Calendar] deleteRecurringInstance using {$storageColumn} (id=$id tenant={$this->tenant_id} date={$dateStr})");
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':val' => $updateValue,
                ':id' => $id,
                ':tenant_id' => $this->tenant_id
            ]);

            // Log attività
            $this->logActivity('event_instance_deleted', $id, [
                'deleted_by' => $this->user_id,
                'instance_date' => $dateStr,
                'participants_notified' => $notifyParticipants ? count($participants) : 0
            ]);

            $this->pdo->commit();

            // Notifica partecipanti solo per questa occorrenza (best effort)
            if ($notifyParticipants && !empty($participants)) {
                // Costruisci una copia evento con start/end specifici dell'occorrenza
                $instanceStart = clone $instanceDate;
                $startSource = $event['start_datetime'] ?? $event['start_date'] ?? $event['start'] ?? $event['start_datetime_local'] ?? null;
                $endSource = $event['end_datetime'] ?? $event['end_date'] ?? $event['end'] ?? $event['end_datetime_local'] ?? null;

                try {
                    $originalStart = $startSource ? new DateTime($startSource) : null;
                } catch (Exception $e) {
                    $originalStart = null;
                }
                try {
                    $originalEnd = $endSource ? new DateTime($endSource) : null;
                } catch (Exception $e) {
                    $originalEnd = null;
                }

                $duration = ($originalStart && $originalEnd) ? $originalStart->diff($originalEnd) : new DateInterval('PT1H');

                if ($originalStart) {
                    $instanceStart->setTime(
                        (int)$originalStart->format('H'),
                        (int)$originalStart->format('i'),
                        (int)$originalStart->format('s')
                    );
                }
                $instanceEnd = (clone $instanceStart)->add($duration);

                $instanceEvent = $event;
                $instanceEvent['start_datetime'] = $instanceStart->format('Y-m-d H:i:s');
                $instanceEvent['end_datetime'] = $instanceEnd->format('Y-m-d H:i:s');

                $this->sendCancellation($instanceEvent, $participants);
            }

            // Invalida cache
            $this->invalidateCache();

            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Errore eliminazione occorrenza ricorrente: " . $e->getMessage());
            throw new RuntimeException('Errore durante eliminazione occorrenza');
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
            $workHours = $this->getUserWorkHours($userId, (int)$date->format('N'), $date);

            // Calcola slot liberi
            $busySlots = [];
            foreach ($events as $event) {
                if ($event['status'] !== 'cancelled') {
                    $busySlots[] = [
                        'start' => new DateTime($event['start_datetime']),
                        'end' => new DateTime($event['end_datetime'])
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

        } catch (Throwable $e) {
            error_log("Errore getUserAvailability: " . $e->getMessage());
            // Best-effort fallback: full default working hours, no busy slots
            $fallback = $this->getUserWorkHours($userId, (int)$date->format('N'), $date);
            $freeSlots = [[
                'start' => clone $fallback['start'],
                'end' => clone $fallback['end'],
            ]];
            return [
                'date' => $date->format('Y-m-d'),
                'work_hours' => $fallback,
                'busy_slots' => [],
                'free_slots' => $freeSlots,
                'total_free_minutes' => $this->calculateTotalMinutes($freeSlots),
                'warning' => 'Fallback availability used',
            ];
        }
    }

    /**
     * Default working hours resolver (schema-drift safe).
     *
     * Currently uses fixed defaults to keep scheduling functional:
     * - Mon–Fri: 09:00–18:00
     * - Sat/Sun: 09:00–13:00
     *
     * @return array{start:DateTime,end:DateTime}
     */
    private function getUserWorkHours(int $userId, int $dayOfWeek, ?DateTime $date = null): array {
        // NOTE: For now we do not store per-user working hours. This keeps suggestFreeSlots operational.
        $base = $date ? clone $date : new DateTime('today');
        $base->setTime(0, 0, 0);

        $isWeekend = in_array((int)$dayOfWeek, [6, 7], true);
        $start = clone $base;
        $end = clone $base;

        if ($isWeekend) {
            $start->setTime(9, 0, 0);
            $end->setTime(13, 0, 0);
        } else {
            $start->setTime(9, 0, 0);
            $end->setTime(18, 0, 0);
        }

        // Ensure end > start (defensive)
        if ($end <= $start) {
            $end = (clone $start)->add(new DateInterval('PT60M'));
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Calculate total minutes across a list of slots.
     *
     * @param array<int,array{start:DateTime,end:DateTime}> $slots
     */
    private function calculateTotalMinutes(array $slots): int {
        $sum = 0;
        foreach ($slots as $s) {
            if (!is_array($s)) continue;
            $st = $s['start'] ?? null;
            $en = $s['end'] ?? null;
            if (!($st instanceof DateTime) || !($en instanceof DateTime)) continue;
            $delta = $en->getTimestamp() - $st->getTimestamp();
            if ($delta <= 0) continue;
            $sum += (int)round($delta / 60);
        }
        return $sum;
    }

    /**
     * Rileva conflitti di scheduling
     */
    public function detectConflicts(DateTime $start, DateTime $end, array $participants = []): array {
        $conflicts = [];

        try {
            // Prepara query base
            $sql = "SELECT e.*, u.name as organizer_name
                    FROM events e
                    LEFT JOIN users u ON e.organizer_id = u.id
                    WHERE e.tenant_id = :tenant_id
                      AND e.deleted_at IS NULL
                      AND e.status != 'cancelled'
                      AND (
                          (e.start_datetime < :end_datetime AND e.end_datetime > :start_datetime)
                      )";

            $params = [
                ':tenant_id' => $this->tenant_id,
                ':start_datetime' => $start->format('Y-m-d H:i:s'),
                ':end_datetime' => $end->format('Y-m-d H:i:s')
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
                            new DateTime($instance['start_datetime']),
                            new DateTime($instance['end_datetime'])
                        )) {
                            $conflicts[] = [
                                'event_id' => $instance['id'],
                                'title' => $instance['title'],
                                'start' => $instance['start_datetime'],
                                'end' => $instance['end_datetime'],
                                'type' => 'time_conflict',
                                'severity' => 'high'
                            ];
                        }
                    }
                } else {
                    $conflicts[] = [
                        'event_id' => $row['id'],
                        'title' => $row['title'],
                        'start' => $row['start_datetime'],
                        'end' => $row['end_datetime'],
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
     * Compute common free slots across participants for a given day.
     *
     * NOTE: This method is intentionally deterministic and does NOT use external AI.
     * It is used by suggestFreeSlots() and must stay schema-drift safe.
     *
     * @param array<int,array<string,mixed>> $availabilities userId => availability payload from getUserAvailability()
     * @return array<int,array{start:DateTime,end:DateTime}>
     */
    private function findCommonFreeSlots(array $availabilities, int $durationMinutes): array {
        $durationMinutes = max(15, (int)$durationMinutes);
        if (empty($availabilities)) return [];

        // Normalize free slot lists per user
        $freeByUser = [];
        foreach ($availabilities as $userId => $a) {
            $slots = [];
            if (is_array($a) && isset($a['free_slots']) && is_array($a['free_slots'])) {
                foreach ($a['free_slots'] as $s) {
                    if (!is_array($s)) continue;
                    $st = $s['start'] ?? null;
                    $en = $s['end'] ?? null;
                    if ($st instanceof DateTime && $en instanceof DateTime && $en > $st) {
                        $slots[] = ['start' => clone $st, 'end' => clone $en];
                        continue;
                    }
                    // Defensive: allow string datetimes too (shouldn't happen, but keep safe)
                    if (is_string($st) && is_string($en)) {
                        try {
                            $dst = new DateTime($st);
                            $den = new DateTime($en);
                            if ($den > $dst) $slots[] = ['start' => $dst, 'end' => $den];
                        } catch (Throwable $e) {
                            // ignore
                        }
                    }
                }
            }

            // Fallback: if no free slots, consider full working hours interval if present
            if (empty($slots) && is_array($a) && isset($a['work_hours']) && is_array($a['work_hours'])) {
                $ws = $a['work_hours']['start'] ?? null;
                $we = $a['work_hours']['end'] ?? null;
                if ($ws instanceof DateTime && $we instanceof DateTime && $we > $ws) {
                    $slots[] = ['start' => clone $ws, 'end' => clone $we];
                }
            }

            if (!empty($slots)) {
                usort($slots, fn($x, $y) => ($x['start'] <=> $y['start']));
                $freeByUser[(int)$userId] = $slots;
            }
        }

        if (empty($freeByUser)) return [];

        // Compute common availability intervals by intersecting free intervals across users
        $commonIntervals = null;
        foreach ($freeByUser as $slots) {
            if ($commonIntervals === null) {
                $commonIntervals = $slots;
                continue;
            }
            $commonIntervals = $this->intersectIntervals($commonIntervals, $slots);
            if (empty($commonIntervals)) break;
        }
        if (empty($commonIntervals)) return [];

        // From common intervals, generate candidate slots of exact duration.
        // Step is 15 minutes to keep suggestions flexible; scoring will prioritize preferred times.
        $out = [];
        foreach ($commonIntervals as $iv) {
            $cursor = $this->roundUpToMinutes(clone $iv['start'], 15);
            $end = $iv['end'];
            $guard = 0;
            while ($cursor < $end && $guard < 64) {
                $slotEnd = (clone $cursor)->add(new DateInterval('PT' . $durationMinutes . 'M'));
                if ($slotEnd > $end) break;
                $out[] = ['start' => clone $cursor, 'end' => $slotEnd];
                $cursor->add(new DateInterval('PT15M'));
                $guard++;
            }
            if (count($out) >= 120) break;
        }

        return $out;
    }

    /**
     * Intersect two lists of time intervals (sorted by start).
     *
     * @param array<int,array{start:DateTime,end:DateTime}> $a
     * @param array<int,array{start:DateTime,end:DateTime}> $b
     * @return array<int,array{start:DateTime,end:DateTime}>
     */
    private function intersectIntervals(array $a, array $b): array {
        if (empty($a) || empty($b)) return [];
        usort($a, fn($x, $y) => ($x['start'] <=> $y['start']));
        usort($b, fn($x, $y) => ($x['start'] <=> $y['start']));

        $i = 0; $j = 0;
        $out = [];
        while ($i < count($a) && $j < count($b)) {
            $as = $a[$i]['start']; $ae = $a[$i]['end'];
            $bs = $b[$j]['start']; $be = $b[$j]['end'];
            if (!($as instanceof DateTime) || !($ae instanceof DateTime) || !($bs instanceof DateTime) || !($be instanceof DateTime)) {
                $i++; $j++;
                continue;
            }

            $start = ($as > $bs) ? $as : $bs;
            $end = ($ae < $be) ? $ae : $be;
            if ($end > $start) {
                $out[] = ['start' => clone $start, 'end' => clone $end];
            }

            // Move the pointer that ends first
            if ($ae <= $be) $i++; else $j++;
            if (count($out) > 300) break;
        }
        return $out;
    }

    /**
     * Round DateTime up to the next N-minute boundary.
     */
    private function roundUpToMinutes(DateTime $dt, int $minutes): DateTime {
        $minutes = max(1, (int)$minutes);
        $inc = $minutes * 60;

        $base = clone $dt;
        $base->setTime(0, 0, 0);
        $baseTs = $base->getTimestamp();

        $delta = $dt->getTimestamp() - $baseTs;
        if ($delta < 0) $delta = 0;

        $roundedDelta = (int)ceil($delta / $inc) * $inc;
        $dt->setTimestamp($baseTs + $roundedDelta);
        $dt->setTime((int)$dt->format('H'), (int)$dt->format('i'), 0);
        return $dt;
    }

    /**
     * Score a candidate slot. Higher is better.
     *
     * @param array{start:DateTime,end:DateTime} $slot
     */
    private function calculateSlotScore(array $slot, array $preferences): int {
        $start = $slot['start'] ?? null;
        $end = $slot['end'] ?? null;
        if (!($start instanceof DateTime) || !($end instanceof DateTime) || $end <= $start) return 0;

        $score = 50;

        // Prefer times close to preferred_times
        $preferredTimes = $preferences['preferred_times'] ?? [];
        if (is_array($preferredTimes) && !empty($preferredTimes)) {
            $bestDiff = null;
            foreach ($preferredTimes as $pt) {
                if (!is_string($pt)) continue;
                if (!preg_match('/^\d{1,2}:\d{2}$/', trim($pt))) continue;
                [$hh, $mm] = array_map('intval', explode(':', $pt));
                $pref = clone $start;
                $pref->setTime($hh, $mm, 0);
                $diff = abs($start->getTimestamp() - $pref->getTimestamp()) / 60.0;
                if ($bestDiff === null || $diff < $bestDiff) $bestDiff = $diff;
            }
            if ($bestDiff !== null) {
                if ($bestDiff <= 15) $score += 30;
                elseif ($bestDiff <= 60) $score += 15;
                elseif ($bestDiff <= 180) $score += 5;
                else $score -= 10;
            }
        }

        // Penalize lunch overlap if requested
        $avoidLunch = (bool)($preferences['avoid_lunch'] ?? true);
        if ($avoidLunch) {
            $sm = ((int)$start->format('H')) * 60 + (int)$start->format('i');
            $em = ((int)$end->format('H')) * 60 + (int)$end->format('i');
            $lStart = 12 * 60 + 30;
            $lEnd = 14 * 60;
            if ($sm < $lEnd && $em > $lStart) {
                $score -= 20;
            }
        }

        // Mild preference: earlier slots in the day
        $hour = (int)$start->format('H');
        if ($hour >= 16) $score -= 8;
        elseif ($hour <= 10) $score += 6;

        if ($score < 0) $score = 0;
        return (int)$score;
    }

    /**
     * @param array{start:DateTime,end:DateTime} $slot
     * @return array<int,string>
     */
    private function getSlotReasons(array $slot, array $preferences): array {
        $reasons = [];
        $start = $slot['start'] ?? null;
        $end = $slot['end'] ?? null;
        if (!($start instanceof DateTime) || !($end instanceof DateTime) || $end <= $start) return $reasons;

        $preferredTimes = $preferences['preferred_times'] ?? [];
        if (is_array($preferredTimes) && !empty($preferredTimes)) {
            $reasons[] = 'Vicino a orari preferiti';
        }
        if ((bool)($preferences['avoid_lunch'] ?? true)) {
            $sm = ((int)$start->format('H')) * 60 + (int)$start->format('i');
            $em = ((int)$end->format('H')) * 60 + (int)$end->format('i');
            $lStart = 12 * 60 + 30;
            $lEnd = 14 * 60;
            if (!($sm < $lEnd && $em > $lStart)) {
                $reasons[] = 'Evita fascia pranzo';
            }
        }
        return $reasons;
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
                            'start_datetime' => $newSlot['start']->format('Y-m-d H:i:s'),
                            'end_datetime' => $newSlot['end']->format('Y-m-d H:i:s'),
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
                      AND e.start_datetime < :end_datetime
                      AND e.end_datetime > :start_datetime";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':room_id' => $roomId,
                ':start_datetime' => $start->format('Y-m-d H:i:s'),
                ':end_datetime' => $end->format('Y-m-d H:i:s')
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
            $hasSendAfter = $this->tableHasColumn('event_reminders', 'send_after');
            $hasIsSent = $this->tableHasColumn('event_reminders', 'is_sent');
            $hasSendAttempts = $this->tableHasColumn('event_reminders', 'send_attempts');
            $hasLastError = $this->tableHasColumn('event_reminders', 'last_error');
            $hasParticipantStatus = $this->tableHasColumn('event_participants', 'status');

            // Trova promemoria da inviare
            // BUG-148c FIX: Use unique named parameters (PDO doesn't support reusing named params)
            $sql = "SELECT er.*, e.*, u.email, u.name
                    FROM event_reminders er
                    JOIN events e ON er.event_id = e.id
                    JOIN event_participants ep ON ep.event_id = e.id
                    JOIN users u ON ep.user_id = u.id
                    WHERE e.tenant_id = :tenant_id_events
                      AND er.tenant_id = :tenant_id_reminders
                      AND e.deleted_at IS NULL
                      AND e.status = 'confirmed'
                      AND ep.tenant_id = :tenant_id_participants
                      AND ep.deleted_at IS NULL
                      " . ($hasParticipantStatus ? "AND ep.status IN ('accepted', 'tentative')" : "") . "
                      AND er.type = 'email'
                      AND er.deleted_at IS NULL
                      " . ($hasIsSent ? "AND er.is_sent = 0" : "AND er.sent_at IS NULL") . "
                      AND (
                        " . ($hasSendAfter ? "er.send_after <= NOW()" : "DATE_SUB(e.start_datetime, INTERVAL er.minutes_before MINUTE) <= NOW()") . "
                      )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id_events' => $this->tenant_id,
                ':tenant_id_reminders' => $this->tenant_id,
                ':tenant_id_participants' => $this->tenant_id
            ]);

            $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($reminders as $reminder) {
                $reminderId = (int)($reminder['id'] ?? 0);
                if ($reminderId <= 0) continue;

                // Increment attempt counter (best effort)
                if ($hasSendAttempts) {
                    try {
                        $this->pdo->prepare("UPDATE event_reminders SET send_attempts = send_attempts + 1 WHERE id = :id")
                            ->execute([':id' => $reminderId]);
                    } catch (Exception $e) {
                        // ignore
                    }
                }

                if ($this->sendReminderEmail($reminder)) {
                    // Marca come inviato (support both schema variants)
                    $updateSql = "UPDATE event_reminders SET sent_at = NOW()";
                    if ($hasIsSent) $updateSql .= ", is_sent = 1";
                    if ($hasLastError) $updateSql .= ", last_error = NULL";
                    $updateSql .= " WHERE id = :id";

                    $this->pdo->prepare($updateSql)->execute([':id' => $reminderId]);
                    $count++;

                    // In-app notification (best effort; table may not exist in some envs)
                    try {
                        if (isset($reminder['user_id'])) {
                            $this->createInAppNotification((int)$reminder['user_id'], [
                                'id' => $reminder['event_id'] ?? null,
                                'title' => $reminder['title'] ?? '',
                                'start_datetime' => $reminder['start_datetime'] ?? null,
                                'end_datetime' => $reminder['end_datetime'] ?? null,
                                'location' => $reminder['location'] ?? null
                            ], self::NOTIFICATION_REMINDER);
                        }
                    } catch (Exception $e) {
                        // ignore
                    }
                } else {
                    if ($hasLastError) {
                        try {
                            $this->pdo->prepare("UPDATE event_reminders SET last_error = :err WHERE id = :id")
                                ->execute([
                                    ':id' => $reminderId,
                                    ':err' => 'Email send failed'
                                ]);
                        } catch (Exception $e) {
                            // ignore
                        }
                    }
                }
            }

            return $count;

        } catch (Exception $e) {
            error_log("Errore sendEmailReminders: " . $e->getMessage());
            throw new RuntimeException('Errore invio promemoria');
        }
    }

    /**
     * Aggiungi promemoria evento (event_reminders).
     * @param int $eventId
     * @param array $reminders Array di reminder: [{type, minutes_before}, ...]
     */
    private function addReminders(int $eventId, array $reminders): void {
        if (empty($reminders)) return;

        // Fetch event start_datetime for send_after computation
        $stmt = $this->pdo->prepare("SELECT start_datetime FROM events WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $eventId, ':tenant_id' => $this->tenant_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $start = isset($row['start_datetime']) ? new DateTime((string)$row['start_datetime']) : null;

        $hasSendAfter = $this->tableHasColumn('event_reminders', 'send_after');
        $hasIsSent = $this->tableHasColumn('event_reminders', 'is_sent');
        $hasSendAttempts = $this->tableHasColumn('event_reminders', 'send_attempts');
        $hasDeletedAt = $this->tableHasColumn('event_reminders', 'deleted_at');

        foreach ($reminders as $r) {
            if (!is_array($r)) continue;
            $type = isset($r['type']) ? (string)$r['type'] : 'email';
            $minutes = isset($r['minutes_before']) ? (int)$r['minutes_before'] : 15;
            if ($minutes < 0) $minutes = 0;

            $sendAfter = null;
            if ($hasSendAfter && $start instanceof DateTime) {
                $sendAfterDt = clone $start;
                if ($minutes > 0) $sendAfterDt->modify("-{$minutes} minutes");
                $sendAfter = $sendAfterDt->format('Y-m-d H:i:s');
            }

            $cols = ['tenant_id', 'event_id', 'type', 'minutes_before', 'sent_at'];
            $vals = [':tenant_id', ':event_id', ':type', ':minutes_before', ':sent_at'];
            $params = [
                ':tenant_id' => $this->tenant_id,
                ':event_id' => $eventId,
                ':type' => $type,
                ':minutes_before' => $minutes,
                ':sent_at' => null
            ];

            if ($hasIsSent) {
                $cols[] = 'is_sent';
                $vals[] = ':is_sent';
                $params[':is_sent'] = 0;
            }
            if ($hasSendAfter) {
                $cols[] = 'send_after';
                $vals[] = ':send_after';
                $params[':send_after'] = $sendAfter;
            }
            if ($hasSendAttempts) {
                $cols[] = 'send_attempts';
                $vals[] = ':send_attempts';
                $params[':send_attempts'] = 0;
            }
            if ($hasDeletedAt) {
                $cols[] = 'deleted_at';
                $vals[] = ':deleted_at';
                $params[':deleted_at'] = null;
            }

            $sql = "INSERT INTO event_reminders (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
            $this->pdo->prepare($sql)->execute($params);
        }
    }

    /**
     * Aggiorna promemoria di un evento: soft-delete degli esistenti + re-insert.
     */
    private function updateReminders(int $eventId, array $reminders): void {
        // Soft delete existing reminders for this event
        if ($this->tableHasColumn('event_reminders', 'deleted_at')) {
            $this->pdo->prepare("UPDATE event_reminders SET deleted_at = NOW() WHERE tenant_id = :tenant_id AND event_id = :event_id AND deleted_at IS NULL")
                ->execute([':tenant_id' => $this->tenant_id, ':event_id' => $eventId]);
        } else {
            $this->pdo->prepare("DELETE FROM event_reminders WHERE tenant_id = :tenant_id AND event_id = :event_id")
                ->execute([':tenant_id' => $this->tenant_id, ':event_id' => $eventId]);
        }

        $this->addReminders($eventId, $reminders);
    }

    /**
     * Parse reminders string from SQL GROUP_CONCAT(CONCAT(type, ':', minutes_before)).
     */
    private function parseReminders(string $reminders): array {
        $out = [];
        $parts = array_filter(array_map('trim', explode(',', $reminders)));
        foreach ($parts as $p) {
            $pair = explode(':', $p, 2);
            $type = isset($pair[0]) ? trim($pair[0]) : 'email';
            $minutes = isset($pair[1]) ? (int)$pair[1] : 15;
            $out[] = [
                'type' => $type !== '' ? $type : 'email',
                'minutes_before' => $minutes
            ];
        }
        return $out;
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

            // Schema-aware insert (notifications.data vs notifications.payload/metadata/details)
            $cols = [];
            $vals = [];
            $params = [];

            if ($this->tableHasColumn('notifications', 'tenant_id')) {
                $cols[] = 'tenant_id';
                $vals[] = ':tenant_id';
                $params[':tenant_id'] = $this->tenant_id;
            }
            if ($this->tableHasColumn('notifications', 'user_id')) {
                $cols[] = 'user_id';
                $vals[] = ':user_id';
                $params[':user_id'] = $userId;
            }

            // Type/action field
            $notifType = 'calendar_' . $type;
            if ($this->tableHasColumn('notifications', 'type')) {
                $cols[] = 'type';
                $vals[] = ':type';
                $params[':type'] = $notifType;
            } elseif ($this->tableHasColumn('notifications', 'action')) {
                $cols[] = 'action';
                $vals[] = ':action';
                $params[':action'] = $notifType;
            }

            if ($this->tableHasColumn('notifications', 'title')) {
                $cols[] = 'title';
                $vals[] = ':title';
                $params[':title'] = 'Notifica Calendario';
            }
            if ($this->tableHasColumn('notifications', 'message')) {
                $cols[] = 'message';
                $vals[] = ':message';
                $params[':message'] = $messages[$type] ?? 'Notifica evento';
            }

            $payloadJson = json_encode($event);
            if ($this->tableHasColumn('notifications', 'data')) {
                $cols[] = 'data';
                $vals[] = ':data';
                $params[':data'] = $payloadJson;
            } elseif ($this->tableHasColumn('notifications', 'payload')) {
                $cols[] = 'payload';
                $vals[] = ':payload';
                $params[':payload'] = $payloadJson;
            } elseif ($this->tableHasColumn('notifications', 'metadata')) {
                $cols[] = 'metadata';
                $vals[] = ':metadata';
                $params[':metadata'] = $payloadJson;
            } elseif ($this->tableHasColumn('notifications', 'details')) {
                $cols[] = 'details';
                $vals[] = ':details';
                $params[':details'] = $payloadJson;
            }

            if ($this->tableHasColumn('notifications', 'priority')) {
                $cols[] = 'priority';
                $vals[] = ':priority';
                $params[':priority'] = $type === self::NOTIFICATION_CANCEL ? 'high' : 'normal';
            }

            if ($this->tableHasColumn('notifications', 'created_at')) {
                $cols[] = 'created_at';
                $vals[] = 'NOW()';
            }

            if (empty($cols)) {
                // Can't insert anything safely
                return false;
            }

            $sql = "INSERT INTO notifications (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

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

            $tenantName = htmlspecialchars($this->getTenantName());
            $eventTitle = htmlspecialchars((string)($event['title'] ?? 'Evento'));
            $eventLocation = htmlspecialchars((string)($event['location'] ?? 'Da definire'));
            $eventDescription = nl2br(htmlspecialchars((string)($event['description'] ?? '')));
            $eventDate = htmlspecialchars(date('d/m/Y H:i', strtotime((string)$event['start_datetime'])));

            $htmlMessage = '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
            $htmlMessage .= '<title>Invito Evento</title><style>
                body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;line-height:1.6;color:#333;background:#f4f7fa;margin:0;padding:0}
                .container{max-width:600px;margin:20px auto;background:#fff;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,.1);overflow:hidden}
                .header{background:linear-gradient(135deg,#f59e0b 0%,#d97706 100%);color:#fff;padding:30px 20px;text-align:center}
                .brand{font-size:14px;font-weight:700;opacity:.95;margin-bottom:6px}
                .content{padding:30px}
                .card{background:#f8f9fa;border-left:4px solid #f59e0b;border-radius:4px;padding:20px;margin:20px 0}
                .footer{background:#f8f9fa;padding:20px;text-align:center;color:#666;font-size:12px;border-top:1px solid #e0e0e0}
            </style></head><body>';
            $htmlMessage .= '<div class="container"><div class="header"><div class="brand">Nexio — ' . $tenantName . '</div><h1 style="margin:0;font-size:24px;font-weight:600;">Invito Evento</h1></div>';
            $htmlMessage .= '<div class="content"><p>Ciao <strong>' . htmlspecialchars((string)($participant['name'] ?? '')) . '</strong>,</p>';
            $htmlMessage .= '<p>Sei stato invitato all\'evento:</p>';
            $htmlMessage .= '<div class="card">';
            $htmlMessage .= '<div style="font-size:20px;font-weight:600;color:#2c3e50;margin:0 0 10px 0;">' . $eventTitle . '</div>';
            $htmlMessage .= '<div style="font-size:14px;color:#555;"><strong>📅 Data:</strong> ' . $eventDate . '</div>';
            $htmlMessage .= '<div style="font-size:14px;color:#555;"><strong>📍 Luogo:</strong> ' . $eventLocation . '</div>';
            if ($eventDescription !== '') {
                $htmlMessage .= '<div style="font-size:14px;color:#555;margin-top:10px;"><strong>Descrizione:</strong><br>' . $eventDescription . '</div>';
            }
            $htmlMessage .= '</div>';
            $htmlMessage .= '<p style="font-size:13px;color:#666;">Accetta o rifiuta l\'invito dal file iCal (ICS) allegato.</p>';
            $htmlMessage .= '</div><div class="footer"><p>&copy; ' . date('Y') . ' Nexio. Tutti i diritti riservati.</p></div></div></body></html>';

            $textMessage = "Sei stato invitato all'evento:\n\n";
            $textMessage .= "Titolo: {$event['title']}\n";
            $textMessage .= "Data: " . date('d/m/Y H:i', strtotime($event['start_datetime'])) . "\n";
            $textMessage .= "Luogo: " . ($event['location'] ?? 'Da definire') . "\n\n";
            $textMessage .= "Descrizione:\n{$event['description']}\n\n";
            $textMessage .= "Accetta o rifiuta l'invito dal tuo calendario.";

            // Usa il nuovo helper con allegato iCal
            // TODO: Implementare supporto allegati per iCal
            // Per ora invia email senza allegato iCal
            $context = [
                'action' => 'calendar_invitation',
                'tenant_id' => $this->tenant_id,
                'user_id' => $this->user_id
            ];

            return sendEmail($to, $subject, $htmlMessage, $textMessage, ['context' => $context]);

        } catch (Exception $e) {
            error_log("Errore sendInvitation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia notifica cancellazione (in-app + email)
     */
    public function sendCancellation(array $event, array $participants): bool {
        try {
            $sentCount = 0;
            $failedCount = 0;

            foreach ($participants as $participant) {
                $userId = is_array($participant) ? $participant['user_id'] : $participant;

                // 1. IN-APP NOTIFICATION (già implementato)
                $this->createInAppNotification(
                    $userId,
                    $event,
                    self::NOTIFICATION_CANCEL
                );

                // 2. EMAIL NOTIFICATION (implementato ora)
                $user = $this->getUserById($userId);
                if ($user && $user['email']) {
                    if ($this->sendCancellationEmail($event, $user)) {
                        $sentCount++;
                    } else {
                        $failedCount++;
                    }
                } else {
                    $failedCount++;
                    error_log("[CALENDAR_EMAIL] User $userId not found or no email for cancellation");
                }
            }

            error_log("[CALENDAR_EMAIL] Cancellation emails sent: $sentCount, failed: $failedCount");
            return true;  // Non-blocking, sempre ritorna true

        } catch (Exception $e) {
            error_log("[CALENDAR_EMAIL] Error in sendCancellation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Esporta eventi in formato iCalendar
     */
    public function exportToICS(array $events): string {
        $this->logActivity('calendar_export', null, [
            'events_count' => count($events)
        ]);
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

        $this->logActivity('calendar_import', null, [
            'imported' => count($imported),
            'errors' => count($errors),
            'total' => count($imported) + count($errors)
        ]);

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
            usort($allEvents, fn($a, $b) => $a['start_datetime'] <=> $b['start_datetime']);

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

        $eventStart = new DateTime($event['start_datetime']);
        $eventEnd = new DateTime($event['end_datetime']);
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
                if (!$this->isExceptionDate($current, $event)) {
                    $instance = $event;
                    $instance['id'] = $event['id'] . '_' . $current->format('Ymd');
                    $instance['start_datetime'] = $current->format('Y-m-d H:i:s');
                    $instance['end_datetime'] = $instanceEnd->format('Y-m-d H:i:s');
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
     * Verifica se una data di occorrenza è in elenco eccezioni
     */
    private function isExceptionDate(DateTime $date, array $event): bool {
        $exceptions = [];

        $collect = function($value) use (&$exceptions) {
            if ($value === null || $value === '') {
                return;
            }

            // Se stringa JSON o comma-separated
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $exceptions = array_merge($exceptions, $decoded);
                    return;
                }
                $parts = array_map('trim', explode(',', $value));
                $exceptions = array_merge($exceptions, $parts);
                return;
            }

            if (is_array($value)) {
                $exceptions = array_merge($exceptions, $value);
            }
        };

        // Colonna dedicata (se presente nello schema o nel resultset)
        if (isset($event['recurrence_exceptions'])) {
            $collect($event['recurrence_exceptions']);
        }

        // Campo generico exceptions
        if (isset($event['exceptions'])) {
            $collect($event['exceptions']);
        }

        // Metadata JSON (può contenere exceptions o recurrence_exceptions)
        if (isset($event['metadata'])) {
            $meta = $event['metadata'];
            if (is_string($meta) && $meta !== '') {
                $decoded = json_decode($meta, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            if (is_array($meta)) {
                if (isset($meta['exceptions'])) {
                    $collect($meta['exceptions']);
                }
                if (isset($meta['recurrence_exceptions'])) {
                    $collect($meta['recurrence_exceptions']);
                }
            }
        }

        if (empty($exceptions)) {
            return false;
        }

        $target = $date->format('Y-m-d');

        foreach ($exceptions as $ex) {
            if (!$ex) {
                continue;
            }
            try {
                $exDate = new DateTime(is_array($ex) ? ($ex['date'] ?? '') : (string)$ex);
                if ($exDate->format('Y-m-d') === $target) {
                    return true;
                }
            } catch (Exception $e) {
                // Ignora valori non parseable
                continue;
            }
        }

        return false;
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
        $organizerInfo = [
            'id' => $event['organizer_id'],
            'name' => $event['organizer_name'] ?? null,
            'email' => $event['organizer_email'] ?? null
        ];

        $metadataRaw = $event['metadata'] ?? null;
        $metadataArr = ($metadataRaw !== null && $metadataRaw !== '')
            ? json_decode((string)$metadataRaw, true)
            : null;

        $category = $event['category'] ?? null;
        if ($category === null && is_array($metadataArr) && isset($metadataArr['category'])) {
            $category = $metadataArr['category'];
        }

        return [
            'id' => $event['id'] ?? null,
            'title' => $event['title'] ?? '',
            'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null,
            'start_datetime' => $event['start_datetime'] ?? null,
            'end_datetime' => $event['end_datetime'] ?? null,
            'all_day' => (bool) ($event['all_day'] ?? false),
            'category' => $category ?? 'general',
            'color' => $event['color'] ?? '#3788d8',
            'status' => $event['status'] ?? 'confirmed',
            'visibility' => $event['visibility'] ?? 'private',
            // Recurring instance metadata (needed by frontend to avoid parseInt/id mismatch)
            'is_recurring_instance' => (bool)($event['is_recurring_instance'] ?? false),
            'parent_event_id' => isset($event['parent_event_id']) ? (int)$event['parent_event_id'] : null,
            'organizer_id' => $event['organizer_id'] ?? null,
            'organizer' => $organizerInfo,
            // Backward compatibility: retain creator field pointing to organizer data
            'creator' => $organizerInfo,
            'participants' => !empty($event['participants'])
                ? array_map('intval', explode(',', $event['participants']))
                : [],
            'reminders' => !empty($event['reminders'])
                ? $this->parseReminders($event['reminders'])
                : [],
            'is_recurring' => !empty($event['recurrence_rule']),
            'recurrence_rule' => $event['recurrence_rule'] ?? null,
            'metadata' => is_array($metadataArr) ? $metadataArr : null
        ];
    }

    /**
     * Genera contenuto iCalendar
     */
    private function generateICalendar(array $events, string $method = 'PUBLISH'): string {
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//Nexio//Calendar//IT\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:{$method}\r\n";

        foreach ($events as $event) {
            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "UID:" . md5($event['id'] . '@collaboranexio') . "\r\n";
            $ical .= "DTSTART:" . date('Ymd\THis', strtotime($event['start_datetime'])) . "\r\n";
            $ical .= "DTEND:" . date('Ymd\THis', strtotime($event['end_datetime'])) . "\r\n";
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

        if (empty($data['start_datetime'])) {
            throw new InvalidArgumentException('Data inizio obbligatoria');
        }

        if (empty($data['end_datetime'])) {
            throw new InvalidArgumentException('Data fine obbligatoria');
        }

        // Valida date
        $start = new DateTime($data['start_datetime']);
        $end = new DateTime($data['end_datetime']);

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

        $sql = "SELECT organizer_id FROM events
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

        // Proprietario o ruolo con permesso di gestione calendario (tenant-scoped).
        // NOTE: richiesto: anche i manager devono poter modificare eventi del tenant.
        if ((string)$event['organizer_id'] === (string)$this->user_id) {
            return true;
        }

        $role = $this->getCurrentUserRole();
        return in_array($role, ['manager', 'admin', 'super_admin'], true);
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
     * Get current user role from session
     * @return string user|manager|admin|super_admin (defaults to 'user' if not found)
     */
    private function getCurrentUserRole(): string {
        if (!$this->user_id) {
            return 'user';
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT role FROM users WHERE id = ? AND deleted_at IS NULL"
            );
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user['role'] ?? 'user';
        } catch (Exception $e) {
            error_log("[RBAC] Error getCurrentUserRole: " . $e->getMessage());
            return 'user';  // Fallback to least privileged
        }
    }

    /**
     * Check if current user can invite target user to event
     *
     * RBAC Rules:
     * - User: Can invite users from same company + managers from same company
     * - Manager: Can invite all users from company + admin + super_admin
     * - Admin: Can invite users from assigned companies + super_admin
     * - Super Admin: Can invite anyone
     *
     * @param int $targetUserId User to be invited
     * @return bool True if can invite
     */
    public function canInviteUser(int $targetUserId): bool {
        if (!$this->user_id) {
            error_log("[RBAC] Cannot invite - no user_id in session");
            return false;
        }

        try {
            // BUG-144 FIX: Removed active_tenant_id reference (column does not exist)
            $stmt = $this->pdo->prepare(
                "SELECT id, role, tenant_id
                 FROM users
                 WHERE id = ? AND deleted_at IS NULL AND is_active = 1"
            );
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                error_log("[RBAC] Target user {$targetUserId} not found");
                return false;
            }

            // BUG-144 FIX: Ensure target user belongs to current tenant (via tenant_id OR user_tenant_access)
            // BUG-148c FIX: Use unique named parameters (PDO doesn't support reusing named params)
            $stmt = $this->pdo->prepare(
                "SELECT 1
                 FROM users u
                 LEFT JOIN user_tenant_access uta
                   ON uta.user_id = u.id
                  AND uta.tenant_id = :tenant_id_join
                  AND uta.deleted_at IS NULL
                 WHERE u.id = :uid
                   AND u.deleted_at IS NULL
                   AND u.is_active = 1
                   AND (
                        u.tenant_id = :tenant_id_where
                     OR uta.tenant_id IS NOT NULL
                   )
                 LIMIT 1"
            );
            $stmt->execute([
                ':tenant_id_join' => $this->tenant_id,
                ':tenant_id_where' => $this->tenant_id,
                ':uid' => $targetUserId
            ]);
            $targetInTenant = (bool)$stmt->fetchColumn();
            if (!$targetInTenant) {
                error_log("[RBAC] Target user {$targetUserId} not in tenant {$this->tenant_id} (tenant/active_tenant/membership check failed)");
                return false;
            }

            // Get current user role
            $currentUserRole = $this->getCurrentUserRole();

            // Super Admin: Can invite ANYONE
            if ($currentUserRole === 'super_admin') {
                error_log("[RBAC] User {$this->user_id} (super_admin) CAN invite user {$targetUserId}");
                return true;
            }

            // Admin: Can invite users from assigned companies + super_admin
            if ($currentUserRole === 'admin') {
                // Admin already scoped to tenant via Calendar; allow inviting anyone in tenant plus super_admin
                error_log("[RBAC] User {$this->user_id} (admin) CAN invite user {$targetUserId} in tenant {$this->tenant_id}");
                return true;
            }

            // Manager: Can invite all from company + admin + super_admin
            if ($currentUserRole === 'manager') {
                if (in_array($targetUser['role'], ['admin', 'super_admin'])) {
                    error_log("[RBAC] User {$this->user_id} (manager) CAN invite user {$targetUserId} ({$targetUser['role']}) in tenant {$this->tenant_id}");
                    return true; // already checked tenant membership above
                }
                // Same tenant (checked) -> allow
                $canInvite = true;
                error_log("[RBAC] User {$this->user_id} (manager) CAN invite user {$targetUserId} (tenant match)");
                return $canInvite;
            }

            // User (base): Can invite from same company + managers from same company
            if ($currentUserRole === 'user') {
                // Can invite other users OR managers from same company
                $canInvite = in_array($targetUser['role'], ['user', 'manager']);
                error_log("[RBAC] User {$this->user_id} (user) " . ($canInvite ? "CAN" : "CANNOT") . " invite user {$targetUserId} (role: {$targetUser['role']}, tenant match)");
                return $canInvite;
            }

            error_log("[RBAC] User {$this->user_id} (unknown role: {$currentUserRole}) CANNOT invite user {$targetUserId}");
            return false;

        } catch (Exception $e) {
            error_log("[RBAC] Error canInviteUser: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get list of users that current user can invite to events
     * Filtered by role-based permissions
     *
     * @param int|null $eventId Optional - exclude already invited users
     * @return array List of invitable users [{id, name, email, role, tenant_id, company_name}]
     */
    public function getAvailableUsersForInvitation(?int $eventId = null): array {
        if (!$this->user_id) {
            error_log("[RBAC] Cannot get available users - no user_id in session");
            return [];
        }

        try {
            $currentUserRole = $this->getCurrentUserRole();
            $users = [];

            // BUG-146 FIX: Use unique parameter names to avoid PDO "Invalid parameter number" error
            // PDO native prepared statements don't support reusing named parameters
            // BUG-144 FIX: Build query based on role (removed active_tenant_id)
            $sql = "SELECT u.id, u.name, u.email, u.role, u.tenant_id, t.name as company_name
                    FROM users u
                    LEFT JOIN tenants t ON u.tenant_id = t.id
                    LEFT JOIN user_tenant_access uta
                      ON uta.user_id = u.id
                     AND uta.tenant_id = :tenant_id_join
                     AND uta.deleted_at IS NULL
                    WHERE u.deleted_at IS NULL AND u.is_active = 1
                      AND (
                           u.tenant_id = :tenant_id_where
                        OR uta.tenant_id IS NOT NULL
                      )";

            $params = [];
            // BUG-146: Bind same value with unique names
            $params[':tenant_id_join'] = $this->tenant_id;
            $params[':tenant_id_where'] = $this->tenant_id;

            // Role-specific filtering
            if ($currentUserRole === 'super_admin') {
                // Super admin: all users of current tenant (no cross-tenant leakage)
                error_log("[RBAC] Loading users for super_admin (tenant-scoped)");
            } elseif ($currentUserRole === 'admin') {
                // Admin: Users from assigned companies OR super_admin
                $sql .= " AND (u.role = 'super_admin' OR EXISTS (
                            SELECT 1 FROM user_tenant_access uta2
                            WHERE uta2.user_id = :current_user_id
                              AND uta2.tenant_id = u.tenant_id
                              AND uta2.deleted_at IS NULL
                          ))";
                $params[':current_user_id'] = $this->user_id;
                error_log("[RBAC] Loading users for admin (assigned companies + super_admin)");
            } elseif ($currentUserRole === 'manager') {
                // Manager: Same company + admin + super_admin
                // BUG-146: Use unique parameter name for role-specific condition
                $sql .= " AND (u.role IN ('admin', 'super_admin') OR u.tenant_id = :tenant_id_role)";
                $params[':tenant_id_role'] = $this->tenant_id;
                error_log("[RBAC] Loading users for manager (same company + admin/super_admin)");
            } else {  // user
                // User: Same company users + managers only
                // BUG-146: Use unique parameter name for role-specific condition
                $sql .= " AND u.tenant_id = :tenant_id_role AND u.role IN ('user', 'manager')";
                $params[':tenant_id_role'] = $this->tenant_id;
                error_log("[RBAC] Loading users for user (same company users/managers)");
            }

            // Exclude already invited (if event_id provided)
            if ($eventId !== null) {
                $sql .= " AND u.id NOT IN (
                            SELECT user_id FROM event_participants
                            WHERE event_id = :event_id AND deleted_at IS NULL
                          )";
                $params[':event_id'] = $eventId;
            }

            // Exclude self
            $sql .= " AND u.id != :user_id";
            $params[':user_id'] = $this->user_id;

            $sql .= " ORDER BY u.name ASC LIMIT 100";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("[RBAC] Found " . count($users) . " available users for invitation (role: {$currentUserRole})");

            return $users;

        } catch (Exception $e) {
            error_log("[RBAC] Error getAvailableUsersForInvitation: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Log attività
     */
    private function logActivity(string $type, ?int $entityId, array $data): void {
        try {
            // Schema-aware activity log (activity_logs.type/data vs action/details)
            $cols = [];
            $vals = [];
            $params = [];

            if ($this->tableHasColumn('activity_logs', 'tenant_id')) {
                $cols[] = 'tenant_id';
                $vals[] = ':tenant_id';
                $params[':tenant_id'] = $this->tenant_id;
            }
            if ($this->tableHasColumn('activity_logs', 'user_id')) {
                $cols[] = 'user_id';
                $vals[] = ':user_id';
                $params[':user_id'] = $this->user_id;
            }

            if ($this->tableHasColumn('activity_logs', 'type')) {
                $cols[] = 'type';
                $vals[] = ':type';
                $params[':type'] = $type;
            } elseif ($this->tableHasColumn('activity_logs', 'action')) {
                $cols[] = 'action';
                $vals[] = ':action';
                $params[':action'] = $type;
            }

            if ($this->tableHasColumn('activity_logs', 'entity_type')) {
                $cols[] = 'entity_type';
                $vals[] = ':entity_type';
                $params[':entity_type'] = 'event';
            }
            if ($this->tableHasColumn('activity_logs', 'entity_id')) {
                $cols[] = 'entity_id';
                $vals[] = ':entity_id';
                $params[':entity_id'] = $entityId;
            }

            $payloadJson = json_encode($data);
            if ($this->tableHasColumn('activity_logs', 'data')) {
                $cols[] = 'data';
                $vals[] = ':data';
                $params[':data'] = $payloadJson;
            } elseif ($this->tableHasColumn('activity_logs', 'details')) {
                $cols[] = 'details';
                $vals[] = ':details';
                $params[':details'] = $payloadJson;
            }

            if ($this->tableHasColumn('activity_logs', 'ip_address')) {
                $cols[] = 'ip_address';
                $vals[] = ':ip';
                $params[':ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
            }
            if ($this->tableHasColumn('activity_logs', 'user_agent')) {
                $cols[] = 'user_agent';
                $vals[] = ':ua';
                $params[':ua'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
            }
            if ($this->tableHasColumn('activity_logs', 'created_at')) {
                $cols[] = 'created_at';
                $vals[] = 'NOW()';
            }

            if (empty($cols)) {
                return;
            }

            $sql = "INSERT INTO activity_logs (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
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
     * BUG-148c FIX: Use unique named parameters (PDO doesn't support reusing named params)
     */
    private function userExists(int $userId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM users u
             LEFT JOIN user_tenant_access uta
               ON uta.user_id = u.id
              AND uta.tenant_id = :tenant_id_join
              AND uta.deleted_at IS NULL
             WHERE u.id = :id
               AND u.deleted_at IS NULL
               AND u.is_active = 1
               AND (
                    u.tenant_id = :tenant_id_where
                 OR uta.tenant_id IS NOT NULL
               )
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $userId,
            ':tenant_id_join' => $this->tenant_id,
            ':tenant_id_where' => $this->tenant_id
        ]);
        // BUG-144 FIX: Removed active_tenant_id from WHERE clause
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

    // ========================================
    // EMAIL NOTIFICATION METHODS
    // ========================================

    /**
     * Recupera dati utente per email notifications
     * @param int $userId User ID
     * @return array|null ['id', 'name', 'email'] o null se non trovato
     */
    private function getUserById(int $userId): ?array {
        // BUG-144 FIX: Removed active_tenant_id from WHERE clause
        // BUG-148c FIX: Use unique named parameters (PDO doesn't support reusing named params)
        $sql = "SELECT u.id, u.name, u.email
                FROM users u
                LEFT JOIN user_tenant_access uta
                  ON uta.user_id = u.id
                 AND uta.tenant_id = :tenant_id_join
                 AND uta.deleted_at IS NULL
                WHERE u.id = :id
                  AND u.deleted_at IS NULL
                  AND u.is_active = 1
                  AND (
                       u.tenant_id = :tenant_id_where
                    OR uta.tenant_id IS NOT NULL
                  )
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $userId,
            ':tenant_id_join' => $this->tenant_id,
            ':tenant_id_where' => $this->tenant_id
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * Formatta data evento per email (es. "15 Nov 2024, 10:00-11:00 UTC")
     * @param array $event Evento con start_datetime, end_datetime, all_day, timezone
     * @return string Data formattata in italiano
     */
    private function formatEventDate(array $event): string {
        try {
            $start = new DateTime($event['start_datetime']);
            $end = new DateTime($event['end_datetime']);

            // Mesi italiani
            $months = [
                1 => 'Gen', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
                5 => 'Mag', 6 => 'Giu', 7 => 'Lug', 8 => 'Ago',
                9 => 'Set', 10 => 'Ott', 11 => 'Nov', 12 => 'Dic'
            ];

            if (!empty($event['all_day'])) {
                // Evento tutto il giorno
                if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
                    return $start->format('d') . ' ' . $months[(int)$start->format('n')] . ' ' . $start->format('Y');
                } else {
                    return $start->format('d') . ' ' . $months[(int)$start->format('n')] . ' ' . $start->format('Y') .
                           ' - ' . $end->format('d') . ' ' . $months[(int)$end->format('n')] . ' ' . $end->format('Y');
                }
            }

            $timezone = $event['timezone'] ?? 'UTC';

            if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
                // Stesso giorno
                return $start->format('d') . ' ' . $months[(int)$start->format('n')] . ' ' . $start->format('Y') .
                       ', ' . $start->format('H:i') . ' - ' . $end->format('H:i') . ' ' . $timezone;
            } else {
                // Multi-giorno
                return $start->format('d') . ' ' . $months[(int)$start->format('n')] . ' ' . $start->format('Y, H:i') .
                       ' - ' . $end->format('d') . ' ' . $months[(int)$end->format('n')] . ' ' . $end->format('Y, H:i') .
                       ' ' . $timezone;
            }
        } catch (Exception $e) {
            error_log("[CALENDAR] Error formatting date: " . $e->getMessage());
            return ($event['start_datetime'] ?? 'Unknown') . ' to ' . ($event['end_datetime'] ?? 'Unknown');
        }
    }

    /**
     * Invia email di cancellazione evento
     * @param array $event Dati evento
     * @param array $user Dati utente destinatario
     * @return bool True se inviata con successo
     */
    private function sendCancellationEmail(array $event, array $user): bool {
        try {
            $templatePath = __DIR__ . '/email_templates/calendar/event_deleted.html';
            if (!file_exists($templatePath)) {
                error_log("[CALENDAR_EMAIL] Template missing: $templatePath");
                return false;
            }

            $template = file_get_contents($templatePath);
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';

            // Recupera nome organizzatore
            $organizer = $this->getUserById($event['organizer_id']);
            $organizerName = $organizer ? $organizer['name'] : 'Unknown';

            $replacements = [
                '{{EVENT_TITLE}}' => htmlspecialchars($event['title'] ?? 'Evento'),
                '{{EVENT_DATE}}' => $this->formatEventDate($event),
                '{{EVENT_LOCATION}}' => htmlspecialchars($event['location'] ?? 'Non specificato'),
                '{{EVENT_DESCRIPTION}}' => htmlspecialchars($event['description'] ?? 'Nessuna descrizione'),
                '{{ORGANIZER_NAME}}' => htmlspecialchars($organizerName),
                '{{PARTICIPANT_NAME}}' => htmlspecialchars($user['name']),
                '{{BASE_URL}}' => $baseUrl,
                '{{TENANT_NAME}}' => htmlspecialchars($this->getTenantName()),
                '{{YEAR}}' => date('Y')
            ];

            $htmlBody = str_replace(array_keys($replacements), array_values($replacements), $template);
            if ($htmlBody !== '' && !cnx_email_is_full_document($htmlBody)) {
                $htmlBody = renderEmailLayout(
                    'Evento cancellato',
                    $htmlBody,
                    [
                        'BASE_URL' => $baseUrl,
                        'TENANT_NAME' => (string)$this->getTenantName(),
                        'YEAR' => date('Y')
                    ],
                    ['brandColor' => '#1a2332']
                );
            }

            return sendEmail($user['email'], 'Evento Cancellato: ' . $event['title'], $htmlBody, '', [
                'context' => [
                    'action' => 'calendar_event_deleted',
                    'tenant_id' => $this->tenant_id,
                    'user_id' => $user['id'] ?? null
                ]
            ]);

        } catch (Exception $e) {
            error_log("[CALENDAR_EMAIL] Error in sendCancellationEmail: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia inviti email ai partecipanti
     * @param int $eventId ID evento
     * @param array $userIds Array di user IDs da invitare
     * @return bool True se tutti gli inviti sono stati inviati
     */
    public function scheduleEmailInvitations(int $eventId, array $userIds): bool {
        try {
            $event = $this->getEventById($eventId);
            if (!$event) {
                error_log("[CALENDAR_EMAIL] Event $eventId not found");
                return false;
            }

            $templatePath = __DIR__ . '/email_templates/calendar/event_invitation.html';
            if (!file_exists($templatePath)) {
                error_log("[CALENDAR_EMAIL] Template missing: $templatePath");
                return false;
            }

            $template = file_get_contents($templatePath);
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';

            // Recupera nome organizzatore
            $organizer = $this->getUserById($event['organizer_id']);
            $organizerName = $organizer ? $organizer['name'] : 'Unknown';

            $sentCount = 0;
            $failedCount = 0;

            foreach ($userIds as $userId) {
                $user = $this->getUserById($userId);
                if (!$user || !$user['email']) {
                    $failedCount++;
                    error_log("[CALENDAR_EMAIL] User $userId not found or no email");
                    continue;
                }

                $replacements = [
                    '{{EVENT_TITLE}}' => htmlspecialchars($event['title'] ?? 'Evento'),
                    '{{EVENT_DATE}}' => $this->formatEventDate($event),
                    '{{EVENT_LOCATION}}' => htmlspecialchars($event['location'] ?? 'Non specificato'),
                    '{{EVENT_DESCRIPTION}}' => htmlspecialchars($event['description'] ?? 'Nessuna descrizione'),
                    '{{ORGANIZER_NAME}}' => htmlspecialchars($organizerName),
                    '{{PARTICIPANT_NAME}}' => htmlspecialchars($user['name']),
                    '{{RESPOND_URL}}' => $baseUrl . '/calendar.php?event=' . $eventId,
                    '{{BASE_URL}}' => $baseUrl,
                    '{{TENANT_NAME}}' => htmlspecialchars($this->getTenantName()),
                    '{{YEAR}}' => date('Y')
                ];

                $htmlBody = str_replace(array_keys($replacements), array_values($replacements), $template);
                if ($htmlBody !== '' && !cnx_email_is_full_document($htmlBody)) {
                    $htmlBody = renderEmailLayout(
                        'Invito evento',
                        $htmlBody,
                        [
                            'BASE_URL' => $baseUrl,
                            'TENANT_NAME' => (string)$this->getTenantName(),
                            'YEAR' => date('Y')
                        ],
                        ['brandColor' => '#1a2332']
                    );
                }

                if (sendEmail($user['email'], 'Invito Evento: ' . $event['title'], $htmlBody, '', [
                    'context' => [
                        'action' => 'calendar_event_invitation',
                        'tenant_id' => $this->tenant_id,
                        'user_id' => $user['id'] ?? null
                    ]
                ])) {
                    $sentCount++;
                } else {
                    $failedCount++;
                    error_log("[CALENDAR_EMAIL] Failed to send invitation to " . $user['email']);
                }
            }

            error_log("[CALENDAR_EMAIL] Invitations sent: $sentCount, failed: $failedCount for event $eventId");

            return $failedCount == 0;

        } catch (Exception $e) {
            error_log("[CALENDAR_EMAIL] Error in scheduleEmailInvitations: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia notifiche email per eventi (update/reminder)
     * @param int $eventId ID evento
     * @param string $type Tipo notifica (self::NOTIFICATION_UPDATE, self::NOTIFICATION_REMINDER)
     * @return bool True se notifiche inviate con successo
     */
    public function scheduleNotifications(int $eventId, string $type): bool {
        try {
            $event = $this->getEventById($eventId);
            if (!$event) {
                error_log("[CALENDAR_EMAIL] Event $eventId not found for notification $type");
                return false;
            }

            // Determina template in base al tipo
            $templateFile = '';
            $subject = '';

            switch ($type) {
                case self::NOTIFICATION_UPDATE:
                    $templateFile = 'event_updated.html';
                    $subject = 'Evento Modificato: ' . $event['title'];
                    break;

                case self::NOTIFICATION_REMINDER:
                    $templateFile = 'event_reminder.html';
                    $subject = 'Promemoria Evento: ' . $event['title'];
                    break;

                case self::NOTIFICATION_INVITE:
                    $templateFile = 'event_invitation.html';
                    $subject = 'Invito Evento: ' . $event['title'];
                    break;

                default:
                    error_log("[CALENDAR_EMAIL] Unknown notification type: $type");
                    return false;
            }

            $templatePath = __DIR__ . '/email_templates/calendar/' . $templateFile;
            if (!file_exists($templatePath)) {
                error_log("[CALENDAR_EMAIL] Template missing: $templatePath");
                return false;
            }

            $template = file_get_contents($templatePath);
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';

            // Recupera partecipanti
            $sql = "SELECT user_id FROM event_participants
                    WHERE event_id = :event_id
                    AND tenant_id = :tenant_id
                    AND deleted_at IS NULL";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':event_id' => $eventId,
                ':tenant_id' => $this->tenant_id
            ]);

            $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($participants)) {
                error_log("[CALENDAR_EMAIL] No participants found for event $eventId");
                return true; // Non è un errore se non ci sono partecipanti
            }

            // Recupera nome organizzatore
            $organizer = $this->getUserById($event['organizer_id']);
            $organizerName = $organizer ? $organizer['name'] : 'Unknown';

            $sentCount = 0;
            $failedCount = 0;

            foreach ($participants as $userId) {
                $user = $this->getUserById($userId);
                if (!$user || !$user['email']) {
                    $failedCount++;
                    error_log("[CALENDAR_EMAIL] User $userId not found or no email");
                    continue;
                }

                // Calcola tempo al reminder (per reminder type)
                $reminderTime = '';
                if ($type === self::NOTIFICATION_REMINDER) {
                    try {
                        $now = new DateTime();
                        $start = new DateTime($event['start_datetime']);
                        $diff = $now->diff($start);

                        if ($diff->days > 0) {
                            $reminderTime = "L'evento inizia tra " . $diff->days . " giorni";
                        } else if ($diff->h > 0) {
                            $reminderTime = "L'evento inizia tra " . $diff->h . " ore";
                        } else {
                            $reminderTime = "L'evento inizia tra " . $diff->i . " minuti";
                        }
                    } catch (Exception $e) {
                        $reminderTime = "L'evento sta per iniziare";
                    }
                }

                $replacements = [
                    '{{EVENT_TITLE}}' => htmlspecialchars($event['title'] ?? 'Evento'),
                    '{{EVENT_DATE}}' => $this->formatEventDate($event),
                    '{{EVENT_LOCATION}}' => htmlspecialchars($event['location'] ?? 'Non specificato'),
                    '{{EVENT_DESCRIPTION}}' => htmlspecialchars($event['description'] ?? 'Nessuna descrizione'),
                    '{{ORGANIZER_NAME}}' => htmlspecialchars($organizerName),
                    '{{PARTICIPANT_NAME}}' => htmlspecialchars($user['name']),
                    '{{RESPOND_URL}}' => $baseUrl . '/calendar.php?event=' . $eventId,
                    '{{BASE_URL}}' => $baseUrl,
                    '{{TENANT_NAME}}' => htmlspecialchars($this->getTenantName()),
                    '{{YEAR}}' => date('Y'),
                    '{{CHANGE_SUMMARY}}' => 'L\'evento è stato aggiornato. Verifica i nuovi dettagli.',
                    '{{REMINDER_TIME}}' => $reminderTime
                ];

                $htmlBody = str_replace(array_keys($replacements), array_values($replacements), $template);
                if ($htmlBody !== '' && !cnx_email_is_full_document($htmlBody)) {
                    $htmlBody = renderEmailLayout(
                        $subject,
                        $htmlBody,
                        [
                            'BASE_URL' => $baseUrl,
                            'TENANT_NAME' => (string)$this->getTenantName(),
                            'YEAR' => date('Y')
                        ],
                        ['brandColor' => '#1a2332']
                    );
                }

                if (sendEmail($user['email'], $subject, $htmlBody, '', [
                    'context' => [
                        'action' => 'calendar_event_' . $type,
                        'tenant_id' => $this->tenant_id,
                        'user_id' => $user['id'] ?? null
                    ]
                ])) {
                    $sentCount++;
                } else {
                    $failedCount++;
                    error_log("[CALENDAR_EMAIL] Failed to send $type notification to " . $user['email']);
                }
            }

            error_log("[CALENDAR_EMAIL] Notifications ($type) sent: $sentCount, failed: $failedCount for event $eventId");

            return $failedCount == 0;

        } catch (Exception $e) {
            error_log("[CALENDAR_EMAIL] Error in scheduleNotifications: " . $e->getMessage());
            return false;
        }
    }
}
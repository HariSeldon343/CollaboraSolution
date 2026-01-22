<?php
/**
 * Shift Notification Helper
 *
 * Gestisce le notifiche email per eventi relativi ai turni di lavoro in CollaboraNexio.
 * - Turno assegnato
 * - Turno modificato
 * - Turno cancellato
 * - Richiesta modifica ricevuta (al manager)
 * - Richiesta approvata
 * - Richiesta rifiutata
 *
 * Pattern: non-blocking (try/catch con error_log su fallimenti)
 *
 * @author CollaboraNexio
 * @version 1.0.0
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_layout.php';
require_once __DIR__ . '/email_template_renderer.php';

class ShiftNotificationHelper
{
    /**
     * @var string Directory dei template email per i turni
     */
    private static string $templateDir = __DIR__ . '/email_templates/shifts/';

    /**
     * @var string URL base della piattaforma
     */
    private static function getBaseUrl(): string
    {
        return defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
    }

    /**
     * @var array Cache dei nomi tenant
     */
    private static array $tenantNameCache = [];

    /**
     * Ottiene il nome del tenant dalla cache o dal database
     *
     * @param int|null $tenantId ID del tenant
     * @return string Nome del tenant o stringa vuota
     */
    private static function getTenantName(?int $tenantId): string
    {
        if (!$tenantId) {
            return '';
        }
        if (isset(self::$tenantNameCache[$tenantId])) {
            return self::$tenantNameCache[$tenantId];
        }
        try {
            $db = Database::getInstance();
            $row = $db->fetchOne('SELECT name FROM tenants WHERE id = ? LIMIT 1', [$tenantId]);
            $name = is_array($row) ? (string)($row['name'] ?? '') : '';
            self::$tenantNameCache[$tenantId] = $name;
            return $name;
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::getTenantName Error: " . $e->getMessage());
            self::$tenantNameCache[$tenantId] = '';
            return '';
        }
    }

    /**
     * Ottiene le informazioni di un utente
     *
     * @param int $userId ID utente
     * @return array|null Dati utente o null
     */
    private static function getUserInfo(int $userId): ?array
    {
        try {
            $db = Database::getInstance();
            return $db->fetchOne(
                'SELECT id, name, email, tenant_id FROM users WHERE id = ? AND deleted_at IS NULL',
                [$userId]
            );
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::getUserInfo Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ottiene i dettagli di un turno
     *
     * @param int $shiftId ID turno
     * @return array|null Dati turno con info tipo turno
     */
    private static function getShiftDetails(int $shiftId): ?array
    {
        try {
            $db = Database::getInstance();
            return $db->fetchOne(
                'SELECT ws.*, st.name as shift_type_name, st.code as shift_type_code,
                        st.start_time as type_start_time, st.end_time as type_end_time,
                        st.duration_minutes, st.color as shift_type_color
                 FROM work_shifts ws
                 JOIN shift_types st ON ws.shift_type_id = st.id
                 WHERE ws.id = ? AND ws.deleted_at IS NULL',
                [$shiftId]
            );
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::getShiftDetails Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ottiene i dettagli di una richiesta modifica turno
     *
     * @param int $requestId ID richiesta
     * @return array|null Dati richiesta con info turno e utente
     */
    private static function getRequestDetails(int $requestId): ?array
    {
        try {
            $db = Database::getInstance();
            return $db->fetchOne(
                'SELECT scr.*, ws.shift_date, ws.user_id as shift_user_id,
                        st.name as shift_type_name, st.start_time as type_start_time, st.end_time as type_end_time,
                        COALESCE(ws.start_time_override, st.start_time) as shift_start_time,
                        COALESCE(ws.end_time_override, st.end_time) as shift_end_time,
                        u.name as requester_name, u.email as requester_email,
                        tu.name as target_user_name
                 FROM shift_change_requests scr
                 JOIN work_shifts ws ON scr.work_shift_id = ws.id
                 JOIN shift_types st ON ws.shift_type_id = st.id
                 JOIN users u ON scr.requester_id = u.id
                 LEFT JOIN users tu ON scr.target_user_id = tu.id
                 WHERE scr.id = ? AND scr.deleted_at IS NULL',
                [$requestId]
            );
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::getRequestDetails Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Formatta l'orario in formato italiano (HH:MM)
     *
     * @param string|null $time Orario in formato TIME
     * @return string Orario formattato
     */
    private static function formatTime(?string $time): string
    {
        if (!$time) {
            return '--:--';
        }
        // Se e gia in formato HH:MM o HH:MM:SS, estrai solo HH:MM
        if (preg_match('/^(\d{2}:\d{2})/', $time, $m)) {
            return $m[1];
        }
        return $time;
    }

    /**
     * Formatta la data in formato italiano (dd/mm/YYYY)
     *
     * @param string|null $date Data in formato DATE o DATETIME
     * @return string Data formattata
     */
    private static function formatDate(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : $date;
    }

    /**
     * Formatta la durata in ore e minuti
     *
     * @param int|null $minutes Durata in minuti
     * @return string Durata formattata
     */
    private static function formatDuration(?int $minutes): string
    {
        if (!$minutes) {
            return '';
        }
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}m";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$mins}m";
        }
    }

    /**
     * Ottiene l'etichetta del tipo di richiesta
     *
     * @param string $type Tipo richiesta (change, swap, cancel)
     * @return string Etichetta in italiano
     */
    private static function getRequestTypeLabel(string $type): string
    {
        $labels = [
            'change' => 'Modifica orario',
            'swap'   => 'Scambio turno',
            'cancel' => 'Annullamento turno'
        ];
        return $labels[$type] ?? ucfirst($type);
    }

    /**
     * Ottiene l'etichetta dello stato turno
     *
     * @param string $status Stato turno
     * @return string Etichetta in italiano
     */
    private static function getShiftStatusLabel(string $status): string
    {
        $labels = [
            'scheduled'   => 'Programmato',
            'confirmed'   => 'Confermato',
            'in_progress' => 'In corso',
            'completed'   => 'Completato',
            'cancelled'   => 'Cancellato',
            'no_show'     => 'Assente'
        ];
        return $labels[$status] ?? ucfirst($status);
    }

    /**
     * Renderizza un template email
     *
     * @param string $templateName Nome del file template
     * @param array $data Dati per il template
     * @return string HTML renderizzato
     */
    private static function renderTemplate(string $templateName, array $data): string
    {
        $templatePath = self::$templateDir . $templateName;

        if (!file_exists($templatePath)) {
            error_log("ShiftNotificationHelper: Template non trovato: $templatePath");
            return '';
        }

        $html = cnx_render_email_template_file($templatePath, $data, [
            'remove_unknown_placeholders' => true
        ]);

        // Se il template e content-only, wrappalo nel layout Nexio
        if ($html !== '' && !cnx_email_is_full_document($html)) {
            $title = (string)($data['EMAIL_TITLE'] ?? 'Notifica Turni');
            $layoutVars = [
                'BASE_URL'    => self::getBaseUrl(),
                'TENANT_NAME' => (string)($data['TENANT_NAME'] ?? ''),
                'YEAR'        => (string)($data['YEAR'] ?? date('Y'))
            ];
            $html = renderEmailLayout($title, $html, $layoutVars, ['brandColor' => '#1a2332']);
        }

        return $html;
    }

    /**
     * Notifica: Turno Assegnato
     *
     * Invia una notifica email al dipendente quando gli viene assegnato un nuovo turno.
     *
     * @param int $shiftId ID del turno assegnato
     * @param int $assignedBy ID dell'utente che ha effettuato l'assegnazione
     * @return bool True se l'invio ha successo
     */
    public static function notifyShiftAssigned(int $shiftId, int $assignedBy): bool
    {
        try {
            $shift = self::getShiftDetails($shiftId);
            if (!$shift) {
                error_log("ShiftNotificationHelper::notifyShiftAssigned - Turno $shiftId non trovato");
                return false;
            }

            $user = self::getUserInfo((int)$shift['user_id']);
            if (!$user || empty($user['email'])) {
                error_log("ShiftNotificationHelper::notifyShiftAssigned - Utente non trovato per turno $shiftId");
                return false;
            }

            $assigner = self::getUserInfo($assignedBy);
            $tenantName = self::getTenantName((int)$shift['tenant_id']);

            $startTime = self::formatTime($shift['start_time_override'] ?? $shift['type_start_time']);
            $endTime = self::formatTime($shift['end_time_override'] ?? $shift['type_end_time']);

            $templateData = [
                'EMAIL_TITLE'       => 'Nuovo turno assegnato',
                'USER_NAME'         => $user['name'],
                'TENANT_NAME'       => $tenantName,
                'SHIFT_DATE'        => self::formatDate($shift['shift_date']),
                'SHIFT_TYPE_NAME'   => $shift['shift_type_name'],
                'SHIFT_START_TIME'  => $startTime,
                'SHIFT_END_TIME'    => $endTime,
                'SHIFT_DURATION'    => self::formatDuration((int)$shift['duration_minutes']),
                'SHIFT_NOTES'       => $shift['notes'] ?? '',
                'ASSIGNED_BY_NAME'  => $assigner['name'] ?? 'Sistema',
                'SHIFTS_URL'        => self::getBaseUrl() . '/turni.php',
                'BASE_URL'          => self::getBaseUrl(),
                'YEAR'              => date('Y')
            ];

            $html = self::renderTemplate('shift_assigned.html', $templateData);
            $subject = "Nuovo turno: " . self::formatDate($shift['shift_date']) . " ({$shift['shift_type_name']})";

            return sendEmail(
                $user['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $shift['tenant_id'],
                        'user_id'   => $user['id'],
                        'action'    => 'shift_assigned_notification'
                    ]
                ]
            );
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::notifyShiftAssigned Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica: Turno Modificato
     *
     * Invia una notifica email al dipendente quando il suo turno viene modificato.
     *
     * @param int $shiftId ID del turno modificato
     * @param int $updatedBy ID dell'utente che ha effettuato la modifica
     * @param array $changes Array delle modifiche ['field' => ['old' => ..., 'new' => ...]]
     * @return bool True se l'invio ha successo
     */
    public static function notifyShiftUpdated(int $shiftId, int $updatedBy, array $changes = []): bool
    {
        try {
            $shift = self::getShiftDetails($shiftId);
            if (!$shift) {
                error_log("ShiftNotificationHelper::notifyShiftUpdated - Turno $shiftId non trovato");
                return false;
            }

            $user = self::getUserInfo((int)$shift['user_id']);
            if (!$user || empty($user['email'])) {
                return false;
            }

            // Non notificare se l'utente ha modificato il proprio turno
            if ($updatedBy === (int)$user['id']) {
                return true;
            }

            $updater = self::getUserInfo($updatedBy);
            $tenantName = self::getTenantName((int)$shift['tenant_id']);

            $startTime = self::formatTime($shift['start_time_override'] ?? $shift['type_start_time']);
            $endTime = self::formatTime($shift['end_time_override'] ?? $shift['type_end_time']);

            $templateData = [
                'EMAIL_TITLE'        => 'Turno modificato',
                'USER_NAME'          => $user['name'],
                'TENANT_NAME'        => $tenantName,
                'SHIFT_DATE'         => self::formatDate($shift['shift_date']),
                'SHIFT_TYPE_NAME'    => $shift['shift_type_name'],
                'SHIFT_START_TIME'   => $startTime,
                'SHIFT_END_TIME'     => $endTime,
                'SHIFT_STATUS'       => $shift['status'],
                'SHIFT_STATUS_LABEL' => self::getShiftStatusLabel($shift['status']),
                'SHIFT_NOTES'        => $shift['notes'] ?? '',
                'UPDATED_BY_NAME'    => $updater['name'] ?? 'Sistema',
                'HAS_CHANGES'        => !empty($changes),
                'SHIFTS_URL'         => self::getBaseUrl() . '/turni.php',
                'BASE_URL'           => self::getBaseUrl(),
                'YEAR'               => date('Y')
            ];

            // Aggiungi dettagli delle modifiche
            if (isset($changes['time'])) {
                $templateData['TIME_CHANGED'] = true;
                $templateData['OLD_TIME'] = $changes['time']['old'] ?? '';
                $templateData['NEW_TIME'] = $changes['time']['new'] ?? '';
            }
            if (isset($changes['status'])) {
                $templateData['STATUS_CHANGED'] = true;
                $templateData['OLD_STATUS'] = self::getShiftStatusLabel($changes['status']['old'] ?? '');
                $templateData['NEW_STATUS'] = self::getShiftStatusLabel($changes['status']['new'] ?? '');
            }
            if (isset($changes['notes'])) {
                $templateData['NOTES_CHANGED'] = true;
            }

            $html = self::renderTemplate('shift_updated.html', $templateData);
            $subject = "Turno modificato: " . self::formatDate($shift['shift_date']);

            return sendEmail(
                $user['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $shift['tenant_id'],
                        'user_id'   => $user['id'],
                        'action'    => 'shift_updated_notification'
                    ]
                ]
            );
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::notifyShiftUpdated Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica: Turno Cancellato
     *
     * Invia una notifica email al dipendente quando il suo turno viene cancellato.
     *
     * @param int $shiftId ID del turno cancellato
     * @param int $cancelledBy ID dell'utente che ha effettuato la cancellazione
     * @param string $reason Motivo della cancellazione (opzionale)
     * @return bool True se l'invio ha successo
     */
    public static function notifyShiftCancelled(int $shiftId, int $cancelledBy, string $reason = ''): bool
    {
        try {
            // Dobbiamo recuperare i dati del turno prima che venga eliminato
            // oppure il turno e gia in stato cancelled
            $db = Database::getInstance();
            $shift = $db->fetchOne(
                'SELECT ws.*, st.name as shift_type_name,
                        COALESCE(ws.start_time_override, st.start_time) as start_time,
                        COALESCE(ws.end_time_override, st.end_time) as end_time
                 FROM work_shifts ws
                 JOIN shift_types st ON ws.shift_type_id = st.id
                 WHERE ws.id = ?',
                [$shiftId]
            );

            if (!$shift) {
                error_log("ShiftNotificationHelper::notifyShiftCancelled - Turno $shiftId non trovato");
                return false;
            }

            $user = self::getUserInfo((int)$shift['user_id']);
            if (!$user || empty($user['email'])) {
                return false;
            }

            $canceller = self::getUserInfo($cancelledBy);
            $tenantName = self::getTenantName((int)$shift['tenant_id']);

            $templateData = [
                'EMAIL_TITLE'         => 'Turno cancellato',
                'USER_NAME'           => $user['name'],
                'TENANT_NAME'         => $tenantName,
                'SHIFT_DATE'          => self::formatDate($shift['shift_date']),
                'SHIFT_TYPE_NAME'     => $shift['shift_type_name'],
                'SHIFT_START_TIME'    => self::formatTime($shift['start_time']),
                'SHIFT_END_TIME'      => self::formatTime($shift['end_time']),
                'CANCELLED_BY_NAME'   => $canceller['name'] ?? 'Sistema',
                'CANCELLATION_REASON' => $reason,
                'SHIFTS_URL'          => self::getBaseUrl() . '/turni.php',
                'BASE_URL'            => self::getBaseUrl(),
                'YEAR'                => date('Y')
            ];

            $html = self::renderTemplate('shift_cancelled.html', $templateData);
            $subject = "Turno cancellato: " . self::formatDate($shift['shift_date']);

            return sendEmail(
                $user['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $shift['tenant_id'],
                        'user_id'   => $user['id'],
                        'action'    => 'shift_cancelled_notification'
                    ]
                ]
            );
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::notifyShiftCancelled Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica: Richiesta Modifica Ricevuta
     *
     * Invia una notifica email ai manager quando un dipendente crea una richiesta
     * di modifica/scambio/annullamento turno.
     *
     * @param int $requestId ID della richiesta
     * @return bool True se almeno una notifica e stata inviata
     */
    public static function notifyChangeRequestReceived(int $requestId): bool
    {
        try {
            $request = self::getRequestDetails($requestId);
            if (!$request) {
                error_log("ShiftNotificationHelper::notifyChangeRequestReceived - Richiesta $requestId non trovata");
                return false;
            }

            // Trova i manager del tenant (mai super_admin)
            $db = Database::getInstance();
            $utaHasDeletedAt = $db->fetchOne(
                "SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'user_tenant_access'
                   AND COLUMN_NAME = 'deleted_at'
                 LIMIT 1"
            );
            $utaWhere = $utaHasDeletedAt ? " AND uta.deleted_at IS NULL" : "";

            $tenantId = (int)($request['tenant_id'] ?? 0);
            $requesterId = (int)($request['requester_id'] ?? 0);

            $managers = $db->fetchAll(
                "SELECT DISTINCT u.id, u.name, u.email
                 FROM users u
                 WHERE u.deleted_at IS NULL
                   AND u.role = 'manager'
                   AND u.id != ?
                   AND (
                        u.tenant_id = ?
                        OR EXISTS (
                            SELECT 1
                            FROM user_tenant_access uta
                            WHERE uta.user_id = u.id
                              AND uta.tenant_id = ?" . $utaWhere . "
                        )
                   )",
                [$requesterId, $tenantId, $tenantId]
            );

            if (empty($managers)) {
                error_log("ShiftNotificationHelper::notifyChangeRequestReceived - Nessun manager trovato per tenant " . $request['tenant_id']);
                return false;
            }

            $tenantName = self::getTenantName((int)$request['tenant_id']);
            $requestType = $request['request_type'] ?? 'change';

            $baseTemplateData = [
                'EMAIL_TITLE'           => 'Nuova richiesta modifica turno',
                'TENANT_NAME'           => $tenantName,
                'REQUESTER_NAME'        => $request['requester_name'],
                'REQUEST_TYPE_LABEL'    => self::getRequestTypeLabel($requestType),
                'SHIFT_DATE'            => self::formatDate($request['shift_date']),
                'SHIFT_TYPE_NAME'       => $request['shift_type_name'],
                'SHIFT_START_TIME'      => self::formatTime($request['shift_start_time']),
                'SHIFT_END_TIME'        => self::formatTime($request['shift_end_time']),
                'REQUEST_DATE'          => self::formatDate($request['created_at']),
                'REQUEST_REASON'        => $request['reason'] ?? '',
                'REQUEST_TYPE_CHANGE'   => ($requestType === 'change'),
                'REQUEST_TYPE_SWAP'     => ($requestType === 'swap'),
                'REQUEST_TYPE_CANCEL'   => ($requestType === 'cancel'),
                'REQUESTED_START_TIME'  => self::formatTime($request['requested_start_time']),
                'REQUESTED_END_TIME'    => self::formatTime($request['requested_end_time']),
                'TARGET_USER_NAME'      => $request['target_user_name'] ?? '',
                'REQUESTS_URL'          => self::getBaseUrl() . '/turni.php?view=requests',
                'BASE_URL'              => self::getBaseUrl(),
                'YEAR'                  => date('Y')
            ];

            $successCount = 0;

            foreach ($managers as $manager) {
                if (empty($manager['email'])) {
                    continue;
                }

                $templateData = $baseTemplateData;
                $templateData['MANAGER_NAME'] = $manager['name'];

                $html = self::renderTemplate('shift_request_received.html', $templateData);
                $subject = "Richiesta modifica turno da " . $request['requester_name'];

                $sent = sendEmail(
                    $manager['email'],
                    $subject,
                    $html,
                    '',
                    [
                        'context' => [
                            'tenant_id' => $request['tenant_id'],
                            'user_id'   => $manager['id'],
                            'action'    => 'shift_request_received_notification'
                        ]
                    ]
                );

                if ($sent) {
                    $successCount++;
                }
            }

            return $successCount > 0;
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::notifyChangeRequestReceived Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica: Richiesta Approvata
     *
     * Invia una notifica email al dipendente quando la sua richiesta viene approvata.
     *
     * @param int $requestId ID della richiesta
     * @param int $approvedBy ID dell'utente che ha approvato
     * @param string $managerNotes Note del manager (opzionale)
     * @return bool True se l'invio ha successo
     */
    public static function notifyRequestApproved(int $requestId, int $approvedBy, string $managerNotes = ''): bool
    {
        try {
            $request = self::getRequestDetails($requestId);
            if (!$request) {
                error_log("ShiftNotificationHelper::notifyRequestApproved - Richiesta $requestId non trovata");
                return false;
            }

            $user = self::getUserInfo((int)$request['requester_id']);
            if (!$user || empty($user['email'])) {
                return false;
            }

            $approver = self::getUserInfo($approvedBy);
            $tenantName = self::getTenantName((int)$request['tenant_id']);
            $requestType = $request['request_type'] ?? 'change';

            $templateData = [
                'EMAIL_TITLE'          => 'Richiesta approvata',
                'USER_NAME'            => $user['name'],
                'TENANT_NAME'          => $tenantName,
                'REQUEST_TYPE_LABEL'   => self::getRequestTypeLabel($requestType),
                'SHIFT_DATE'           => self::formatDate($request['shift_date']),
                'SHIFT_TYPE_NAME'      => $request['shift_type_name'],
                'APPROVED_BY_NAME'     => $approver['name'] ?? 'Sistema',
                'APPROVAL_DATE'        => date('d/m/Y H:i'),
                'REQUEST_TYPE_CHANGE'  => ($requestType === 'change'),
                'REQUEST_TYPE_SWAP'    => ($requestType === 'swap'),
                'REQUEST_TYPE_CANCEL'  => ($requestType === 'cancel'),
                'NEW_START_TIME'       => self::formatTime($request['requested_start_time'] ?? $request['shift_start_time']),
                'NEW_END_TIME'         => self::formatTime($request['requested_end_time'] ?? $request['shift_end_time']),
                'TARGET_USER_NAME'     => $request['target_user_name'] ?? '',
                'MANAGER_NOTES'        => $managerNotes,
                'SHIFTS_URL'           => self::getBaseUrl() . '/turni.php',
                'BASE_URL'             => self::getBaseUrl(),
                'YEAR'                 => date('Y')
            ];

            $html = self::renderTemplate('shift_request_approved.html', $templateData);
            $subject = "Richiesta approvata: " . self::getRequestTypeLabel($requestType);

            return sendEmail(
                $user['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $request['tenant_id'],
                        'user_id'   => $user['id'],
                        'action'    => 'shift_request_approved_notification'
                    ]
                ]
            );
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::notifyRequestApproved Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica: Richiesta Rifiutata
     *
     * Invia una notifica email al dipendente quando la sua richiesta viene rifiutata.
     *
     * @param int $requestId ID della richiesta
     * @param int $rejectedBy ID dell'utente che ha rifiutato
     * @param string $rejectionReason Motivo del rifiuto (obbligatorio)
     * @return bool True se l'invio ha successo
     */
    public static function notifyRequestRejected(int $requestId, int $rejectedBy, string $rejectionReason): bool
    {
        try {
            $request = self::getRequestDetails($requestId);
            if (!$request) {
                error_log("ShiftNotificationHelper::notifyRequestRejected - Richiesta $requestId non trovata");
                return false;
            }

            $user = self::getUserInfo((int)$request['requester_id']);
            if (!$user || empty($user['email'])) {
                return false;
            }

            $rejecter = self::getUserInfo($rejectedBy);
            $tenantName = self::getTenantName((int)$request['tenant_id']);
            $requestType = $request['request_type'] ?? 'change';

            $templateData = [
                'EMAIL_TITLE'         => 'Richiesta rifiutata',
                'USER_NAME'           => $user['name'],
                'TENANT_NAME'         => $tenantName,
                'REQUEST_TYPE_LABEL'  => self::getRequestTypeLabel($requestType),
                'SHIFT_DATE'          => self::formatDate($request['shift_date']),
                'SHIFT_TYPE_NAME'     => $request['shift_type_name'],
                'SHIFT_START_TIME'    => self::formatTime($request['shift_start_time']),
                'SHIFT_END_TIME'      => self::formatTime($request['shift_end_time']),
                'REJECTED_BY_NAME'    => $rejecter['name'] ?? 'Sistema',
                'REJECTION_DATE'      => date('d/m/Y H:i'),
                'REJECTION_REASON'    => $rejectionReason,
                'SHIFTS_URL'          => self::getBaseUrl() . '/turni.php',
                'BASE_URL'            => self::getBaseUrl(),
                'YEAR'                => date('Y')
            ];

            $html = self::renderTemplate('shift_request_rejected.html', $templateData);
            $subject = "Richiesta rifiutata: " . self::getRequestTypeLabel($requestType);

            return sendEmail(
                $user['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $request['tenant_id'],
                        'user_id'   => $user['id'],
                        'action'    => 'shift_request_rejected_notification'
                    ]
                ]
            );
        } catch (Exception $e) {
            error_log("ShiftNotificationHelper::notifyRequestRejected Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica multipla: Turni Assegnati in Bulk
     *
     * Invia notifiche per una creazione multipla di turni (raggruppate per utente).
     *
     * @param array $shiftIds Array di ID turni creati
     * @param int $assignedBy ID dell'utente che ha effettuato l'assegnazione
     * @return int Numero di notifiche inviate con successo
     */
    public static function notifyBulkShiftsAssigned(array $shiftIds, int $assignedBy): int
    {
        $successCount = 0;

        // Raggruppa i turni per utente per evitare spam
        $shiftsByUser = [];
        foreach ($shiftIds as $shiftId) {
            $shift = self::getShiftDetails($shiftId);
            if ($shift) {
                $userId = (int)$shift['user_id'];
                if (!isset($shiftsByUser[$userId])) {
                    $shiftsByUser[$userId] = [];
                }
                $shiftsByUser[$userId][] = $shift;
            }
        }

        // Per ora, invia una notifica per ogni turno
        // In futuro si potrebbe creare un template "bulk" con lista turni
        foreach ($shiftIds as $shiftId) {
            if (self::notifyShiftAssigned($shiftId, $assignedBy)) {
                $successCount++;
            }
        }

        return $successCount;
    }
}

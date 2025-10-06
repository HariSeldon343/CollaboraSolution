<?php
// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once dirname(dirname(__DIR__)) . '/includes/session_init.php';

// POI: Headers (DOPO session_start di session_init.php)
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
    http_response_code(200);
    exit;
}

// Include necessary files
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth_simple.php';

// Initialize authentication
$auth = new AuthSimple();

// Check if user is authenticated
if (!$auth->checkAuth()) {
    http_response_code(401);
    die(json_encode([
        'success' => false,
        'error' => 'Non autorizzato',
        'message' => 'Authentication required'
    ]));
}

// Get current user
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    http_response_code(401);
    die(json_encode([
        'success' => false,
        'error' => 'Utente non trovato',
        'message' => 'User not found'
    ]));
}

// Tenant isolation
$tenant_id = $currentUser['tenant_id'] ?? null;
$user_id = $currentUser['id'];

try {
    $db = Database::getInstance()->getConnection();

    // Check if notifications table exists
    $tableExists = false;
    try {
        $checkTable = $db->query("SHOW TABLES LIKE 'notifications'");
        $tableExists = $checkTable->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Notifications table check failed: " . $e->getMessage());
    }

    // If table doesn't exist, return empty notifications
    if (!$tableExists) {
        echo json_encode([
            'success' => true,
            'notifications' => [],
            'count' => 0,
            'message' => 'Sistema di notifiche non ancora configurato'
        ]);
        exit;
    }

    // Ottieni notifiche non lette per l'utente corrente
    $query = "
        SELECT
            n.id,
            n.type,
            n.title,
            n.message,
            n.data,
            n.is_read,
            n.created_at,
            u.name as from_user_name,
            u.email as from_user_email
        FROM notifications n
        LEFT JOIN users u ON n.from_user_id = u.id
        WHERE n.user_id = :user_id";

    // Aggiungi isolamento tenant se disponibile
    if ($tenant_id) {
        $query .= " AND n.tenant_id = :tenant_id";
    }

    $query .= " AND n.is_read = 0
        ORDER BY n.created_at DESC
        LIMIT 50";

    $stmt = $db->prepare($query);
    $params = ['user_id' => $user_id];
    if ($tenant_id) {
        $params['tenant_id'] = $tenant_id;
    }

    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Processa i dati delle notifiche
    foreach ($notifications as &$notification) {
        if (!empty($notification['data'])) {
            $notification['data'] = json_decode($notification['data'], true);
        }
        // Formatta timestamp
        $notification['created_at_formatted'] = date('Y-m-d H:i:s', strtotime($notification['created_at']));
        $notification['time_ago'] = getTimeAgo($notification['created_at']);
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'count' => count($notifications)
    ]);

} catch (Exception $e) {
    error_log("API Notifiche - Errore: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'error' => 'Errore interno del server',
        'message' => 'Si è verificato un errore durante il recupero delle notifiche'
    ]));
}

/**
 * Funzione helper per ottenere la stringa "tempo fa"
 *
 * @param string $datetime Data e ora da convertire
 * @return string Stringa formattata del tempo trascorso
 */
function getTimeAgo(string $datetime): string {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'adesso';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minut' . ($minutes > 1 ? 'i' : 'o') . ' fa';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' or' . ($hours > 1 ? 'e' : 'a') . ' fa';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' giorn' . ($days > 1 ? 'i' : 'o') . ' fa';
    } else {
        return date('d M Y', $time);
    }
}
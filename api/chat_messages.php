<?php
/**
 * API per gestione messaggi chat
 *
 * Gestisce invio, modifica e cancellazione messaggi
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 */

// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../includes/session_init.php';
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Authentication validation
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Non autorizzato']));
}

// Tenant isolation
$tenant_id = $_SESSION['tenant_id'];
$user_id = $_SESSION['user_id'];

// Input sanitization
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? 'send';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    switch ($action) {
        case 'send':
            // Validazione input
            if (!isset($input['channel_id']) || !isset($input['content'])) {
                http_response_code(400);
                die(json_encode(['error' => 'Parametri mancanti']));
            }

            $channel_id = (int)$input['channel_id'];
            $content = trim($input['content']);
            $parent_message_id = isset($input['parent_message_id']) ? (int)$input['parent_message_id'] : null;
            $message_type = isset($input['message_type']) && in_array($input['message_type'], ['text', 'file', 'system', 'code', 'poll'])
                ? $input['message_type']
                : 'text';

            // Validazione contenuto
            if (empty($content)) {
                http_response_code(400);
                die(json_encode(['error' => 'Messaggio vuoto']));
            }

            if (strlen($content) > 10000) {
                http_response_code(400);
                die(json_encode(['error' => 'Messaggio troppo lungo (max 10000 caratteri)']));
            }

            // Verifica accesso al canale
            $sql = "SELECT COUNT(*) FROM channel_members
                    WHERE tenant_id = :tenant_id
                        AND user_id = :user_id
                        AND channel_id = :channel_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':user_id' => $user_id,
                ':channel_id' => $channel_id
            ]);

            if ($stmt->fetchColumn() == 0) {
                http_response_code(403);
                die(json_encode(['error' => 'Accesso al canale non autorizzato']));
            }

            // Genera sequence_id univoco
            $pdo->beginTransaction();

            try {
                // Ottieni prossimo sequence_id
                $sql = "SELECT COALESCE(MAX(sequence_id), 0) + 1 as next_seq
                        FROM chat_messages
                        WHERE tenant_id = :tenant_id";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([':tenant_id' => $tenant_id]);
                $next_sequence = $stmt->fetch(PDO::FETCH_ASSOC)['next_seq'];

                // Inserisci messaggio
                $sql = "INSERT INTO chat_messages (
                            tenant_id,
                            channel_id,
                            user_id,
                            parent_message_id,
                            message_type,
                            content,
                            content_plain,
                            sequence_id,
                            created_at
                        ) VALUES (
                            :tenant_id,
                            :channel_id,
                            :user_id,
                            :parent_message_id,
                            :message_type,
                            :content,
                            :content_plain,
                            :sequence_id,
                            NOW()
                        )";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':tenant_id' => $tenant_id,
                    ':channel_id' => $channel_id,
                    ':user_id' => $user_id,
                    ':parent_message_id' => $parent_message_id,
                    ':message_type' => $message_type,
                    ':content' => $content,
                    ':content_plain' => strip_tags($content), // Versione plain text per ricerca
                    ':sequence_id' => $next_sequence
                ]);

                $message_id = $pdo->lastInsertId();

                // Aggiorna contatori canale
                $sql = "UPDATE chat_channels
                        SET last_message_at = NOW(),
                            message_count = message_count + 1
                        WHERE id = :channel_id
                            AND tenant_id = :tenant_id";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':channel_id' => $channel_id,
                    ':tenant_id' => $tenant_id
                ]);

                // Se è una risposta, aggiorna contatore thread
                if ($parent_message_id) {
                    $sql = "UPDATE chat_messages
                            SET reply_count = reply_count + 1
                            WHERE id = :parent_id
                                AND tenant_id = :tenant_id";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':parent_id' => $parent_message_id,
                        ':tenant_id' => $tenant_id
                    ]);
                }

                $pdo->commit();

                // Recupera messaggio completo per la risposta
                $sql = "SELECT
                            m.id,
                            m.sequence_id,
                            m.channel_id,
                            m.user_id,
                            m.parent_message_id,
                            m.message_type,
                            m.content,
                            m.created_at,
                            u.nome AS user_name,
                            u.email AS user_email
                        FROM chat_messages m
                        INNER JOIN users u ON m.user_id = u.id
                        WHERE m.id = :id";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $message_id]);
                $message = $stmt->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'message' => $message
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'edit':
            // Modifica messaggio
            if (!isset($input['message_id']) || !isset($input['content'])) {
                http_response_code(400);
                die(json_encode(['error' => 'Parametri mancanti']));
            }

            $message_id = (int)$input['message_id'];
            $new_content = trim($input['content']);

            if (empty($new_content)) {
                http_response_code(400);
                die(json_encode(['error' => 'Contenuto vuoto']));
            }

            // Verifica proprietà del messaggio
            $sql = "SELECT content, user_id
                    FROM chat_messages
                    WHERE id = :id
                        AND tenant_id = :tenant_id
                        AND is_deleted = 0";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => $message_id,
                ':tenant_id' => $tenant_id
            ]);

            $message = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$message) {
                http_response_code(404);
                die(json_encode(['error' => 'Messaggio non trovato']));
            }

            if ($message['user_id'] != $user_id) {
                http_response_code(403);
                die(json_encode(['error' => 'Non autorizzato a modificare questo messaggio']));
            }

            $pdo->beginTransaction();

            try {
                // Salva storia modifiche
                $sql = "INSERT INTO message_edits (
                            tenant_id,
                            message_id,
                            user_id,
                            previous_content,
                            new_content
                        ) VALUES (
                            :tenant_id,
                            :message_id,
                            :user_id,
                            :previous_content,
                            :new_content
                        )";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':tenant_id' => $tenant_id,
                    ':message_id' => $message_id,
                    ':user_id' => $user_id,
                    ':previous_content' => $message['content'],
                    ':new_content' => $new_content
                ]);

                // Aggiorna messaggio
                $sql = "UPDATE chat_messages
                        SET content = :content,
                            content_plain = :content_plain,
                            is_edited = 1,
                            edit_count = edit_count + 1,
                            updated_at = NOW()
                        WHERE id = :id
                            AND tenant_id = :tenant_id";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':content' => $new_content,
                    ':content_plain' => strip_tags($new_content),
                    ':id' => $message_id,
                    ':tenant_id' => $tenant_id
                ]);

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message_id' => $message_id
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'delete':
            // Cancella messaggio (soft delete)
            if (!isset($input['message_id'])) {
                http_response_code(400);
                die(json_encode(['error' => 'ID messaggio mancante']));
            }

            $message_id = (int)$input['message_id'];

            // Verifica proprietà o ruolo admin
            $sql = "SELECT m.user_id, u.ruolo
                    FROM chat_messages m
                    INNER JOIN users u ON u.id = :user_id
                    WHERE m.id = :message_id
                        AND m.tenant_id = :tenant_id
                        AND m.is_deleted = 0";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':message_id' => $message_id,
                ':tenant_id' => $tenant_id,
                ':user_id' => $user_id
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                http_response_code(404);
                die(json_encode(['error' => 'Messaggio non trovato']));
            }

            // Controlla autorizzazione
            $canDelete = $result['user_id'] == $user_id ||
                        in_array($result['ruolo'], ['admin', 'super_admin']);

            if (!$canDelete) {
                http_response_code(403);
                die(json_encode(['error' => 'Non autorizzato a cancellare questo messaggio']));
            }

            // Soft delete
            $sql = "UPDATE chat_messages
                    SET is_deleted = 1,
                        deleted_at = NOW()
                    WHERE id = :id
                        AND tenant_id = :tenant_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => $message_id,
                ':tenant_id' => $tenant_id
            ]);

            echo json_encode([
                'success' => true,
                'message_id' => $message_id
            ]);
            break;

        case 'pin':
            // Pin/unpin messaggio
            if (!isset($input['message_id'])) {
                http_response_code(400);
                die(json_encode(['error' => 'ID messaggio mancante']));
            }

            $message_id = (int)$input['message_id'];
            $pin = isset($input['pin']) ? (bool)$input['pin'] : true;

            // Verifica ruolo admin o moderatore per il canale
            $sql = "SELECT cm.role, u.ruolo
                    FROM chat_messages m
                    INNER JOIN channel_members cm ON cm.channel_id = m.channel_id
                        AND cm.user_id = :user_id
                    INNER JOIN users u ON u.id = :user_id
                    WHERE m.id = :message_id
                        AND m.tenant_id = :tenant_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':message_id' => $message_id,
                ':tenant_id' => $tenant_id,
                ':user_id' => $user_id
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                http_response_code(404);
                die(json_encode(['error' => 'Messaggio non trovato']));
            }

            $canPin = in_array($result['role'], ['owner', 'admin']) ||
                     in_array($result['ruolo'], ['admin', 'super_admin']);

            if (!$canPin) {
                http_response_code(403);
                die(json_encode(['error' => 'Non autorizzato a pinnare messaggi']));
            }

            // Aggiorna stato pin
            $sql = "UPDATE chat_messages
                    SET is_pinned = :pinned
                    WHERE id = :id
                        AND tenant_id = :tenant_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':pinned' => $pin ? 1 : 0,
                ':id' => $message_id,
                ':tenant_id' => $tenant_id
            ]);

            echo json_encode([
                'success' => true,
                'message_id' => $message_id,
                'is_pinned' => $pin
            ]);
            break;

        case 'history':
            // Recupera storia messaggi di un canale
            if (!isset($input['channel_id'])) {
                http_response_code(400);
                die(json_encode(['error' => 'ID canale mancante']));
            }

            $channel_id = (int)$input['channel_id'];
            $limit = isset($input['limit']) ? min((int)$input['limit'], 100) : 50;
            $before_id = isset($input['before_id']) ? (int)$input['before_id'] : PHP_INT_MAX;

            // Verifica accesso al canale
            $sql = "SELECT COUNT(*) FROM channel_members
                    WHERE tenant_id = :tenant_id
                        AND user_id = :user_id
                        AND channel_id = :channel_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':user_id' => $user_id,
                ':channel_id' => $channel_id
            ]);

            if ($stmt->fetchColumn() == 0) {
                http_response_code(403);
                die(json_encode(['error' => 'Accesso al canale non autorizzato']));
            }

            // Recupera messaggi
            $sql = "SELECT
                        m.id,
                        m.sequence_id,
                        m.user_id,
                        m.parent_message_id,
                        m.message_type,
                        m.content,
                        m.is_edited,
                        m.is_pinned,
                        m.created_at,
                        m.updated_at,
                        u.nome AS user_name,
                        u.email AS user_email,
                        u.avatar_url AS user_avatar
                    FROM chat_messages m
                    INNER JOIN users u ON m.user_id = u.id
                    WHERE m.tenant_id = :tenant_id
                        AND m.channel_id = :channel_id
                        AND m.is_deleted = 0
                        AND m.id < :before_id
                    ORDER BY m.id DESC
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':tenant_id', $tenant_id, PDO::PARAM_INT);
            $stmt->bindValue(':channel_id', $channel_id, PDO::PARAM_INT);
            $stmt->bindValue(':before_id', $before_id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Inverti ordine per avere i messaggi dal più vecchio al più recente
            $messages = array_reverse($messages);

            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'has_more' => count($messages) === $limit
            ]);
            break;

        default:
            http_response_code(400);
            die(json_encode(['error' => 'Azione non valida']));
    }

} catch (Exception $e) {
    error_log("Chat Messages API Error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'error' => 'Errore server',
        'message' => DEBUG_MODE ? $e->getMessage() : 'Si è verificato un errore'
    ]));
}
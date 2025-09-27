<?php
/**
 * Esempi di utilizzo della classe Database per CollaboraNexio
 *
 * Questo file mostra vari esempi pratici di come utilizzare
 * la classe Database singleton per operazioni comuni
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 */

// Include la classe Database
require_once __DIR__ . '/db.php';

// ===================================
// ESEMPI DI UTILIZZO
// ===================================

try {
    // 1. OTTENERE L'ISTANZA DATABASE
    // --------------------------------
    // Metodo 1: Usando getInstance()
    $db = Database::getInstance();

    // Metodo 2: Usando la funzione helper
    $database = db();

    // Entrambi restituiscono la stessa istanza (singleton)

    // 2. QUERY SEMPLICI
    // --------------------------------

    // SELECT semplice
    $users = $db->fetchAll("SELECT * FROM users WHERE active = 1");

    // SELECT con parametri
    $user = $db->fetchOne(
        "SELECT * FROM users WHERE id = :id AND tenant_id = :tenant",
        [':id' => 123, ':tenant' => 1]
    );

    // Contare record
    $count = $db->fetchColumn(
        "SELECT COUNT(*) FROM users WHERE tenant_id = ?",
        [1]
    );

    // 3. INSERT CON PREPARED STATEMENTS
    // --------------------------------

    $sql = "INSERT INTO users (name, email, password, tenant_id) VALUES (:name, :email, :password, :tenant)";

    $params = [
        ':name' => 'Mario Rossi',
        ':email' => 'mario@example.com',
        ':password' => password_hash('password123', PASSWORD_BCRYPT),
        ':tenant' => 1
    ];

    $stmt = $db->query($sql, $params);
    $newUserId = $db->lastInsertId();
    echo "Nuovo utente creato con ID: " . $newUserId;

    // 4. UPDATE CON PREPARED STATEMENTS
    // --------------------------------

    $sql = "UPDATE users SET last_login = NOW() WHERE id = :id AND tenant_id = :tenant";

    $stmt = $db->query($sql, [
        ':id' => $newUserId,
        ':tenant' => 1
    ]);

    $rowsAffected = $db->rowCount($stmt);
    echo "Righe aggiornate: " . $rowsAffected;

    // 5. DELETE CON PREPARED STATEMENTS
    // --------------------------------

    $sql = "DELETE FROM sessions WHERE expired_at < :date";
    $stmt = $db->query($sql, [':date' => date('Y-m-d H:i:s')]);

    // 6. TRANSAZIONI
    // --------------------------------

    // Inizia transazione
    $db->beginTransaction();

    try {
        // Operazione 1: Inserisci ordine
        $orderSql = "INSERT INTO orders (customer_id, total, tenant_id) VALUES (?, ?, ?)";
        $orderStmt = $db->query($orderSql, [123, 99.99, 1]);
        $orderId = $db->lastInsertId();

        // Operazione 2: Inserisci dettagli ordine
        $detailSql = "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
        $db->query($detailSql, [$orderId, 456, 2, 49.99]);

        // Operazione 3: Aggiorna inventario
        $inventorySql = "UPDATE products SET stock = stock - ? WHERE id = ? AND tenant_id = ?";
        $db->query($inventorySql, [2, 456, 1]);

        // Se tutto va bene, conferma la transazione
        $db->commit();
        echo "Ordine completato con successo!";

    } catch (Exception $e) {
        // Se qualcosa va storto, annulla tutto
        $db->rollback();
        echo "Errore nell'ordine: " . $e->getMessage();
    }

    // 7. PREPARED STATEMENTS RIUTILIZZABILI
    // --------------------------------

    // Prepara lo statement una volta
    $stmt = $db->prepare("SELECT * FROM products WHERE category_id = :cat AND price <= :price");

    // Esegui con diversi parametri
    $db->execute($stmt, [':cat' => 1, ':price' => 100]);
    $products1 = $stmt->fetchAll();

    $db->execute($stmt, [':cat' => 2, ':price' => 50]);
    $products2 = $stmt->fetchAll();

    // 8. QUERY BATCH CON TRANSAZIONE
    // --------------------------------

    $updates = [
        ['id' => 1, 'status' => 'active'],
        ['id' => 2, 'status' => 'inactive'],
        ['id' => 3, 'status' => 'pending']
    ];

    $db->beginTransaction();

    try {
        $stmt = $db->prepare("UPDATE users SET status = :status WHERE id = :id");

        foreach ($updates as $update) {
            $db->execute($stmt, [
                ':id' => $update['id'],
                ':status' => $update['status']
            ]);
        }

        $db->commit();
        echo "Tutti gli aggiornamenti completati!";

    } catch (Exception $e) {
        $db->rollback();
        echo "Errore negli aggiornamenti batch";
    }

    // 9. GESTIONE ERRORI PERSONALIZZATA
    // --------------------------------

    try {
        // Query che potrebbe fallire
        $result = $db->fetchOne("SELECT * FROM unknown_table");
    } catch (PDOException $e) {
        // Log dell'errore (internamente)
        error_log("Errore database: " . $e->getMessage());

        // Messaggio user-friendly
        echo "Si è verificato un errore. Riprova più tardi.";
    }

    // 10. UTILIZZO FUNZIONI HELPER
    // --------------------------------

    // Usando la funzione query() globale
    $stmt = query("SELECT * FROM settings WHERE tenant_id = ?", [1]);
    $settings = $stmt->fetchAll();

    // 11. CONTROLLO CONNESSIONE
    // --------------------------------

    if ($db->isConnected()) {
        echo "Database connesso";

        // Ottieni informazioni
        $stats = $db->getConnectionStats();
        echo "Driver: " . $stats['driver'];
        echo "Versione: " . $stats['server_version'];
    }

    // 12. QUERY COMPLESSE CON JOIN
    // --------------------------------

    $sql = "
        SELECT
            u.id,
            u.name,
            u.email,
            COUNT(o.id) as order_count,
            SUM(o.total) as total_spent
        FROM
            users u
            LEFT JOIN orders o ON u.id = o.customer_id
        WHERE
            u.tenant_id = :tenant
            AND u.created_at >= :date
        GROUP BY
            u.id
        HAVING
            order_count > 0
        ORDER BY
            total_spent DESC
        LIMIT 10
    ";

    $topCustomers = $db->fetchAll($sql, [
        ':tenant' => 1,
        ':date' => date('Y-m-d', strtotime('-30 days'))
    ]);

    // 13. ESCAPE MANUALE (usa solo se necessario!)
    // --------------------------------
    // NOTA: Preferire sempre prepared statements!

    $unsafeInput = "'; DROP TABLE users; --";
    $safeInput = $db->quote($unsafeInput);
    // Risultato: ''\'; DROP TABLE users; --'

    // 14. MULTI-TENANT QUERIES
    // --------------------------------

    // Sempre includere tenant_id nelle query
    $tenantId = $_SESSION['tenant_id'] ?? 1;

    // Esempio di query multi-tenant sicura
    $products = $db->fetchAll(
        "SELECT * FROM products WHERE tenant_id = :tenant AND category = :category",
        [':tenant' => $tenantId, ':category' => 'electronics']
    );

    // 15. PAGINAZIONE
    // --------------------------------

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    // Conta totale record
    $total = $db->fetchColumn(
        "SELECT COUNT(*) FROM products WHERE tenant_id = ?",
        [$tenantId]
    );

    // Ottieni record paginati
    $products = $db->fetchAll(
        "SELECT * FROM products WHERE tenant_id = ? ORDER BY name LIMIT ? OFFSET ?",
        [$tenantId, $perPage, $offset]
    );

    $totalPages = ceil($total / $perPage);

} catch (Exception $e) {
    // Gestione errori globale
    error_log("Errore critico: " . $e->getMessage());
    echo "Si è verificato un errore imprevisto";
}

// ===================================
// BEST PRACTICES
// ===================================

/**
 * 1. Usa sempre prepared statements per i dati utente
 * 2. Includi sempre tenant_id nelle query multi-tenant
 * 3. Usa transazioni per operazioni multiple correlate
 * 4. Gestisci sempre le eccezioni PDO
 * 5. Non esporre mai dettagli errori SQL agli utenti
 * 6. Valida sempre i dati prima di inserirli nel database
 * 7. Usa indici appropriati per query frequenti
 * 8. Limita sempre i risultati con LIMIT quando appropriato
 * 9. Chiudi le connessioni solo se necessario (PHP lo fa automaticamente)
 * 10. Log degli errori per debugging, messaggi generici agli utenti
 */
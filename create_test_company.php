<?php
/**
 * Script per creare una Test Company eliminabile
 *
 * Questo script crea una seconda azienda nel database che PUÒ essere eliminata,
 * a differenza della Demo Company (ID=1) che è protetta.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Verifica se Test Company già esiste
    $existingCompany = $db->fetchOne(
        'SELECT id, name, denominazione, domain, status, deleted_at FROM tenants WHERE id = 2',
        []
    );

    if ($existingCompany) {
        echo "✓ Test Company già esistente!\n\n";
        echo "Dettagli:\n";
        echo "  - ID: {$existingCompany['id']}\n";
        echo "  - Nome: {$existingCompany['name']}\n";
        echo "  - Denominazione: " . ($existingCompany['denominazione'] ?? 'N/A') . "\n";
        echo "  - Domain: " . ($existingCompany['domain'] ?? 'N/A') . "\n";
        echo "  - Status: " . ($existingCompany['status'] ?? 'N/A') . "\n";
        echo "  - Eliminato: " . ($existingCompany['deleted_at'] ? 'Sì (soft-deleted)' : 'No') . "\n\n";
        echo "Questa azienda può essere eliminata per testare la funzionalità di eliminazione.\n";
        echo "La Demo Company (ID=1) rimane protetta e non può essere eliminata.\n";
        exit(0);
    }

    // Crea Test Company
    $db->beginTransaction();

    try {
        // Prepara le sedi operative come JSON array
        $sediOperative = json_encode([
            [
                'indirizzo' => 'Via Test Operativa',
                'civico' => '5',
                'cap' => '20100',
                'comune' => 'Milano',
                'provincia' => 'MI'
            ]
        ]);

        // Inserisci l'azienda con la struttura REALE della tabella
        $stmt = $conn->prepare("
            INSERT INTO tenants (
                id,
                name,
                denominazione,
                code,
                codice_fiscale,
                partita_iva,
                domain,
                sede_legale_indirizzo,
                sede_legale_civico,
                sede_legale_comune,
                sede_legale_provincia,
                sede_legale_cap,
                sedi_operative,
                settore_merceologico,
                numero_dipendenti,
                capitale_sociale,
                telefono,
                email,
                pec,
                manager_id,
                rappresentante_legale,
                status,
                plan_type,
                max_users,
                max_storage_gb,
                settings,
                created_at,
                updated_at,
                deleted_at
            ) VALUES (
                2,
                'Test Company',
                'Test Company S.r.l.',
                'TEST001',
                'TSTCMP00A01H501Z',
                '12345678901',
                'testcompany.local',
                'Via Test',
                '10',
                'Milano',
                'MI',
                '20100',
                :sedi_operative,
                'informatica',
                5,
                10000.00,
                '+39 02 12345678',
                'test@example.com',
                'test@pec.example.com',
                1,
                'Test Administrator',
                'active',
                'professional',
                50,
                500,
                '{}',
                NOW(),
                NOW(),
                NULL
            )
        ");

        $stmt->execute([
            ':sedi_operative' => $sediOperative
        ]);

        $db->commit();

        echo "✓ Test Company creata con successo!\n\n";
        echo "Dettagli:\n";
        echo "  - ID: 2\n";
        echo "  - Nome: Test Company\n";
        echo "  - Denominazione: Test Company S.r.l.\n";
        echo "  - Codice Fiscale: TSTCMP00A01H501Z\n";
        echo "  - Partita IVA: 12345678901\n";
        echo "  - Domain: testcompany.local\n";
        echo "  - Status: active\n";
        echo "  - Manager ID: 1\n\n";
        echo "Questa azienda può essere eliminata per testare la funzionalità di eliminazione.\n";
        echo "La Demo Company (ID=1) rimane protetta e non può essere eliminata.\n";

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    echo "✗ Errore nella creazione Test Company: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
/**
 * Tenant Validation Functions (Shared)
 *
 * Funzioni di validazione condivise tra create.php e update.php
 * NON contiene logica di esecuzione, solo definizioni di funzioni
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 * @created 2025-12-17 (BUG-155 Fix)
 */

declare(strict_types=1);

/**
 * Valida Codice Fiscale italiano
 */
function validateCodiceFiscale(string $cf): bool {
    // Pattern regex per CF italiano (16 caratteri alfanumerici)
    // 6 lettere + 2 numeri + 1 lettera + 2 numeri + 1 lettera + 3 numeri + 1 lettera
    $pattern = '/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/i';
    return preg_match($pattern, strtoupper($cf)) === 1;
}

/**
 * Valida Partita IVA italiana
 */
function validatePartitaIva(string $piva): bool {
    // Rimuove spazi e caratteri non numerici
    $piva = preg_replace('/[^0-9]/', '', $piva);

    // Deve essere esattamente 11 cifre
    if (strlen($piva) !== 11) {
        return false;
    }

    // Verifica checksum con algoritmo Luhn modificato per P.IVA italiana
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $digit = (int)$piva[$i];

        if ($i % 2 === 0) {
            // Posizioni dispari (0, 2, 4, 6, 8)
            $sum += $digit;
        } else {
            // Posizioni pari (1, 3, 5, 7, 9)
            $double = $digit * 2;
            $sum += ($double > 9) ? ($double - 9) : $double;
        }
    }

    $checkDigit = (10 - ($sum % 10)) % 10;

    return $checkDigit === (int)$piva[10];
}

/**
 * Valida indirizzo sede legale completo
 */
function validateSedeLegale(array $sede): array {
    $errors = [];

    if (empty($sede['indirizzo'])) {
        $errors[] = 'Indirizzo sede legale obbligatorio';
    }
    if (empty($sede['civico'])) {
        $errors[] = 'Civico sede legale obbligatorio';
    }
    if (empty($sede['comune'])) {
        $errors[] = 'Comune sede legale obbligatorio';
    }
    if (empty($sede['provincia'])) {
        $errors[] = 'Provincia sede legale obbligatoria';
    } elseif (strlen($sede['provincia']) !== 2) {
        $errors[] = 'Provincia deve essere 2 caratteri (es. MI, RM)';
    }
    if (empty($sede['cap'])) {
        $errors[] = 'CAP sede legale obbligatorio';
    } elseif (!preg_match('/^\d{5}$/', $sede['cap'])) {
        $errors[] = 'CAP deve essere 5 cifre';
    }

    return $errors;
}

/**
 * Valida formato telefono italiano
 */
function validateTelefono(string $tel): bool {
    // Pattern per telefoni italiani: +39 seguito da 6-11 cifre
    // Accetta formati: +39 02 1234567, +39 02 12345678, +39 3331234567, 0212345678
    $pattern = '/^(\+39\s?)?0?\d{6,11}$/';
    return preg_match($pattern, str_replace([' ', '-', '.'], '', $tel)) === 1;
}

/**
 * Valida sedi operative (max 5)
 */
function validateSediOperative(array $sedi): array {
    $errors = [];

    if (count($sedi) > 5) {
        $errors[] = 'Massimo 5 sedi operative consentite';
    }

    foreach ($sedi as $index => $sede) {
        if (empty($sede['indirizzo'])) {
            $errors[] = "Sede operativa #" . ($index + 1) . ": indirizzo obbligatorio";
        }
        if (empty($sede['comune'])) {
            $errors[] = "Sede operativa #" . ($index + 1) . ": comune obbligatorio";
        }
        if (!empty($sede['cap']) && !preg_match('/^\d{5}$/', $sede['cap'])) {
            $errors[] = "Sede operativa #" . ($index + 1) . ": CAP deve essere 5 cifre";
        }
        if (!empty($sede['provincia']) && strlen($sede['provincia']) !== 2) {
            $errors[] = "Sede operativa #" . ($index + 1) . ": provincia deve essere 2 caratteri";
        }
    }

    return $errors;
}

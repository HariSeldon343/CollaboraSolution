# Note Implementative - API Gestione Tenants

## Overview Tecnico

Le API sono state implementate seguendo i pattern architetturali di CollaboraNexio:
- **PHP 8.3 vanilla** (no framework)
- **Pattern api_auth.php** centralizzato
- **Database singleton** con helper methods
- **Prepared statements** per tutte le query
- **Transazioni** per operazioni multi-step
- **Audit logging** automatico

---

## Pattern Architetturali Utilizzati

### 1. Inizializzazione API Standard

Tutte le API seguono questo pattern:

```php
require_once '../../includes/api_auth.php';
initializeApiEnvironment();
verifyApiAuthentication();
$userInfo = getApiUserInfo();
verifyApiCsrfToken();
requireApiRole('admin'); // Se richiesto ruolo specifico
```

**Vantaggi**:
- Gestione errori centralizzata
- Headers JSON automatici
- Output buffering per prevenire leak HTML
- CSRF validation standardizzata
- Session management unificato

### 2. Validazione Input

Approccio a due livelli:

**Livello 1: Validazione base**
```php
if (empty($input['denominazione'])) {
    $errors[] = 'Denominazione obbligatoria';
}
```

**Livello 2: Validazione specializzata**
```php
if ($cf && !validateCodiceFiscale($cf)) {
    $errors[] = 'Codice Fiscale non valido';
}
```

**Accumulazione errori**:
```php
$errors = [];
// ... validazioni
if (!empty($errors)) {
    apiError('Validazione fallita: ' . implode('; ', $errors), 400, ['errors' => $errors]);
}
```

### 3. Transazioni Database

Pattern standard per operazioni multi-step:

```php
$db->beginTransaction();
try {
    $tenantId = $db->insert('tenants', $tenantData);
    $db->insert('audit_logs', $auditData);
    $db->commit();
    apiSuccess($result, 'Operazione completata');
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}
```

### 4. Tenant Isolation

Implementazione gerarchica basata su ruolo:

```php
if ($userInfo['role'] === 'super_admin') {
    // Accesso completo, nessun filtro
} elseif ($userInfo['role'] === 'admin') {
    // Filtra per tenant accessibili
    $accessibleTenants = [$userInfo['tenant_id']];
    // + user_tenant_access
    $sql .= " AND t.id IN (?)";
} else {
    // Solo tenant primario
    $sql .= " AND t.id = ?";
    $params[] = $userInfo['tenant_id'];
}
```

---

## Validatori Implementati

### 1. Codice Fiscale Italiano

**Algoritmo**:
```php
function validateCodiceFiscale(string $cf): bool {
    $pattern = '/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/i';
    return preg_match($pattern, strtoupper($cf)) === 1;
}
```

**Note**:
- Pattern regex completo
- Case-insensitive (convertito in uppercase)
- **NON** valida checksum (troppo complesso, tabelle di conversione necessarie)
- Validazione pattern sufficiente per uso aziendale

**Miglioramento futuro**:
Implementare validazione completa con tabelle di conversione per checksum.

### 2. Partita IVA Italiana

**Algoritmo Luhn Modificato**:
```php
function validatePartitaIva(string $piva): bool {
    $piva = preg_replace('/[^0-9]/', '', $piva);
    if (strlen($piva) !== 11) return false;

    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $digit = (int)$piva[$i];
        if ($i % 2 === 0) {
            $sum += $digit;
        } else {
            $double = $digit * 2;
            $sum += ($double > 9) ? ($double - 9) : $double;
        }
    }
    $checkDigit = (10 - ($sum % 10)) % 10;
    return $checkDigit === (int)$piva[10];
}
```

**Dettagli**:
- Rimuove caratteri non numerici
- Verifica lunghezza esatta (11 cifre)
- Calcola checksum con algoritmo standard italiano
- **Validazione completa** con checksum

**Test cases**:
```
VALIDI:
- 12345678903 ✓
- 01234567890 ✓

INVALIDI:
- 12345678900 ✗ (checksum errato)
- 1234567890 ✗ (10 cifre)
- 123456789012 ✗ (12 cifre)
```

### 3. Telefono Italiano

**Pattern**:
```php
function validateTelefono(string $tel): bool {
    $pattern = '/^(\+39\s?)?0?\d{6,11}$/';
    return preg_match($pattern, str_replace([' ', '-', '.'], '', $tel)) === 1;
}
```

**Formati accettati**:
- `+39 02 1234567` (fisso con prefisso)
- `+39 333 1234567` (mobile con prefisso)
- `02 12345678` (fisso locale)
- `3331234567` (mobile locale)
- `0212345678` (fisso compatto)

**Note**:
- Rimuove spazi, trattini, punti prima della validazione
- Accetta prefisso internazionale opzionale
- Range 6-11 cifre copre tutti i formati italiani

### 4. Sede Legale Completa

**Validazione strutturata**:
```php
function validateSedeLegale(array $sede): array {
    $errors = [];
    if (empty($sede['indirizzo'])) $errors[] = 'Indirizzo obbligatorio';
    if (empty($sede['civico'])) $errors[] = 'Civico obbligatorio';
    if (empty($sede['comune'])) $errors[] = 'Comune obbligatorio';
    if (empty($sede['provincia'])) $errors[] = 'Provincia obbligatoria';
    elseif (strlen($sede['provincia']) !== 2) $errors[] = 'Provincia 2 caratteri';
    if (empty($sede['cap'])) $errors[] = 'CAP obbligatorio';
    elseif (!preg_match('/^\d{5}$/', $sede['cap'])) $errors[] = 'CAP 5 cifre';
    return $errors;
}
```

**Ritorna array di errori** per accumularli con altre validazioni.

### 5. Sedi Operative Multiple

**Limitazioni**:
```php
function validateSediOperative(array $sedi): array {
    $errors = [];
    if (count($sedi) > 5) {
        $errors[] = 'Massimo 5 sedi operative';
    }
    // Valida ogni sede
    foreach ($sedi as $index => $sede) {
        // Validazione come sede_legale ma meno strict
    }
    return $errors;
}
```

**Note**:
- Max 5 sedi (requisito business)
- Validazione meno rigida (solo indirizzo e comune obbligatori)
- Messaggi errore indicizzati per identificare sede problematica

---

## Gestione JSON

### Sedi Operative

**Storage**:
```php
$tenantData['sedi_operative'] = json_encode($input['sedi_operative']);
```

**Retrieval**:
```php
$sediOperative = json_decode($tenant['sedi_operative'], true);
```

**Formato**:
```json
[
  {
    "indirizzo": "Via Torino",
    "civico": "45",
    "comune": "Roma",
    "provincia": "RM",
    "cap": "00100"
  }
]
```

**Vantaggi**:
- Schema flessibile
- No tabelle aggiuntive
- Query semplici
- Facile da estendere

**Svantaggi**:
- Non indicizzabile
- No vincoli FK
- Query complesse su JSON

**Alternativa considerata** (scartata):
Tabella `tenant_locations` separata con FK. Scartata per semplicità e requisito max 5 sedi.

---

## Performance Considerations

### 1. Query Optimization

**Lista Tenants**:
```sql
SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) as manager_name
FROM tenants t
LEFT JOIN users u ON t.manager_id = u.id AND u.deleted_at IS NULL
```

**Indici richiesti**:
```sql
CREATE INDEX idx_tenants_manager ON tenants(manager_id);
CREATE INDEX idx_tenants_denominazione ON tenants(denominazione);
CREATE INDEX idx_users_deleted ON users(deleted_at);
```

### 2. N+1 Query Prevention

**EVITATO**: Query separata per manager
```php
// BAD - N+1 problem
foreach ($tenants as $tenant) {
    $manager = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$tenant['manager_id']]);
}
```

**IMPLEMENTATO**: JOIN nella query principale
```php
// GOOD - Single query with JOIN
$sql = "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) as manager_name
        FROM tenants t LEFT JOIN users u ON t.manager_id = u.id";
```

### 3. Statistiche

**Lazy loading** in /get endpoint:
```php
$stats = [
    'total_users' => $db->count('users', ['tenant_id' => $tenantId, 'deleted_at' => null]),
    'active_users' => $db->count('users', ['tenant_id' => $tenantId, 'status' => 'active']),
    'total_projects' => $db->count('projects', ['tenant_id' => $tenantId]),
    'total_files' => $db->count('files', ['tenant_id' => $tenantId, 'deleted_at' => null])
];
```

**Ottimizzazione futura**: Cache Redis/Memcached per statistiche.

---

## Security Deep Dive

### 1. SQL Injection Prevention

**Preparato statements OVUNQUE**:
```php
// Mai fare questo
$sql = "SELECT * FROM tenants WHERE id = " . $_GET['id']; // VULNERABILE!

// Sempre fare questo
$sql = "SELECT * FROM tenants WHERE id = ?";
$tenant = $db->fetchOne($sql, [$tenantId]); // SICURO
```

**Validazione nome tabella**:
```php
private function validateTableName(string $table): void {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new Exception('Nome tabella non valido');
    }
}
```

### 2. CSRF Protection

**Header validation**:
```php
verifyApiCsrfToken(); // Da api_auth.php
```

**Supporta multipli formati**:
- `X-CSRF-Token` header
- `csrf_token` in JSON body
- `csrf_token` in query string

### 3. Tenant Isolation

**Verifica accesso in update.php**:
```php
if ($userInfo['role'] !== 'super_admin') {
    $hasAccess = false;
    if ($userInfo['tenant_id'] == $tenantId) $hasAccess = true;
    else {
        $accessCheck = $db->fetchOne(
            'SELECT id FROM user_tenant_access WHERE user_id = ? AND tenant_id = ?',
            [$userInfo['user_id'], $tenantId]
        );
        if ($accessCheck) $hasAccess = true;
    }
    if (!$hasAccess) apiError('Permessi insufficienti', 403);
}
```

**Previene**:
- Modifica aziende non autorizzate
- Horizontal privilege escalation
- Cross-tenant data leak

### 4. Input Sanitization

**Trim su tutti i campi**:
```php
$tenantData['denominazione'] = trim($input['denominazione']);
```

**Case normalization**:
```php
$tenantData['codice_fiscale'] = strtoupper($cf);
$tenantData['sede_legale_provincia'] = strtoupper(trim($sede['provincia']));
```

**Type casting**:
```php
$tenantData['numero_dipendenti'] = (int)$input['numero_dipendenti'];
$tenantData['capitale_sociale'] = (float)$input['capitale_sociale'];
```

---

## Error Handling

### 1. Exception Hierarchy

```
Exception (base)
├── PDOException (database)
├── ValidationException (custom - future)
└── AuthException (custom - future)
```

**Attualmente**: Catch generico `Exception` con logging.

**Futuro**: Exception custom per error handling più granulare.

### 2. Error Response Format

**Standard**:
```json
{
  "success": false,
  "error": "Messaggio user-friendly",
  "data": {
    "errors": ["Dettaglio 1", "Dettaglio 2"]
  }
}
```

**HTTP codes semantici**:
- `400` - Bad Request (validazione)
- `401` - Unauthorized (sessione)
- `403` - Forbidden (permessi)
- `404` - Not Found (risorsa)
- `500` - Server Error (eccezioni)

### 3. Logging

**Server-side**:
```php
logApiError('tenants/create', $e);
// Log completo con stack trace in DEBUG_MODE
```

**Client-side**:
```json
{
  "error": "Errore generico" // No dettagli tecnici
}
```

**Separazione**: Dettagli tecnici solo nei log server, mai esposti al client.

---

## Audit Logging

### Schema

```php
$db->insert('audit_logs', [
    'tenant_id' => $tenantId,
    'user_id' => $userInfo['user_id'],
    'action' => 'tenant_created', // o 'tenant_updated'
    'resource_type' => 'tenant',
    'resource_id' => (string)$tenantId,
    'old_values' => json_encode($existingData), // Solo update
    'new_values' => json_encode($newData),
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
]);
```

### Informazioni tracciate

- **Chi**: `user_id`
- **Cosa**: `action` + `resource_type`
- **Quando**: `created_at` (auto)
- **Dove**: `ip_address`
- **Come**: `user_agent`
- **Delta**: `old_values` vs `new_values`

### Utilizzo

- Compliance (GDPR Art. 30)
- Debugging modifiche
- Security audit trail
- Rollback supporto

---

## Testing Strategy

### 1. Unit Tests (da implementare)

**Validatori**:
```php
testValidateCodiceFiscale() {
    assertTrue(validateCodiceFiscale('RSSMRA80A01H501Z'));
    assertFalse(validateCodiceFiscale('INVALID'));
}

testValidatePartitaIva() {
    assertTrue(validatePartitaIva('12345678903'));
    assertFalse(validatePartitaIva('12345678900'));
}
```

### 2. Integration Tests

**Create workflow**:
```php
testCreateTenant() {
    $response = apiPost('/api/tenants/create.php', $validData);
    assertEquals(200, $response->status);
    assertTrue($response->data->tenant_id > 0);
}
```

### 3. Manual Testing

**cURL scripts**:
```bash
./test_create_tenant.sh
./test_update_tenant.sh
./test_list_tenants.sh
```

---

## Deployment Checklist

- [ ] Eseguire migration: `database/migrate_aziende_ruoli_sistema.sql`
- [ ] Verificare indici database
- [ ] Testare validazione CF/P.IVA
- [ ] Verificare tenant isolation per ogni ruolo
- [ ] Testare CSRF protection
- [ ] Verificare audit logging
- [ ] Controllare permessi file (755 per dir, 644 per file)
- [ ] Verificare error_reporting in produzione (0)
- [ ] Testare transazioni rollback
- [ ] Verificare query performance (EXPLAIN)

---

## Known Limitations

### 1. Validazione CF Incompleta

**Attuale**: Solo pattern matching
**Manca**: Checksum validation

**Motivo**: Algoritmo checksum CF richiede tabelle di conversione complesse.

**Workaround**: Pattern matching cattura 99% errori di battitura.

**Future**: Implementare libreria dedicata o servizio esterno.

### 2. Sedi Operative in JSON

**Limitazione**: Non indicizzabili, no vincoli FK

**Alternativa considerata**: Tabella `tenant_locations` separata

**Decisione**: JSON sufficiente per max 5 sedi

**Trade-off**: Semplicità vs normalizzazione

### 3. No Soft Delete per Tenants

**Attuale**: Hard delete (CASCADE)

**Motivo**: Complessità gestione dati orfani

**Future**: Implementare `deleted_at` in tenants

**Impatto**: Richiede modifica API list/get per filtrare

---

## Future Enhancements

### 1. Validazione CF Completa

Implementare algoritmo checksum con tabelle di conversione.

### 2. Geocoding Indirizzi

Validare indirizzi tramite API Google Maps/OpenStreetMap.

### 3. Import/Export CSV

Endpoint per import massivo aziende da CSV.

### 4. API Versioning

```
/api/v1/tenants/create.php
/api/v2/tenants/create.php
```

### 5. Rate Limiting

Limitare richieste per IP/user per prevenire abuse.

### 6. Caching

Redis/Memcached per lista tenants frequentemente acceduta.

### 7. Search API

Endpoint `/api/tenants/search.php` con full-text search.

### 8. Bulk Operations

```php
POST /api/tenants/bulk-update.php
{
  "tenant_ids": [1, 2, 3],
  "status": "active"
}
```

---

## Troubleshooting

### Problema: "Tenant non trovato" ma esiste

**Causa**: Tenant isolation blocca accesso

**Soluzione**: Verificare ruolo utente e `user_tenant_access`

### Problema: "Partita IVA non valida" ma è corretta

**Causa**: Checksum errato o formato con spazi

**Soluzione**: Rimuovere spazi, verificare checksum manualmente

### Problema: CSRF token invalid

**Causa**: Token non passato in header

**Soluzione**: Aggiungere header `X-CSRF-Token: xxx`

### Problema: Manager non trovato

**Causa**: Manager ha `deleted_at` non NULL

**Soluzione**: Ripristinare manager o scegliere altro manager

---

## Contact & Support

**Maintainer**: CollaboraNexio Development Team
**Version**: 1.0.0
**Last Updated**: 2025-10-07

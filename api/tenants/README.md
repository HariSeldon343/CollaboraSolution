# API Gestione Aziende (Tenants)

Documentazione completa delle API per la gestione delle aziende nel sistema CollaboraNexio.

## Overview

Queste API implementano la gestione completa delle aziende (tenants) con il nuovo schema database esteso che include:
- Dati fiscali (Codice Fiscale, Partita IVA)
- Sede legale completa
- Sedi operative multiple (max 5)
- Informazioni aziendali (settore, dipendenti, capitale sociale)
- Contatti (telefono, email, PEC)
- Manager e rappresentante legale

## Endpoints Disponibili

### 1. `POST /api/tenants/create.php`
Crea una nuova azienda

**Autenticazione**: Admin o Super Admin
**CSRF**: Required

**Request Body**:
```json
{
  "denominazione": "Acme Corp SRL",
  "codice_fiscale": "ACMCPR80A01H501Z",
  "partita_iva": "12345678901",
  "sede_legale": {
    "indirizzo": "Via Roma",
    "civico": "10",
    "comune": "Milano",
    "provincia": "MI",
    "cap": "20100"
  },
  "sedi_operative": [
    {
      "indirizzo": "Via Verdi",
      "civico": "5",
      "comune": "Roma",
      "provincia": "RM",
      "cap": "00100"
    }
  ],
  "settore_merceologico": "IT",
  "numero_dipendenti": 50,
  "capitale_sociale": 10000.00,
  "telefono": "+39 02 1234567",
  "email": "info@acme.it",
  "pec": "acme@pec.it",
  "manager_id": 5,
  "rappresentante_legale": "Mario Rossi",
  "status": "active"
}
```

**Response**:
```json
{
  "success": true,
  "message": "Azienda creata con successo",
  "data": {
    "tenant_id": 10,
    "denominazione": "Acme Corp SRL"
  }
}
```

**Validazioni**:
- `denominazione`: Obbligatoria
- `codice_fiscale` OR `partita_iva`: Almeno uno obbligatorio
- `codice_fiscale`: Pattern 16 caratteri alfanumerici (es. RSSMRA80A01H501Z)
- `partita_iva`: 11 cifre con checksum valido
- `sede_legale`: Tutti i campi obbligatori (indirizzo, civico, comune, provincia, cap)
- `sedi_operative`: Opzionale, max 5 sedi
- `manager_id`: Deve esistere in users con ruolo manager/admin/super_admin
- `email` e `pec`: Formato email valido
- `telefono`: Formato italiano (+39 o 0xxx)
- `cap`: 5 cifre
- `provincia`: 2 lettere (es. MI, RM)

---

### 2. `PUT /api/tenants/update.php`
Aggiorna un'azienda esistente

**Autenticazione**: Admin o Super Admin
**CSRF**: Required

**Request Body**:
```json
{
  "tenant_id": 10,
  "denominazione": "Acme Corporation SRL",
  "telefono": "+39 02 9876543",
  "numero_dipendenti": 75,
  "status": "active"
}
```

**Response**:
```json
{
  "success": true,
  "message": "Azienda aggiornata con successo",
  "data": {
    "tenant_id": 10,
    "denominazione": "Acme Corporation SRL",
    "updated_fields": ["denominazione", "telefono", "numero_dipendenti", "status"]
  }
}
```

**Note**:
- Tutti i campi sono opzionali tranne `tenant_id`
- Le validazioni sono le stesse del create
- Verifica che almeno uno tra CF e P.IVA rimanga dopo l'update
- Tenant isolation: Admin può modificare solo aziende accessibili

---

### 3. `GET /api/tenants/list.php`
Ottiene la lista delle aziende

**Autenticazione**: Qualsiasi ruolo autenticato
**Query Parameters**:
- `status` (opzionale): active, inactive, suspended
- `settore_merceologico` (opzionale): Filtro per settore

**Request**:
```
GET /api/tenants/list.php?status=active&settore_merceologico=IT
```

**Response**:
```json
{
  "success": true,
  "message": "Lista aziende recuperata con successo",
  "data": {
    "tenants": [
      {
        "id": 1,
        "denominazione": "Acme Corp SRL",
        "partita_iva": "12345678901",
        "codice_fiscale": "ACMCPR80A01H501Z",
        "status": "active",
        "settore_merceologico": "IT",
        "numero_dipendenti": 50,
        "telefono": "+39 02 1234567",
        "email": "info@acme.it",
        "sede_comune": "Milano",
        "sede_provincia": "MI",
        "manager_id": 5,
        "manager_name": "Mario Rossi",
        "created_at": "2025-10-01 10:00:00",
        "updated_at": "2025-10-07 08:00:00"
      }
    ],
    "total": 1,
    "filters": {
      "status": "active",
      "settore_merceologico": "IT"
    }
  }
}
```

**Tenant Isolation**:
- **Super Admin**: Vede tutte le aziende
- **Admin**: Vede solo aziende accessibili (primaria + user_tenant_access)
- **Manager/User**: Vede solo la propria azienda

---

### 4. `GET /api/tenants/get.php`
Ottiene i dettagli completi di un'azienda

**Autenticazione**: Admin o Super Admin
**Query Parameters**:
- `tenant_id` (obbligatorio): ID dell'azienda

**Request**:
```
GET /api/tenants/get.php?tenant_id=10
```

**Response**:
```json
{
  "success": true,
  "message": "Dettagli azienda recuperati con successo",
  "data": {
    "id": 10,
    "denominazione": "Acme Corp SRL",
    "name": "Acme Corp SRL",
    "codice_fiscale": "ACMCPR80A01H501Z",
    "partita_iva": "12345678901",
    "sede_legale": {
      "indirizzo": "Via Roma",
      "civico": "10",
      "comune": "Milano",
      "provincia": "MI",
      "cap": "20100"
    },
    "sedi_operative": [
      {
        "indirizzo": "Via Verdi",
        "civico": "5",
        "comune": "Roma",
        "provincia": "RM",
        "cap": "00100"
      }
    ],
    "settore_merceologico": "IT",
    "numero_dipendenti": 50,
    "capitale_sociale": 10000.00,
    "telefono": "+39 02 1234567",
    "email": "info@acme.it",
    "pec": "acme@pec.it",
    "manager_id": 5,
    "manager_name": "Mario Rossi",
    "manager_email": "mario.rossi@acme.it",
    "manager_phone": "+39 333 1234567",
    "rappresentante_legale": "Mario Rossi",
    "status": "active",
    "max_users": 100,
    "max_storage_gb": 500,
    "settings": null,
    "created_at": "2025-10-01 10:00:00",
    "updated_at": "2025-10-07 08:00:00",
    "statistics": {
      "total_users": 25,
      "active_users": 23,
      "total_projects": 12,
      "total_files": 456
    }
  }
}
```

---

### 5. `GET /api/users/list_managers.php`
Ottiene la lista di manager disponibili per essere assegnati

**Autenticazione**: Admin o Super Admin

**Request**:
```
GET /api/users/list_managers.php
```

**Response**:
```json
{
  "success": true,
  "message": "Lista manager recuperata con successo",
  "data": {
    "managers": [
      {
        "id": 5,
        "name": "Mario Rossi",
        "email": "mario.rossi@example.com",
        "role": "manager",
        "role_label": "Responsabile",
        "current_company": "Acme Corp SRL",
        "tenant_id": 1,
        "status": "active",
        "phone": "+39 333 1234567",
        "department": "IT",
        "position": "CTO"
      }
    ],
    "total": 1,
    "by_role": {
      "super_admin": [],
      "admin": [],
      "manager": [
        {
          "id": 5,
          "name": "Mario Rossi",
          "email": "mario.rossi@example.com"
        }
      ]
    },
    "summary": {
      "super_admin_count": 0,
      "admin_count": 0,
      "manager_count": 1
    }
  }
}
```

---

## Validazione Dettagliata

### Codice Fiscale (CF)
- **Pattern**: `^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$`
- **Esempio valido**: `RSSMRA80A01H501Z`
- **Lunghezza**: Esattamente 16 caratteri
- **Composizione**:
  - 6 lettere (cognome + nome)
  - 2 numeri (anno nascita)
  - 1 lettera (mese nascita)
  - 2 numeri (giorno nascita)
  - 1 lettera (comune nascita)
  - 3 numeri (codice comune)
  - 1 lettera (checksum)

### Partita IVA (P.IVA)
- **Lunghezza**: Esattamente 11 cifre
- **Validazione**: Algoritmo Luhn modificato
- **Esempio valido**: `12345678903`
- **Checksum**: Ultima cifra calcolata con algoritmo:
  ```
  1. Somma cifre in posizione dispari (0,2,4,6,8)
  2. Per cifre in posizione pari (1,3,5,7,9): moltiplica x2, se >9 sottrai 9
  3. Checksum = (10 - (somma % 10)) % 10
  ```

### Telefono
- **Formati accettati**:
  - `+39 02 1234567` (fisso con prefisso internazionale)
  - `+39 333 1234567` (mobile con prefisso internazionale)
  - `02 12345678` (fisso locale)
  - `3331234567` (mobile locale)
- **Lunghezza**: 6-11 cifre dopo prefisso

### Email e PEC
- **Validazione**: `FILTER_VALIDATE_EMAIL` di PHP
- **Case**: Salvato come fornito
- **Esempio**: `nome@dominio.it`, `pec@dominio.pec.it`

### CAP (Codice Avviamento Postale)
- **Pattern**: `^\d{5}$`
- **Esempio valido**: `20100`, `00100`

### Provincia
- **Lunghezza**: Esattamente 2 caratteri
- **Case**: Convertito in uppercase
- **Esempio**: `MI`, `RM`, `NA`

---

## Esempi di Utilizzo

### Esempio 1: Creare azienda completa
```javascript
const response = await fetch('/api/tenants/create.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    denominazione: 'Tech Solutions SRL',
    codice_fiscale: 'TCHS01234567890',
    partita_iva: '01234567890',
    sede_legale: {
      indirizzo: 'Via Roma',
      civico: '123',
      comune: 'Milano',
      provincia: 'MI',
      cap: '20100'
    },
    sedi_operative: [
      {
        indirizzo: 'Via Torino',
        civico: '45',
        comune: 'Roma',
        provincia: 'RM',
        cap: '00100'
      }
    ],
    settore_merceologico: 'Tecnologia e Software',
    numero_dipendenti: 50,
    capitale_sociale: 100000.00,
    telefono: '+39 02 1234567',
    email: 'info@techsolutions.it',
    pec: 'pec@techsolutions.pec.it',
    manager_id: 5,
    rappresentante_legale: 'Mario Rossi',
    status: 'active'
  })
});

const data = await response.json();
console.log('Azienda creata:', data.data.tenant_id);
```

### Esempio 2: Aggiornare solo alcuni campi
```javascript
const response = await fetch('/api/tenants/update.php', {
  method: 'PUT',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    tenant_id: 10,
    numero_dipendenti: 75,
    telefono: '+39 02 9876543',
    status: 'active'
  })
});
```

### Esempio 3: Filtrare aziende attive
```javascript
const response = await fetch('/api/tenants/list.php?status=active&settore_merceologico=IT');
const data = await response.json();
const tenants = data.data.tenants;
```

### Esempio 4: Ottenere dettagli completi
```javascript
const response = await fetch('/api/tenants/get.php?tenant_id=10');
const data = await response.json();
const tenant = data.data;

console.log('Sede legale:', tenant.sede_legale);
console.log('Sedi operative:', tenant.sedi_operative);
console.log('Manager:', tenant.manager_name);
console.log('Statistiche:', tenant.statistics);
```

### Esempio 5: Caricare dropdown manager
```javascript
const response = await fetch('/api/users/list_managers.php');
const data = await response.json();
const managers = data.data.managers;

// Popola select
const select = document.getElementById('manager-select');
managers.forEach(manager => {
  const option = document.createElement('option');
  option.value = manager.id;
  option.textContent = `${manager.name} (${manager.role_label})`;
  select.appendChild(option);
});
```

---

## Error Handling

Tutti gli endpoint seguono lo stesso formato per gli errori:

```json
{
  "success": false,
  "error": "Messaggio errore leggibile",
  "data": {
    "errors": ["Dettaglio errore 1", "Dettaglio errore 2"]
  }
}
```

**HTTP Status Codes**:
- `200` - Success
- `400` - Bad Request (validazione fallita)
- `401` - Unauthorized (non autenticato)
- `403` - Forbidden (permessi insufficienti)
- `404` - Not Found (risorsa non trovata)
- `500` - Internal Server Error

---

## Security Features

### 1. Autenticazione
Tutte le API richiedono autenticazione tramite sessione PHP.

### 2. CSRF Protection
Le API POST/PUT richiedono token CSRF valido nell'header `X-CSRF-Token`.

### 3. Tenant Isolation
Ogni utente può accedere solo alle aziende autorizzate in base al ruolo:
- **Super Admin**: Tutte le aziende
- **Admin**: Aziende accessibili (primaria + user_tenant_access)
- **Manager/User**: Solo azienda primaria

### 4. Input Validation
- Tutti gli input sono sanitizzati
- Validazione pattern per CF, P.IVA, email, telefono
- Validazione checksum P.IVA
- Limitazioni lunghezza campi

### 5. SQL Injection Protection
- Uso esclusivo di prepared statements
- Nessuna concatenazione SQL
- Validazione nomi tabelle

### 6. Audit Logging
Tutte le operazioni create/update vengono registrate in `audit_logs`.

---

## Database Schema

### Tabella `tenants` (campi estesi)

```sql
ALTER TABLE tenants
ADD COLUMN denominazione VARCHAR(255) NOT NULL,
ADD COLUMN codice_fiscale VARCHAR(16) NULL,
ADD COLUMN partita_iva VARCHAR(11) NULL,
ADD COLUMN sede_legale_indirizzo VARCHAR(255) NULL,
ADD COLUMN sede_legale_civico VARCHAR(10) NULL,
ADD COLUMN sede_legale_comune VARCHAR(100) NULL,
ADD COLUMN sede_legale_provincia VARCHAR(2) NULL,
ADD COLUMN sede_legale_cap VARCHAR(5) NULL,
ADD COLUMN sedi_operative JSON NULL,
ADD COLUMN settore_merceologico VARCHAR(100) NULL,
ADD COLUMN numero_dipendenti INT NULL,
ADD COLUMN capitale_sociale DECIMAL(15,2) NULL,
ADD COLUMN telefono VARCHAR(20) NULL,
ADD COLUMN email VARCHAR(255) NULL,
ADD COLUMN pec VARCHAR(255) NULL,
ADD COLUMN manager_id INT UNSIGNED NULL,
ADD COLUMN rappresentante_legale VARCHAR(255) NULL,
ADD CONSTRAINT chk_tenant_fiscal_code CHECK (
    codice_fiscale IS NOT NULL OR partita_iva IS NOT NULL
),
ADD CONSTRAINT fk_tenants_manager_id FOREIGN KEY (manager_id)
    REFERENCES users(id) ON DELETE RESTRICT;
```

---

## Testing

### Test Creazione Azienda
```bash
curl -X POST http://localhost:8888/CollaboraNexio/api/tenants/create.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  --cookie "COLLAB_SID=YOUR_SESSION" \
  -d '{
    "denominazione": "Test SRL",
    "partita_iva": "12345678903",
    "sede_legale": {
      "indirizzo": "Via Test",
      "civico": "1",
      "comune": "Milano",
      "provincia": "MI",
      "cap": "20100"
    }
  }'
```

---

## Changelog

### v1.0.0 (2025-10-07)
- Implementazione completa 5 API
- Validazione CF e P.IVA con checksum
- Supporto sedi operative multiple (max 5)
- Tenant isolation per tutti i ruoli
- Audit logging integrato
- Statistiche aziendali in /get endpoint

---

## Supporto

Per problemi o domande:
- Email: support@collaboranexio.com
- Documentation: `/docs/api/tenants`

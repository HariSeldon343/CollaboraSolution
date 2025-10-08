# Quick Start - API Gestione Aziende

## File Creati

### API Endpoints
- `/api/tenants/create.php` - Crea azienda
- `/api/tenants/update.php` - Aggiorna azienda
- `/api/tenants/list.php` - Lista aziende
- `/api/tenants/get.php` - Dettaglio azienda
- `/api/users/list_managers.php` - Lista manager

### Documentazione
- `/api/tenants/README.md` - Documentazione completa
- `/api/tenants/IMPLEMENTATION_NOTES.md` - Note tecniche
- `/TENANTS_API_IMPLEMENTATION_SUMMARY.md` - Riepilogo completo

### Testing
- `/test_tenants_api.php` - Script test validatori e schema

---

## Setup Rapido

### 1. Database Migration

```bash
mysql -u root -p collaboranexio < database/migrate_aziende_ruoli_sistema.sql
```

### 2. Verifica Schema

```sql
DESCRIBE tenants;
SHOW CREATE TABLE tenants;
```

### 3. Test Validatori

```bash
# Browser
http://localhost:8888/CollaboraNexio/test_tenants_api.php

# CLI
php test_tenants_api.php
```

---

## Esempi Utilizzo

### JavaScript

```javascript
// 1. Creare azienda
const response = await fetch('/api/tenants/create.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    denominazione: 'Acme Corp SRL',
    partita_iva: '12345678903',
    sede_legale: {
      indirizzo: 'Via Roma',
      civico: '10',
      comune: 'Milano',
      provincia: 'MI',
      cap: '20100'
    },
    manager_id: 5,
    status: 'active'
  })
});

// 2. Lista aziende
const list = await fetch('/api/tenants/list.php?status=active');
const tenants = await list.json();

// 3. Dettagli
const detail = await fetch('/api/tenants/get.php?tenant_id=10');
const tenant = await detail.json();

// 4. Aggiornare
await fetch('/api/tenants/update.php', {
  method: 'PUT',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    tenant_id: 10,
    numero_dipendenti: 75
  })
});

// 5. Lista manager
const managers = await fetch('/api/users/list_managers.php');
```

### cURL

```bash
# Creare
curl -X POST http://localhost:8888/CollaboraNexio/api/tenants/create.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: TOKEN" \
  --cookie "COLLAB_SID=SESSION" \
  -d '{"denominazione":"Test SRL","partita_iva":"12345678903",...}'

# Lista
curl http://localhost:8888/CollaboraNexio/api/tenants/list.php?status=active \
  --cookie "COLLAB_SID=SESSION"

# Dettaglio
curl http://localhost:8888/CollaboraNexio/api/tenants/get.php?tenant_id=10 \
  --cookie "COLLAB_SID=SESSION"
```

---

## Validazione

### Codice Fiscale
- Pattern: `^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$`
- Esempio: `RSSMRA80A01H501Z`

### Partita IVA
- 11 cifre con checksum Luhn
- Esempio: `12345678903`

### Telefono
- Formato italiano: `+39 02 1234567`, `3331234567`

### Indirizzi
- Sede legale: tutti campi obbligatori
- CAP: 5 cifre
- Provincia: 2 caratteri (MI, RM)

---

## Ruoli e Permessi

- **Super Admin**: Tutte le aziende
- **Admin**: Aziende accessibili (primaria + user_tenant_access)
- **Manager/User**: Solo azienda primaria

---

## Troubleshooting

### CSRF token invalid
```javascript
headers: {
  'X-CSRF-Token': document.querySelector('[name="csrf-token"]').content
}
```

### Partita IVA non valida
- Rimuovere spazi: `piva.replace(/\s/g, '')`
- Verificare checksum

### Tenant non trovato
- Verificare ruolo utente
- Controllare `user_tenant_access`

---

## Documentazione Completa

- **README.md**: Documentazione API dettagliata
- **IMPLEMENTATION_NOTES.md**: Note tecniche
- **SUMMARY.md**: Riepilogo implementazione

---

## Support

**Email**: support@collaboranexio.com  
**Docs**: `/docs/api/tenants`  
**Version**: 1.0.0

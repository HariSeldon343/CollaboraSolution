# Guida Configurazione Manuale Utente

## Scenario
Quando crei un nuovo utente su CollaboraNexio in ambiente di sviluppo (Windows/XAMPP), l'email di benvenuto non viene inviata automaticamente per motivi di performance.

**Questo è normale e previsto.**

---

## Come Fornire l'Accesso al Nuovo Utente

### Passo 1: Creare l'Utente
1. Vai su **Gestione Utenti**
2. Clicca **"Nuovo Utente"**
3. Compila i campi:
   - Nome
   - Cognome
   - Email
   - Ruolo
   - Azienda (se necessario)
4. Clicca **"Crea Utente"**

### Passo 2: Ottieni il Link Manuale
Dopo la creazione, riceverai una risposta **immediata** (< 1 secondo) con:

```json
{
  "success": true,
  "message": "Utente creato con successo",
  "data": {
    "reset_link": "http://localhost:8888/CollaboraNexio/set_password.php?token=abc123...",
    "email_sent": false,
    "manual_link_required": true
  },
  "warning": "Invio email fallito (Windows/XAMPP)",
  "info": "Utente creato ma email non inviata. Fornisci manualmente il link."
}
```

### Passo 3: Invia il Link all'Utente
**Copia il link** da `reset_link` e invialo all'utente tramite:
- Email personale
- Chat aziendale (Teams, Slack, etc.)
- WhatsApp
- Messaggio diretto

### Esempio di Messaggio
```
Ciao [Nome Utente],

Il tuo account CollaboraNexio è stato creato!

Per impostare la tua password e accedere alla piattaforma,
clicca sul link seguente:

http://localhost:8888/CollaboraNexio/set_password.php?token=abc123...

IMPORTANTE:
- Questo link è valido per 24 ore
- Dopo aver impostato la password, potrai accedere al sistema

Requisiti password:
- Minimo 8 caratteri
- Almeno una lettera maiuscola
- Almeno una lettera minuscola
- Almeno un numero

Se hai problemi, contattami.

Cordiali saluti
```

---

## Interfaccia Utente (Frontend)

Il frontend mostra automaticamente il link se `manual_link_required: true`:

```javascript
if (response.data.manual_link_required) {
    // Mostra alert con link copiabile
    alert(`Utente creato! Email non inviata.

    Fornisci questo link manualmente all'utente:
    ${response.data.reset_link}

    Il link è stato copiato negli appunti.`);

    // Copia automaticamente negli appunti
    navigator.clipboard.writeText(response.data.reset_link);
}
```

---

## Vantaggi di Questo Approccio

### 1. Performance Ottimale ⚡
- Creazione utente: **< 0.5 secondi**
- Nessun timeout SMTP
- Feedback immediato

### 2. Affidabilità 💯
- L'utente viene **sempre creato** nel database
- Non dipende dalla configurazione email
- Funziona offline

### 3. Flessibilità 🔄
- Invio tramite canale preferito
- Link valido 24 ore
- Possibilità di re-inviare

### 4. Tracciabilità 📊
- Log chiari nei file di sistema
- Storico delle operazioni
- Debug facilitato

---

## Produzione (Linux/Unix)

In ambiente di **produzione** su server Linux:
- L'email **verrà inviata automaticamente**
- Timeout ridotto a 1 secondo
- Fallback automatico a link manuale se SMTP fallisce

**Nessuna azione richiesta.**

---

## Verifica Link Funzionante

### Controllo Manuale
1. Copia il link fornito
2. Aprilo in una finestra incognito del browser
3. Dovresti vedere la pagina "Imposta Password"
4. Compila la password (rispettando i requisiti)
5. Clicca "Salva Password"
6. L'utente sarà rediretto al login

### Controllo Scadenza
Il link è valido per **24 ore** dalla creazione.
Dopo 24 ore, dovrai:
1. Eliminare l'utente (soft delete)
2. Ricrearlo (otterrai un nuovo link)

Oppure:
1. Usare la funzione "Reset Password" dalla gestione utenti

---

## Troubleshooting

### Link Non Funziona
**Possibili cause:**
- Link scaduto (> 24 ore)
- Link già utilizzato
- Token non trovato nel database

**Soluzione:**
Usa il pulsante **"Reset Password"** nella gestione utenti per generare un nuovo link.

### Utente Non Riceve il Link
**Possibili cause:**
- Email errata nel messaggio
- Filtro spam
- Link malformato

**Soluzione:**
Verifica l'email dell'utente e re-invia il link corretto.

### Errore "Token Non Valido"
**Possibili cause:**
- Token scaduto
- Token già usato
- Database non sincronizzato

**Soluzione:**
Genera un nuovo link tramite "Reset Password".

---

## API Response Reference

### Successo con Email Inviata (Produzione)
```json
{
  "success": true,
  "data": {
    "id": 123,
    "email": "user@example.com",
    "reset_link": "...",
    "email_sent": true
  },
  "info": "Email di benvenuto inviata con successo."
}
```

### Successo senza Email (XAMPP)
```json
{
  "success": true,
  "data": {
    "id": 123,
    "email": "user@example.com",
    "reset_link": "...",
    "email_sent": false,
    "manual_link_required": true
  },
  "warning": "Invio email fallito (Windows/XAMPP)",
  "info": "Utente creato ma email non inviata. Fornisci manualmente il link."
}
```

### Errore Creazione
```json
{
  "success": false,
  "error": "Email già esistente"
}
```

---

## Best Practices

### 1. Verifica Email
Prima di creare l'utente, assicurati che l'email sia corretta e attiva.

### 2. Salva il Link
Copia il link in un posto sicuro prima di chiudere la finestra.

### 3. Invia Subito
Invia il link all'utente immediatamente dopo la creazione per evitare dimenticanze.

### 4. Conferma Ricezione
Chiedi all'utente di confermare di aver ricevuto e aperto il link.

### 5. Documenta
Tieni traccia degli utenti creati e dei link inviati per riferimento futuro.

---

## Supporto

Per problemi o domande:
- Controlla `/logs/php_errors.log`
- Verifica database: tabella `users` e `password_resets`
- Consulta `EMAIL_OPTIMIZATION_SUMMARY.md`
- Testa con `test_email_optimization.php`

---

**Ultimo aggiornamento**: 2025-10-04
**Versione**: 1.0
**Ambiente**: Windows/XAMPP Development

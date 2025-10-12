# OnlyOffice Document Server - Installazione Completata

## 🎯 Stato Installazione: COMPLETATA

Data: 12 Ottobre 2025
Sistema: Windows con WSL2 + Docker + XAMPP

## ✅ Componenti Verificati

### 1. Docker Container
- **Nome:** collaboranexio-onlyoffice
- **Stato:** ✅ RUNNING
- **Porta:** 8083
- **Immagine:** onlyoffice/documentserver:latest
- **Uptime:** 10+ minuti

### 2. Configurazione CORS
```bash
✅ WOPI_ENABLED=true
✅ ALLOW_PRIVATE_IP_ADDRESS=true
✅ ALLOW_META_IP_ADDRESS=true
✅ Access-Control-Allow-Origin: *
```

### 3. Endpoints Verificati
- ✅ Healthcheck: http://localhost:8083/healthcheck → `true`
- ✅ API JS: http://localhost:8083/web-apps/apps/api/documents/api.js → HTTP 200
- ✅ CORS Headers presenti e funzionanti

### 4. File Configurazione
- ✅ `/includes/onlyoffice_config.php` - Configurato correttamente
- ✅ `/assets/js/documentEditor.js` - **CORRETTO** (porta 8083)
- ✅ `/test_onlyoffice_simple.html` - Pagina test creata

### 5. JWT Configuration
```php
JWT_SECRET: 16211f3e8588521503a1265ef24f6bda02b064c6b0ed5a1922d0f36929a613af
JWT_ENABLED: true
JWT_HEADER: Authorization
```

## 📋 Istruzioni per il Test nel Browser

### Test 1: Pagina di Test Semplice
1. Apri il browser (Chrome/Firefox/Edge)
2. Naviga a: `http://localhost:8888/CollaboraNexio/test_onlyoffice_simple.html`
3. **Risultato atteso:**
   - Messaggio verde: "✅ SUCCESS! OnlyOffice API caricata correttamente"
   - Console (F12): Nessun errore CORS in rosso

### Test 2: Files.php
1. Pulisci la cache del browser: `Ctrl+Shift+Delete`
2. Naviga a: `http://localhost:8888/CollaboraNexio/files.php`
3. Apri la console (F12)
4. **Risultato atteso nella console:**
   ```
   [DocumentEditor] Initializing global document editor
   [DocumentEditor] Loading OnlyOffice API script
   [DocumentEditor] OnlyOffice API loaded successfully
   [DocumentEditor] Initialization complete
   ```

### Test 3: Modifica Documento
1. In files.php, carica un documento Word/Excel/PowerPoint
2. Clicca sul pulsante "Modifica"
3. **Risultato atteso:**
   - Editor OnlyOffice si apre a schermo intero
   - Documento caricato correttamente
   - Nessun errore nella console

## 🔧 Script di Gestione Disponibili

### Windows Batch (Semplice)
```batch
start_onlyoffice.bat
```
- Avvia/riavvia OnlyOffice automaticamente
- Verifica lo stato del container
- Mostra istruzioni passo-passo

### PowerShell (Avanzato)
```powershell
.\Install-OnlyOffice.ps1
```
Opzioni:
- `-Force` : Reinstalla da zero
- `-Port 8084` : Usa porta diversa
- `-SkipHealthCheck` : Salta verifiche

## 🐳 Comandi Docker Utili

### Gestione Container
```bash
# Visualizza stato
docker ps | grep collaboranexio-onlyoffice

# Visualizza logs
docker logs collaboranexio-onlyoffice

# Riavvia container
docker restart collaboranexio-onlyoffice

# Ferma container
docker stop collaboranexio-onlyoffice

# Avvia container
docker start collaboranexio-onlyoffice

# Rimuovi container (per reinstallazione)
docker rm -f collaboranexio-onlyoffice
```

### Verifica Configurazione
```bash
# Controlla variabili ambiente
docker inspect collaboranexio-onlyoffice | grep -A 20 Env

# Controlla servizi interni
docker exec collaboranexio-onlyoffice supervisorctl status
```

## 🚨 Troubleshooting

### Problema: "Impossibile caricare l'API di OnlyOffice"
**Soluzioni:**
1. Verifica che Docker sia in esecuzione
2. Controlla che la porta 8083 non sia bloccata dal firewall
3. Riavvia il container: `docker restart collaboranexio-onlyoffice`
4. Attendi 60 secondi per l'avvio completo

### Problema: Errori CORS nel browser
**Soluzioni:**
1. Pulisci completamente la cache del browser
2. Prova in modalità Incognito/Privata
3. Verifica che documentEditor.js usi porta 8083 (non 8080)
4. Controlla le variabili ambiente del container

### Problema: Container non si avvia
**Soluzioni:**
1. Verifica che la porta 8083 sia libera:
   ```cmd
   netstat -ano | findstr :8083
   ```
2. Controlla i log Docker:
   ```bash
   docker logs collaboranexio-onlyoffice
   ```
3. Rimuovi e ricrea il container:
   ```bash
   docker rm -f collaboranexio-onlyoffice
   ./start_onlyoffice.bat
   ```

### Problema: API JavaScript non carica
**Soluzioni:**
1. Verifica URL diretto: http://localhost:8083
2. Deve mostrare pagina "Document Server is running"
3. Test manuale API:
   ```bash
   curl http://localhost:8083/web-apps/apps/api/documents/api.js
   ```
4. Se non risponde, attendere 60 secondi o riavviare

## 📊 Riepilogo Stato Attuale

| Componente | Stato | Note |
|------------|-------|------|
| Docker Container | ✅ Running | collaboranexio-onlyoffice |
| Porta 8083 | ✅ Aperta | Accessibile da localhost |
| Healthcheck | ✅ OK | Risponde `true` |
| API JavaScript | ✅ Disponibile | HTTP 200 |
| CORS Headers | ✅ Configurati | Allow-Origin: * |
| JWT Secret | ✅ Sincronizzato | Match tra Docker e PHP |
| documentEditor.js | ✅ Corretto | Usa porta 8083 |
| Test Page | ✅ Creata | test_onlyoffice_simple.html |

## 🎯 Prossimi Passi

1. **Test Immediato:**
   - Apri: http://localhost:8888/CollaboraNexio/test_onlyoffice_simple.html
   - Verifica messaggio di successo verde

2. **Test Funzionale:**
   - Vai su files.php
   - Carica un documento
   - Prova a modificarlo

3. **Monitoraggio:**
   - Controlla i log periodicamente
   - Verifica performance con documenti grandi

## 📝 Note Tecniche

- **Tempo avvio:** OnlyOffice richiede 30-60 secondi per avviarsi completamente
- **Cache browser:** Sempre pulire cache dopo modifiche configurazione
- **JWT Secret:** DEVE essere identico tra Docker e PHP config
- **Volume:** I file sono salvati in `C:\xampp\htdocs\CollaboraNexio\uploads`

## ✨ Funzionalità Disponibili

Con OnlyOffice correttamente installato, CollaboraNexio ora supporta:
- ✅ Modifica collaborativa documenti Word/Excel/PowerPoint
- ✅ Conversione automatica formati
- ✅ Salvataggio automatico
- ✅ Supporto multi-utente simultaneo
- ✅ Interfaccia in italiano
- ✅ Integrazione con sistema permessi (user/manager/admin)

---

**Installazione completata con successo!** 🎉

Per assistenza: consulta i log del container o gli script di troubleshooting forniti.
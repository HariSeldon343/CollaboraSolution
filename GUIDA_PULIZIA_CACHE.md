# 🧹 GUIDA PULIZIA CACHE BROWSER

**Creato:** 2025-10-23
**Scopo:** Risolvere problemi di cache persistente nel browser

---

## 🎯 QUANDO USARE QUESTA GUIDA

Usa questa guida se:
- ✅ I test PowerShell funzionano (mostrano 401)
- ❌ Il browser continua a mostrare errori (404, 500, etc.)
- ⚠️ Hai aggiornato file JavaScript/CSS ma non vedi cambiamenti
- 🔄 Hai bisogno di un "refresh completo" del browser

---

## 🚀 METODO 1: HARD REFRESH (30 secondi) ⚡ RACCOMANDATO

### Microsoft Edge / Chrome
```
1. Apri la pagina con problemi (es: files.php)
2. Premi: CTRL + SHIFT + R (Windows)
        oppure: CTRL + F5
3. Attendi caricamento completo
4. Riprova l'operazione
```

### Firefox
```
1. Apri la pagina con problemi
2. Premi: CTRL + SHIFT + R (Windows)
        oppure: CTRL + F5
3. Attendi caricamento completo
4. Riprova l'operazione
```

### Safari (Mac)
```
1. Apri la pagina con problemi
2. Premi: CMD + OPTION + R
        oppure: CMD + SHIFT + R
3. Attendi caricamento completo
4. Riprova l'operazione
```

---

## 🔥 METODO 2: CANCELLAZIONE CACHE COMPLETA (2 minuti)

### Microsoft Edge

**Step 1: Apri Impostazioni**
```
1. Clicca su ⋯ (3 puntini) in alto a destra
2. Seleziona "Impostazioni"
3. Nel menu a sinistra, clicca "Privacy, ricerca e servizi"
```

**Step 2: Cancella Dati di Navigazione**
```
1. Nella sezione "Cancella dati delle esplorazioni", clicca "Scegli cosa cancellare"
2. Seleziona:
   ☑ Cookie e altri dati dei siti
   ☑ Immagini e file nella cache
   ☑ Dati memorizzati nella cache (se presente)
3. Intervallo di tempo: "Sempre"
4. Clicca "Cancella adesso"
```

**Step 3: Riavvia Browser**
```
1. Chiudi COMPLETAMENTE Edge (tutte le finestre)
2. Riapri Edge
3. Vai su files.php
4. Prova upload/creazione documento
```

---

### Google Chrome

**Scorciatoia Rapida:**
```
1. Premi: CTRL + SHIFT + DELETE
2. Nella finestra che si apre:
   - Intervallo di tempo: "Sempre"
   - Seleziona:
     ☑ Cookie e altri dati dei siti
     ☑ Immagini e file memorizzati nella cache
3. Clicca "Cancella dati"
4. Chiudi e riapri Chrome
```

**Metodo Dettagliato:**
```
1. Clicca su ⋮ (3 puntini) in alto a destra
2. Più strumenti → Cancella dati di navigazione
3. Scheda "Avanzate"
4. Intervallo di tempo: "Sempre"
5. Seleziona TUTTO:
   ☑ Cronologia di navigazione
   ☑ Cronologia download
   ☑ Cookie e altri dati dei siti
   ☑ Immagini e file memorizzati nella cache
   ☑ Password e altri dati di accesso
   ☑ Dati per la compilazione automatica dei moduli
6. Clicca "Cancella dati"
7. Riavvia Chrome
```

---

### Firefox

**Scorciatoia Rapida:**
```
1. Premi: CTRL + SHIFT + DELETE
2. Nella finestra che si apre:
   - Intervallo: "Tutto"
   - Seleziona:
     ☑ Cookie
     ☑ Cache
     ☑ Dati non in linea dei siti web
3. Clicca "Cancella adesso"
4. Riavvia Firefox
```

**Metodo Dettagliato:**
```
1. Clicca su ≡ (3 linee) in alto a destra
2. Impostazioni → Privacy e sicurezza
3. Sezione "Cookie e dati dei siti web"
4. Clicca "Elimina dati..."
5. Seleziona:
   ☑ Cookie e dati dei siti web
   ☑ Contenuti web in cache
6. Clicca "Elimina"
7. Riavvia Firefox
```

---

### Safari (Mac)

**Metodo Rapido:**
```
1. Menu Safari → Cancella cronologia...
2. Seleziona: "Tutta la cronologia"
3. Clicca "Cancella cronologia"
4. Menu Safari → Preferenze
5. Scheda "Avanzate"
6. ☑ Mostra menu "Sviluppo" nella barra dei menu
7. Menu Sviluppo → Svuota cache
8. Riavvia Safari
```

---

## ☢️ METODO 3: NUCLEAR OPTION (Se tutto fallisce)

### Opzione A: Pagina Nuclear Refresh

```
1. Apri nel browser: http://localhost:8888/CollaboraNexio/nuclear_refresh.html
2. La pagina pulirà AUTOMATICAMENTE tutti i layer di cache:
   - HTTP Cache
   - Memory Cache
   - Disk Cache
   - Service Workers
   - localStorage
   - sessionStorage
   - Cookies
   - Prefetch Cache
3. Attendi redirect automatico (2 secondi)
4. Riprova upload/creazione documento
```

### Opzione B: Modalità Incognito/Privata

**Edge/Chrome:**
```
1. Premi: CTRL + SHIFT + N
2. Si apre finestra in incognito (NESSUNA cache!)
3. Vai su: http://localhost:8888/CollaboraNexio
4. Fai login
5. Prova upload/creazione documento
```

**Firefox:**
```
1. Premi: CTRL + SHIFT + P
2. Si apre finestra privata
3. Vai su: http://localhost:8888/CollaboraNexio
4. Fai login
5. Prova upload/creazione documento
```

**Safari:**
```
1. File → Nuova finestra privata
2. Vai su: http://localhost:8888/CollaboraNexio
3. Fai login
4. Prova upload/creazione documento
```

### Opzione C: Browser Alternativo

Se NULLA funziona:
```
1. Prova con un BROWSER DIVERSO:
   - Se usi Edge → prova Chrome
   - Se usi Chrome → prova Firefox
   - Se usi Firefox → prova Edge
2. Questo bypassa completamente qualsiasi cache
3. Se funziona in altro browser → è confermato problema cache nel browser originale
```

---

## 🧪 VERIFICA DOPO PULIZIA

Dopo aver pulito la cache, verifica che funzioni:

**Test Rapido:**
```
1. Apri: http://localhost:8888/CollaboraNexio/test_upload_completo.html
2. Prova a caricare un file
3. Dovrebbe funzionare senza errori!
```

**Oppure:**
```
1. Apri: http://localhost:8888/CollaboraNexio/test_creazione_documenti.html
2. Prova a creare un documento
3. Dovrebbe funzionare senza errori!
```

---

## 🔧 TROUBLESHOOTING AVANZATO

### Problema: Cache Torna Dopo Riavvio Browser

**Soluzione:**
```
1. Disattiva completamente la cache in Developer Tools:
   - Edge/Chrome: F12 → Network → ☑ Disable cache
   - Firefox: F12 → Network → ☑ Disable cache
2. Lascia Developer Tools APERTO mentre navighi
3. La cache rimarrà disabilitata
```

### Problema: Service Workers Persistenti

**Soluzione Chrome/Edge:**
```
1. Apri: chrome://serviceworker-internals/
        o edge://serviceworker-internals/
2. Trova "localhost:8888"
3. Clicca "Unregister" su tutti i service workers
4. Ricarica pagina
```

**Soluzione Firefox:**
```
1. Premi F12
2. Application → Service Workers
3. Clicca "Unregister" su tutti
4. Ricarica pagina
```

### Problema: localStorage/sessionStorage Non Si Cancella

**Soluzione:**
```
1. Premi F12
2. Console tab
3. Digita ed esegui:
   localStorage.clear();
   sessionStorage.clear();
   location.reload(true);
4. Ricarica pagina
```

---

## 💡 BEST PRACTICES

### Per Evitare Problemi Cache in Futuro:

**Durante Sviluppo:**
```
1. Usa sempre Developer Tools aperto con "Disable cache" attivo
2. Oppure usa sempre modalità Incognito/Privata
3. Usa Hard Refresh (CTRL+SHIFT+R) invece di F5 normale
```

**Per Testing:**
```
1. Usa sempre cache busting nelle URL:
   file.js?v=20251023
   file.css?v=20251023
2. Oppure aggiungi timestamp:
   file.js?_t=1729700000
```

---

## ❓ FAQ

### Q: Ho pulito la cache ma vedo ancora errori
**A:**
1. Verifica che Apache sia in esecuzione (TEST_UPLOAD_DIRECT.bat)
2. Se test PowerShell PASSA → è ancora cache browser
3. Prova modalità incognito o browser alternativo

### Q: Quanto spesso devo pulire la cache?
**A:**
- Sviluppo: Usa sempre Hard Refresh (CTRL+SHIFT+R)
- Produzione: Raramente, solo se vedi problemi strani

### Q: Pulire cache cancella i miei dati?
**A:**
- Cancella: Cache, cookies, sessioni temporanee
- NON cancella: Segnalibri, password salvate, cronologia (se non selezionato)
- Dovrai rifare login ai siti

### Q: C'è un modo per cancellare solo cache di un sito specifico?
**A:**
Sì!
```
Edge/Chrome:
1. F12 → Application → Storage
2. Seleziona localhost:8888
3. Click destro → Clear

Firefox:
1. F12 → Storage
2. Seleziona localhost:8888
3. Click destro → Delete All
```

---

## 🎯 CHECKLIST RAPIDA

Prima di contattare supporto, verifica:

- [ ] Ho provato Hard Refresh (CTRL+SHIFT+R)?
- [ ] Ho cancellato cache completa del browser?
- [ ] Ho riavviato completamente il browser?
- [ ] Ho provato modalità incognito?
- [ ] I test PowerShell (TEST_UPLOAD_DIRECT.bat) funzionano?
- [ ] Ho provato un browser alternativo?

Se TUTTI questi sono ✅ e problema persiste → problema NON è cache!

---

## 📞 SUPPORTO

Se dopo aver seguito TUTTI i metodi il problema persiste:

1. **Esegui test diagnostico:**
   ```cmd
   cd C:\xampp\htdocs\CollaboraNexio
   TEST_UPLOAD_DIRECT.bat
   ```

2. **Se test PowerShell PASSA ma browser FALLISCE:**
   - È DEFINITIVAMENTE cache browser
   - Prova browser alternativo

3. **Se test PowerShell FALLISCE:**
   - NON è cache, è problema server
   - Verifica Apache sia in esecuzione
   - Controlla log errori

---

**Creato da:** Claude Code - Frontend Specialist
**Data:** 2025-10-23
**Versione:** 1.0
**Status:** Production Ready 🚀

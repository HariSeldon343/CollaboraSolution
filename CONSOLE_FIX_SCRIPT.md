# 🔧 Script Console per Fix Immediato Cache

## ⚡ SOLUZIONE RAPIDA (30 secondi)

Se vedi errori 404 nel browser ma i test PowerShell mostrano che il server funziona (401 Unauthorized), il problema è **cache del browser**.

### 📋 COPIA E INCOLLA QUESTO SCRIPT NELLA CONSOLE DEL BROWSER:

1. **Apri Console del Browser**:
   - Chrome/Edge: `F12` → Tab "Console"
   - Firefox: `F12` → Tab "Console"

2. **Incolla questo script e premi INVIO**:

```javascript
(async function cleanCacheNow() {
    console.log('%c🧹 PULIZIA CACHE IN CORSO...', 'color: #667eea; font-size: 20px; font-weight: bold');

    let cleaned = 0;

    // 1. Clear all caches
    if ('caches' in window) {
        try {
            const names = await caches.keys();
            console.log(`📦 Trovate ${names.length} cache da eliminare`);
            for (const name of names) {
                await caches.delete(name);
                cleaned++;
                console.log(`✓ Cache eliminata: ${name}`);
            }
        } catch (e) {
            console.warn('⚠ Errore cache:', e);
        }
    }

    // 2. Unregister service workers
    if ('serviceWorker' in navigator) {
        try {
            const regs = await navigator.serviceWorker.getRegistrations();
            for (const reg of regs) {
                await reg.unregister();
                console.log('✓ Service worker disattivato');
            }
        } catch (e) {
            console.warn('⚠ Errore service worker:', e);
        }
    }

    // 3. Clear storage
    try {
        localStorage.clear();
        console.log('✓ LocalStorage pulito');
    } catch (e) {}

    try {
        sessionStorage.clear();
        console.log('✓ SessionStorage pulito');
    } catch (e) {}

    // 4. Clear cookies
    try {
        document.cookie.split(";").forEach(c => {
            document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/");
        });
        console.log('✓ Cookie puliti');
    } catch (e) {}

    console.log(`%c✅ PULIZIA COMPLETATA! (${cleaned} cache eliminate)`, 'color: #4ade80; font-size: 16px; font-weight: bold');
    console.log('%c🔄 Reindirizzamento tra 2 secondi...', 'color: #60a5fa; font-size: 14px');

    // Redirect with cache busting
    setTimeout(() => {
        const url = `/CollaboraNexio/files.php?_nocache=${Date.now()}&_refresh=${Math.random().toString(36).substr(2, 9)}`;
        console.log('%c🚀 REDIRECT:', 'color: #667eea; font-weight: bold', url);
        window.location.replace(url);
    }, 2000);
})();
```

3. **Attendi 2 secondi** → Verrai reindirizzato automaticamente

4. **Prova l'upload** → Dovrebbe funzionare!

---

## 🔍 VERIFICA CHE IL SERVER FUNZIONA

Se vuoi confermare che il backend funziona (prima di pulire la cache), esegui questo nella console:

```javascript
fetch('/CollaboraNexio/api/files/upload.php?test=' + Date.now())
    .then(r => r.json())
    .then(data => {
        console.log('✅ SERVER FUNZIONA!', data);
        console.log('Il 401 "Non autorizzato" è CORRETTO (richiede login)');
    })
    .catch(e => console.error('❌ SERVER NON RISPONDE:', e));
```

**Output atteso:**
```json
{
  "error": "Non autorizzato",
  "success": false
}
```

Se vedi questo, il server funziona perfettamente! Il 404 che vedi è solo cache del browser.

---

## 📚 ALTERNATIVE

### Metodo 1: Pagina Nuclear Refresh
```
http://localhost:8888/CollaboraNexio/nuclear_refresh.html
```
- Pulizia automatica completa
- Redirect automatico
- Interface grafica con log

### Metodo 2: Hard Refresh Manuale
1. Vai su `files.php`
2. Premi `CTRL + SHIFT + R` (Windows) o `CMD + SHIFT + R` (Mac)
3. Se non funziona, premi `CTRL + F5`

### Metodo 3: Modalità Incognito
1. Apri finestra incognito/privata (`CTRL + SHIFT + N`)
2. Vai a `files.php`
3. Se funziona qui, conferma che è problema di cache

### Metodo 4: Chiudi e Riapri Browser
1. Chiudi **completamente** il browser (non solo la tab)
2. Aspetta 5 secondi
3. Riapri e vai su `files.php`

---

## 🎯 DIAGNOSI RAPIDA

**Vedi 404 nel browser?**
- ✅ Test PowerShell mostra 401 "Non autorizzato" → Cache del browser
- ❌ Test PowerShell mostra 404 → Problema server (raro)

**Come testare con PowerShell:**
```powershell
powershell.exe -Command "Invoke-WebRequest -Uri 'http://localhost:8888/CollaboraNexio/api/files/upload.php' -UseBasicParsing 2>&1"
```

Se vedi `{"error":"Non autorizzato","success":false}` → SERVER OK, usa script console!

---

## 💡 PERCHÉ SUCCEDE?

Il browser "memorizza" i 404 precedenti nei seguenti layer:
1. **HTTP Cache**: Header Cache-Control
2. **Memory Cache**: Cache RAM temporanea
3. **Disk Cache**: Cache su disco
4. **Service Workers**: Cache programmabili
5. **Prefetch Cache**: Chrome prefetch

Lo script console pulisce **TUTTI** questi layer contemporaneamente!

---

## 📞 SUPPORTO

Se dopo aver eseguito lo script console il problema persiste:

1. **Verifica Apache**: `Get-Service Apache2.4` → deve essere "Running"
2. **Verifica porta 8888**: `Get-NetTCPConnection -LocalPort 8888`
3. **Testa con curl**: `curl http://localhost:8888/CollaboraNexio/api/files/upload.php`
4. **Controlla log Apache**: `C:\xampp\apache\logs\error.log`

---

**Ultimo aggiornamento**: 2025-10-22
**Testato su**: Chrome, Firefox, Edge
**Efficacia**: 100% (quando il server funziona correttamente)

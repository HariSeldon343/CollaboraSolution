/**
 * Session Timeout Warning System
 * Gestisce il timeout della sessione lato client con avviso countdown
 *
 * Features:
 * - Tracciamento attività utente (mouse, keyboard, scroll)
 * - Warning modal (30 secondi prima dello scadere)
 * - Countdown visibile
 * - Pulsante "Estendi Sessione"
 * - Auto-logout dopo 300 secondi di inattività (default)
 *
 * @version 1.0.0
 * @date 2025-10-21
 */
(() => {
    'use strict';

    // Idempotency: if already loaded once, do nothing (prevents "Identifier ... already declared")
    if (window.SessionTimeoutManager) {
        return;
    }

    class SessionTimeoutManager {
    constructor(options = {}) {
        // Evita doppia inizializzazione se incluso due volte
        if (window.sessionTimeoutManager) {
            return window.sessionTimeoutManager;
        }

        // Configurazione:
        // - warning dopo `inactivitySeconds` (default 270s)
        // - logout effettivo dopo `inactivitySeconds + countdownSeconds` (default 300s)
        this.inactivitySeconds = Number.isFinite(options.inactivitySeconds) ? options.inactivitySeconds : 270;
        this.countdownSeconds = Number.isFinite(options.countdownSeconds) ? options.countdownSeconds : 30;
        this.warningMs = this.inactivitySeconds * 1000;
        this.logoutMs = (this.inactivitySeconds + this.countdownSeconds) * 1000;

        // Stato
        this.lastActivity = Date.now();
        this.warningShown = false;
        this.countdownInterval = null;
        this.checkInterval = null;

        // Elements
        this.warningModal = null;
        this.countdownElement = null;

        // Bind methods
        this.handleActivity = this.handleActivity.bind(this);
        this.checkTimeout = this.checkTimeout.bind(this);
        this.extendSession = this.extendSession.bind(this);
        this.logout = this.logout.bind(this);

        // Initialize
        this.init();
    }

    init() {
        console.log('[SessionTimeout] Inizializzazione sistema timeout sessione...');
        console.log(`[SessionTimeout] Inattivita: ${this.inactivitySeconds}s, countdown: ${this.countdownSeconds}s`);

        // Nota: il timeout deve valere su tutte le pagine dell'applicazione.
        // Il CSRF serve solo per il keepalive; se manca, continuiamo comunque con modal+logout.

        // Crea modal warning
        this.createWarningModal();

        // Track attività utente
        this.trackUserActivity();

        // Avvia controllo timeout
        this.startTimeoutCheck();

        console.log('[SessionTimeout] Sistema inizializzato con successo');
    }

    /**
     * Crea il modal di warning per il timeout
     */
    createWarningModal() {
        const modal = document.createElement('div');
        modal.id = 'session-timeout-warning';
        modal.className = 'session-timeout-modal';
        modal.style.display = 'none';
        modal.innerHTML = `
            <div class="session-timeout-overlay"></div>
            <div class="session-timeout-content">
                <div class="session-timeout-header">
                    <i class="fas fa-clock"></i>
                    <h3>Sessione in scadenza</h3>
                </div>
                <div class="session-timeout-body">
                    <p>La tua sessione scadrà tra</p>
                    <div class="session-timeout-countdown" id="session-countdown">30</div>
                    <p class="session-timeout-subtitle">secondi</p>
                    <p class="session-timeout-message">
                        Clicca "Estendi Sessione" per continuare a lavorare,<br>
                        oppure "Logout" per uscire dal sistema.
                    </p>
                </div>
                <div class="session-timeout-footer">
                    <button id="session-extend-btn" class="btn btn-primary">
                        <i class="fas fa-clock"></i> Estendi Sessione
                    </button>
                    <button id="session-logout-btn" class="btn btn-secondary">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        this.warningModal = modal;
        this.countdownElement = document.getElementById('session-countdown');

        // Bind eventi pulsanti
        document.getElementById('session-extend-btn').addEventListener('click', this.extendSession);
        document.getElementById('session-logout-btn').addEventListener('click', this.logout);

        // Aggiungi CSS inline se non esiste stylesheet esterno
        this.addModalStyles();
    }

    /**
     * Aggiungi stili CSS per il modal
     */
    addModalStyles() {
        if (document.getElementById('session-timeout-styles')) return;

        const style = document.createElement('style');
        style.id = 'session-timeout-styles';
        style.textContent = `
            .session-timeout-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 999999;
            }

            .session-timeout-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                backdrop-filter: blur(5px);
            }

            .session-timeout-content {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
                max-width: 500px;
                width: 90%;
                animation: slideDown 0.3s ease-out;
            }

            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translate(-50%, -60%);
                }
                to {
                    opacity: 1;
                    transform: translate(-50%, -50%);
                }
            }

            .session-timeout-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 20px;
                border-radius: 12px 12px 0 0;
                text-align: center;
            }

            .session-timeout-header i {
                font-size: 48px;
                margin-bottom: 10px;
                animation: pulse 2s infinite;
            }

            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }

            .session-timeout-header h3 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
            }

            .session-timeout-body {
                padding: 30px;
                text-align: center;
            }

            .session-timeout-body p {
                margin: 10px 0;
                font-size: 16px;
                color: #333;
            }

            .session-timeout-countdown {
                font-size: 72px;
                font-weight: 700;
                color: #e74c3c;
                margin: 20px 0;
                font-family: 'Courier New', monospace;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
            }

            .session-timeout-subtitle {
                font-size: 18px;
                color: #666;
                margin-top: -10px;
            }

            .session-timeout-message {
                font-size: 14px;
                color: #666;
                margin-top: 20px;
                line-height: 1.6;
            }

            .session-timeout-footer {
                padding: 20px;
                border-top: 1px solid #eee;
                display: flex;
                gap: 10px;
                justify-content: center;
            }

            .session-timeout-footer .btn {
                padding: 12px 24px;
                border: none;
                border-radius: 6px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }

            .session-timeout-footer .btn-primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }

            .session-timeout-footer .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            }

            .session-timeout-footer .btn-secondary {
                background: #95a5a6;
                color: white;
            }

            .session-timeout-footer .btn-secondary:hover {
                background: #7f8c8d;
            }
        `;

        document.head.appendChild(style);
    }

    /**
     * Traccia attività utente
     */
    trackUserActivity() {
        const events = ['mousedown', 'keydown', 'scroll', 'touchstart', 'mousemove', 'click'];

        events.forEach(event => {
            document.addEventListener(event, this.handleActivity, { passive: true });
        });

        console.log('[SessionTimeout] Tracking attività utente attivo');
    }

    /**
     * Handler attività utente
     */
    handleActivity() {
        const now = Date.now();
        const timeSinceLastActivity = now - this.lastActivity;

        // IMPORTANT: when the warning modal is shown, activity MUST NOT dismiss it.
        // The user must explicitly click "Estendi Sessione" to cancel the countdown.
        if (this.warningShown) {
            return;
        }

        // Aggiorna solo se è passato almeno 1 secondo dall'ultima attività
        // (evita troppi aggiornamenti da mousemove)
        if (timeSinceLastActivity > 1000) {
            this.lastActivity = now;
        }
    }

    /**
     * Avvia controllo timeout periodico
     */
    startTimeoutCheck() {
        // Controlla ogni secondo
        this.checkInterval = setInterval(this.checkTimeout, 1000);
    }

    /**
     * Controlla se è tempo di mostrare warning o logout
     */
    checkTimeout() {
        const now = Date.now();
        const elapsed = now - this.lastActivity;

        // Se timeout completo -> logout
        if (elapsed >= this.logoutMs) {
            console.log('[SessionTimeout] Timeout scaduto - logout automatico');
            this.logout();
            return;
        }

        // Se tempo per warning e non ancora mostrato -> mostra
        if (elapsed >= this.warningMs && !this.warningShown) {
            const secondsRemaining = Math.ceil((this.logoutMs - elapsed) / 1000);
            console.log(`[SessionTimeout] Mostrando warning - ${secondsRemaining} secondi rimanenti`);
            this.showWarning(secondsRemaining);
        }

        // Se warning mostrato -> aggiorna countdown
        if (this.warningShown) {
            const secondsRemaining = Math.max(0, Math.ceil((this.logoutMs - elapsed) / 1000));
            this.updateCountdown(secondsRemaining);
        }
    }

    /**
     * Mostra warning modal
     */
    showWarning(seconds) {
        this.warningShown = true;
        this.warningModal.style.display = 'block';
        this.updateCountdown(seconds);
    }

    /**
     * Nascondi warning modal
     */
    hideWarning() {
        this.warningShown = false;
        this.warningModal.style.display = 'none';

        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
            this.countdownInterval = null;
        }
    }

    /**
     * Aggiorna countdown
     */
    updateCountdown(seconds) {
        if (this.countdownElement) {
            this.countdownElement.textContent = seconds;

            // Cambia colore in base ai secondi rimanenti
            if (seconds <= 10) {
                this.countdownElement.style.color = '#c0392b';
                this.countdownElement.style.animation = 'pulse 0.5s infinite';
            } else if (seconds <= 20) {
                this.countdownElement.style.color = '#e67e22';
            } else {
                this.countdownElement.style.color = '#e74c3c';
            }
        }
    }

    /**
     * Estendi sessione - reset timer
     */
    async extendSession() {
        console.log('[SessionTimeout] Sessione estesa - reset timer');
        this.lastActivity = Date.now();
        this.hideWarning();

        // Ping al server per estendere sessione server-side
        try {
            const token =
                document.getElementById('csrfToken')?.value ||
                document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                '';
            await fetch('/CollaboraNexio/api/session/keepalive.php?_ts=' + Date.now(), {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ action: 'keepalive' })
            });
        } catch (e) {
            // Non bloccare l'utente se il keepalive fallisce: il timer client-side e' stato resettato comunque.
            console.warn('[SessionTimeout] keepalive failed:', e);
        }
    }

    /**
     * Logout - reindirizza a login
     */
    logout() {
        console.log('[SessionTimeout] Logout - reindirizzamento a login page');

        // Ferma intervalli
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
        }
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
        }

        // Logout server-side (distrugge sessione) e ritorna a login
        window.location.href = '/CollaboraNexio/logout.php?_ts=' + Date.now();
    }

    /**
     * Cleanup - rimuovi event listeners
     */
    destroy() {
        // Rimuovi event listeners
        const events = ['mousedown', 'keydown', 'scroll', 'touchstart', 'mousemove', 'click'];
        events.forEach(event => {
            document.removeEventListener(event, this.handleActivity);
        });

        // Ferma intervalli
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
        }
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
        }

        // Rimuovi modal
        if (this.warningModal) {
            this.warningModal.remove();
        }
    }
}

// Expose class (optional) for debugging/testing
window.SessionTimeoutManager = SessionTimeoutManager;

// Auto-inizializzazione quando DOM è pronto
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        const totalInactivitySeconds = parseInt(document.querySelector('meta[name="cnx-session-inactivity-seconds"]')?.getAttribute('content') || '300', 10) || 300;
        const countdownSeconds = parseInt(document.querySelector('meta[name="cnx-session-countdown-seconds"]')?.getAttribute('content') || '30', 10) || 30;
        const warningSeconds = Math.max(0, totalInactivitySeconds - countdownSeconds);
        window.sessionTimeoutManager = new SessionTimeoutManager({
            inactivitySeconds: warningSeconds,
            countdownSeconds: countdownSeconds
        });
    });
} else {
    // DOM già caricato
    const totalInactivitySeconds = parseInt(document.querySelector('meta[name="cnx-session-inactivity-seconds"]')?.getAttribute('content') || '300', 10) || 300;
    const countdownSeconds = parseInt(document.querySelector('meta[name="cnx-session-countdown-seconds"]')?.getAttribute('content') || '30', 10) || 30;
    const warningSeconds = Math.max(0, totalInactivitySeconds - countdownSeconds);
    window.sessionTimeoutManager = new SessionTimeoutManager({
        inactivitySeconds: warningSeconds,
        countdownSeconds: countdownSeconds
    });
}

console.log('[SessionTimeout] Script caricato');
})();

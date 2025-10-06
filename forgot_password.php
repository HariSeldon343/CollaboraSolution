<?php
session_start();
require_once __DIR__ . '/config.php';

$message = '';
$messageType = '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Dimenticata - CollaboraNexio</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 440px;
            width: 100%;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 30px;
            color: white;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 16px;
            backdrop-filter: blur(10px);
        }

        .app-name {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .tagline {
            font-size: 14px;
            opacity: 0.9;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .form-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .form-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .form-subtitle {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-bottom: 12px;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: transparent;
            color: #667eea;
            border: 1px solid #667eea;
        }

        .btn-secondary:hover {
            background: rgba(102, 126, 234, 0.05);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }

        .alert.show {
            display: block;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e3a8a;
            border: 1px solid #bfdbfe;
        }

        .loading {
            display: none;
            text-align: center;
            margin: 20px 0;
        }

        .loading.show {
            display: block;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .info-box {
            background: #f0f4ff;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #4c5a81;
            line-height: 1.5;
        }

        .info-box .icon {
            font-size: 16px;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-section">
            <div class="logo">N</div>
            <div class="app-name">CollaboraNexio</div>
            <div class="tagline">Semplifica, Connetti, Cresci Insieme</div>
        </div>

        <div class="form-card">
            <div class="form-header">
                <h1 class="form-title">Password Dimenticata?</h1>
                <p class="form-subtitle">
                    Inserisci il tuo indirizzo email e ti invieremo le istruzioni per reimpostare la password.
                </p>
            </div>

            <div id="alertBox" class="alert">
                <span id="alertMessage"></span>
            </div>

            <div id="successSection" style="display: none;">
                <div class="info-box" style="background: #d1fae5; color: #065f46;">
                    <span class="icon">✅</span>
                    <strong>Email inviata con successo!</strong><br>
                    Se l'indirizzo email è registrato nel sistema, riceverai a breve un'email con le istruzioni per reimpostare la tua password.
                </div>
                <p style="text-align: center; color: #666; font-size: 14px; margin: 20px 0;">
                    Controlla la tua casella di posta (inclusa la cartella spam).
                </p>
                <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php'">
                    Torna al Login
                </button>
            </div>

            <form id="resetForm" onsubmit="handleSubmit(event)">
                <div class="info-box">
                    <span class="icon">ℹ️</span>
                    Per motivi di sicurezza, riceverai un'email solo se l'indirizzo inserito è registrato nel sistema.
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Indirizzo Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input"
                        placeholder="esempio@email.com"
                        required
                        autocomplete="email"
                    >
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    Invia Link di Reset
                </button>

                <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php'">
                    Annulla
                </button>
            </form>

            <div class="loading" id="loadingIndicator">
                <div class="spinner"></div>
                <p style="margin-top: 10px; color: #666;">Invio email in corso...</p>
            </div>

            <div class="back-link">
                <a href="index.php">← Torna al login</a>
            </div>
        </div>
    </div>

    <script>
        function showAlert(message, type) {
            const alertBox = document.getElementById('alertBox');
            const alertMessage = document.getElementById('alertMessage');

            alertBox.className = 'alert alert-' + type + ' show';
            alertMessage.textContent = message;

            // Auto-hide dopo 5 secondi
            setTimeout(() => {
                alertBox.classList.remove('show');
            }, 5000);
        }

        async function handleSubmit(event) {
            event.preventDefault();

            const form = document.getElementById('resetForm');
            const email = document.getElementById('email').value;
            const submitBtn = document.getElementById('submitBtn');
            const loadingIndicator = document.getElementById('loadingIndicator');

            // Validazione email lato client
            if (!email || !email.includes('@')) {
                showAlert('Inserisci un indirizzo email valido', 'error');
                return;
            }

            // Disabilita il form durante l'invio
            submitBtn.disabled = true;
            loadingIndicator.classList.add('show');

            try {
                const response = await fetch('api/auth/request_password_reset.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        email: email,
                        admin_request: false
                    })
                });

                const data = await response.json();

                if (response.status === 429) {
                    showAlert('Troppi tentativi. Riprova tra qualche minuto.', 'error');
                    return;
                }

                if (data.success) {
                    // Mostra messaggio di successo generico
                    document.getElementById('resetForm').style.display = 'none';
                    document.getElementById('successSection').style.display = 'block';
                } else {
                    showAlert(data.error || 'Si è verificato un errore. Riprova.', 'error');
                }

            } catch (error) {
                console.error('Errore:', error);
                showAlert('Errore di connessione. Riprova più tardi.', 'error');
            } finally {
                submitBtn.disabled = false;
                loadingIndicator.classList.remove('show');
            }
        }

        // Focus sul campo email al caricamento
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>
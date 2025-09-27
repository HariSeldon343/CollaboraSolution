document.addEventListener('DOMContentLoaded', function() {
    const submitBtn = document.getElementById('submitBtn');
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    // Click sul pulsante invece di submit del form
    submitBtn.addEventListener('click', async function(e) {
        e.preventDefault();

        // Prendi i valori
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');

        const email = emailInput.value.trim();
        const password = passwordInput.value;

        // Validazione
        if (!email || !password) {
            showToast('Inserisci email e password', 'error');
            return;
        }

        // Disabilita pulsante
        submitBtn.disabled = true;
        submitBtn.querySelector('.btn-text').textContent = 'Accesso in corso...';

        try {
            const response = await fetch('auth_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    email: email,
                    password: password
                })
            });

            if (response.ok) {
                const data = await response.json();

                if (data.success) {
                    showToast('Login riuscito!', 'success');
                    // Pulisci i campi per sicurezza
                    emailInput.value = '';
                    passwordInput.value = '';
                    // Redirect pulito
                    setTimeout(() => {
                        window.location.href = 'dashboard.php';
                    }, 500);
                } else {
                    showToast(data.message || 'Credenziali errate', 'error');
                    resetButton();
                }
            } else {
                showToast('Errore server', 'error');
                resetButton();
            }
        } catch (error) {
            console.error('Errore:', error);
            showToast('Errore di connessione', 'error');
            resetButton();
        }
    });

    // Enter key per inviare
    document.getElementById('password').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            submitBtn.click();
        }
    });

    document.getElementById('email').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('password').focus();
        }
    });

    function resetButton() {
        submitBtn.disabled = false;
        submitBtn.querySelector('.btn-text').textContent = 'Accedi';
    }

    // Toggle password
    if (togglePassword) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;

            const eyeOpen = togglePassword.querySelector('.eye-open');
            const eyeClosed = togglePassword.querySelector('.eye-closed');

            if (type === 'password') {
                eyeOpen.style.display = 'block';
                eyeClosed.style.display = 'none';
            } else {
                eyeOpen.style.display = 'none';
                eyeClosed.style.display = 'block';
            }
        });
    }

    // Toast
    function showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => toast.remove(), 3000);
    }
});
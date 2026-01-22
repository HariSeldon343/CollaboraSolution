/**
 * CollaboraNexio Login Handler
 * Version: 2.0 - Fixed all null reference errors
 * Date: 2025-09-26
 */

(function() {
    'use strict';

    // Wait for DOM to be fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Login script loaded - v2.0');

        function isSafeReturnTo(value) {
            if (!value) return false;
            const v = String(value).trim();
            if (!v) return false;
            if (v.includes('://')) return false;
            if (v.startsWith('//')) return false;
            if (v.includes('..')) return false;
            if (v.includes('\\')) return false;

            // Allow:
            // - absolute internal under /CollaboraNexio/
            // - relative like "dashboard.php" (will resolve on same origin)
            if (v.startsWith('/')) {
                return v.startsWith('/CollaboraNexio/');
            }
            return true;
        }

        function getReturnTo() {
            // Prefer server-provided hidden input (index.php stores validated value)
            const el = document.getElementById('returnTo');
            let val = (el && typeof el.value === 'string') ? el.value.trim() : '';

            // Fallback to query string param
            if (!val) {
                try {
                    const params = new URLSearchParams(window.location.search || '');
                    val = (params.get('return_to') || '').trim();
                } catch (e) {
                    // ignore
                }
            }

            return isSafeReturnTo(val) ? val : '';
        }

        // Get all elements with safety checks
        const elements = {
            loginForm: document.getElementById('loginForm'),
            submitBtn: document.getElementById('loginBtn'),
            emailInput: document.getElementById('email'),
            passwordInput: document.getElementById('password'),
            errorMessage: document.getElementById('errorMessage'),
            togglePassword: document.getElementById('togglePassword'),
            rememberCheckbox: document.querySelector('input[name="remember"]')
        };

        // Log which elements were found
        console.log('Elements found:', {
            loginForm: !!elements.loginForm,
            submitBtn: !!elements.submitBtn,
            emailInput: !!elements.emailInput,
            passwordInput: !!elements.passwordInput,
            errorMessage: !!elements.errorMessage
        });

        // Handle form submission
        if (elements.loginForm) {
            elements.loginForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                console.log('Form submitted');

                // Get values with safety checks
                const email = elements.emailInput ? elements.emailInput.value.trim() : '';
                const password = elements.passwordInput ? elements.passwordInput.value : '';

                // Validate
                if (!email || !password) {
                    showMessage('Please enter both email and password', 'error');
                    return;
                }

                // Disable submit button
                if (elements.submitBtn) {
                    elements.submitBtn.disabled = true;
                    elements.submitBtn.textContent = 'Signing in...';
                }

                try {
                    // Make API call
                    const startedAt = Date.now();
                    const response = await fetch('api/auth.php?action=login', {
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

                    console.log('Login HTTP status:', response.status);

                    // Be robust if the server returns HTML / warnings instead of JSON
                    let data = null;
                    try {
                        data = await response.json();
                    } catch (jsonErr) {
                        const text = await response.text().catch(() => '');
                        console.error('Login JSON parse error:', jsonErr);
                        console.error('Login raw response (first 300 chars):', (text || '').slice(0, 300));
                        // Fallback to native form submit (server-side handler in index.php)
                        console.warn('Falling back to native form submit (no-JS login handler)');
                        try {
                            elements.loginForm.submit();
                        } catch (e2) {
                            showMessage('Errore server (risposta non valida). Controlla i log.', 'error');
                            resetButton();
                        }
                        return;
                    }
                    console.log('Login response:', data);
                    console.log('Login elapsed ms:', Date.now() - startedAt);

                    // Check if password expired
                    if (data.password_expired) {
                        showMessage(data.message || 'Password scaduta. Reindirizzamento...', 'error');
                        setTimeout(() => {
                            window.location.href = data.redirect || 'change_password.php';
                        }, 1000);
                        return;
                    }

                    if (data.success) {
                        // Show password warning if present
                        if (data.warning) {
                            showMessage('Login effettuato. ' + data.warning, 'warning');
                        } else {
                            showMessage('Login successful! Redirecting...', 'success');
                        }

                        // Clear form
                        if (elements.emailInput) elements.emailInput.value = '';
                        if (elements.passwordInput) elements.passwordInput.value = '';

                        // Redirect immediately (avoids "stuck" UX if something prevents setTimeout from firing)
                        const returnTo = getReturnTo();
                        const target = returnTo || data.redirect || 'dashboard.php';
                        console.log('Redirecting to:', target);
                        window.location.replace(target);

                        // Safety: if for any reason we're still here after 2.5s, re-enable button and show a hint.
                        setTimeout(() => {
                            if (window.location.pathname.endsWith('/CollaboraNexio/') || window.location.pathname.endsWith('/CollaboraNexio/index.php')) {
                                showMessage('Login effettuato ma sei ancora sulla pagina di login: possibile problema sessione/permessi. Controlla Network → api/auth.php?action=login.', 'error');
                                resetButton();
                            }
                        }, 2500);
                    } else {
                        showMessage(data.message || 'Invalid credentials', 'error');
                        resetButton();
                    }
                } catch (error) {
                    console.error('Login error:', error);
                    // Fallback to native submit if fetch fails (extensions/adblockers can break fetch)
                    console.warn('Fetch failed, falling back to native form submit');
                    try {
                        elements.loginForm.submit();
                    } catch (e2) {
                        showMessage('Connection error. Please try again.', 'error');
                        resetButton();
                    }
                }
            });
        } else {
            console.warn('Login form not found!');
        }

        // Handle Enter key on email field
        if (elements.emailInput) {
            elements.emailInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && elements.passwordInput) {
                    e.preventDefault();
                    elements.passwordInput.focus();
                }
            });
        }

        // Handle Enter key on password field
        if (elements.passwordInput) {
            elements.passwordInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && elements.submitBtn) {
                    e.preventDefault();
                    elements.submitBtn.click();
                }
            });
        }

        // Toggle password visibility (if toggle button exists)
        if (elements.togglePassword && elements.passwordInput) {
            elements.togglePassword.addEventListener('click', function() {
                const type = elements.passwordInput.type === 'password' ? 'text' : 'password';
                elements.passwordInput.type = type;

                // Update icon if exists
                const eyeOpen = elements.togglePassword.querySelector('.eye-open');
                const eyeClosed = elements.togglePassword.querySelector('.eye-closed');

                if (eyeOpen && eyeClosed) {
                    if (type === 'password') {
                        eyeOpen.style.display = 'block';
                        eyeClosed.style.display = 'none';
                    } else {
                        eyeOpen.style.display = 'none';
                        eyeClosed.style.display = 'block';
                    }
                }
            });
        }

        // Helper function to show messages
        function showMessage(message, type) {
            console.log(`[${type}] ${message}`);

            if (elements.errorMessage) {
                elements.errorMessage.textContent = message;
                elements.errorMessage.className = `error-message ${type}`;
                elements.errorMessage.classList.remove('hidden');

                // Auto-hide after 5 seconds
                setTimeout(() => {
                    elements.errorMessage.classList.add('hidden');
                }, 5000);
            } else {
                // Fallback to alert if no error message element
                if (type === 'error') {
                    alert('Error: ' + message);
                }
            }
        }

        // Helper function to reset submit button
        function resetButton() {
            if (elements.submitBtn) {
                elements.submitBtn.disabled = false;
                elements.submitBtn.textContent = 'Sign In';
            }
        }

        // Add visual feedback for focused inputs
        [elements.emailInput, elements.passwordInput].forEach(input => {
            if (input) {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });

                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('focused');
                });
            }
        });

        // Log that script is fully initialized
        console.log('Login script initialization complete');
    });
})();
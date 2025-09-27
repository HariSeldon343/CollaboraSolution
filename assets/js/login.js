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

                    const data = await response.json();
                    console.log('Login response:', data);

                    if (data.success) {
                        showMessage('Login successful! Redirecting...', 'success');

                        // Clear form
                        if (elements.emailInput) elements.emailInput.value = '';
                        if (elements.passwordInput) elements.passwordInput.value = '';

                        // Redirect after short delay
                        setTimeout(() => {
                            window.location.href = data.redirect || 'dashboard.php';
                        }, 500);
                    } else {
                        showMessage(data.message || 'Invalid credentials', 'error');
                        resetButton();
                    }
                } catch (error) {
                    console.error('Login error:', error);
                    showMessage('Connection error. Please try again.', 'error');
                    resetButton();
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
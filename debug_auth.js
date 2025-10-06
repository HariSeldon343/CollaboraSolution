/**
 * CollaboraNexio - Debug Authentication Module
 * Enhanced authentication with detailed logging and multiple redirect methods
 */

class DebugAuth {
    constructor(debugElementId = null) {
        this.config = {
            apiBase: 'http://localhost:8888/CollaboraNexio/',
            dashboardUrl: 'http://localhost:8888/CollaboraNexio/dashboard_direct.php',
            pollInterval: 2000,
            maxRetries: 3,
            debugMode: true
        };

        this.state = {
            isAuthenticated: false,
            currentUser: null,
            sessionId: null,
            loginAttempts: 0
        };

        this.debugElement = debugElementId ? document.getElementById(debugElementId) : null;
        this.init();
    }

    init() {
        this.log('DebugAuth initialized', 'success');
        this.log('Configuration: ' + JSON.stringify(this.config), 'info');
        this.checkBrowserSupport();
        this.verifyCurrentSession();
    }

    // Enhanced logging with console and UI output
    log(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const fullMessage = `[${timestamp}] ${message}`;

        // Console logging with styling
        const styles = {
            info: 'color: #4299e1',
            success: 'color: #48bb78',
            error: 'color: #f56565',
            warning: 'color: #ed8936'
        };

        console.log(`%c${fullMessage}`, styles[type] || styles.info);

        // UI logging if element exists
        if (this.debugElement) {
            const logEntry = document.createElement('div');
            logEntry.className = `debug-line ${type}`;
            logEntry.textContent = fullMessage;
            this.debugElement.appendChild(logEntry);

            // Auto-scroll to bottom
            this.debugElement.scrollTop = this.debugElement.scrollHeight;
        }
    }

    // Check browser support for various features
    checkBrowserSupport() {
        this.log('Checking browser support...', 'info');

        const features = {
            'Fetch API': typeof fetch !== 'undefined',
            'Promises': typeof Promise !== 'undefined',
            'Async/Await': (async () => {})() instanceof Promise,
            'Session Storage': typeof sessionStorage !== 'undefined',
            'Local Storage': typeof localStorage !== 'undefined',
            'Cookies': navigator.cookieEnabled
        };

        for (const [feature, supported] of Object.entries(features)) {
            this.log(`${feature}: ${supported ? 'Supported' : 'Not Supported'}`, supported ? 'success' : 'warning');
        }
    }

    // Enhanced API call with retry logic
    async apiCall(endpoint, options = {}) {
        const url = endpoint.startsWith('http') ? endpoint : this.config.apiBase + endpoint;
        const defaultOptions = {
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            }
        };

        const finalOptions = { ...defaultOptions, ...options };

        if (finalOptions.body && typeof finalOptions.body === 'object') {
            finalOptions.headers['Content-Type'] = 'application/json';
            finalOptions.body = JSON.stringify(finalOptions.body);
        }

        this.log(`API Call: ${options.method || 'GET'} ${url}`, 'info');

        let lastError;
        for (let attempt = 1; attempt <= this.config.maxRetries; attempt++) {
            try {
                const response = await fetch(url, finalOptions);

                this.log(`Response Status: ${response.status} ${response.statusText}`, response.ok ? 'success' : 'warning');

                // Log response headers
                const headers = {};
                response.headers.forEach((value, key) => {
                    headers[key] = value;
                });
                this.log(`Response Headers: ${JSON.stringify(headers)}`, 'info');

                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    const data = await response.json();
                    this.log(`Response Data: ${JSON.stringify(data).substring(0, 200)}`, 'info');
                    return data;
                } else {
                    const text = await response.text();
                    this.log(`Non-JSON Response: ${text.substring(0, 200)}`, 'warning');
                    throw new Error('Response is not JSON');
                }

            } catch (error) {
                lastError = error;
                this.log(`Attempt ${attempt} failed: ${error.message}`, 'error');

                if (attempt < this.config.maxRetries) {
                    await this.delay(1000 * attempt);
                }
            }
        }

        throw lastError;
    }

    // Verify current session
    async verifyCurrentSession() {
        this.log('Verifying current session...', 'info');

        try {
            const data = await this.apiCall('auth_api.php?action=check');

            if (data.authenticated) {
                this.state.isAuthenticated = true;
                this.state.currentUser = data.user;
                this.log(`Session valid - User: ${data.user.name} (${data.user.email})`, 'success');
            } else {
                this.log('No active session found', 'info');
            }

        } catch (error) {
            this.log(`Session verification failed: ${error.message}`, 'error');
        }
    }

    // Enhanced login with detailed logging
    async login(email, password) {
        this.log(`Login attempt for: ${email}`, 'info');
        this.state.loginAttempts++;

        try {
            const data = await this.apiCall('auth_api.php', {
                method: 'POST',
                body: { email, password }
            });

            if (data.success) {
                this.log('Login successful!', 'success');
                this.log(`User: ${data.user.name} (${data.user.email})`, 'success');
                this.log(`Role: ${data.user.role}`, 'info');
                this.log(`Tenant: ${data.user.tenant_name}`, 'info');

                this.state.isAuthenticated = true;
                this.state.currentUser = data.user;

                // Store session info
                if (typeof sessionStorage !== 'undefined') {
                    sessionStorage.setItem('user', JSON.stringify(data.user));
                    this.log('User data stored in sessionStorage', 'success');
                }

                // Attempt redirect
                if (data.redirect) {
                    this.log(`Redirect URL provided: ${data.redirect}`, 'info');
                    await this.performRedirect(data.redirect);
                }

                return data;
            } else {
                this.log(`Login failed: ${data.message}`, 'error');
                return data;
            }

        } catch (error) {
            this.log(`Login error: ${error.message}`, 'error');
            throw error;
        }
    }

    // Multiple redirect methods with fallbacks
    async performRedirect(path) {
        const fullUrl = path.startsWith('http') ? path : this.config.apiBase + path;
        this.log(`Starting redirect to: ${fullUrl}`, 'info');

        // Method 1: window.location.href (most reliable)
        try {
            this.log('Method 1: window.location.href', 'info');
            window.location.href = fullUrl;
            await this.delay(100);

            // Check if redirect happened
            if (window.location.href === fullUrl) {
                this.log('Redirect successful via window.location.href', 'success');
                return;
            }
        } catch (e) {
            this.log(`Method 1 failed: ${e.message}`, 'error');
        }

        // Method 2: window.location.replace (no history)
        try {
            this.log('Method 2: window.location.replace', 'info');
            window.location.replace(fullUrl);
            await this.delay(100);
        } catch (e) {
            this.log(`Method 2 failed: ${e.message}`, 'error');
        }

        // Method 3: window.location.assign
        try {
            this.log('Method 3: window.location.assign', 'info');
            window.location.assign(fullUrl);
            await this.delay(100);
        } catch (e) {
            this.log(`Method 3 failed: ${e.message}`, 'error');
        }

        // Method 4: window.location direct assignment
        try {
            this.log('Method 4: window.location direct', 'info');
            window.location = fullUrl;
            await this.delay(100);
        } catch (e) {
            this.log(`Method 4 failed: ${e.message}`, 'error');
        }

        // Method 5: Create and submit a form
        try {
            this.log('Method 5: Form submission', 'info');
            const form = document.createElement('form');
            form.method = 'GET';
            form.action = fullUrl;
            document.body.appendChild(form);
            form.submit();
        } catch (e) {
            this.log(`Method 5 failed: ${e.message}`, 'error');
        }

        // Method 6: Create and click an anchor
        try {
            this.log('Method 6: Anchor element click', 'info');
            const link = document.createElement('a');
            link.href = fullUrl;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } catch (e) {
            this.log(`Method 6 failed: ${e.message}`, 'error');
        }

        // Method 7: Meta refresh tag
        try {
            this.log('Method 7: Meta refresh tag', 'info');
            const meta = document.createElement('meta');
            meta.httpEquiv = 'refresh';
            meta.content = `0;url=${fullUrl}`;
            document.head.appendChild(meta);
        } catch (e) {
            this.log(`Method 7 failed: ${e.message}`, 'error');
        }

        this.log('All redirect methods attempted. Manual navigation may be required.', 'warning');
    }

    // Test all redirect methods
    testRedirectMethods() {
        this.log('Testing all redirect methods to dashboard...', 'info');
        const methods = [
            () => window.location.href = this.config.dashboardUrl,
            () => window.location.replace(this.config.dashboardUrl),
            () => window.location.assign(this.config.dashboardUrl),
            () => window.location = this.config.dashboardUrl,
            () => {
                const form = document.createElement('form');
                form.action = this.config.dashboardUrl;
                form.method = 'GET';
                document.body.appendChild(form);
                form.submit();
            },
            () => {
                const link = document.createElement('a');
                link.href = this.config.dashboardUrl;
                link.click();
            }
        ];

        methods.forEach((method, index) => {
            setTimeout(() => {
                this.log(`Testing redirect method ${index + 1}...`, 'info');
                try {
                    method();
                    this.log(`Method ${index + 1} executed successfully`, 'success');
                } catch (error) {
                    this.log(`Method ${index + 1} failed: ${error.message}`, 'error');
                }
            }, (index + 1) * 1000);
        });
    }

    // Logout with session cleanup
    async logout() {
        this.log('Logging out...', 'info');

        try {
            const data = await this.apiCall('auth_api.php?action=logout');

            if (data.success) {
                this.log('Logout successful', 'success');
                this.state.isAuthenticated = false;
                this.state.currentUser = null;

                // Clear storage
                if (typeof sessionStorage !== 'undefined') {
                    sessionStorage.removeItem('user');
                }

                // Redirect to login
                window.location.href = this.config.apiBase + 'login_fixed.php';
            }

        } catch (error) {
            this.log(`Logout error: ${error.message}`, 'error');
        }
    }

    // Get detailed session information
    async getSessionInfo() {
        this.log('Fetching session information...', 'info');

        try {
            const data = await this.apiCall('auth_api.php?action=session');

            this.log('Session Information:', 'info');
            this.log(`Session ID: ${data.session_id}`, 'info');
            this.log(`Session Status: ${data.session_status}`, 'info');
            this.log(`Session Data: ${JSON.stringify(data.session_data, null, 2)}`, 'info');

            return data;
        } catch (error) {
            this.log(`Session info error: ${error.message}`, 'error');
            throw error;
        }
    }

    // Verify cookies are enabled and working
    checkCookieSupport() {
        this.log('Checking cookie support...', 'info');

        // Check if cookies are enabled
        if (!navigator.cookieEnabled) {
            this.log('Cookies are disabled in browser!', 'error');
            return false;
        }

        // Try to set a test cookie
        document.cookie = 'testcookie=test; path=/';
        const cookieEnabled = document.cookie.indexOf('testcookie') !== -1;

        if (cookieEnabled) {
            this.log('Cookie support verified', 'success');
            // Clean up test cookie
            document.cookie = 'testcookie=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/';
        } else {
            this.log('Cannot set cookies!', 'error');
        }

        // List all current cookies
        this.log(`Current cookies: ${document.cookie || 'None'}`, 'info');

        return cookieEnabled;
    }

    // Utility: Delay function
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // Monitor network activity
    monitorNetworkActivity() {
        this.log('Starting network activity monitor...', 'info');

        // Override fetch to log all requests
        const originalFetch = window.fetch;
        window.fetch = async (...args) => {
            const [url, options = {}] = args;
            this.log(`Network Request: ${options.method || 'GET'} ${url}`, 'info');

            try {
                const response = await originalFetch(...args);
                this.log(`Network Response: ${response.status} from ${url}`, response.ok ? 'success' : 'warning');
                return response;
            } catch (error) {
                this.log(`Network Error: ${error.message} for ${url}`, 'error');
                throw error;
            }
        };
    }

    // Create diagnostic report
    generateDiagnosticReport() {
        const report = {
            timestamp: new Date().toISOString(),
            browser: navigator.userAgent,
            cookiesEnabled: navigator.cookieEnabled,
            currentUrl: window.location.href,
            sessionStorage: typeof sessionStorage !== 'undefined',
            localStorage: typeof localStorage !== 'undefined',
            state: this.state,
            config: this.config
        };

        this.log('Diagnostic Report:', 'info');
        this.log(JSON.stringify(report, null, 2), 'info');

        return report;
    }
}

// Auto-initialize if not in module context
if (typeof module === 'undefined' && typeof window !== 'undefined') {
    window.DebugAuth = DebugAuth;
}
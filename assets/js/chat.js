(function() {
    'use strict';

    /**
     * Chat Module
     * Handles real-time messaging, channel management, and chat interactions
     */
    class ChatManager {
    constructor() {
        this.config = {
            apiBase: '/api/chat/',
            pollInterval: 2000,
            maxMessageLength: 1000
        };
        this.state = {
            currentChannel: 'general',
            currentUser: null,
            messages: [],
            typingUsers: new Set(),
            unreadCounts: new Map()
        };
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadInitialData();
        this.setupSidebar();
        this.startPolling();
    }

    bindEvents() {
        // Sidebar toggle
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });

        // Channel selection - using event delegation
        document.querySelector('.channel-list')?.addEventListener('click', (e) => {
            const channelItem = e.target.closest('.channel-item');
            if (channelItem) {
                this.handleChannelSelect(channelItem);
            }
        });

        // New channel button
        document.getElementById('newChannelBtn')?.addEventListener('click', () => {
            this.createNewChannel();
        });

        // Info panel toggle
        document.getElementById('toggleInfo')?.addEventListener('click', () => {
            this.toggleInfoPanel();
        });

        document.getElementById('closeInfo')?.addEventListener('click', () => {
            this.closeInfoPanel();
        });

        // Message input handling
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.addEventListener('keydown', (e) => this.handleMessageKeydown(e));
            messageInput.addEventListener('input', (e) => this.handleMessageInput(e));
        }

        // Send button
        document.getElementById('sendBtn')?.addEventListener('click', () => {
            this.sendMessage();
        });

        // Logout button
        document.getElementById('logoutBtn')?.addEventListener('click', () => {
            this.handleLogout();
        });

        // Attach file button
        document.querySelector('[title="Attach File"]')?.addEventListener('click', () => {
            this.handleFileAttachment();
        });

        // Emoji button
        document.querySelector('[title="Emoji"]')?.addEventListener('click', () => {
            this.showEmojiPicker();
        });

        // Auto-resize message input
        this.setupAutoResize();
    }

    setupSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            // Check if sidebar should be collapsed on mobile
            if (window.innerWidth < 768) {
                sidebar.classList.add('collapsed');
            }

            // Handle window resize
            window.addEventListener('resize', () => {
                if (window.innerWidth < 768 && !sidebar.classList.contains('collapsed')) {
                    sidebar.classList.add('collapsed');
                } else if (window.innerWidth >= 768 && sidebar.classList.contains('collapsed')) {
                    sidebar.classList.remove('collapsed');
                }
            });
        }
    }

    setupAutoResize() {
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.addEventListener('input', () => {
                messageInput.style.height = 'auto';
                const newHeight = Math.min(messageInput.scrollHeight, 120);
                messageInput.style.height = newHeight + 'px';
            });
        }
    }

    async loadInitialData() {
        try {
            // Load messages for current channel
            await this.loadMessages(this.state.currentChannel);
            // Scroll to bottom
            this.scrollToBottom();
        } catch (error) {
            console.error('Error loading initial data:', error);
            this.showToast('Error loading messages', 'error');
        }
    }

    handleChannelSelect(channelItem) {
        // Remove active class from all channels
        document.querySelectorAll('.channel-item').forEach(item => {
            item.classList.remove('active');
        });

        // Add active class to selected channel
        channelItem.classList.add('active');

        // Update current channel
        const channelId = channelItem.dataset.channel;
        this.state.currentChannel = channelId;

        // Update chat title
        const channelName = channelItem.querySelector('.channel-name').textContent;
        const isDirectMessage = channelId.startsWith('dm-');
        const chatTitle = document.querySelector('.chat-title');
        if (chatTitle) {
            chatTitle.textContent = isDirectMessage ? channelName : `# ${channelName}`;
        }

        // Clear unread badge
        const badge = channelItem.querySelector('.channel-badge');
        if (badge) {
            badge.remove();
        }

        // Load messages for selected channel
        this.loadMessages(channelId);
    }

    async loadMessages(channelId) {
        // Clear current messages
        const container = document.getElementById('messagesContainer');
        if (container) {
            // Keep typing indicator
            const typingIndicator = document.getElementById('typingIndicator');
            container.innerHTML = `
                <div class="date-separator">
                    <span>Today</span>
                </div>
            `;

            // Add sample messages based on channel
            this.renderChannelMessages(channelId);

            // Re-add typing indicator
            if (typingIndicator) {
                container.appendChild(typingIndicator);
            }
        }

        this.scrollToBottom();
    }

    renderChannelMessages(channelId) {
        const container = document.getElementById('messagesContainer');
        if (!container) return;

        // Sample messages for different channels
        const messages = this.getSampleMessages(channelId);

        messages.forEach(msg => {
            const messageEl = this.createMessageElement(msg);
            container.insertBefore(messageEl, container.lastElementChild);
        });
    }

    getSampleMessages(channelId) {
        // Return different messages based on channel
        if (channelId === 'development') {
            return [
                { author: 'Mike Johnson', avatar: 'MJ', time: '9:00 AM', text: 'The new API endpoints are ready for testing.' },
                { author: 'Jane Smith', avatar: 'JS', time: '9:15 AM', text: 'Great! I\'ll start integration testing this afternoon.' },
                { author: 'You', avatar: 'U', time: '9:30 AM', text: 'I found a bug in the authentication flow. Working on a fix.', own: true }
            ];
        } else if (channelId === 'design') {
            return [
                { author: 'Sarah Williams', avatar: 'SW', time: '8:45 AM', text: 'New mockups are ready for review in Figma.' },
                { author: 'John Doe', avatar: 'JD', time: '8:50 AM', text: 'Looking good! Love the new color scheme.' }
            ];
        } else if (channelId.startsWith('dm-')) {
            return [
                { author: 'Direct Message', avatar: 'DM', time: '11:00 AM', text: 'Hey, do you have time for a quick call?' },
                { author: 'You', avatar: 'U', time: '11:02 AM', text: 'Sure, give me 5 minutes.', own: true }
            ];
        }

        // Default messages for general channel
        return [];
    }

    createMessageElement(msg) {
        const div = document.createElement('div');
        div.className = msg.own ? 'message own' : 'message';
        div.innerHTML = `
            <div class="message-avatar">${msg.avatar}</div>
            <div class="message-content">
                <div class="message-header">
                    <span class="message-author">${msg.author}</span>
                    <span class="message-time">${msg.time}</span>
                </div>
                <div class="message-text">${this.escapeHtml(msg.text)}</div>
            </div>
        `;
        return div;
    }

    handleMessageKeydown(e) {
        // Send on Enter (without Shift)
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.sendMessage();
        }
    }

    handleMessageInput(e) {
        const text = e.target.value.trim();

        // Simulate typing indicator
        if (text.length > 0) {
            this.sendTypingIndicator();
        }

        // Check message length
        if (text.length > this.config.maxMessageLength) {
            e.target.value = text.substring(0, this.config.maxMessageLength);
            this.showToast(`Message too long (max ${this.config.maxMessageLength} characters)`, 'warning');
        }
    }

    sendMessage() {
        const messageInput = document.getElementById('messageInput');
        if (!messageInput) return;

        const text = messageInput.value.trim();
        if (text.length === 0) return;

        // Create message object
        const message = {
            author: 'You',
            avatar: document.querySelector('.sidebar-footer .user-avatar')?.textContent || 'U',
            time: this.getCurrentTime(),
            text: text,
            own: true
        };

        // Add message to UI
        const container = document.getElementById('messagesContainer');
        if (container) {
            const messageEl = this.createMessageElement(message);
            const typingIndicator = document.getElementById('typingIndicator');
            container.insertBefore(messageEl, typingIndicator);
        }

        // Clear input
        messageInput.value = '';
        messageInput.style.height = 'auto';

        // Scroll to bottom
        this.scrollToBottom();

        // Show confirmation
        this.showToast('Message sent', 'success');

        // Simulate response after delay
        setTimeout(() => this.simulateResponse(), 2000);
    }

    simulateResponse() {
        // Random responses for demo
        const responses = [
            { author: 'John Doe', avatar: 'JD', text: 'Got it, thanks!' },
            { author: 'Jane Smith', avatar: 'JS', text: 'Sounds good to me.' },
            { author: 'Mike Johnson', avatar: 'MJ', text: 'I\'ll look into it.' },
            { author: 'Sarah Williams', avatar: 'SW', text: 'Perfect, let\'s proceed.' }
        ];

        const response = responses[Math.floor(Math.random() * responses.length)];
        response.time = this.getCurrentTime();

        const container = document.getElementById('messagesContainer');
        if (container) {
            const messageEl = this.createMessageElement(response);
            const typingIndicator = document.getElementById('typingIndicator');
            container.insertBefore(messageEl, typingIndicator);
            this.scrollToBottom();
        }
    }

    sendTypingIndicator() {
        // Simulate typing indicator
        // In a real app, this would be sent via WebSocket
        console.log('User is typing...');
    }

    createNewChannel() {
        const channelName = prompt('Enter channel name:');
        if (channelName && channelName.trim()) {
            // Add new channel to list
            const channelSection = document.querySelector('.channel-section');
            if (channelSection) {
                const newChannel = document.createElement('div');
                newChannel.className = 'channel-item';
                newChannel.dataset.channel = channelName.toLowerCase().replace(/\s+/g, '-');
                newChannel.innerHTML = `
                    <span class="channel-icon">#</span>
                    <span class="channel-name">${channelName}</span>
                `;
                channelSection.appendChild(newChannel);

                this.showToast(`Channel #${channelName} created`, 'success');

                // Select the new channel
                this.handleChannelSelect(newChannel);
            }
        }
    }

    toggleInfoPanel() {
        const infoPanel = document.getElementById('chatInfo');
        if (infoPanel) {
            infoPanel.classList.toggle('open');
        }
    }

    closeInfoPanel() {
        const infoPanel = document.getElementById('chatInfo');
        if (infoPanel) {
            infoPanel.classList.remove('open');
        }
    }

    handleFileAttachment() {
        const input = document.createElement('input');
        input.type = 'file';
        input.multiple = true;
        input.onchange = (e) => {
            const files = Array.from(e.target.files);
            if (files.length > 0) {
                const fileNames = files.map(f => f.name).join(', ');
                this.showToast(`Files selected: ${fileNames}`, 'info');
            }
        };
        input.click();
    }

    showEmojiPicker() {
        // Simple emoji picker simulation
        const emojis = ['😊', '😂', '❤️', '👍', '🎉', '🔥', '✨', '💯'];
        const emoji = emojis[Math.floor(Math.random() * emojis.length)];

        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.value += emoji;
            messageInput.focus();
        }
    }

    scrollToBottom() {
        const container = document.getElementById('messagesContainer');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }

    getCurrentTime() {
        const now = new Date();
        const hours = now.getHours();
        const minutes = now.getMinutes().toString().padStart(2, '0');
        const period = hours >= 12 ? 'PM' : 'AM';
        const displayHours = hours % 12 || 12;
        return `${displayHours}:${minutes} ${period}`;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    startPolling() {
        // Simulate real-time updates
        setInterval(() => {
            this.checkForNewMessages();
        }, this.config.pollInterval);
    }

    checkForNewMessages() {
        // In a real app, this would check for new messages via API
        // For demo, we'll randomly show typing indicator
        const typingIndicator = document.getElementById('typingIndicator');
        if (typingIndicator && Math.random() > 0.9) {
            typingIndicator.classList.remove('hidden');
            setTimeout(() => {
                typingIndicator.classList.add('hidden');
            }, 3000);
        }
    }

    async handleLogout() {
        if (confirm('Are you sure you want to logout?')) {
            try {
                const response = await fetch('auth_api.php?action=logout', {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                if (response.ok) {
                    window.location.href = 'index.php';
                }
            } catch (error) {
                console.error('Logout error:', error);
                window.location.href = 'index.php';
            }
        }
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;

        document.body.appendChild(toast);

        setTimeout(() => toast.classList.add('show'), 10);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        window.chatManager = new ChatManager();
    });

    // Add required styles for animations
    const chatAnimationStyles = document.createElement('style');
    chatAnimationStyles.textContent = `
    .toast {
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 12px 24px;
        background: var(--color-gray-800);
        color: white;
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-lg);
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.3s;
        z-index: var(--z-toast);
    }

    .toast.show {
        opacity: 1;
        transform: translateY(0);
    }

    .toast-success { background: var(--color-success); }
    .toast-error { background: var(--color-error); }
    .toast-warning { background: var(--color-warning); }
    .toast-info { background: var(--color-info); }

    @keyframes typing {
        0%, 60%, 100% { transform: translateY(0); }
        30% { transform: translateY(-10px); }
    }

    .typing-dots span {
        display: inline-block;
        width: 8px;
        height: 8px;
        background: var(--color-gray-400);
        border-radius: 50%;
        margin: 0 2px;
        animation: typing 1.4s infinite;
    }

    .typing-dots span:nth-child(2) {
        animation-delay: 0.2s;
    }

    .typing-dots span:nth-child(3) {
        animation-delay: 0.4s;
        }
    `;
    document.head.appendChild(chatAnimationStyles);
})();
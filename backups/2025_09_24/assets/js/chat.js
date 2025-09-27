/**
 * CollaboraNexio Chat Interface
 * Complete chat implementation with long-polling, notifications, and real-time features
 */

class ChatApp {
    constructor() {
        this.config = {
            apiBase: '/api/',
            pollInterval: 2000,
            maxRetries: 10,
            baseRetryDelay: 1000,
            typingTimeout: 3000,
            notificationSound: '/assets/sounds/notification.mp3',
            messageSound: '/assets/sounds/message.mp3'
        };

        this.state = {
            currentUserId: null,
            currentTenantId: null,
            currentUsername: null,
            currentChannelId: null,
            currentChannelType: 'channel',
            lastSequenceId: 0,
            messages: new Map(),
            channels: new Map(),
            users: new Map(),
            onlineUsers: new Set(),
            typingUsers: new Map(),
            replyingTo: null,
            isConnected: false,
            offlineQueue: [],
            unreadCounts: new Map(),
            notificationsEnabled: false,
            soundEnabled: true
        };

        this.polling = {
            timeout: null,
            retryCount: 0,
            isPolling: false,
            abortController: null
        };

        this.ui = {
            emojiPicker: null,
            contextMenu: null,
            dragDropZone: null
        };

        this.init();
    }

    init() {
        this.loadUserInfo();
        this.bindEvents();
        this.setupAutoResize();
        this.setupTypingDetection();
        this.setupDragDrop();
        this.setupNotifications();
        this.loadChannels();
        this.startPolling();
        this.initializeEmojiPicker();
        this.setupOnlinePresence();
    }

    loadUserInfo() {
        const userIdEl = document.getElementById('currentUserId');
        const tenantIdEl = document.getElementById('currentTenantId');
        const usernameEl = document.getElementById('currentUsername');

        if (userIdEl && tenantIdEl && usernameEl) {
            this.state.currentUserId = parseInt(userIdEl.value);
            this.state.currentTenantId = parseInt(tenantIdEl.value);
            this.state.currentUsername = usernameEl.value;
        }
    }

    bindEvents() {
        // Main container event delegation
        const chatContainer = document.getElementById('chatContainer');
        if (chatContainer) {
            chatContainer.addEventListener('click', (e) => this.handleContainerClick(e));
            chatContainer.addEventListener('contextmenu', (e) => this.handleContextMenu(e));
        }

        // Message input events
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.addEventListener('keydown', (e) => this.handleMessageKeydown(e));
            messageInput.addEventListener('input', (e) => this.handleMessageInput(e));
            messageInput.addEventListener('paste', (e) => this.handlePaste(e));
        }

        // Sidebar events
        const sidebar = document.getElementById('chatSidebar');
        if (sidebar) {
            sidebar.addEventListener('click', (e) => this.handleSidebarClick(e));
        }

        // Right sidebar events
        const rightSidebar = document.getElementById('rightSidebar');
        if (rightSidebar) {
            rightSidebar.addEventListener('click', (e) => this.handleRightSidebarClick(e));
        }

        // Window events
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());
        window.addEventListener('beforeunload', () => this.cleanup());

        // Document events for closing menus
        document.addEventListener('click', (e) => this.handleDocumentClick(e));
        document.addEventListener('keydown', (e) => this.handleGlobalKeydown(e));
    }

    handleContainerClick(e) {
        // Send button
        if (e.target.matches('#btnSend')) {
            this.sendMessage();
        }
        // Attach button
        else if (e.target.matches('#btnAttach')) {
            this.selectFile();
        }
        // Emoji button
        else if (e.target.matches('#btnEmoji')) {
            this.toggleEmojiPicker();
        }
        // Cancel reply
        else if (e.target.matches('#btnCancelReply')) {
            this.cancelReply();
        }
        // Message actions
        else if (e.target.matches('.message-action-reply')) {
            const messageId = e.target.closest('.message').dataset.messageId;
            this.replyToMessage(messageId);
        }
        else if (e.target.matches('.message-action-edit')) {
            const messageId = e.target.closest('.message').dataset.messageId;
            this.editMessage(messageId);
        }
        else if (e.target.matches('.message-action-delete')) {
            const messageId = e.target.closest('.message').dataset.messageId;
            this.deleteMessage(messageId);
        }
        else if (e.target.matches('.message-action-react')) {
            const messageId = e.target.closest('.message').dataset.messageId;
            this.showReactionPicker(messageId);
        }
        // Reactions
        else if (e.target.closest('.reaction')) {
            const reaction = e.target.closest('.reaction');
            const messageId = reaction.closest('.message').dataset.messageId;
            const emoji = reaction.dataset.emoji;
            this.toggleReaction(messageId, emoji);
        }
        // Thread
        else if (e.target.matches('.thread-preview')) {
            const messageId = e.target.closest('.message').dataset.messageId;
            this.openThread(messageId);
        }
    }

    handleSidebarClick(e) {
        // Add channel button
        if (e.target.matches('#btnAddChannel')) {
            this.showCreateChannelModal();
        }
        // New DM button
        else if (e.target.matches('#btnNewDM')) {
            this.showNewDMModal();
        }
        // Channel/DM item
        else if (e.target.closest('.channel-item, .dm-item')) {
            const item = e.target.closest('.channel-item, .dm-item');
            const channelId = parseInt(item.dataset.channelId);
            const channelType = item.dataset.channelType;
            this.selectChannel(channelId, channelType);
        }
        // Online user
        else if (e.target.closest('.user-item')) {
            const item = e.target.closest('.user-item');
            const userId = parseInt(item.dataset.userId);
            this.startDirectMessage(userId);
        }
    }

    handleRightSidebarClick(e) {
        // Close button
        if (e.target.matches('#btnCloseRight')) {
            this.closeRightSidebar();
        }
        // Tab buttons
        else if (e.target.matches('#btnMembers')) {
            this.showChannelMembers();
        }
        else if (e.target.matches('#btnSearch')) {
            this.showSearch();
        }
        else if (e.target.matches('#btnInfo')) {
            this.showChannelInfo();
        }
    }

    handleMessageKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.sendMessage();
        }
    }

    handleMessageInput(e) {
        this.updateTypingIndicator();
        this.checkMentions(e.target.value);
    }

    handlePaste(e) {
        const items = e.clipboardData.items;
        for (let item of items) {
            if (item.type.indexOf('image') !== -1) {
                const file = item.getAsFile();
                this.uploadFiles([file]);
                e.preventDefault();
            }
        }
    }

    handleContextMenu(e) {
        if (e.target.closest('.message')) {
            e.preventDefault();
            const message = e.target.closest('.message');
            this.showMessageContextMenu(message, e.clientX, e.clientY);
        }
    }

    handleDocumentClick(e) {
        // Close emoji picker
        const emojiPicker = document.getElementById('emojiPicker');
        if (emojiPicker && !emojiPicker.contains(e.target) && !e.target.matches('#btnEmoji')) {
            emojiPicker.style.display = 'none';
        }

        // Close context menu
        if (this.ui.contextMenu) {
            this.ui.contextMenu.remove();
            this.ui.contextMenu = null;
        }
    }

    handleGlobalKeydown(e) {
        // Quick channel switch (Ctrl+K)
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault();
            this.showQuickSwitcher();
        }
        // Toggle sidebar (Ctrl+B)
        else if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            this.toggleSidebar();
        }
    }

    setupAutoResize() {
        const messageInput = document.getElementById('messageInput');
        if (!messageInput) return;

        messageInput.addEventListener('input', () => {
            messageInput.style.height = 'auto';
            messageInput.style.height = Math.min(messageInput.scrollHeight, 120) + 'px';
        });
    }

    setupTypingDetection() {
        let typingTimeout = null;

        this.updateTypingIndicator = () => {
            if (!this.state.currentChannelId) return;

            // Send typing indicator
            this.sendTypingIndicator(true);

            // Clear existing timeout
            clearTimeout(typingTimeout);

            // Stop typing after timeout
            typingTimeout = setTimeout(() => {
                this.sendTypingIndicator(false);
            }, this.config.typingTimeout);
        };
    }

    setupDragDrop() {
        const messageContainer = document.getElementById('messageContainer');
        if (!messageContainer) return;

        messageContainer.addEventListener('dragover', (e) => {
            e.preventDefault();
            this.showDropZone();
        });

        messageContainer.addEventListener('dragleave', (e) => {
            if (!messageContainer.contains(e.relatedTarget)) {
                this.hideDropZone();
            }
        });

        messageContainer.addEventListener('drop', (e) => {
            e.preventDefault();
            this.hideDropZone();

            const files = Array.from(e.dataTransfer.files);
            if (files.length > 0) {
                this.uploadFiles(files);
            }
        });
    }

    showDropZone() {
        if (!this.ui.dragDropZone) {
            this.ui.dragDropZone = document.createElement('div');
            this.ui.dragDropZone.className = 'drag-drop-zone';
            this.ui.dragDropZone.innerHTML = `
                <div class="drag-drop-content">
                    <div class="drag-drop-icon">📁</div>
                    <div class="drag-drop-text">Drop files here to upload</div>
                </div>
            `;
            document.getElementById('messageContainer').appendChild(this.ui.dragDropZone);
        }
        this.ui.dragDropZone.classList.add('active');
    }

    hideDropZone() {
        if (this.ui.dragDropZone) {
            this.ui.dragDropZone.classList.remove('active');
            setTimeout(() => {
                if (this.ui.dragDropZone && !this.ui.dragDropZone.classList.contains('active')) {
                    this.ui.dragDropZone.remove();
                    this.ui.dragDropZone = null;
                }
            }, 300);
        }
    }

    async setupNotifications() {
        if ('Notification' in window) {
            if (Notification.permission === 'default') {
                const permission = await Notification.requestPermission();
                this.state.notificationsEnabled = permission === 'granted';
            } else {
                this.state.notificationsEnabled = Notification.permission === 'granted';
            }
        }
    }

    showNotification(title, body, icon = null) {
        if (!this.state.notificationsEnabled) return;
        if (document.hasFocus()) return;

        const notification = new Notification(title, {
            body: body,
            icon: icon || '/assets/images/logo.png',
            badge: '/assets/images/badge.png',
            tag: 'collaboranexio-chat',
            renotify: true
        });

        notification.onclick = () => {
            window.focus();
            notification.close();
        };

        // Auto close after 5 seconds
        setTimeout(() => notification.close(), 5000);
    }

    playSound(type = 'message') {
        if (!this.state.soundEnabled) return;

        const soundFile = type === 'notification'
            ? this.config.notificationSound
            : this.config.messageSound;

        const audio = new Audio(soundFile);
        audio.volume = 0.3;
        audio.play().catch(err => console.log('Sound play failed:', err));
    }

    initializeEmojiPicker() {
        const emojiPicker = document.getElementById('emojiPicker');
        if (!emojiPicker) return;

        const emojis = [
            '😀', '😃', '😄', '😁', '😅', '😂', '🤣', '😊', '😇',
            '🙂', '😉', '😌', '😍', '🥰', '😘', '😗', '😙', '😚',
            '😋', '😛', '😜', '🤪', '😝', '🤑', '🤗', '🤭', '🤫',
            '🤔', '🤐', '🤨', '😐', '😑', '😶', '😏', '😒', '🙄',
            '😬', '🤥', '😌', '😔', '😪', '😴', '😷', '🤒', '🤕',
            '🤢', '🤮', '🤧', '🥵', '🥶', '😵', '🤯', '🤠', '🥳',
            '😎', '🤓', '🧐', '😕', '😟', '🙁', '😮', '😯', '😲',
            '😳', '🥺', '😦', '😧', '😨', '😰', '😥', '😢', '😭',
            '😱', '😖', '😣', '😞', '😓', '😩', '😫', '🥱', '😤',
            '😡', '😠', '🤬', '😈', '👿', '💀', '💩', '🤡', '👻',
            '👽', '🤖', '😺', '😸', '😹', '😻', '😼', '😽', '🙀',
            '😿', '😾', '👋', '🤚', '🖐️', '✋', '🖖', '👌', '🤏',
            '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆', '🖕',
            '👇', '☝️', '👍', '👎', '✊', '👊', '🤛', '🤜', '👏',
            '🙌', '👐', '🤲', '🤝', '🙏', '❤️', '🧡', '💛', '💚',
            '💙', '💜', '🖤', '🤍', '🤎', '💔', '❣️', '💕', '💞',
            '💓', '💗', '💖', '💘', '💝', '⭐', '🌟', '✨', '⚡',
            '🔥', '💥', '🎉', '🎊', '🎈', '🎁', '🏆', '🥇', '🥈',
            '🥉', '🏅', '🎯', '🎮', '🎲', '🎰', '🎵', '🎶', '🎸'
        ];

        const emojiGrid = document.createElement('div');
        emojiGrid.className = 'emoji-grid';

        emojis.forEach(emoji => {
            const emojiBtn = document.createElement('span');
            emojiBtn.className = 'emoji';
            emojiBtn.textContent = emoji;
            emojiBtn.addEventListener('click', () => this.insertEmoji(emoji));
            emojiGrid.appendChild(emojiBtn);
        });

        emojiPicker.innerHTML = '';
        emojiPicker.appendChild(emojiGrid);
    }

    async apiCall(endpoint, options = {}) {
        try {
            const response = await fetch(this.config.apiBase + endpoint, {
                credentials: 'same-origin',
                ...options
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            this.showToast('Communication error', 'error');
            throw error;
        }
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('show');
        }, 10);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    async loadChannels() {
        try {
            const data = await this.apiCall('channels.php');

            if (data.success) {
                this.renderChannels(data.channels);

                // Select first channel if available
                if (data.channels.length > 0 && !this.state.currentChannelId) {
                    this.selectChannel(data.channels[0].id, data.channels[0].type);
                }
            }
        } catch (error) {
            console.error('Failed to load channels:', error);
        }
    }

    renderChannels(channels) {
        const channelList = document.getElementById('channelList');
        const dmList = document.getElementById('dmList');

        if (!channelList || !dmList) return;

        channelList.innerHTML = '';
        dmList.innerHTML = '';

        channels.forEach(channel => {
            this.state.channels.set(channel.id, channel);

            const item = document.createElement('div');
            item.className = channel.type === 'dm' ? 'dm-item' : 'channel-item';
            item.dataset.channelId = channel.id;
            item.dataset.channelType = channel.type;

            if (channel.id === this.state.currentChannelId) {
                item.classList.add('active');
            }

            const nameSpan = document.createElement('span');
            nameSpan.className = 'channel-name';
            nameSpan.textContent = channel.name;
            item.appendChild(nameSpan);

            if (channel.type === 'dm') {
                const status = document.createElement('span');
                status.className = `status-indicator ${channel.online ? 'online' : 'offline'}`;
                item.insertBefore(status, nameSpan);
            }

            const unreadCount = this.state.unreadCounts.get(channel.id) || channel.unread_count || 0;
            if (unreadCount > 0) {
                const unread = document.createElement('span');
                unread.className = 'unread-count';
                unread.textContent = unreadCount > 99 ? '99+' : unreadCount;
                item.appendChild(unread);
            }

            if (channel.type === 'dm') {
                dmList.appendChild(item);
            } else {
                channelList.appendChild(item);
            }
        });
    }

    async selectChannel(channelId, type) {
        // Update active state
        document.querySelectorAll('.channel-item, .dm-item').forEach(item => {
            item.classList.remove('active');
        });

        const activeItem = document.querySelector(`[data-channel-id="${channelId}"]`);
        if (activeItem) {
            activeItem.classList.add('active');

            // Clear unread count
            const unreadEl = activeItem.querySelector('.unread-count');
            if (unreadEl) {
                unreadEl.remove();
            }
        }

        this.state.currentChannelId = channelId;
        this.state.currentChannelType = type;
        this.state.unreadCounts.delete(channelId);

        // Update header
        const channel = this.state.channels.get(channelId);
        if (channel) {
            const channelName = document.getElementById('channelName');
            const channelDescription = document.getElementById('channelDescription');

            if (channelName) {
                channelName.textContent = type === 'dm' ? channel.name : '#' + channel.name;
            }
            if (channelDescription) {
                channelDescription.textContent = channel.description || '';
            }
        }

        // Clear messages and load channel messages
        this.state.messages.clear();
        const messageList = document.getElementById('messageList');
        if (messageList) {
            messageList.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
        }

        await this.loadChannelMessages(channelId);
    }

    async loadChannelMessages(channelId) {
        try {
            const data = await this.apiCall(`messages.php?channel_id=${channelId}`);

            if (data.success) {
                this.renderMessages(data.messages);
                this.scrollToBottom();
            }
        } catch (error) {
            console.error('Failed to load messages:', error);
            const messageList = document.getElementById('messageList');
            if (messageList) {
                messageList.innerHTML = '<div class="error-message">Failed to load messages</div>';
            }
        }
    }

    renderMessages(messages, append = false) {
        const messageList = document.getElementById('messageList');
        if (!messageList) return;

        if (!append) {
            messageList.innerHTML = '';
        }

        let lastDate = null;

        messages.forEach(message => {
            // Add to message map
            this.state.messages.set(message.id, message);

            // Add date divider if needed
            const messageDate = new Date(message.created_at).toLocaleDateString();
            if (messageDate !== lastDate) {
                lastDate = messageDate;
                const divider = this.createDateDivider(messageDate);
                messageList.appendChild(divider);
            }

            // Create message element
            const messageEl = this.createMessageElement(message);
            messageList.appendChild(messageEl);
        });

        // Auto scroll if near bottom
        this.scrollToBottomIfNeeded();
    }

    createDateDivider(date) {
        const divider = document.createElement('div');
        divider.className = 'date-divider';

        const span = document.createElement('span');
        const today = new Date().toLocaleDateString();
        const yesterday = new Date(Date.now() - 86400000).toLocaleDateString();

        if (date === today) {
            span.textContent = 'Today';
        } else if (date === yesterday) {
            span.textContent = 'Yesterday';
        } else {
            span.textContent = date;
        }

        divider.appendChild(span);
        return divider;
    }

    createMessageElement(message) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'message';
        messageDiv.dataset.messageId = message.id;
        messageDiv.dataset.userId = message.user_id;

        if (message.user_id === this.state.currentUserId) {
            messageDiv.classList.add('own-message');
        }

        // Avatar
        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.style.background = this.getUserColor(message.user_id);
        avatar.textContent = (message.username || 'U').charAt(0).toUpperCase();
        messageDiv.appendChild(avatar);

        // Content
        const content = document.createElement('div');
        content.className = 'message-content';

        // Header
        const header = document.createElement('div');
        header.className = 'message-header';

        const author = document.createElement('span');
        author.className = 'message-author';
        author.textContent = message.username;
        header.appendChild(author);

        const time = document.createElement('span');
        time.className = 'message-time';
        time.textContent = this.formatTime(message.created_at);
        time.title = new Date(message.created_at).toLocaleString();
        header.appendChild(time);

        if (message.edited_at) {
            const edited = document.createElement('span');
            edited.className = 'message-edited';
            edited.textContent = '(edited)';
            edited.title = `Edited ${new Date(message.edited_at).toLocaleString()}`;
            header.appendChild(edited);
        }

        content.appendChild(header);

        // Reply preview if replying to another message
        if (message.parent_id && message.parent_message) {
            const replyPreview = document.createElement('div');
            replyPreview.className = 'message-reply-preview';
            replyPreview.innerHTML = `
                <div class="reply-author">${message.parent_message.username}</div>
                <div class="reply-content">${this.truncateText(message.parent_message.content, 50)}</div>
            `;
            content.appendChild(replyPreview);
        }

        // Body
        const body = document.createElement('div');
        body.className = 'message-body';
        body.innerHTML = this.formatMessage(message.content);
        content.appendChild(body);

        // Attachments
        if (message.attachments && message.attachments.length > 0) {
            const attachments = document.createElement('div');
            attachments.className = 'message-attachments';

            message.attachments.forEach(attachment => {
                const attachmentEl = this.createAttachmentElement(attachment);
                attachments.appendChild(attachmentEl);
            });

            content.appendChild(attachments);
        }

        // Reactions
        if (message.reactions && Object.keys(message.reactions).length > 0) {
            const reactions = this.createReactionsElement(message.reactions, message.id);
            content.appendChild(reactions);
        }

        // Actions (visible on hover)
        const actions = document.createElement('div');
        actions.className = 'message-actions';

        const actionsInner = document.createElement('div');
        actionsInner.className = 'message-actions-inner';

        // Reply
        const replyBtn = document.createElement('button');
        replyBtn.className = 'message-action message-action-reply';
        replyBtn.title = 'Reply';
        replyBtn.innerHTML = '↩';
        actionsInner.appendChild(replyBtn);

        // React
        const reactBtn = document.createElement('button');
        reactBtn.className = 'message-action message-action-react';
        reactBtn.title = 'Add reaction';
        reactBtn.innerHTML = '😊';
        actionsInner.appendChild(reactBtn);

        // Edit (only for own messages)
        if (message.user_id === this.state.currentUserId) {
            const editBtn = document.createElement('button');
            editBtn.className = 'message-action message-action-edit';
            editBtn.title = 'Edit';
            editBtn.innerHTML = '✏';
            actionsInner.appendChild(editBtn);

            const deleteBtn = document.createElement('button');
            deleteBtn.className = 'message-action message-action-delete';
            deleteBtn.title = 'Delete';
            deleteBtn.innerHTML = '🗑';
            actionsInner.appendChild(deleteBtn);
        }

        actions.appendChild(actionsInner);
        content.appendChild(actions);

        // Thread preview
        if (message.thread_count > 0) {
            const thread = document.createElement('div');
            thread.className = 'thread-preview';
            thread.innerHTML = `
                <span class="thread-icon">💬</span>
                <span class="thread-info">${message.thread_count} ${message.thread_count === 1 ? 'reply' : 'replies'}</span>
                <span class="thread-last-reply">Last reply ${this.formatTime(message.last_thread_at)}</span>
            `;
            content.appendChild(thread);
        }

        messageDiv.appendChild(content);

        return messageDiv;
    }

    createAttachmentElement(attachment) {
        const div = document.createElement('div');
        div.className = 'message-attachment';

        // Check if image
        if (attachment.type && attachment.type.startsWith('image/')) {
            div.classList.add('attachment-image');
            const img = document.createElement('img');
            img.src = attachment.url;
            img.alt = attachment.name;
            img.loading = 'lazy';
            img.addEventListener('click', () => this.viewImage(attachment.url));
            div.appendChild(img);
        } else {
            // Regular file attachment
            const icon = document.createElement('span');
            icon.className = 'attachment-icon';
            icon.textContent = this.getFileIcon(attachment.name);
            div.appendChild(icon);

            const info = document.createElement('div');
            info.className = 'attachment-info';

            const name = document.createElement('div');
            name.className = 'attachment-name';
            name.textContent = attachment.name;
            info.appendChild(name);

            const size = document.createElement('div');
            size.className = 'attachment-size';
            size.textContent = this.formatFileSize(attachment.size);
            info.appendChild(size);

            div.appendChild(info);

            const download = document.createElement('button');
            download.className = 'attachment-download';
            download.textContent = 'Download';
            download.addEventListener('click', () => this.downloadFile(attachment.url, attachment.name));
            div.appendChild(download);
        }

        return div;
    }

    createReactionsElement(reactions, messageId) {
        const div = document.createElement('div');
        div.className = 'message-reactions';

        Object.entries(reactions).forEach(([emoji, users]) => {
            const reaction = document.createElement('div');
            reaction.className = 'reaction';
            reaction.dataset.emoji = emoji;

            if (users.includes(this.state.currentUserId)) {
                reaction.classList.add('active');
            }

            const emojiSpan = document.createElement('span');
            emojiSpan.className = 'reaction-emoji';
            emojiSpan.textContent = emoji;
            reaction.appendChild(emojiSpan);

            const count = document.createElement('span');
            count.className = 'reaction-count';
            count.textContent = users.length;
            reaction.appendChild(count);

            // Tooltip with usernames
            reaction.title = users.map(id => this.getUserName(id)).join(', ');

            div.appendChild(reaction);
        });

        // Add reaction button
        const addBtn = document.createElement('div');
        addBtn.className = 'reaction reaction-add';
        addBtn.innerHTML = '<span>+</span>';
        addBtn.addEventListener('click', () => this.showReactionPicker(messageId));
        div.appendChild(addBtn);

        return div;
    }

    formatMessage(content) {
        // Escape HTML
        let formatted = content
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // Convert line breaks
        formatted = formatted.replace(/\n/g, '<br>');

        // Format markdown-like syntax
        formatted = formatted
            // Bold
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            // Italic
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            // Code blocks
            .replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
            // Inline code
            .replace(/`(.+?)`/g, '<code>$1</code>')
            // Mentions
            .replace(/@(\w+)/g, '<span class="mention">@$1</span>')
            // Links
            .replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>')
            // Emojis (basic support)
            .replace(/:smile:/g, '😊')
            .replace(/:laugh:/g, '😂')
            .replace(/:heart:/g, '❤️')
            .replace(/:thumbsup:/g, '👍')
            .replace(/:thumbsdown:/g, '👎');

        return formatted;
    }

    formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;

        if (diff < 60000) return 'just now';
        if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
        if (diff < 86400000) {
            const hours = Math.floor(diff / 3600000);
            return hours + 'h ago';
        }

        // Same day
        if (date.toDateString() === now.toDateString()) {
            return date.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Yesterday
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        if (date.toDateString() === yesterday.toDateString()) {
            return 'Yesterday ' + date.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Older
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const icons = {
            pdf: '📄',
            doc: '📝',
            docx: '📝',
            xls: '📊',
            xlsx: '📊',
            ppt: '📽',
            pptx: '📽',
            zip: '🗜',
            rar: '🗜',
            txt: '📃',
            csv: '📈',
            mp3: '🎵',
            mp4: '🎥',
            avi: '🎥',
            mov: '🎥',
            png: '🖼',
            jpg: '🖼',
            jpeg: '🖼',
            gif: '🖼',
            svg: '🖼'
        };
        return icons[ext] || '📎';
    }

    getUserColor(userId) {
        const colors = [
            '#667eea', '#764ba2', '#f093fb', '#ffa0c9',
            '#fd79a8', '#fdcb6e', '#6c5ce7', '#00b894',
            '#00cec9', '#0984e3', '#74b9ff', '#a29bfe'
        ];
        return colors[userId % colors.length];
    }

    getUserName(userId) {
        const user = this.state.users.get(userId);
        return user ? user.name : `User ${userId}`;
    }

    truncateText(text, maxLength) {
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }

    async sendMessage() {
        const input = document.getElementById('messageInput');
        if (!input) return;

        const content = input.value.trim();
        if (!content || !this.state.currentChannelId) return;

        const message = {
            channel_id: this.state.currentChannelId,
            content: content,
            parent_id: this.state.replyingTo ? this.state.replyingTo.id : null
        };

        // Clear input immediately for better UX
        input.value = '';
        input.style.height = 'auto';
        this.cancelReply();

        // Add to offline queue if not connected
        if (!this.state.isConnected) {
            this.state.offlineQueue.push(message);
            this.showToast('Message will be sent when connection is restored', 'warning');
            return;
        }

        try {
            const data = await this.apiCall('messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(message)
            });

            if (data.success) {
                // Message will appear through polling
                this.playSound('message');
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            this.state.offlineQueue.push(message);
            this.showToast('Failed to send message, will retry', 'error');
        }
    }

    async sendTypingIndicator(isTyping) {
        if (!this.state.currentChannelId || !this.state.isConnected) return;

        try {
            await this.apiCall('typing.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    channel_id: this.state.currentChannelId,
                    is_typing: isTyping
                })
            });
        } catch (error) {
            // Silently fail for typing indicators
            console.log('Typing indicator failed:', error);
        }
    }

    replyToMessage(messageId) {
        const message = this.state.messages.get(parseInt(messageId));
        if (!message) return;

        this.state.replyingTo = message;

        const preview = document.getElementById('replyPreview');
        if (preview) {
            const replyToUser = document.getElementById('replyToUser');
            const replyToContent = document.getElementById('replyToContent');

            if (replyToUser) {
                replyToUser.textContent = message.username;
            }
            if (replyToContent) {
                replyToContent.textContent = this.truncateText(message.content, 100);
            }

            preview.style.display = 'flex';
        }

        const input = document.getElementById('messageInput');
        if (input) {
            input.focus();
        }
    }

    cancelReply() {
        this.state.replyingTo = null;
        const preview = document.getElementById('replyPreview');
        if (preview) {
            preview.style.display = 'none';
        }
    }

    async editMessage(messageId) {
        const message = this.state.messages.get(parseInt(messageId));
        if (!message || message.user_id !== this.state.currentUserId) return;

        const newContent = prompt('Edit message:', message.content);
        if (newContent === null || newContent === message.content) return;

        try {
            const data = await this.apiCall(`messages.php/${messageId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content: newContent })
            });

            if (data.success) {
                // Update will come through polling
                this.showToast('Message edited', 'success');
            }
        } catch (error) {
            console.error('Failed to edit message:', error);
        }
    }

    async deleteMessage(messageId) {
        const message = this.state.messages.get(parseInt(messageId));
        if (!message || message.user_id !== this.state.currentUserId) return;

        if (!confirm('Delete this message?')) return;

        try {
            const data = await this.apiCall(`messages.php?id=${messageId}`, {
                method: 'DELETE'
            });

            if (data.success) {
                const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
                if (messageEl) {
                    messageEl.classList.add('message-deleted');
                    setTimeout(() => messageEl.remove(), 300);
                }
                this.state.messages.delete(parseInt(messageId));
                this.showToast('Message deleted', 'success');
            }
        } catch (error) {
            console.error('Failed to delete message:', error);
        }
    }

    showReactionPicker(messageId) {
        const quickReactions = ['👍', '❤️', '😂', '😮', '😢', '👏'];

        const picker = document.createElement('div');
        picker.className = 'reaction-picker';

        quickReactions.forEach(emoji => {
            const btn = document.createElement('button');
            btn.className = 'reaction-picker-emoji';
            btn.textContent = emoji;
            btn.addEventListener('click', () => {
                this.toggleReaction(messageId, emoji);
                picker.remove();
            });
            picker.appendChild(btn);
        });

        // Position near the message
        const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
        if (messageEl) {
            messageEl.appendChild(picker);

            // Remove picker when clicking outside
            setTimeout(() => {
                const clickOutside = (e) => {
                    if (!picker.contains(e.target)) {
                        picker.remove();
                        document.removeEventListener('click', clickOutside);
                    }
                };
                document.addEventListener('click', clickOutside);
            }, 100);
        }
    }

    async toggleReaction(messageId, emoji) {
        try {
            const data = await this.apiCall('messages.php/reaction', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message_id: messageId,
                    emoji: emoji
                })
            });

            if (data.success) {
                // Update will come through polling
            }
        } catch (error) {
            console.error('Failed to toggle reaction:', error);
        }
    }

    toggleEmojiPicker() {
        const picker = document.getElementById('emojiPicker');
        if (picker) {
            picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
        }
    }

    insertEmoji(emoji) {
        const input = document.getElementById('messageInput');
        if (!input) return;

        const start = input.selectionStart;
        const end = input.selectionEnd;
        const text = input.value;

        input.value = text.substring(0, start) + emoji + text.substring(end);
        input.selectionStart = input.selectionEnd = start + emoji.length;
        input.focus();

        const picker = document.getElementById('emojiPicker');
        if (picker) {
            picker.style.display = 'none';
        }
    }

    selectFile() {
        const fileInput = document.getElementById('fileInput');
        if (fileInput) {
            fileInput.click();
        }
    }

    async uploadFiles(files) {
        if (!files || files.length === 0) return;

        const formData = new FormData();
        formData.append('channel_id', this.state.currentChannelId);

        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }

        try {
            this.showToast('Uploading files...', 'info');

            const data = await this.apiCall('upload.php', {
                method: 'POST',
                body: formData
            });

            if (data.success) {
                this.showToast('Files uploaded successfully', 'success');
                // Files will appear as messages through polling
            }
        } catch (error) {
            console.error('Failed to upload files:', error);
            this.showToast('Failed to upload files', 'error');
        }
    }

    viewImage(url) {
        const modal = document.createElement('div');
        modal.className = 'image-viewer-modal';
        modal.innerHTML = `
            <div class="image-viewer-content">
                <img src="${url}" alt="Image">
                <button class="image-viewer-close">&times;</button>
            </div>
        `;

        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.matches('.image-viewer-close')) {
                modal.remove();
            }
        });

        document.body.appendChild(modal);
    }

    downloadFile(url, filename) {
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    showCreateChannelModal() {
        const modal = document.getElementById('createChannelModal');
        if (modal) {
            modal.style.display = 'flex';
            const input = document.getElementById('newChannelName');
            if (input) {
                input.focus();
            }
        }
    }

    async createChannel() {
        const nameInput = document.getElementById('newChannelName');
        const descInput = document.getElementById('newChannelDesc');
        const privateInput = document.getElementById('newChannelPrivate');

        if (!nameInput) return;

        const name = nameInput.value.trim();
        const description = descInput ? descInput.value.trim() : '';
        const isPrivate = privateInput ? privateInput.checked : false;

        if (!name) {
            this.showToast('Channel name is required', 'error');
            return;
        }

        try {
            const data = await this.apiCall('channels.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: name,
                    description: description,
                    type: isPrivate ? 'private' : 'public'
                })
            });

            if (data.success) {
                this.closeModal('createChannelModal');
                await this.loadChannels();
                if (data.channel_id) {
                    this.selectChannel(data.channel_id, 'channel');
                }
                this.showToast('Channel created', 'success');
            }
        } catch (error) {
            console.error('Failed to create channel:', error);
        }
    }

    showNewDMModal() {
        // Implementation for showing new DM modal
        this.showToast('Select a user to message', 'info');
    }

    async startDirectMessage(userId) {
        try {
            const data = await this.apiCall('channels.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: 'dm',
                    user_id: userId
                })
            });

            if (data.success && data.channel_id) {
                await this.loadChannels();
                this.selectChannel(data.channel_id, 'dm');
            }
        } catch (error) {
            console.error('Failed to start DM:', error);
        }
    }

    openThread(messageId) {
        // Implementation for thread view
        this.showToast('Thread view coming soon', 'info');
    }

    showChannelMembers() {
        const rightSidebar = document.getElementById('rightSidebar');
        const rightSidebarTitle = document.getElementById('rightSidebarTitle');
        const rightSidebarContent = document.getElementById('rightSidebarContent');

        if (!rightSidebar || !rightSidebarTitle || !rightSidebarContent) return;

        rightSidebarTitle.textContent = 'Members';
        rightSidebarContent.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
        rightSidebar.style.display = 'flex';

        // Load members
        this.loadChannelMembers();
    }

    async loadChannelMembers() {
        if (!this.state.currentChannelId) return;

        try {
            const data = await this.apiCall(`channels.php/${this.state.currentChannelId}/members`);

            if (data.success) {
                this.renderChannelMembers(data.members);
            }
        } catch (error) {
            console.error('Failed to load members:', error);
        }
    }

    renderChannelMembers(members) {
        const content = document.getElementById('rightSidebarContent');
        if (!content) return;

        content.innerHTML = '';

        const list = document.createElement('div');
        list.className = 'member-list';

        members.forEach(member => {
            const item = document.createElement('div');
            item.className = 'member-item';
            item.dataset.userId = member.id;

            const status = document.createElement('span');
            status.className = `status-indicator ${this.state.onlineUsers.has(member.id) ? 'online' : 'offline'}`;
            item.appendChild(status);

            const avatar = document.createElement('div');
            avatar.className = 'member-avatar';
            avatar.style.background = this.getUserColor(member.id);
            avatar.textContent = member.name.charAt(0).toUpperCase();
            item.appendChild(avatar);

            const info = document.createElement('div');
            info.className = 'member-info';

            const name = document.createElement('div');
            name.className = 'member-name';
            name.textContent = member.name;
            info.appendChild(name);

            if (member.role) {
                const role = document.createElement('div');
                role.className = 'member-role';
                role.textContent = member.role;
                info.appendChild(role);
            }

            item.appendChild(info);

            if (member.id !== this.state.currentUserId) {
                item.addEventListener('click', () => this.startDirectMessage(member.id));
            }

            list.appendChild(item);
        });

        content.appendChild(list);
    }

    showSearch() {
        const rightSidebar = document.getElementById('rightSidebar');
        const rightSidebarTitle = document.getElementById('rightSidebarTitle');
        const rightSidebarContent = document.getElementById('rightSidebarContent');

        if (!rightSidebar || !rightSidebarTitle || !rightSidebarContent) return;

        rightSidebarTitle.textContent = 'Search';
        rightSidebarContent.innerHTML = `
            <div class="search-container">
                <input type="text" id="searchInput" class="search-input" placeholder="Search messages...">
                <div id="searchResults" class="search-results"></div>
            </div>
        `;
        rightSidebar.style.display = 'flex';

        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.focus();
            searchInput.addEventListener('input', (e) => this.performSearch(e.target.value));
        }
    }

    async performSearch(query) {
        if (!query || query.length < 2) {
            const results = document.getElementById('searchResults');
            if (results) {
                results.innerHTML = '';
            }
            return;
        }

        try {
            const data = await this.apiCall(`search.php?q=${encodeURIComponent(query)}&channel_id=${this.state.currentChannelId}`);

            if (data.success) {
                this.renderSearchResults(data.results);
            }
        } catch (error) {
            console.error('Search failed:', error);
        }
    }

    renderSearchResults(results) {
        const resultsEl = document.getElementById('searchResults');
        if (!resultsEl) return;

        if (results.length === 0) {
            resultsEl.innerHTML = '<div class="no-results">No results found</div>';
            return;
        }

        resultsEl.innerHTML = '';

        results.forEach(result => {
            const item = document.createElement('div');
            item.className = 'search-result';
            item.innerHTML = `
                <div class="search-result-author">${result.username}</div>
                <div class="search-result-content">${this.highlightSearchTerm(result.content, result.query)}</div>
                <div class="search-result-time">${this.formatTime(result.created_at)}</div>
            `;

            item.addEventListener('click', () => this.jumpToMessage(result.id));
            resultsEl.appendChild(item);
        });
    }

    highlightSearchTerm(text, term) {
        const regex = new RegExp(`(${term})`, 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }

    jumpToMessage(messageId) {
        const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
        if (messageEl) {
            messageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            messageEl.classList.add('highlight');
            setTimeout(() => messageEl.classList.remove('highlight'), 2000);
        }
    }

    showChannelInfo() {
        // Implementation for channel info
        this.showToast('Channel info coming soon', 'info');
    }

    closeRightSidebar() {
        const rightSidebar = document.getElementById('rightSidebar');
        if (rightSidebar) {
            rightSidebar.style.display = 'none';
        }
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';

            // Clear form inputs
            const inputs = modal.querySelectorAll('input, textarea');
            inputs.forEach(input => {
                if (input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }
    }

    showMessageContextMenu(message, x, y) {
        // Remove existing context menu
        if (this.ui.contextMenu) {
            this.ui.contextMenu.remove();
        }

        const menu = document.createElement('div');
        menu.className = 'context-menu';
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';

        const messageId = message.dataset.messageId;
        const userId = parseInt(message.dataset.userId);

        // Copy text
        const copyItem = document.createElement('div');
        copyItem.className = 'context-menu-item';
        copyItem.textContent = 'Copy Text';
        copyItem.addEventListener('click', () => {
            const text = message.querySelector('.message-body').textContent;
            navigator.clipboard.writeText(text);
            this.showToast('Copied to clipboard', 'success');
            menu.remove();
        });
        menu.appendChild(copyItem);

        // Copy link
        const linkItem = document.createElement('div');
        linkItem.className = 'context-menu-item';
        linkItem.textContent = 'Copy Message Link';
        linkItem.addEventListener('click', () => {
            const link = `${window.location.origin}/chat#message-${messageId}`;
            navigator.clipboard.writeText(link);
            this.showToast('Link copied', 'success');
            menu.remove();
        });
        menu.appendChild(linkItem);

        // Separator
        const separator = document.createElement('div');
        separator.className = 'context-menu-separator';
        menu.appendChild(separator);

        // Reply
        const replyItem = document.createElement('div');
        replyItem.className = 'context-menu-item';
        replyItem.textContent = 'Reply';
        replyItem.addEventListener('click', () => {
            this.replyToMessage(messageId);
            menu.remove();
        });
        menu.appendChild(replyItem);

        // Edit/Delete for own messages
        if (userId === this.state.currentUserId) {
            const editItem = document.createElement('div');
            editItem.className = 'context-menu-item';
            editItem.textContent = 'Edit';
            editItem.addEventListener('click', () => {
                this.editMessage(messageId);
                menu.remove();
            });
            menu.appendChild(editItem);

            const deleteItem = document.createElement('div');
            deleteItem.className = 'context-menu-item context-menu-item-danger';
            deleteItem.textContent = 'Delete';
            deleteItem.addEventListener('click', () => {
                this.deleteMessage(messageId);
                menu.remove();
            });
            menu.appendChild(deleteItem);
        }

        document.body.appendChild(menu);
        this.ui.contextMenu = menu;
    }

    showQuickSwitcher() {
        // Implementation for quick channel switcher
        this.showToast('Quick switcher coming soon', 'info');
    }

    toggleSidebar() {
        const sidebar = document.getElementById('chatSidebar');
        if (sidebar) {
            sidebar.classList.toggle('collapsed');
        }
    }

    checkMentions(text) {
        // Check for @ mentions and show autocomplete
        const mentionMatch = text.match(/@(\w*)$/);
        if (mentionMatch) {
            // Show mention autocomplete
            console.log('Mention detected:', mentionMatch[1]);
        }
    }

    scrollToBottom() {
        const container = document.getElementById('messageContainer');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }

    scrollToBottomIfNeeded() {
        const container = document.getElementById('messageContainer');
        if (!container) return;

        // Check if user is near bottom (within 100px)
        const isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;

        if (isNearBottom) {
            this.scrollToBottom();
        } else {
            // Show new message indicator
            this.showNewMessageIndicator();
        }
    }

    showNewMessageIndicator() {
        let indicator = document.getElementById('newMessageIndicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'newMessageIndicator';
            indicator.className = 'new-message-indicator';
            indicator.innerHTML = 'New messages ↓';
            indicator.addEventListener('click', () => {
                this.scrollToBottom();
                indicator.remove();
            });

            const container = document.getElementById('messageContainer');
            if (container) {
                container.appendChild(indicator);
            }
        }
    }

    setupOnlinePresence() {
        // Send online status periodically
        setInterval(() => {
            if (this.state.isConnected) {
                this.sendPresenceUpdate();
            }
        }, 30000); // Every 30 seconds
    }

    async sendPresenceUpdate() {
        try {
            await this.apiCall('presence.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status: 'online' })
            });
        } catch (error) {
            console.log('Presence update failed:', error);
        }
    }

    // Long-polling implementation with exponential backoff
    async startPolling() {
        if (this.polling.isPolling) return;

        this.polling.isPolling = true;
        this.state.isConnected = true;
        this.updateConnectionStatus();

        while (this.polling.isPolling) {
            try {
                await this.poll();

                // Reset retry count on successful poll
                this.polling.retryCount = 0;

                // Process offline queue if any
                if (this.state.offlineQueue.length > 0) {
                    await this.processOfflineQueue();
                }

            } catch (error) {
                console.error('Polling error:', error);

                // Exponential backoff
                this.polling.retryCount++;
                const delay = Math.min(
                    this.config.baseRetryDelay * Math.pow(2, this.polling.retryCount),
                    30000 // Max 30 seconds
                );

                console.log(`Retrying poll in ${delay}ms (attempt ${this.polling.retryCount}/${this.config.maxRetries})`);

                if (this.polling.retryCount >= this.config.maxRetries) {
                    this.state.isConnected = false;
                    this.updateConnectionStatus();
                }

                await this.sleep(delay);
            }
        }
    }

    async poll() {
        const channelIds = Array.from(this.state.channels.keys());

        // Create abort controller for timeout
        this.polling.abortController = new AbortController();
        const timeoutId = setTimeout(() => this.polling.abortController.abort(), 35000);

        try {
            const response = await fetch(this.config.apiBase + 'chat-poll.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    last_sequence_id: this.state.lastSequenceId,
                    channel_ids: channelIds
                }),
                signal: this.polling.abortController.signal,
                credentials: 'same-origin'
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                this.processPollData(data);
            }

        } catch (error) {
            clearTimeout(timeoutId);

            if (error.name === 'AbortError') {
                console.log('Poll request timed out');
            } else {
                throw error;
            }
        }
    }

    processPollData(data) {
        // Update sequence ID
        if (data.last_sequence_id) {
            this.state.lastSequenceId = data.last_sequence_id;
        }

        // Process new messages
        if (data.messages && data.messages.length > 0) {
            this.processNewMessages(data.messages);
        }

        // Update presence
        if (data.presence) {
            this.updatePresence(data.presence);
        }

        // Update typing indicators
        if (data.typing) {
            this.updateTypingIndicators(data.typing);
        }

        // Process channel updates
        if (data.channels) {
            this.processChannelUpdates(data.channels);
        }
    }

    processNewMessages(messages) {
        const currentChannelMessages = [];
        const otherChannelMessages = new Map();

        messages.forEach(msg => {
            if (msg.channel_id === this.state.currentChannelId) {
                currentChannelMessages.push(msg);
            } else {
                if (!otherChannelMessages.has(msg.channel_id)) {
                    otherChannelMessages.set(msg.channel_id, []);
                }
                otherChannelMessages.get(msg.channel_id).push(msg);
            }
        });

        // Render messages in current channel
        if (currentChannelMessages.length > 0) {
            this.renderMessages(currentChannelMessages, true);

            // Play sound for messages from others
            const hasOtherMessages = currentChannelMessages.some(
                msg => msg.user_id !== this.state.currentUserId
            );
            if (hasOtherMessages) {
                this.playSound('message');
            }
        }

        // Update unread counts and show notifications for other channels
        otherChannelMessages.forEach((msgs, channelId) => {
            // Update unread count
            const currentUnread = this.state.unreadCounts.get(channelId) || 0;
            this.state.unreadCounts.set(channelId, currentUnread + msgs.length);

            // Update UI
            const channelEl = document.querySelector(`[data-channel-id="${channelId}"]`);
            if (channelEl) {
                let unreadEl = channelEl.querySelector('.unread-count');
                if (!unreadEl) {
                    unreadEl = document.createElement('span');
                    unreadEl.className = 'unread-count';
                    channelEl.appendChild(unreadEl);
                }
                const count = this.state.unreadCounts.get(channelId);
                unreadEl.textContent = count > 99 ? '99+' : count;
            }

            // Show notification for first message
            const channel = this.state.channels.get(channelId);
            if (channel && msgs.length > 0) {
                const firstMsg = msgs[0];
                this.showNotification(
                    channel.name,
                    `${firstMsg.username}: ${this.truncateText(firstMsg.content, 50)}`
                );
                this.playSound('notification');
            }
        });
    }

    updatePresence(presence) {
        this.state.onlineUsers = new Set(presence.online || []);

        // Update online indicators in UI
        document.querySelectorAll('.user-item, .dm-item').forEach(item => {
            const userId = parseInt(item.dataset.userId);
            if (userId) {
                const indicator = item.querySelector('.status-indicator');
                if (indicator) {
                    indicator.className = `status-indicator ${this.state.onlineUsers.has(userId) ? 'online' : 'offline'}`;
                }
            }
        });

        // Update online count
        const onlineCount = document.getElementById('onlineCount');
        if (onlineCount) {
            onlineCount.textContent = this.state.onlineUsers.size;
        }
    }

    updateTypingIndicators(typing) {
        // Clear old typing indicators
        this.state.typingUsers.clear();

        typing.forEach(t => {
            if (t.user_id !== this.state.currentUserId) {
                this.state.typingUsers.set(t.user_id, {
                    username: t.username,
                    channel_id: t.channel_id
                });
            }
        });

        // Update UI
        const indicator = document.getElementById('typingIndicator');
        if (!indicator) return;

        const currentChannelTyping = Array.from(this.state.typingUsers.values()).filter(
            t => t.channel_id === this.state.currentChannelId
        );

        if (currentChannelTyping.length > 0) {
            const names = currentChannelTyping.map(t => t.username);
            const typingText = names.length === 1
                ? `${names[0]} is typing...`
                : names.length === 2
                ? `${names[0]} and ${names[1]} are typing...`
                : `${names[0]} and ${names.length - 1} others are typing...`;

            indicator.querySelector('.typing-text').textContent = typingText;
            indicator.style.display = 'flex';
        } else {
            indicator.style.display = 'none';
        }
    }

    processChannelUpdates(channels) {
        // Update channel list if needed
        let needsRerender = false;

        channels.forEach(channel => {
            const existing = this.state.channels.get(channel.id);
            if (!existing || JSON.stringify(existing) !== JSON.stringify(channel)) {
                this.state.channels.set(channel.id, channel);
                needsRerender = true;
            }
        });

        if (needsRerender) {
            this.renderChannels(Array.from(this.state.channels.values()));
        }
    }

    async processOfflineQueue() {
        const queue = [...this.state.offlineQueue];
        this.state.offlineQueue = [];

        for (const message of queue) {
            try {
                await this.apiCall('messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(message)
                });
            } catch (error) {
                console.error('Failed to send queued message:', error);
                this.state.offlineQueue.push(message);
            }
        }
    }

    updateConnectionStatus() {
        const statusEl = document.getElementById('connectionStatus');
        if (!statusEl) return;

        if (this.state.isConnected) {
            statusEl.className = 'connection-status online';
            statusEl.textContent = 'Connected';
        } else {
            statusEl.className = 'connection-status offline';
            statusEl.textContent = 'Reconnecting...';
        }
    }

    handleOnline() {
        console.log('Connection restored');
        this.state.isConnected = true;
        this.updateConnectionStatus();
        this.showToast('Connection restored', 'success');

        // Resume polling
        if (!this.polling.isPolling) {
            this.startPolling();
        }
    }

    handleOffline() {
        console.log('Connection lost');
        this.state.isConnected = false;
        this.updateConnectionStatus();
        this.showToast('Connection lost, messages will be queued', 'warning');
    }

    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    cleanup() {
        // Stop polling
        this.polling.isPolling = false;

        // Cancel any pending requests
        if (this.polling.abortController) {
            this.polling.abortController.abort();
        }

        // Clear timeouts
        if (this.polling.timeout) {
            clearTimeout(this.polling.timeout);
        }

        // Send offline status
        this.sendPresenceUpdate('offline');
    }
}

// Initialize chat app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.chatApp = new ChatApp();
});
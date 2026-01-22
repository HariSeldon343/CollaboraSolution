<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
$auth = new Auth();

if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

// Get current user data
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}

// Require active tenant access (super_admins bypass this check)
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess($currentUser['id'], $currentUser['role']);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Chat - Nexio';
    $pageCss = ['assets/css/chat.css'];
    require __DIR__ . '/includes/layout_head.php';
?>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
        <main class="chat-main">
            <!-- Channels/Conversations List -->
            <aside class="chat-sidebar">
                <div class="chat-sidebar-header">
                    <h2>Channels</h2>
                    <button class="btn-icon" id="newChannelBtn" title="New Channel">+</button>
                </div>

                <div class="channel-list">
                    <!-- Public Channels -->
                    <div class="channel-section">
                        <div class="channel-section-title">Public Channels</div>
                        <div class="channel-item active" data-channel="general">
                            <span class="channel-icon">#</span>
                            <span class="channel-name">general</span>
                            <span class="channel-badge">12</span>
                        </div>
                        <div class="channel-item" data-channel="development">
                            <span class="channel-icon">#</span>
                            <span class="channel-name">development</span>
                        </div>
                        <div class="channel-item" data-channel="design">
                            <span class="channel-icon">#</span>
                            <span class="channel-name">design</span>
                            <span class="channel-badge">3</span>
                        </div>
                        <div class="channel-item" data-channel="marketing">
                            <span class="channel-icon">#</span>
                            <span class="channel-name">marketing</span>
                        </div>
                    </div>

                    <!-- Direct Messages -->
                    <div class="channel-section">
                        <div class="channel-section-title">Direct Messages</div>
                        <div class="channel-item" data-channel="dm-john">
                            <span class="user-status online"></span>
                            <span class="channel-name">John Doe</span>
                        </div>
                        <div class="channel-item" data-channel="dm-jane">
                            <span class="user-status online"></span>
                            <span class="channel-name">Jane Smith</span>
                            <span class="channel-badge">1</span>
                        </div>
                        <div class="channel-item" data-channel="dm-mike">
                            <span class="user-status offline"></span>
                            <span class="channel-name">Mike Johnson</span>
                        </div>
                        <div class="channel-item" data-channel="dm-sarah">
                            <span class="user-status away"></span>
                            <span class="channel-name">Sarah Williams</span>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Messages Area -->
            <section class="chat-content">
                <!-- Chat Header -->
                <header class="chat-header">
                    <div class="chat-header-left">
                        <button class="sidebar-toggle" id="sidebarToggle">☰</button>
                        <h2 class="chat-title"># general</h2>
                        <span class="chat-members">23 members</span>
                    </div>
                    <div class="chat-header-right">
                        <button class="btn-icon" title="Search">🔍</button>
                        <button class="btn-icon" title="Call">📞</button>
                        <button class="btn-icon" title="Info" id="toggleInfo">ℹ</button>
                    </div>
                </header>

                <!-- Messages Container -->
                <div class="messages-container" id="messagesContainer">
                    <!-- Date Separator -->
                    <div class="date-separator">
                        <span>Today</span>
                    </div>

                    <!-- Messages -->
                    <div class="message">
                        <div class="message-avatar">JD</div>
                        <div class="message-content">
                            <div class="message-header">
                                <span class="message-author">John Doe</span>
                                <span class="message-time">10:30 AM</span>
                            </div>
                            <div class="message-text">
                                Good morning team! Ready for today's standup meeting?
                            </div>
                        </div>
                    </div>

                    <div class="message">
                        <div class="message-avatar">JS</div>
                        <div class="message-content">
                            <div class="message-header">
                                <span class="message-author">Jane Smith</span>
                                <span class="message-time">10:32 AM</span>
                            </div>
                            <div class="message-text">
                                Morning! Yes, I have my updates ready.
                            </div>
                        </div>
                    </div>

                    <div class="message own">
                        <div class="message-avatar"><?php echo strtoupper(substr($currentUser['name'], 0, 1)); ?></div>
                        <div class="message-content">
                            <div class="message-header">
                                <span class="message-author">You</span>
                                <span class="message-time">10:35 AM</span>
                            </div>
                            <div class="message-text">
                                Great! I'll share my screen for the project demo.
                            </div>
                        </div>
                    </div>

                    <div class="message">
                        <div class="message-avatar">MJ</div>
                        <div class="message-content">
                            <div class="message-header">
                                <span class="message-author">Mike Johnson</span>
                                <span class="message-time">10:36 AM</span>
                            </div>
                            <div class="message-text">
                                Looking forward to it! I've completed the API integration.
                            </div>
                        </div>
                    </div>

                    <!-- System Message -->
                    <div class="system-message">
                        <span>Sarah Williams joined the channel</span>
                    </div>

                    <div class="message">
                        <div class="message-avatar">SW</div>
                        <div class="message-content">
                            <div class="message-header">
                                <span class="message-author">Sarah Williams</span>
                                <span class="message-time">10:40 AM</span>
                            </div>
                            <div class="message-text">
                                Hey everyone! Sorry I'm late, had some connection issues.
                            </div>
                        </div>
                    </div>

                    <!-- Typing Indicator -->
                    <div class="typing-indicator hidden" id="typingIndicator">
                        <span class="typing-dots">
                            <span></span>
                            <span></span>
                            <span></span>
                        </span>
                        <span class="typing-text">John is typing...</span>
                    </div>
                </div>

                <!-- Message Input -->
                <div class="message-input-container">
                    <button class="btn-icon" title="Attach File">📎</button>
                    <div class="message-input-wrapper">
                        <textarea
                            id="messageInput"
                            class="message-input"
                            placeholder="Type a message..."
                            rows="1"></textarea>
                    </div>
                    <button class="btn-icon" title="Emoji">😊</button>
                    <button class="btn-send" id="sendBtn">Send</button>
                </div>
            </section>

            <!-- Info Panel -->
            <aside class="chat-info" id="chatInfo">
                <div class="info-header">
                    <h3>Channel Info</h3>
                    <button class="btn-icon" id="closeInfo">✕</button>
                </div>

                <div class="info-content">
                    <!-- Channel Details -->
                    <div class="info-section">
                        <h4>About</h4>
                        <div class="info-item">
                            <span class="info-label">Channel:</span>
                            <span class="info-value">#general</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Topic:</span>
                            <span class="info-value">General discussion and announcements</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Created:</span>
                            <span class="info-value">Jan 15, 2024</span>
                        </div>
                    </div>

                    <!-- Members -->
                    <div class="info-section">
                        <h4>Members (23)</h4>
                        <div class="member-list">
                            <div class="member-item">
                                <span class="user-status online"></span>
                                <span class="member-name">John Doe</span>
                                <span class="member-role">Admin</span>
                            </div>
                            <div class="member-item">
                                <span class="user-status online"></span>
                                <span class="member-name">Jane Smith</span>
                                <span class="member-role">Member</span>
                            </div>
                            <div class="member-item">
                                <span class="user-status away"></span>
                                <span class="member-name">Mike Johnson</span>
                                <span class="member-role">Member</span>
                            </div>
                            <div class="member-item">
                                <span class="user-status offline"></span>
                                <span class="member-name">Sarah Williams</span>
                                <span class="member-role">Member</span>
                            </div>
                        </div>
                        <button class="btn btn-ghost btn-full">View All Members</button>
                    </div>

                    <!-- Shared Files -->
                    <div class="info-section">
                        <h4>Shared Files</h4>
                        <div class="file-list">
                            <div class="file-item">
                                <span class="file-icon">📄</span>
                                <span class="file-name">Project_Specs.pdf</span>
                                <span class="file-size">2.4 MB</span>
                            </div>
                            <div class="file-item">
                                <span class="file-icon">🖼</span>
                                <span class="file-name">Design_Mockup.png</span>
                                <span class="file-size">856 KB</span>
                            </div>
                        </div>
                        <button class="btn btn-ghost btn-full">View All Files</button>
                    </div>
                </div>
            </aside>
        </main>

    <!-- Hidden CSRF token -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <!-- Chat JavaScript -->
    <script src="assets/js/chat.js"></script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
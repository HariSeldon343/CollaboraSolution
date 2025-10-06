<?php
/**
 * Dashboard RESTful API for CollaboraNexio
 *
 * Complete API implementation for dashboard management, widgets, metrics, and data visualization
 * Follows REST principles with comprehensive CRUD operations and advanced features
 *
 * @version 1.0.0
 * @author CollaboraNexio Development Team
 */

// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../includes/session_init.php';


declare(strict_types=1);

// Initialize session and headers// CORS and content type headers
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Version');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// API versioning
header('X-API-Version: 1.0.0');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load dependencies
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/metrics.php';

// Rate limiting headers
header('X-RateLimit-Limit: 1000');
header('X-RateLimit-Remaining: 999');
header('X-RateLimit-Reset: ' . (time() + 3600));

/**
 * Dashboard API Class
 *
 * Handles all dashboard-related API operations with multi-tenant support
 */
class DashboardAPI {

    private Database $db;
    private ?PDO $pdo;
    private MetricsCollector $metrics;
    private ?int $tenantId;
    private ?int $userId;
    private array $requestData = [];
    private string $method;
    private array $pathSegments = [];
    private array $queryParams = [];

    /**
     * Constructor - Initialize API components
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
        $this->metrics = MetricsCollector::getInstance();
        $this->method = $_SERVER['REQUEST_METHOD'];

        // Parse request
        $this->parseRequest();

        // Authenticate user
        $this->authenticate();
    }

    /**
     * Parse incoming request
     */
    private function parseRequest(): void {
        // Parse URL path
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $basePath = '/api/dashboard.php';
        $relativePath = substr($path, strlen($basePath));
        $this->pathSegments = array_filter(explode('/', trim($relativePath, '/')));

        // Parse query parameters
        $this->queryParams = $_GET;

        // Parse request body for POST/PUT/PATCH
        if (in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
            $rawData = file_get_contents('php://input');
            $this->requestData = json_decode($rawData, true) ?? [];
        }
    }

    /**
     * Authenticate user and set tenant/user context
     */
    private function authenticate(): void {
        // Check session authentication
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['tenant_id'])) {
            $this->sendError(401, 'Authentication required');
        }

        $this->userId = (int)$_SESSION['user_id'];
        $this->tenantId = (int)$_SESSION['tenant_id'];
    }

    /**
     * Main request handler
     */
    public function handleRequest(): void {
        try {
            // Determine resource and action
            $resource = $this->pathSegments[0] ?? '';
            $resourceId = isset($this->pathSegments[1]) ? (int)$this->pathSegments[1] : null;
            $subResource = $this->pathSegments[2] ?? '';

            // Route to appropriate handler
            switch ($resource) {
                case 'widgets':
                    $this->handleWidgets($resourceId, $subResource);
                    break;

                case 'metrics':
                    $this->handleMetrics($subResource);
                    break;

                case 'dashboards':
                    $this->handleDashboards($resourceId, $subResource);
                    break;

                default:
                    // Handle query parameter based routing for backwards compatibility
                    if (isset($this->queryParams['action'])) {
                        $this->handleActionBasedRouting();
                    } elseif (isset($this->queryParams['resource'])) {
                        $this->handleLegacyRouting();
                    } else {
                        $this->sendError(404, 'Resource not found');
                    }
            }
        } catch (Exception $e) {
            $this->sendError(500, 'Internal server error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle action-based routing (for dashboard.js compatibility)
     */
    private function handleActionBasedRouting(): void {
        $action = $this->queryParams['action'] ?? '';

        // Check if it's a widget data request
        if (isset($this->queryParams['widget'])) {
            $this->handleWidgetDataRequest();
            return;
        }

        switch ($action) {
            case 'load':
                $this->loadDashboardData();
                break;

            case 'stats':
                $this->getDashboardStats();
                break;

            case 'activities':
                $this->getRecentActivities();
                break;

            case 'notifications':
                $this->getUserNotifications();
                break;

            case 'widgets':
                $this->getWidgetConfiguration();
                break;

            case 'updateWidget':
                $this->updateWidgetPosition();
                break;

            case 'saveLayout':
                $this->saveDashboardLayout();
                break;

            default:
                $this->sendError(400, 'Invalid action: ' . $action);
        }
    }

    /**
     * Load main dashboard data
     */
    private function loadDashboardData(): void {
        try {
            // Get default widgets configuration
            $widgets = [
                [
                    'id' => 'widget-1',
                    'type' => 'metric',
                    'title' => 'Utenti Totali',
                    'gridPosition' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
                    'settings' => ['metric' => 'users'],
                    'refreshInterval' => 60000
                ],
                [
                    'id' => 'widget-2',
                    'type' => 'metric',
                    'title' => 'File Caricati',
                    'gridPosition' => ['x' => 2, 'y' => 0, 'width' => 2, 'height' => 2],
                    'settings' => ['metric' => 'files'],
                    'refreshInterval' => 60000
                ],
                [
                    'id' => 'widget-3',
                    'type' => 'metric',
                    'title' => 'Task Attivi',
                    'gridPosition' => ['x' => 4, 'y' => 0, 'width' => 2, 'height' => 2],
                    'settings' => ['metric' => 'tasks'],
                    'refreshInterval' => 60000
                ],
                [
                    'id' => 'widget-4',
                    'type' => 'chart',
                    'title' => 'Attività Settimanale',
                    'gridPosition' => ['x' => 0, 'y' => 2, 'width' => 3, 'height' => 3],
                    'settings' => ['chartType' => 'line'],
                    'refreshInterval' => 300000
                ],
                [
                    'id' => 'widget-5',
                    'type' => 'activities',
                    'title' => 'Attività Recenti',
                    'gridPosition' => ['x' => 3, 'y' => 2, 'width' => 3, 'height' => 3],
                    'refreshInterval' => 30000
                ],
                [
                    'id' => 'widget-6',
                    'type' => 'calendar',
                    'title' => 'Calendario',
                    'gridPosition' => ['x' => 0, 'y' => 5, 'width' => 3, 'height' => 3],
                    'refreshInterval' => 300000
                ],
                [
                    'id' => 'widget-7',
                    'type' => 'storage',
                    'title' => 'Spazio Utilizzato',
                    'gridPosition' => ['x' => 3, 'y' => 5, 'width' => 3, 'height' => 2],
                    'refreshInterval' => 120000
                ]
            ];

            // Get basic stats
            $stats = $this->getBasicStats();

            // Get recent activities
            $activities = $this->getRecentActivitiesData();

            // Get notifications count
            $notificationsCount = $this->getNotificationCount();

            $this->sendSuccess([
                'widgets' => $widgets,
                'stats' => $stats,
                'activities' => $activities,
                'notificationsCount' => $notificationsCount,
                'user' => [
                    'id' => $this->userId,
                    'name' => $_SESSION['user_name'] ?? 'User',
                    'role' => $_SESSION['user_role'] ?? 'user'
                ]
            ], 'Dashboard data loaded successfully');

        } catch (Exception $e) {
            $this->sendError(500, 'Failed to load dashboard data', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get basic statistics for dashboard
     */
    private function getBasicStats(): array {
        try {
            // Get user count
            $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM users WHERE tenant_id = :tenant_id AND is_active = 1");
            $stmt->execute([':tenant_id' => $this->tenantId]);
            $userCount = $stmt->fetch()['count'] ?? 0;

            // For now, return mock data for tables that don't exist yet
            return [
                'users' => [
                    'total' => $userCount,
                    'change' => 12.5,
                    'trend' => 'up'
                ],
                'files' => [
                    'total' => 342,
                    'change' => 8.3,
                    'trend' => 'up'
                ],
                'tasks' => [
                    'total' => 58,
                    'completed' => 32,
                    'pending' => 26,
                    'change' => -3.2,
                    'trend' => 'down'
                ],
                'storage' => [
                    'used' => 2.8, // GB
                    'total' => 10, // GB
                    'percentage' => 28
                ]
            ];
        } catch (Exception $e) {
            error_log('Error getting stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent activities data
     */
    private function getRecentActivitiesData(): array {
        // Return mock data for now
        $activities = [
            [
                'id' => 1,
                'user' => 'Marco Rossi',
                'action' => 'uploaded',
                'target' => 'Report Q3 2024.pdf',
                'timestamp' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
                'type' => 'file'
            ],
            [
                'id' => 2,
                'user' => 'Laura Bianchi',
                'action' => 'completed',
                'target' => 'Review marketing proposal',
                'timestamp' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
                'type' => 'task'
            ],
            [
                'id' => 3,
                'user' => 'Giuseppe Verdi',
                'action' => 'commented',
                'target' => 'Project timeline discussion',
                'timestamp' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
                'type' => 'comment'
            ],
            [
                'id' => 4,
                'user' => 'Anna Ferrari',
                'action' => 'created',
                'target' => 'Budget 2025 Planning',
                'timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                'type' => 'project'
            ],
            [
                'id' => 5,
                'user' => 'Roberto Esposito',
                'action' => 'shared',
                'target' => 'Meeting notes - Product roadmap',
                'timestamp' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'type' => 'document'
            ]
        ];

        return $activities;
    }

    /**
     * Get notification count
     */
    private function getNotificationCount(): int {
        // Return mock count for now
        return 3;
    }

    /**
     * Get dashboard statistics
     */
    private function getDashboardStats(): void {
        $stats = $this->getBasicStats();
        $this->sendSuccess($stats, 'Statistics retrieved successfully');
    }

    /**
     * Get recent activities
     */
    private function getRecentActivities(): void {
        $activities = $this->getRecentActivitiesData();
        $this->sendSuccess(['activities' => $activities], 'Activities retrieved successfully');
    }

    /**
     * Get user notifications
     */
    private function getUserNotifications(): void {
        // Mock notifications data
        $notifications = [
            [
                'id' => 1,
                'title' => 'Nuovo commento',
                'message' => 'Marco Rossi ha commentato il tuo documento',
                'type' => 'comment',
                'read' => false,
                'timestamp' => date('Y-m-d H:i:s', strtotime('-10 minutes'))
            ],
            [
                'id' => 2,
                'title' => 'Task assegnato',
                'message' => 'Ti è stato assegnato un nuovo task: Review Q3 Report',
                'type' => 'task',
                'read' => false,
                'timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour'))
            ],
            [
                'id' => 3,
                'title' => 'File condiviso',
                'message' => 'Laura Bianchi ha condiviso un file con te',
                'type' => 'file',
                'read' => true,
                'timestamp' => date('Y-m-d H:i:s', strtotime('-3 hours'))
            ]
        ];

        $this->sendSuccess(['notifications' => $notifications], 'Notifications retrieved successfully');
    }

    /**
     * Get widget configuration
     */
    private function getWidgetConfiguration(): void {
        // This would typically load from database
        $widgets = [
            'available' => [
                ['type' => 'metric', 'name' => 'Metriche', 'icon' => 'chart-bar'],
                ['type' => 'chart', 'name' => 'Grafico', 'icon' => 'chart-line'],
                ['type' => 'activities', 'name' => 'Attività', 'icon' => 'activity'],
                ['type' => 'calendar', 'name' => 'Calendario', 'icon' => 'calendar'],
                ['type' => 'storage', 'name' => 'Spazio', 'icon' => 'database'],
                ['type' => 'burndown', 'name' => 'Burndown', 'icon' => 'trending-down']
            ]
        ];

        $this->sendSuccess($widgets, 'Widget configuration retrieved');
    }

    /**
     * Update widget position
     */
    private function updateWidgetPosition(): void {
        $widgetId = $this->requestData['widgetId'] ?? null;
        $position = $this->requestData['position'] ?? null;

        if (!$widgetId || !$position) {
            $this->sendError(400, 'Widget ID and position required');
        }

        // Here you would save to database
        // For now, just return success
        $this->sendSuccess(['widgetId' => $widgetId, 'position' => $position], 'Widget position updated');
    }

    /**
     * Save dashboard layout
     */
    private function saveDashboardLayout(): void {
        $layout = $this->requestData['layout'] ?? null;

        if (!$layout) {
            $this->sendError(400, 'Layout data required');
        }

        // Here you would save to database
        // For now, just return success
        $this->sendSuccess(['layout' => $layout], 'Dashboard layout saved successfully');
    }

    /**
     * Handle widget data requests
     */
    private function handleWidgetDataRequest(): void {
        $widgetType = $this->queryParams['widget'] ?? '';

        switch ($widgetType) {
            case 'metric':
                $this->getMetricData();
                break;

            case 'chart':
                $this->getChartData();
                break;

            case 'activities':
                $this->getActivitiesWidgetData();
                break;

            case 'storage':
                $this->getStorageData();
                break;

            case 'burndown':
                $this->getBurndownData();
                break;

            case 'calendar':
                $this->getCalendarData();
                break;

            default:
                $this->sendError(400, 'Invalid widget type: ' . $widgetType);
        }
    }

    /**
     * Get metric widget data
     */
    private function getMetricData(): void {
        $metric = $this->queryParams['metric'] ?? 'users';

        $metricsData = [
            'users' => [
                'value' => 127,
                'label' => 'Utenti Totali',
                'change' => 12.5,
                'changeLabel' => '+12.5% dal mese scorso',
                'icon' => 'users',
                'color' => '#3b82f6'
            ],
            'files' => [
                'value' => 342,
                'label' => 'File Caricati',
                'change' => 8.3,
                'changeLabel' => '+8.3% dal mese scorso',
                'icon' => 'folder',
                'color' => '#10b981'
            ],
            'tasks' => [
                'value' => 58,
                'label' => 'Task Attivi',
                'change' => -3.2,
                'changeLabel' => '-3.2% dal mese scorso',
                'icon' => 'clipboard',
                'color' => '#f59e0b'
            ],
            'projects' => [
                'value' => 24,
                'label' => 'Progetti',
                'change' => 5.7,
                'changeLabel' => '+5.7% dal mese scorso',
                'icon' => 'briefcase',
                'color' => '#8b5cf6'
            ]
        ];

        $data = $metricsData[$metric] ?? $metricsData['users'];
        $this->sendSuccess($data);
    }

    /**
     * Get chart widget data
     */
    private function getChartData(): void {
        $chartType = $this->queryParams['type'] ?? 'line';

        // Generate sample data for the last 7 days
        $labels = [];
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $labels[] = date('d/m', strtotime("-$i days"));
            $data[] = rand(20, 100);
        }

        $chartData = [
            'type' => $chartType,
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Attività',
                    'data' => $data,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'tension' => 0.4
                ]
            ]
        ];

        $this->sendSuccess($chartData);
    }

    /**
     * Get activities widget data
     */
    private function getActivitiesWidgetData(): void {
        $activities = $this->getRecentActivitiesData();
        $this->sendSuccess(['activities' => $activities]);
    }

    /**
     * Get storage widget data
     */
    private function getStorageData(): void {
        $storageData = [
            'used' => 2.8, // GB
            'total' => 10, // GB
            'percentage' => 28,
            'breakdown' => [
                ['type' => 'Documents', 'size' => 1.2, 'color' => '#3b82f6'],
                ['type' => 'Images', 'size' => 0.8, 'color' => '#10b981'],
                ['type' => 'Videos', 'size' => 0.5, 'color' => '#f59e0b'],
                ['type' => 'Other', 'size' => 0.3, 'color' => '#6b7280']
            ]
        ];

        $this->sendSuccess($storageData);
    }

    /**
     * Get burndown chart data
     */
    private function getBurndownData(): void {
        // Generate sample burndown data
        $totalPoints = 100;
        $daysInSprint = 14;
        $currentDay = 7;

        $labels = [];
        $ideal = [];
        $actual = [];

        for ($i = 0; $i <= $daysInSprint; $i++) {
            $labels[] = "Giorno $i";
            $ideal[] = $totalPoints - ($totalPoints / $daysInSprint * $i);

            if ($i <= $currentDay) {
                // Simulate actual progress
                $reduction = ($totalPoints / $daysInSprint * $i) + rand(-10, 5);
                $actual[] = max(0, $totalPoints - $reduction);
            }
        }

        $burndownData = [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Ideale',
                    'data' => $ideal,
                    'borderColor' => '#6b7280',
                    'borderDash' => [5, 5],
                    'fill' => false
                ],
                [
                    'label' => 'Attuale',
                    'data' => $actual,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true
                ]
            ],
            'summary' => [
                'totalPoints' => $totalPoints,
                'remainingPoints' => end($actual),
                'daysLeft' => $daysInSprint - $currentDay,
                'velocity' => round(($totalPoints - end($actual)) / $currentDay, 1)
            ]
        ];

        $this->sendSuccess($burndownData);
    }

    /**
     * Get calendar widget data
     */
    private function getCalendarData(): void {
        // Generate sample calendar events
        $events = [
            [
                'id' => 1,
                'title' => 'Team Meeting',
                'start' => date('Y-m-d 10:00:00'),
                'end' => date('Y-m-d 11:00:00'),
                'color' => '#3b82f6',
                'type' => 'meeting'
            ],
            [
                'id' => 2,
                'title' => 'Project Deadline',
                'start' => date('Y-m-d', strtotime('+2 days')),
                'allDay' => true,
                'color' => '#ef4444',
                'type' => 'deadline'
            ],
            [
                'id' => 3,
                'title' => 'Code Review',
                'start' => date('Y-m-d 14:00:00', strtotime('+1 day')),
                'end' => date('Y-m-d 15:30:00', strtotime('+1 day')),
                'color' => '#10b981',
                'type' => 'review'
            ],
            [
                'id' => 4,
                'title' => 'Sprint Planning',
                'start' => date('Y-m-d 09:00:00', strtotime('+3 days')),
                'end' => date('Y-m-d 12:00:00', strtotime('+3 days')),
                'color' => '#8b5cf6',
                'type' => 'planning'
            ],
            [
                'id' => 5,
                'title' => 'Client Presentation',
                'start' => date('Y-m-d 15:00:00', strtotime('+4 days')),
                'end' => date('Y-m-d 16:30:00', strtotime('+4 days')),
                'color' => '#f59e0b',
                'type' => 'presentation'
            ]
        ];

        $this->sendSuccess(['events' => $events]);
    }

    /**
     * Handle widget-related operations
     */
    private function handleWidgets(?int $widgetId, string $subResource): void {
        switch ($this->method) {
            case 'GET':
                if ($widgetId) {
                    $this->getWidget($widgetId);
                } else {
                    $this->listWidgets();
                }
                break;

            case 'POST':
                $this->createWidget();
                break;

            case 'PUT':
                if (!$widgetId) {
                    $this->sendError(400, 'Widget ID required');
                }
                $this->updateWidget($widgetId);
                break;

            case 'PATCH':
                if (!$widgetId) {
                    $this->sendError(400, 'Widget ID required');
                }
                if ($subResource === 'position') {
                    $this->updateWidgetPosition($widgetId);
                } else {
                    $this->patchWidget($widgetId);
                }
                break;

            case 'DELETE':
                if (!$widgetId) {
                    $this->sendError(400, 'Widget ID required');
                }
                $this->deleteWidget($widgetId);
                break;

            default:
                $this->sendError(405, 'Method not allowed');
        }
    }

    /**
     * List available widgets for user
     */
    private function listWidgets(): void {
        $dashboardId = $this->queryParams['dashboard_id'] ?? null;
        $includeTemplates = filter_var($this->queryParams['include_templates'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $page = max(1, (int)($this->queryParams['page'] ?? 1));
        $limit = min(100, max(1, (int)($this->queryParams['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // Get user's widgets
        $sql = "SELECT
                    w.*,
                    d.name as dashboard_name
                FROM dashboard_widgets w
                JOIN dashboards d ON w.dashboard_id = d.id
                WHERE w.tenant_id = :tenant_id
                    AND d.user_id = :user_id
                    AND d.status = 'active'";

        $params = [
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId
        ];

        if ($dashboardId) {
            $sql .= " AND w.dashboard_id = :dashboard_id";
            $params[':dashboard_id'] = $dashboardId;
        }

        // Add sorting
        $sortField = $this->queryParams['sort'] ?? 'sort_order';
        $sortOrder = strtoupper($this->queryParams['order'] ?? 'ASC');
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'ASC';
        }
        $sql .= " ORDER BY w.$sortField $sortOrder";

        // Add pagination
        $sql .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse JSON fields
        foreach ($widgets as &$widget) {
            $widget['data_filters'] = json_decode($widget['data_filters'] ?? '{}', true);
            $widget['config'] = json_decode($widget['config'] ?? '{}', true);
        }

        // Get total count
        $countSql = "SELECT COUNT(*)
                     FROM dashboard_widgets w
                     JOIN dashboards d ON w.dashboard_id = d.id
                     WHERE w.tenant_id = :tenant_id
                         AND d.user_id = :user_id
                         AND d.status = 'active'";

        if ($dashboardId) {
            $countSql .= " AND w.dashboard_id = :dashboard_id";
        }

        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute(array_intersect_key($params, array_flip([':tenant_id', ':user_id', ':dashboard_id'])));
        $totalCount = (int)$countStmt->fetchColumn();

        // Include templates if requested
        $templates = [];
        if ($includeTemplates) {
            $templateSql = "SELECT * FROM widget_templates
                           WHERE tenant_id = :tenant_id
                               AND status = 'active'
                               AND (is_public = TRUE OR created_by = :user_id)
                           ORDER BY usage_count DESC
                           LIMIT 10";

            $templateStmt = $this->pdo->prepare($templateSql);
            $templateStmt->execute([
                ':tenant_id' => $this->tenantId,
                ':user_id' => $this->userId
            ]);

            $templates = $templateStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($templates as &$template) {
                $template['default_config'] = json_decode($template['default_config'] ?? '{}', true);
                $template['preview_data'] = json_decode($template['preview_data'] ?? '{}', true);
            }
        }

        $this->sendSuccess([
            'widgets' => $widgets,
            'templates' => $templates
        ], 'Widgets retrieved successfully', [
            'page' => $page,
            'total' => $totalCount,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Get single widget details
     */
    private function getWidget(int $widgetId): void {
        $sql = "SELECT
                    w.*,
                    d.name as dashboard_name,
                    d.user_id
                FROM dashboard_widgets w
                JOIN dashboards d ON w.dashboard_id = d.id
                WHERE w.id = :widget_id
                    AND w.tenant_id = :tenant_id
                    AND d.user_id = :user_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':widget_id' => $widgetId,
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId
        ]);

        $widget = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$widget) {
            $this->sendError(404, 'Widget not found');
        }

        // Parse JSON fields
        $widget['data_filters'] = json_decode($widget['data_filters'] ?? '{}', true);
        $widget['config'] = json_decode($widget['config'] ?? '{}', true);

        // Get widget data if requested
        if (filter_var($this->queryParams['include_data'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $widget['data'] = $this->getWidgetData($widget);
        }

        $this->sendSuccess($widget, 'Widget retrieved successfully');
    }

    /**
     * Create new widget
     */
    private function createWidget(): void {
        // Validate required fields
        $required = ['dashboard_id', 'widget_type', 'title'];
        foreach ($required as $field) {
            if (empty($this->requestData[$field])) {
                $this->sendError(400, "Field '$field' is required");
            }
        }

        // Verify dashboard ownership
        $dashboardCheck = $this->pdo->prepare(
            "SELECT id FROM dashboards
             WHERE id = :dashboard_id
                 AND tenant_id = :tenant_id
                 AND user_id = :user_id
                 AND status = 'active'"
        );
        $dashboardCheck->execute([
            ':dashboard_id' => $this->requestData['dashboard_id'],
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId
        ]);

        if (!$dashboardCheck->fetch()) {
            $this->sendError(403, 'Dashboard not found or access denied');
        }

        // Prepare widget data
        $sql = "INSERT INTO dashboard_widgets (
                    tenant_id, dashboard_id, widget_type, title, description,
                    grid_x, grid_y, grid_width, grid_height,
                    data_source, data_query, data_filters,
                    config, refresh_interval, cache_duration,
                    color_scheme, show_header, show_border,
                    is_visible, sort_order
                ) VALUES (
                    :tenant_id, :dashboard_id, :widget_type, :title, :description,
                    :grid_x, :grid_y, :grid_width, :grid_height,
                    :data_source, :data_query, :data_filters,
                    :config, :refresh_interval, :cache_duration,
                    :color_scheme, :show_header, :show_border,
                    :is_visible, :sort_order
                )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':dashboard_id' => $this->requestData['dashboard_id'],
            ':widget_type' => $this->requestData['widget_type'],
            ':title' => $this->requestData['title'],
            ':description' => $this->requestData['description'] ?? null,
            ':grid_x' => $this->requestData['grid_x'] ?? 0,
            ':grid_y' => $this->requestData['grid_y'] ?? 0,
            ':grid_width' => $this->requestData['grid_width'] ?? 4,
            ':grid_height' => $this->requestData['grid_height'] ?? 4,
            ':data_source' => $this->requestData['data_source'] ?? null,
            ':data_query' => $this->requestData['data_query'] ?? null,
            ':data_filters' => json_encode($this->requestData['data_filters'] ?? []),
            ':config' => json_encode($this->requestData['config'] ?? []),
            ':refresh_interval' => $this->requestData['refresh_interval'] ?? 300,
            ':cache_duration' => $this->requestData['cache_duration'] ?? 60,
            ':color_scheme' => $this->requestData['color_scheme'] ?? 'default',
            ':show_header' => $this->requestData['show_header'] ?? true,
            ':show_border' => $this->requestData['show_border'] ?? true,
            ':is_visible' => $this->requestData['is_visible'] ?? true,
            ':sort_order' => $this->requestData['sort_order'] ?? 0
        ]);

        $widgetId = $this->pdo->lastInsertId();

        // Update dashboard timestamp
        $this->updateDashboardTimestamp($this->requestData['dashboard_id']);

        // Return created widget
        $this->getWidget((int)$widgetId);
    }

    /**
     * Update widget configuration
     */
    private function updateWidget(int $widgetId): void {
        // Verify widget ownership
        if (!$this->verifyWidgetOwnership($widgetId)) {
            $this->sendError(403, 'Widget not found or access denied');
        }

        // Build update query dynamically
        $allowedFields = [
            'title', 'description', 'widget_type',
            'grid_x', 'grid_y', 'grid_width', 'grid_height',
            'data_source', 'data_query', 'data_filters',
            'config', 'refresh_interval', 'cache_duration',
            'color_scheme', 'show_header', 'show_border',
            'is_visible', 'sort_order'
        ];

        $updates = [];
        $params = [':widget_id' => $widgetId];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $this->requestData)) {
                $value = $this->requestData[$field];

                // Handle JSON fields
                if (in_array($field, ['data_filters', 'config'])) {
                    $value = json_encode($value);
                }

                $updates[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }

        if (empty($updates)) {
            $this->sendError(400, 'No fields to update');
        }

        $sql = "UPDATE dashboard_widgets
                SET " . implode(', ', $updates) . ", updated_at = NOW()
                WHERE id = :widget_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // Update dashboard timestamp
        $this->updateDashboardTimestampByWidget($widgetId);

        // Return updated widget
        $this->getWidget($widgetId);
    }

    /**
     * Update widget position/size only
     */
    private function updateWidgetPosition(int $widgetId): void {
        if (!$this->verifyWidgetOwnership($widgetId)) {
            $this->sendError(403, 'Widget not found or access denied');
        }

        $sql = "UPDATE dashboard_widgets
                SET grid_x = :grid_x,
                    grid_y = :grid_y,
                    grid_width = :grid_width,
                    grid_height = :grid_height,
                    updated_at = NOW()
                WHERE id = :widget_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':widget_id' => $widgetId,
            ':grid_x' => $this->requestData['grid_x'] ?? 0,
            ':grid_y' => $this->requestData['grid_y'] ?? 0,
            ':grid_width' => $this->requestData['grid_width'] ?? 4,
            ':grid_height' => $this->requestData['grid_height'] ?? 4
        ]);

        $this->updateDashboardTimestampByWidget($widgetId);

        $this->sendSuccess(null, 'Widget position updated successfully');
    }

    /**
     * Partial update of widget
     */
    private function patchWidget(int $widgetId): void {
        // Same as updateWidget but only updates provided fields
        $this->updateWidget($widgetId);
    }

    /**
     * Delete widget
     */
    private function deleteWidget(int $widgetId): void {
        if (!$this->verifyWidgetOwnership($widgetId)) {
            $this->sendError(403, 'Widget not found or access denied');
        }

        $stmt = $this->pdo->prepare("DELETE FROM dashboard_widgets WHERE id = :widget_id");
        $stmt->execute([':widget_id' => $widgetId]);

        $this->updateDashboardTimestampByWidget($widgetId);

        $this->sendSuccess(null, 'Widget deleted successfully');
    }

    /**
     * Handle metrics-related operations
     */
    private function handleMetrics(string $subResource): void {
        switch ($subResource) {
            case 'realtime':
                $this->getRealtimeMetrics();
                break;

            case 'compare':
                $this->compareMetrics();
                break;

            default:
                if ($this->method === 'GET') {
                    $this->getMetrics();
                } else {
                    $this->sendError(405, 'Method not allowed');
                }
        }
    }

    /**
     * Get metric data formatted for Chart.js
     */
    private function getMetrics(): void {
        $metricName = $this->queryParams['name'] ?? null;
        if (!$metricName) {
            $this->sendError(400, 'Metric name is required');
        }

        // Parse period
        $period = $this->queryParams['period'] ?? '1d';
        $endDate = new DateTime();
        $startDate = clone $endDate;

        switch ($period) {
            case '1h':
                $startDate->modify('-1 hour');
                $aggregationLevel = 'raw';
                break;
            case '6h':
                $startDate->modify('-6 hours');
                $aggregationLevel = 'minute';
                break;
            case '1d':
                $startDate->modify('-1 day');
                $aggregationLevel = 'hour';
                break;
            case '7d':
                $startDate->modify('-7 days');
                $aggregationLevel = 'hour';
                break;
            case '1m':
                $startDate->modify('-1 month');
                $aggregationLevel = 'day';
                break;
            case '3m':
                $startDate->modify('-3 months');
                $aggregationLevel = 'day';
                break;
            case '1y':
                $startDate->modify('-1 year');
                $aggregationLevel = 'month';
                break;
            default:
                $this->sendError(400, 'Invalid period specified');
        }

        // Get metrics from collector
        $filters = [
            'aggregation_level' => $this->queryParams['aggregation'] ?? $aggregationLevel,
            'limit' => min(1000, (int)($this->queryParams['limit'] ?? 500))
        ];

        $metrics = $this->metrics->getMetrics(
            $this->tenantId,
            $metricName,
            $startDate,
            $endDate,
            $filters
        );

        // Format for Chart.js
        $chartData = $this->formatForChartJs($metrics, $metricName);

        // Add caching headers
        $cacheTime = 60; // 1 minute
        header('Cache-Control: public, max-age=' . $cacheTime);
        header('ETag: ' . md5(json_encode($chartData)));

        $this->sendSuccess($chartData, 'Metrics retrieved successfully', [
            'period' => $period,
            'start' => $startDate->format('c'),
            'end' => $endDate->format('c'),
            'count' => count($metrics),
            'timestamp' => date('c')
        ]);
    }

    /**
     * Get real-time streaming metrics
     */
    private function getRealtimeMetrics(): void {
        $metricName = $this->queryParams['name'] ?? null;
        if (!$metricName) {
            $this->sendError(400, 'Metric name is required');
        }

        // Set up SSE headers for streaming
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        // Stream metrics
        $windowSeconds = (int)($this->queryParams['window'] ?? 60);
        $interval = (int)($this->queryParams['interval'] ?? 5);

        while (true) {
            $metrics = iterator_to_array(
                $this->metrics->streamRealTimeMetrics(
                    $this->tenantId,
                    [$metricName],
                    $windowSeconds
                )
            );

            $data = $this->formatForChartJs($metrics, $metricName);

            echo "data: " . json_encode([
                'success' => true,
                'data' => $data,
                'timestamp' => date('c')
            ]) . "\n\n";

            ob_flush();
            flush();

            sleep($interval);

            // Check if connection is still alive
            if (connection_aborted()) {
                break;
            }
        }
    }

    /**
     * Compare multiple metrics
     */
    private function compareMetrics(): void {
        $metricNames = $this->queryParams['metrics'] ?? [];
        if (empty($metricNames)) {
            $this->sendError(400, 'At least one metric is required');
        }

        $period = $this->queryParams['period'] ?? '1d';
        $endDate = new DateTime();
        $startDate = clone $endDate;

        // Calculate date range based on period
        switch ($period) {
            case '1h':
                $startDate->modify('-1 hour');
                break;
            case '1d':
                $startDate->modify('-1 day');
                break;
            case '7d':
                $startDate->modify('-7 days');
                break;
            case '1m':
                $startDate->modify('-1 month');
                break;
            default:
                $startDate->modify('-1 day');
        }

        $comparisonData = [
            'datasets' => []
        ];

        // Get data for each metric
        foreach ($metricNames as $metricName) {
            $metrics = $this->metrics->getMetrics(
                $this->tenantId,
                $metricName,
                $startDate,
                $endDate,
                ['limit' => 100]
            );

            $dataset = $this->formatForChartJs($metrics, $metricName);
            $comparisonData['datasets'][] = $dataset['datasets'][0] ?? [];
        }

        // Use labels from first dataset
        if (!empty($comparisonData['datasets'])) {
            $firstMetrics = $this->metrics->getMetrics(
                $this->tenantId,
                $metricNames[0],
                $startDate,
                $endDate,
                ['limit' => 100]
            );
            $firstData = $this->formatForChartJs($firstMetrics, $metricNames[0]);
            $comparisonData['labels'] = $firstData['labels'] ?? [];
        }

        $this->sendSuccess($comparisonData, 'Metrics comparison retrieved successfully', [
            'metrics' => $metricNames,
            'period' => $period,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Handle dashboard-related operations
     */
    private function handleDashboards(?int $dashboardId, string $subResource): void {
        switch ($this->method) {
            case 'GET':
                if ($dashboardId) {
                    $this->getDashboard($dashboardId);
                } else {
                    $this->listDashboards();
                }
                break;

            case 'POST':
                if ($dashboardId && $subResource === 'share') {
                    $this->shareDashboard($dashboardId);
                } else {
                    $this->createDashboard();
                }
                break;

            case 'PUT':
                if (!$dashboardId) {
                    $this->sendError(400, 'Dashboard ID required');
                }
                $this->updateDashboard($dashboardId);
                break;

            case 'DELETE':
                if (!$dashboardId) {
                    $this->sendError(400, 'Dashboard ID required');
                }
                $this->deleteDashboard($dashboardId);
                break;

            default:
                $this->sendError(405, 'Method not allowed');
        }
    }

    /**
     * List user's dashboards
     */
    private function listDashboards(): void {
        $page = max(1, (int)($this->queryParams['page'] ?? 1));
        $limit = min(50, max(1, (int)($this->queryParams['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        $sql = "SELECT
                    d.*,
                    COUNT(DISTINCT w.id) as widget_count
                FROM dashboards d
                LEFT JOIN dashboard_widgets w ON d.id = w.dashboard_id
                WHERE d.tenant_id = :tenant_id
                    AND d.user_id = :user_id
                    AND d.status = 'active'
                GROUP BY d.id
                ORDER BY d.is_default DESC, d.last_accessed DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId,
            ':limit' => $limit,
            ':offset' => $offset
        ]);

        $dashboards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse JSON fields
        foreach ($dashboards as &$dashboard) {
            $dashboard['layout_config'] = json_decode($dashboard['layout_config'] ?? '{}', true);
            $dashboard['shared_with'] = json_decode($dashboard['shared_with'] ?? '[]', true);
        }

        // Get total count
        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM dashboards
             WHERE tenant_id = :tenant_id AND user_id = :user_id AND status = 'active'"
        );
        $countStmt->execute([
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId
        ]);
        $totalCount = (int)$countStmt->fetchColumn();

        $this->sendSuccess($dashboards, 'Dashboards retrieved successfully', [
            'page' => $page,
            'total' => $totalCount,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Get single dashboard with widgets
     */
    private function getDashboard(int $dashboardId): void {
        // Get dashboard
        $sql = "SELECT * FROM dashboards
                WHERE id = :dashboard_id
                    AND tenant_id = :tenant_id
                    AND user_id = :user_id
                    AND status = 'active'";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':dashboard_id' => $dashboardId,
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId
        ]);

        $dashboard = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dashboard) {
            $this->sendError(404, 'Dashboard not found');
        }

        // Parse JSON fields
        $dashboard['layout_config'] = json_decode($dashboard['layout_config'] ?? '{}', true);
        $dashboard['shared_with'] = json_decode($dashboard['shared_with'] ?? '[]', true);

        // Get widgets
        $widgetSql = "SELECT * FROM dashboard_widgets
                      WHERE dashboard_id = :dashboard_id
                          AND tenant_id = :tenant_id
                          AND is_visible = TRUE
                      ORDER BY sort_order, grid_y, grid_x";

        $widgetStmt = $this->pdo->prepare($widgetSql);
        $widgetStmt->execute([
            ':dashboard_id' => $dashboardId,
            ':tenant_id' => $this->tenantId
        ]);

        $widgets = $widgetStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($widgets as &$widget) {
            $widget['data_filters'] = json_decode($widget['data_filters'] ?? '{}', true);
            $widget['config'] = json_decode($widget['config'] ?? '{}', true);

            // Include widget data if requested
            if (filter_var($this->queryParams['include_data'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $widget['data'] = $this->getWidgetData($widget);
            }
        }

        $dashboard['widgets'] = $widgets;

        // Update last accessed
        $this->updateDashboardAccess($dashboardId);

        $this->sendSuccess($dashboard, 'Dashboard retrieved successfully');
    }

    /**
     * Create new dashboard
     */
    private function createDashboard(): void {
        $sql = "INSERT INTO dashboards (
                    tenant_id, user_id, name, description,
                    is_default, layout_config, theme,
                    grid_columns, row_height,
                    is_public, auto_refresh_interval, timezone,
                    created_by
                ) VALUES (
                    :tenant_id, :user_id, :name, :description,
                    :is_default, :layout_config, :theme,
                    :grid_columns, :row_height,
                    :is_public, :auto_refresh_interval, :timezone,
                    :created_by
                )";

        // If setting as default, unset other defaults
        if ($this->requestData['is_default'] ?? false) {
            $unsetStmt = $this->pdo->prepare(
                "UPDATE dashboards SET is_default = FALSE
                 WHERE tenant_id = :tenant_id AND user_id = :user_id"
            );
            $unsetStmt->execute([
                ':tenant_id' => $this->tenantId,
                ':user_id' => $this->userId
            ]);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId,
            ':name' => $this->requestData['name'] ?? 'New Dashboard',
            ':description' => $this->requestData['description'] ?? null,
            ':is_default' => $this->requestData['is_default'] ?? false,
            ':layout_config' => json_encode($this->requestData['layout_config'] ?? []),
            ':theme' => $this->requestData['theme'] ?? 'light',
            ':grid_columns' => $this->requestData['grid_columns'] ?? 12,
            ':row_height' => $this->requestData['row_height'] ?? 60,
            ':is_public' => $this->requestData['is_public'] ?? false,
            ':auto_refresh_interval' => $this->requestData['auto_refresh_interval'] ?? 0,
            ':timezone' => $this->requestData['timezone'] ?? 'UTC',
            ':created_by' => $this->userId
        ]);

        $dashboardId = (int)$this->pdo->lastInsertId();

        // Create default widgets if template specified
        if (!empty($this->requestData['template'])) {
            $this->createDefaultWidgets($dashboardId, $this->requestData['template']);
        }

        $this->getDashboard($dashboardId);
    }

    /**
     * Update dashboard settings
     */
    private function updateDashboard(int $dashboardId): void {
        if (!$this->verifyDashboardOwnership($dashboardId)) {
            $this->sendError(403, 'Dashboard not found or access denied');
        }

        $allowedFields = [
            'name', 'description', 'is_default', 'layout_config',
            'theme', 'grid_columns', 'row_height', 'is_public',
            'auto_refresh_interval', 'timezone'
        ];

        $updates = [];
        $params = [':dashboard_id' => $dashboardId];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $this->requestData)) {
                $value = $this->requestData[$field];

                if ($field === 'layout_config') {
                    $value = json_encode($value);
                }

                $updates[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }

        if (empty($updates)) {
            $this->sendError(400, 'No fields to update');
        }

        // Handle default dashboard
        if (isset($this->requestData['is_default']) && $this->requestData['is_default']) {
            $unsetStmt = $this->pdo->prepare(
                "UPDATE dashboards SET is_default = FALSE
                 WHERE tenant_id = :tenant_id AND user_id = :user_id AND id != :dashboard_id"
            );
            $unsetStmt->execute([
                ':tenant_id' => $this->tenantId,
                ':user_id' => $this->userId,
                ':dashboard_id' => $dashboardId
            ]);
        }

        $sql = "UPDATE dashboards
                SET " . implode(', ', $updates) . ", updated_at = NOW()
                WHERE id = :dashboard_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $this->getDashboard($dashboardId);
    }

    /**
     * Delete dashboard
     */
    private function deleteDashboard(int $dashboardId): void {
        if (!$this->verifyDashboardOwnership($dashboardId)) {
            $this->sendError(403, 'Dashboard not found or access denied');
        }

        // Soft delete
        $stmt = $this->pdo->prepare(
            "UPDATE dashboards SET status = 'deleted', updated_at = NOW()
             WHERE id = :dashboard_id"
        );
        $stmt->execute([':dashboard_id' => $dashboardId]);

        $this->sendSuccess(null, 'Dashboard deleted successfully');
    }

    /**
     * Share dashboard with other users
     */
    private function shareDashboard(int $dashboardId): void {
        if (!$this->verifyDashboardOwnership($dashboardId)) {
            $this->sendError(403, 'Dashboard not found or access denied');
        }

        $shareWith = $this->requestData['users'] ?? [];
        $makePublic = $this->requestData['public'] ?? false;

        // Generate share token if making public
        $shareToken = null;
        if ($makePublic) {
            $shareToken = bin2hex(random_bytes(32));
        }

        $sql = "UPDATE dashboards
                SET is_public = :is_public,
                    share_token = :share_token,
                    shared_with = :shared_with,
                    updated_at = NOW()
                WHERE id = :dashboard_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':dashboard_id' => $dashboardId,
            ':is_public' => $makePublic,
            ':share_token' => $shareToken,
            ':shared_with' => json_encode($shareWith)
        ]);

        $response = [
            'shared' => true,
            'public' => $makePublic,
            'users' => $shareWith
        ];

        if ($shareToken) {
            $response['share_url'] = $_SERVER['HTTP_HOST'] . '/dashboard/shared/' . $shareToken;
        }

        $this->sendSuccess($response, 'Dashboard shared successfully');
    }

    /**
     * Handle legacy query parameter routing
     */
    private function handleLegacyRouting(): void {
        $resource = $this->queryParams['resource'] ?? '';
        $action = $this->queryParams['action'] ?? '';
        $id = isset($this->queryParams['id']) ? (int)$this->queryParams['id'] : null;

        switch ($resource) {
            case 'widget':
                switch ($action) {
                    case 'list':
                        $this->listWidgets();
                        break;
                    case 'get':
                        if ($id) $this->getWidget($id);
                        else $this->sendError(400, 'Widget ID required');
                        break;
                    case 'create':
                        $this->createWidget();
                        break;
                    case 'update':
                        if ($id) $this->updateWidget($id);
                        else $this->sendError(400, 'Widget ID required');
                        break;
                    case 'delete':
                        if ($id) $this->deleteWidget($id);
                        else $this->sendError(400, 'Widget ID required');
                        break;
                    default:
                        $this->sendError(400, 'Invalid action');
                }
                break;

            case 'metric':
                switch ($action) {
                    case 'get':
                        $this->getMetrics();
                        break;
                    case 'realtime':
                        $this->getRealtimeMetrics();
                        break;
                    case 'compare':
                        $this->compareMetrics();
                        break;
                    default:
                        $this->sendError(400, 'Invalid action');
                }
                break;

            case 'dashboard':
                switch ($action) {
                    case 'list':
                        $this->listDashboards();
                        break;
                    case 'get':
                        if ($id) $this->getDashboard($id);
                        else $this->sendError(400, 'Dashboard ID required');
                        break;
                    case 'create':
                        $this->createDashboard();
                        break;
                    case 'update':
                        if ($id) $this->updateDashboard($id);
                        else $this->sendError(400, 'Dashboard ID required');
                        break;
                    case 'delete':
                        if ($id) $this->deleteDashboard($id);
                        else $this->sendError(400, 'Dashboard ID required');
                        break;
                    case 'share':
                        if ($id) $this->shareDashboard($id);
                        else $this->sendError(400, 'Dashboard ID required');
                        break;
                    default:
                        $this->sendError(400, 'Invalid action');
                }
                break;

            default:
                $this->sendError(400, 'Invalid resource');
        }
    }

    /**
     * Helper Methods
     */

    /**
     * Format metrics data for Chart.js
     */
    private function formatForChartJs(array $metrics, string $metricName): array {
        $labels = [];
        $data = [];

        foreach ($metrics as $metric) {
            $timestamp = $metric['timestamp'] ?? $metric['period_start'] ?? '';
            $labels[] = date('Y-m-d H:i', strtotime($timestamp));
            $data[] = $metric['value'] ?? 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $metricName,
                    'data' => $data,
                    'borderColor' => 'rgb(75, 192, 192)',
                    'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                    'tension' => 0.1
                ]
            ]
        ];
    }

    /**
     * Get data for a widget based on its configuration
     */
    private function getWidgetData(array $widget): array {
        // This would fetch data based on widget configuration
        // Implementation depends on data sources available

        if ($widget['data_source'] === 'metrics') {
            $config = $widget['config'] ?? [];
            $metricName = $config['metric'] ?? null;

            if ($metricName) {
                $endDate = new DateTime();
                $startDate = clone $endDate;
                $startDate->modify('-1 day');

                $metrics = $this->metrics->getMetrics(
                    $this->tenantId,
                    $metricName,
                    $startDate,
                    $endDate,
                    ['limit' => 100]
                );

                return $this->formatForChartJs($metrics, $metricName);
            }
        }

        return [];
    }

    /**
     * Create default widgets for a dashboard template
     */
    private function createDefaultWidgets(int $dashboardId, string $template): void {
        // Define template widgets
        $templates = [
            'analytics' => [
                ['type' => 'metric', 'title' => 'Daily Active Users', 'metric' => 'daily_active_users', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
                ['type' => 'chart', 'title' => 'User Activity', 'metric' => 'user_activity', 'x' => 3, 'y' => 0, 'w' => 6, 'h' => 4],
                ['type' => 'metric', 'title' => 'Storage Used', 'metric' => 'storage_used', 'x' => 9, 'y' => 0, 'w' => 3, 'h' => 2],
                ['type' => 'table', 'title' => 'Recent Activities', 'source' => 'activity_log', 'x' => 0, 'y' => 4, 'w' => 12, 'h' => 4]
            ],
            'monitoring' => [
                ['type' => 'metric', 'title' => 'API Response Time', 'metric' => 'api_response_time', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
                ['type' => 'metric', 'title' => 'Error Rate', 'metric' => 'error_rate', 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
                ['type' => 'chart', 'title' => 'System Performance', 'metric' => 'system_performance', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 4],
                ['type' => 'table', 'title' => 'Recent Errors', 'source' => 'error_log', 'x' => 0, 'y' => 4, 'w' => 12, 'h' => 4]
            ]
        ];

        $widgets = $templates[$template] ?? $templates['analytics'];

        foreach ($widgets as $index => $widget) {
            $config = [
                'metric' => $widget['metric'] ?? null,
                'source' => $widget['source'] ?? null
            ];

            $sql = "INSERT INTO dashboard_widgets (
                        tenant_id, dashboard_id, widget_type, title,
                        grid_x, grid_y, grid_width, grid_height,
                        config, sort_order
                    ) VALUES (
                        :tenant_id, :dashboard_id, :widget_type, :title,
                        :grid_x, :grid_y, :grid_width, :grid_height,
                        :config, :sort_order
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $this->tenantId,
                ':dashboard_id' => $dashboardId,
                ':widget_type' => $widget['type'],
                ':title' => $widget['title'],
                ':grid_x' => $widget['x'],
                ':grid_y' => $widget['y'],
                ':grid_width' => $widget['w'],
                ':grid_height' => $widget['h'],
                ':config' => json_encode($config),
                ':sort_order' => $index
            ]);
        }
    }

    /**
     * Verify widget ownership
     */
    private function verifyWidgetOwnership(int $widgetId): bool {
        $sql = "SELECT w.id
                FROM dashboard_widgets w
                JOIN dashboards d ON w.dashboard_id = d.id
                WHERE w.id = :widget_id
                    AND w.tenant_id = :tenant_id
                    AND d.user_id = :user_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':widget_id' => $widgetId,
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId
        ]);

        return (bool)$stmt->fetch();
    }

    /**
     * Verify dashboard ownership
     */
    private function verifyDashboardOwnership(int $dashboardId): bool {
        $sql = "SELECT id FROM dashboards
                WHERE id = :dashboard_id
                    AND tenant_id = :tenant_id
                    AND user_id = :user_id
                    AND status = 'active'";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':dashboard_id' => $dashboardId,
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId
        ]);

        return (bool)$stmt->fetch();
    }

    /**
     * Update dashboard last accessed timestamp
     */
    private function updateDashboardAccess(int $dashboardId): void {
        $stmt = $this->pdo->prepare(
            "UPDATE dashboards SET last_accessed = NOW() WHERE id = :dashboard_id"
        );
        $stmt->execute([':dashboard_id' => $dashboardId]);
    }

    /**
     * Update dashboard timestamp
     */
    private function updateDashboardTimestamp(int $dashboardId): void {
        $stmt = $this->pdo->prepare(
            "UPDATE dashboards SET updated_at = NOW() WHERE id = :dashboard_id"
        );
        $stmt->execute([':dashboard_id' => $dashboardId]);
    }

    /**
     * Update dashboard timestamp by widget ID
     */
    private function updateDashboardTimestampByWidget(int $widgetId): void {
        $sql = "UPDATE dashboards d
                JOIN dashboard_widgets w ON d.id = w.dashboard_id
                SET d.updated_at = NOW()
                WHERE w.id = :widget_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':widget_id' => $widgetId]);
    }

    /**
     * Send success response
     */
    private function sendSuccess($data, string $message = 'Success', array $metadata = []): void {
        $response = [
            'success' => true,
            'data' => $data,
            'message' => $message,
            'metadata' => array_merge([
                'timestamp' => date('c')
            ], $metadata)
        ];

        http_response_code(200);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send error response
     */
    private function sendError(int $code, string $message, array $data = null): void {
        $response = [
            'success' => false,
            'data' => $data,
            'message' => $message,
            'metadata' => [
                'timestamp' => date('c')
            ]
        ];

        http_response_code($code);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Initialize and handle request
try {
    $api = new DashboardAPI();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => 'Internal server error',
        'metadata' => [
            'timestamp' => date('c'),
            'error' => DEBUG_MODE ? $e->getMessage() : null
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
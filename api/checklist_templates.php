<?php
declare(strict_types=1);
require_once __DIR__ . '/ai/_common.php';
require_once __DIR__ . '/../includes/ai/tenant_checklists.php';

cnx_ai_require_csrf_for_write();

$tenantId = cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

api_success(['templates' => cnx_tenant_checklists_list_templates()]);


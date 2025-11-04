<?php
/**
 * File Permissions and Document Workflow System - Constants
 *
 * Defines all constants, enums, and helper functions for the workflow system
 *
 * @package CollaboraNexio
 * @subpackage Workflow
 * @version 1.0.0
 * @since 2025-10-29
 */

declare(strict_types=1);

// ============================================
// WORKFLOW STATES
// ============================================

/**
 * Document Workflow States
 */
define('WORKFLOW_STATE_DRAFT', 'bozza');
define('WORKFLOW_STATE_IN_VALIDATION', 'in_validazione');
define('WORKFLOW_STATE_VALIDATED', 'validato');
define('WORKFLOW_STATE_IN_APPROVAL', 'in_approvazione');
define('WORKFLOW_STATE_APPROVED', 'approvato');
define('WORKFLOW_STATE_REJECTED', 'rifiutato');

/**
 * All valid workflow states
 */
const WORKFLOW_STATES = [
    WORKFLOW_STATE_DRAFT,
    WORKFLOW_STATE_IN_VALIDATION,
    WORKFLOW_STATE_VALIDATED,
    WORKFLOW_STATE_IN_APPROVAL,
    WORKFLOW_STATE_APPROVED,
    WORKFLOW_STATE_REJECTED
];

/**
 * Workflow state display names (Italian)
 */
const WORKFLOW_STATE_LABELS = [
    WORKFLOW_STATE_DRAFT => 'Bozza',
    WORKFLOW_STATE_IN_VALIDATION => 'In Validazione',
    WORKFLOW_STATE_VALIDATED => 'Validato',
    WORKFLOW_STATE_IN_APPROVAL => 'In Approvazione',
    WORKFLOW_STATE_APPROVED => 'Approvato',
    WORKFLOW_STATE_REJECTED => 'Rifiutato'
];

/**
 * Workflow state colors (CSS classes or hex codes)
 */
const WORKFLOW_STATE_COLORS = [
    WORKFLOW_STATE_DRAFT => '#6c757d',           // Gray
    WORKFLOW_STATE_IN_VALIDATION => '#ffc107',   // Amber
    WORKFLOW_STATE_VALIDATED => '#17a2b8',       // Cyan
    WORKFLOW_STATE_IN_APPROVAL => '#fd7e14',     // Orange
    WORKFLOW_STATE_APPROVED => '#28a745',        // Green
    WORKFLOW_STATE_REJECTED => '#dc3545'         // Red
];

// ============================================
// WORKFLOW TRANSITION TYPES
// ============================================

/**
 * Workflow Transition Types
 */
define('TRANSITION_SUBMIT', 'submit');
define('TRANSITION_VALIDATE', 'validate');
define('TRANSITION_REJECT', 'reject_to_creator');
define('TRANSITION_APPROVE', 'approve');
define('TRANSITION_RECALL', 'recall');
define('TRANSITION_CANCEL', 'cancel');

/**
 * All valid transition types
 */
const WORKFLOW_TRANSITIONS = [
    TRANSITION_SUBMIT,
    TRANSITION_VALIDATE,
    TRANSITION_REJECT,
    TRANSITION_APPROVE,
    TRANSITION_RECALL,
    TRANSITION_CANCEL
];

/**
 * Transition display names (Italian)
 */
const WORKFLOW_TRANSITION_LABELS = [
    TRANSITION_SUBMIT => 'Inviato per Validazione',
    TRANSITION_VALIDATE => 'Validato',
    TRANSITION_REJECT => 'Rifiutato',
    TRANSITION_APPROVE => 'Approvato',
    TRANSITION_RECALL => 'Richiamato',
    TRANSITION_CANCEL => 'Annullato'
];

// ============================================
// WORKFLOW ROLES
// ============================================

/**
 * Workflow Role Types
 */
define('WORKFLOW_ROLE_VALIDATOR', 'validator');
define('WORKFLOW_ROLE_APPROVER', 'approver');

/**
 * All valid workflow roles
 */
const WORKFLOW_ROLES = [
    WORKFLOW_ROLE_VALIDATOR,
    WORKFLOW_ROLE_APPROVER
];

/**
 * Workflow role display names (Italian)
 */
const WORKFLOW_ROLE_LABELS = [
    WORKFLOW_ROLE_VALIDATOR => 'Validatore',
    WORKFLOW_ROLE_APPROVER => 'Approvatore'
];

// ============================================
// USER ROLES (for workflow context)
// ============================================

/**
 * User Role Types (at time of transition)
 */
define('USER_ROLE_CREATOR', 'creator');
define('USER_ROLE_VALIDATOR', 'validator');
define('USER_ROLE_APPROVER', 'approver');
define('USER_ROLE_ADMIN', 'admin');
define('USER_ROLE_SUPER_ADMIN', 'super_admin');

/**
 * All valid user roles
 */
const USER_ROLES = [
    USER_ROLE_CREATOR,
    USER_ROLE_VALIDATOR,
    USER_ROLE_APPROVER,
    USER_ROLE_ADMIN,
    USER_ROLE_SUPER_ADMIN
];

// ============================================
// ENTITY TYPES
// ============================================

/**
 * Assignment Entity Types
 */
define('ENTITY_TYPE_FILE', 'file');
define('ENTITY_TYPE_FOLDER', 'folder');

/**
 * All valid entity types
 */
const ENTITY_TYPES = [
    ENTITY_TYPE_FILE,
    ENTITY_TYPE_FOLDER
];

/**
 * Entity type display names (Italian)
 */
const ENTITY_TYPE_LABELS = [
    ENTITY_TYPE_FILE => 'File',
    ENTITY_TYPE_FOLDER => 'Cartella'
];

// ============================================
// VALID STATE TRANSITIONS
// ============================================

/**
 * Valid state transitions map
 * Key: from_state, Value: array of allowed to_states
 */
const WORKFLOW_STATE_TRANSITIONS = [
    WORKFLOW_STATE_DRAFT => [
        WORKFLOW_STATE_IN_VALIDATION  // Creator submits
    ],
    WORKFLOW_STATE_IN_VALIDATION => [
        WORKFLOW_STATE_VALIDATED,      // Validator approves
        WORKFLOW_STATE_REJECTED,       // Validator rejects
        WORKFLOW_STATE_DRAFT           // Creator recalls
    ],
    WORKFLOW_STATE_VALIDATED => [
        WORKFLOW_STATE_IN_APPROVAL,    // Auto-transition
        WORKFLOW_STATE_DRAFT           // Creator recalls
    ],
    WORKFLOW_STATE_IN_APPROVAL => [
        WORKFLOW_STATE_APPROVED,       // Approver approves
        WORKFLOW_STATE_REJECTED,       // Approver rejects
        WORKFLOW_STATE_DRAFT           // Creator recalls
    ],
    WORKFLOW_STATE_REJECTED => [
        WORKFLOW_STATE_DRAFT           // Creator edits and resubmits
    ],
    WORKFLOW_STATE_APPROVED => [
        // Terminal state - no transitions allowed
    ]
];

// ============================================
// EMAIL NOTIFICATION TRIGGERS
// ============================================

/**
 * Email notification triggers per transition
 */
const WORKFLOW_EMAIL_TRIGGERS = [
    TRANSITION_SUBMIT => [
        'template' => 'workflow_submitted_to_validation',
        'recipients' => ['validators'],
        'subject' => 'Nuovo documento da validare: {document_name}'
    ],
    TRANSITION_VALIDATE => [
        'template' => 'workflow_validation_approved',
        'recipients' => ['creator', 'approvers'],
        'subject' => 'Documento validato: {document_name}'
    ],
    TRANSITION_REJECT => [
        'template' => 'workflow_rejected',
        'recipients' => ['creator'],
        'subject' => 'Documento rifiutato: {document_name}'
    ],
    TRANSITION_APPROVE => [
        'template' => 'workflow_final_approved',
        'recipients' => ['creator'],
        'subject' => 'Documento approvato: {document_name}'
    ],
    TRANSITION_RECALL => [
        'template' => 'workflow_recalled',
        'recipients' => ['validators', 'approvers'],
        'subject' => 'Documento richiamato: {document_name}'
    ]
];

// ============================================
// VALIDATION RULES
// ============================================

/**
 * Minimum rejection reason length
 */
define('MIN_REJECTION_REASON_LENGTH', 10);

/**
 * Maximum rejection count before warning
 */
define('MAX_REJECTION_WARNING_THRESHOLD', 3);

/**
 * Default assignment expiration days
 */
define('DEFAULT_ASSIGNMENT_EXPIRATION_DAYS', 90);

/**
 * Days before expiration to send warning email
 */
define('ASSIGNMENT_EXPIRATION_WARNING_DAYS', 7);

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Check if a state transition is valid
 *
 * @param string $fromState Current state
 * @param string $toState Target state
 * @return bool True if transition is valid
 */
function isValidWorkflowTransition(string $fromState, string $toState): bool {
    if (!isset(WORKFLOW_STATE_TRANSITIONS[$fromState])) {
        return false;
    }

    return in_array($toState, WORKFLOW_STATE_TRANSITIONS[$fromState], true);
}

/**
 * Get display label for workflow state
 *
 * @param string $state Workflow state
 * @return string Display label
 */
function getWorkflowStateLabel(string $state): string {
    return WORKFLOW_STATE_LABELS[$state] ?? $state;
}

/**
 * Get color for workflow state
 *
 * @param string $state Workflow state
 * @return string Hex color code
 */
function getWorkflowStateColor(string $state): string {
    return WORKFLOW_STATE_COLORS[$state] ?? '#6c757d';
}

/**
 * Get transition label
 *
 * @param string $transition Transition type
 * @return string Display label
 */
function getTransitionLabel(string $transition): string {
    return WORKFLOW_TRANSITION_LABELS[$transition] ?? $transition;
}

/**
 * Check if user has workflow role
 *
 * @param int $userId User ID
 * @param int $tenantId Tenant ID
 * @param string $role Workflow role (validator/approver)
 * @return bool True if user has role
 */
function userHasWorkflowRole(int $userId, int $tenantId, string $role): bool {
    $db = Database::getInstance();

    $result = $db->fetchOne(
        "SELECT id FROM workflow_roles
         WHERE tenant_id = ?
           AND user_id = ?
           AND workflow_role = ?
           AND is_active = 1
           AND deleted_at IS NULL
         LIMIT 1",
        [$tenantId, $userId, $role]
    );

    return $result !== false;
}

/**
 * Check if user can access file (via assignment or role)
 *
 * @param int $userId User ID
 * @param string $userRole User role (from session)
 * @param int $tenantId Tenant ID
 * @param int $fileId File ID
 * @param int $uploadedBy File creator user ID
 * @return bool True if user can access
 */
function canUserAccessFile(int $userId, string $userRole, int $tenantId, int $fileId, int $uploadedBy): bool {
    // Super admin bypasses all restrictions
    if ($userRole === 'super_admin') {
        return true;
    }

    // Manager bypasses tenant restrictions
    if ($userRole === 'manager') {
        return true;
    }

    // Creator always has access
    if ($uploadedBy === $userId) {
        return true;
    }

    // Check active assignment
    $db = Database::getInstance();
    $assignment = $db->fetchOne(
        "SELECT id FROM file_assignments
         WHERE file_id = ?
           AND assigned_to_user_id = ?
           AND tenant_id = ?
           AND deleted_at IS NULL
           AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1",
        [$fileId, $userId, $tenantId]
    );

    return $assignment !== false;
}

/**
 * Get all active validators for tenant
 *
 * @param int $tenantId Tenant ID
 * @return array Array of validator user IDs
 */
function getActiveValidators(int $tenantId): array {
    $db = Database::getInstance();

    $validators = $db->fetchAll(
        "SELECT user_id FROM workflow_roles
         WHERE tenant_id = ?
           AND workflow_role = ?
           AND is_active = 1
           AND deleted_at IS NULL",
        [$tenantId, WORKFLOW_ROLE_VALIDATOR]
    );

    return array_column($validators, 'user_id');
}

/**
 * Get all active approvers for tenant
 *
 * @param int $tenantId Tenant ID
 * @return array Array of approver user IDs
 */
function getActiveApprovers(int $tenantId): array {
    $db = Database::getInstance();

    $approvers = $db->fetchAll(
        "SELECT user_id FROM workflow_roles
         WHERE tenant_id = ?
           AND workflow_role = ?
           AND is_active = 1
           AND deleted_at IS NULL",
        [$tenantId, WORKFLOW_ROLE_APPROVER]
    );

    return array_column($approvers, 'user_id');
}

/**
 * Validate rejection reason
 *
 * @param string $reason Rejection reason
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validateRejectionReason(string $reason): array {
    $reason = trim($reason);

    if (empty($reason)) {
        return [
            'valid' => false,
            'error' => 'Il motivo del rifiuto è obbligatorio'
        ];
    }

    if (strlen($reason) < MIN_REJECTION_REASON_LENGTH) {
        return [
            'valid' => false,
            'error' => sprintf(
                'Il motivo del rifiuto deve essere almeno %d caratteri (attuale: %d)',
                MIN_REJECTION_REASON_LENGTH,
                strlen($reason)
            )
        ];
    }

    return ['valid' => true, 'error' => null];
}

/**
 * Check if assignment is expired
 *
 * @param string|null $expiresAt Expiration timestamp
 * @return bool True if expired
 */
function isAssignmentExpired(?string $expiresAt): bool {
    if ($expiresAt === null) {
        return false; // Permanent assignment
    }

    return strtotime($expiresAt) < time();
}

/**
 * Check if assignment is expiring soon
 *
 * @param string|null $expiresAt Expiration timestamp
 * @param int $warningDays Days before expiration to warn
 * @return bool True if expiring soon
 */
function isAssignmentExpiringSoon(?string $expiresAt, int $warningDays = ASSIGNMENT_EXPIRATION_WARNING_DAYS): bool {
    if ($expiresAt === null) {
        return false; // Permanent assignment
    }

    $expiresTimestamp = strtotime($expiresAt);
    $warningTimestamp = time() + ($warningDays * 86400);

    return $expiresTimestamp <= $warningTimestamp && $expiresTimestamp > time();
}

/**
 * Format workflow metadata for display
 *
 * @param string|null $metadataJson JSON metadata from workflow_history
 * @return array Parsed metadata array
 */
function parseWorkflowMetadata(?string $metadataJson): array {
    if (empty($metadataJson)) {
        return [];
    }

    $metadata = json_decode($metadataJson, true);
    return is_array($metadata) ? $metadata : [];
}

/**
 * Build workflow transition metadata
 *
 * @param array $data Additional metadata
 * @return string JSON encoded metadata
 */
function buildWorkflowMetadata(array $data = []): string {
    $metadata = array_merge([
        'timestamp' => date('Y-m-d H:i:s'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ], $data);

    return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ============================================
// END OF CONSTANTS
// ============================================

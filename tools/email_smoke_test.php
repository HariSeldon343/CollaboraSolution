<?php
/**
 * Lightweight smoke test for email rendering (CLI).
 *
 * Usage:
 *   php tools/email_smoke_test.php
 *
 * This does NOT send emails. It only renders HTML for a few templates to ensure:
 * - no fatal/parse errors
 * - renderer handles conditionals/sections
 * - layout wraps content-only templates
 */

require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/email_template_renderer.php';
require_once __DIR__ . '/../includes/email_layout.php';

function printSection(string $name): void {
    echo "\n==== $name ====\n";
}

printSection('Welcome template');
echo substr(
    getWelcomeEmailTemplate(
        'Mario Rossi',
        'http://example.test/set_password.php?token=abc',
        'Acme Srl',
        'http://example.test'
    ),
    0,
    300
) . "\n";

printSection('Renderer: mustache array section');
$taskTpl = file_get_contents(__DIR__ . '/../includes/email_templates/tasks/task_created.html');
echo emailRenderTemplate($taskTpl, [
    'USER_NAME' => 'Mario',
    'TASK_TITLE' => 'Titolo',
    'TASK_STATUS_LABEL' => 'Da fare',
    'TASK_PRIORITY_LABEL' => 'Media',
    'TASK_URL' => 'http://example.test/tasks.php?task_id=1',
    'TENANT_NAME' => 'Acme Srl',
    'ASSIGNEES_LIST' => true,
    'ASSIGNEES' => ['Luigi', 'Anna'],
]) . "\n";

printSection('Renderer: ticket conditionals');
$ticketTpl = file_get_contents(__DIR__ . '/../includes/email_templates/tickets/ticket_created.html');
echo emailRenderTemplate($ticketTpl, [
    'USER_NAME' => 'Mario',
    'TICKET_NUMBER' => 'T-1',
    'TICKET_SUBJECT' => 'Test',
    'TICKET_DESCRIPTION' => '',
    'TICKET_URL' => 'http://example.test/ticket.php?id=1',
    'TENANT_NAME' => '',
]) . "\n";



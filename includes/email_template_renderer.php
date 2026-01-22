<?php
/**
 * Shared email template renderer for Nexio emails.
 *
 * Supports:
 * - Escaped placeholders: {{KEY}}
 * - Raw placeholders (trusted HTML only): {{{KEY}}}
 * - Ticket-style conditionals:
 *   <!-- IF_KEY --> ... <!-- ENDIF_KEY -->
 * - Ticket-style loops (array of pre-rendered strings):
 *   <!-- LOOP_KEY --> ... <!-- ENDLOOP_KEY -->
 * - Mustache-like sections:
 *   {{#KEY}} ... {{/KEY}}
 *   - If KEY is an array, repeats block (supports {{.}} for scalar lists)
 */

/**
 * Render a template string with the provided data.
 */
function emailRenderTemplate(string $template, array $data): string
{
    // 1) Ticket-style loops: <!-- LOOP_KEY --> ... <!-- ENDLOOP_KEY -->
    foreach ($data as $key => $value) {
        if (!is_array($value)) {
            continue;
        }
        $pattern = '/<!--\\s*LOOP_' . preg_quote((string)$key, '/') . '\\s*-->(.*?)<!--\\s*ENDLOOP_' . preg_quote((string)$key, '/') . '\\s*-->/s';
        if (preg_match($pattern, $template)) {
            $replacement = '';
            foreach ($value as $item) {
                $replacement .= (string)$item;
            }
            $template = preg_replace($pattern, $replacement, $template) ?? $template;
        }
    }

    // 2) Ticket-style conditionals: <!-- IF_KEY --> ... <!-- ENDIF_KEY -->
    foreach ($data as $key => $value) {
        $truthy = !($value === null || $value === false || $value === '' || (is_array($value) && empty($value)));
        $pattern = '/<!--\\s*IF_' . preg_quote((string)$key, '/') . '\\s*-->(.*?)<!--\\s*ENDIF_' . preg_quote((string)$key, '/') . '\\s*-->/s';
        if (!$truthy) {
            $template = preg_replace($pattern, '', $template) ?? $template;
        } else {
            $template = preg_replace($pattern, '$1', $template) ?? $template;
        }
    }

    // 3) Mustache-like sections: {{#KEY}} ... {{/KEY}}
    foreach ($data as $key => $value) {
        $truthy = !($value === null || $value === false || $value === '' || (is_array($value) && empty($value)));
        $pattern = '/{{#' . preg_quote((string)$key, '/') . '}}(.*?){{\\/' . preg_quote((string)$key, '/') . '}}/s';
        if (!$truthy) {
            $template = preg_replace($pattern, '', $template) ?? $template;
            continue;
        }

        // Arrays: repeat the block (supports {{.}} for scalar lists)
        if (is_array($value)) {
            $template = preg_replace_callback(
                $pattern,
                function ($matches) use ($value) {
                    $inner = (string)($matches[1] ?? '');
                    $out = '';
                    foreach ($value as $item) {
                        $chunk = $inner;
                        if (is_array($item)) {
                            $chunk = emailRenderTemplate($chunk, $item);
                        } else {
                            $chunk = str_replace('{{.}}', htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8'), $chunk);
                            $chunk = str_replace('{{{.}}}', (string)$item, $chunk);
                        }
                        $out .= $chunk;
                    }
                    return $out;
                },
                $template
            ) ?? $template;
            continue;
        }

        // Scalars: keep the block content
        $template = preg_replace($pattern, '$1', $template) ?? $template;
    }

    // 4) Raw placeholders {{{KEY}}} (no escaping)
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            continue;
        }
        $template = str_replace('{{{' . $key . '}}}', (string)$value, $template);
    }

    // 5) Escaped placeholders {{KEY}}
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            continue;
        }
        $template = str_replace(
            '{{' . $key . '}}',
            htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'),
            $template
        );
    }

    // 6) Remove any remaining unused blocks/placeholders
    $template = preg_replace('/{{#\\w+}}.*?{{\\/\\w+}}/s', '', $template) ?? $template;
    $template = preg_replace('/<!--\\s*IF_\\w+\\s*-->.*?<!--\\s*ENDIF_\\w+\\s*-->/s', '', $template) ?? $template;
    $template = preg_replace('/<!--\\s*LOOP_\\w+\\s*-->.*?<!--\\s*ENDLOOP_\\w+\\s*-->/s', '', $template) ?? $template;
    $template = preg_replace('/{{{[^}]+}}}/', '', $template) ?? $template;
    $template = preg_replace('/{{[^}]+}}/', '', $template) ?? $template;

    return $template;
}

/**
 * Heuristic: detect if HTML looks like a full document (has <!doctype> or <html> tag).
 */
function cnx_email_is_full_document(string $html): bool
{
    $h = strtolower($html);
    return (strpos($h, '<!doctype') !== false) || (strpos($h, '<html') !== false);
}

/**
 * Render an email template file using the shared renderer.
 *
 * Options:
 * - remove_unknown_placeholders: when true, unknown placeholders are removed (default true)
 */
function cnx_render_email_template_file(string $templatePath, array $data, array $options = []): string
{
    if (!is_file($templatePath)) {
        return '';
    }

    $template = file_get_contents($templatePath);
    if ($template === false) {
        return '';
    }

    // emailRenderTemplate already removes unknown placeholders/blocks.
    return emailRenderTemplate($template, $data);
}



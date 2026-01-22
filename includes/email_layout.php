<?php
/**
 * Shared email layout ("chrome") for Nexio emails.
 *
 * Goals:
 * - Minimal, consistent style across all system emails
 * - Table-based layout for email client compatibility
 * - Single brand color accent: #1a2332
 * - Nexio logo in header (assets/images/logo.png) with graceful fallback
 */

require_once __DIR__ . '/email_template_renderer.php';

/**
 * Escape helper for email HTML.
 */
function cnx_email_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Public allowlist: these pages must NOT force re-auth.
 */
function cnx_email_is_public_target_path(string $path): bool
{
    $path = strtolower($path);
    return in_array($path, [
        '/collaboranexio/set_password.php',
        '/collaboranexio/forgot_password.php',
        '/collaboranexio/public/share.php',
    ], true);
}

/**
 * Normalize + validate an internal URL/path.
 *
 * Returns a normalized internal URL in the form:
 *   /CollaboraNexio/<path>[?query][#fragment]
 * or null if invalid / not internal.
 */
function cnx_email_normalize_internal_relative(string $to): ?string
{
    $to = trim($to);
    if ($to === '') {
        return null;
    }

    if (strpos($to, '://') !== false) {
        return null;
    }
    if (strpos($to, '\\') !== false) {
        return null;
    }
    if (strpos($to, '..') !== false) {
        return null;
    }
    if (str_starts_with($to, '//')) {
        return null;
    }

    $parsed = parse_url($to);
    if ($parsed === false) {
        return null;
    }
    if (isset($parsed['scheme']) || isset($parsed['host'])) {
        return null;
    }

    $path = (string)($parsed['path'] ?? '');
    if ($path === '') {
        return null;
    }

    if (str_starts_with($path, '/CollaboraNexio/')) {
        // ok
    } elseif (str_starts_with($path, 'CollaboraNexio/')) {
        $path = '/' . $path;
    } elseif (str_starts_with($path, '/')) {
        return null;
    } else {
        $path = '/CollaboraNexio/' . ltrim($path, '/');
    }

    if (!str_starts_with($path, '/CollaboraNexio/')) {
        return null;
    }

    $query = isset($parsed['query']) ? ('?' . $parsed['query']) : '';
    $fragment = isset($parsed['fragment']) ? ('#' . $parsed['fragment']) : '';
    return $path . $query . $fragment;
}

/**
 * Wrap internal protected links so they go through /email_link.php and force re-auth.
 *
 * If URL is external or points to a public allowlisted page, returns it unchanged.
 */
function cnx_email_wrap_link_for_login(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return $url;
    }

    // Idempotent: don't double-wrap
    if (strpos($url, '/email_link.php') !== false) {
        return $url;
    }

    $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';

    $parsed = parse_url($url);
    if ($parsed === false) {
        return $url;
    }

    $scheme = $parsed['scheme'] ?? null;
    $host = $parsed['host'] ?? null;

    $dest = '';
    if ($scheme !== null || $host !== null) {
        // Absolute URL: only wrap when we can confirm it's our own host via BASE_URL.
        if ($baseUrl === '') {
            return $url;
        }

        $baseParts = parse_url($baseUrl);
        if ($baseParts === false) {
            return $url;
        }
        $baseHost = $baseParts['host'] ?? '';
        if ($baseHost !== '' && $host !== null && strcasecmp($baseHost, $host) !== 0) {
            return $url;
        }

        $path = (string)($parsed['path'] ?? '');
        $query = isset($parsed['query']) ? ('?' . $parsed['query']) : '';
        $fragment = isset($parsed['fragment']) ? ('#' . $parsed['fragment']) : '';
        $dest = $path . $query . $fragment;
    } else {
        // Relative URL
        $dest = $url;
    }

    $destRel = cnx_email_normalize_internal_relative($dest);
    if ($destRel === null) {
        return $url;
    }

    $destPath = (string)(parse_url($destRel, PHP_URL_PATH) ?? '');
    if ($destPath !== '' && cnx_email_is_public_target_path($destPath)) {
        return $url;
    }

    // Must be absolute for email clients; rely on BASE_URL.
    if ($baseUrl === '') {
        return $url;
    }
    return rtrim($baseUrl, '/') . '/email_link.php?to=' . rawurlencode($destRel);
}

/**
 * Render a primary call-to-action button (table-based) for email compatibility.
 */
function renderEmailPrimaryButton(string $url, string $label, string $brandColor = '#1a2332'): string
{
    $url = cnx_email_wrap_link_for_login($url);
    $safeUrl = cnx_email_escape($url);
    $safeLabel = cnx_email_escape($label);
    $safeBrand = cnx_email_escape($brandColor);

    return ''
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;">'
        . '  <tr>'
        . '    <td align="left" style="border-radius:8px;background-color:' . $safeBrand . ';">'
        . '      <a href="' . $safeUrl . '" style="display:inline-block;padding:12px 18px;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;line-height:18px;border-radius:8px;">'
        . $safeLabel
        . '      </a>'
        . '    </td>'
        . '  </tr>'
        . '</table>';
}

/**
 * Render a full HTML email by wrapping a provided body HTML inside the Nexio layout.
 *
 * @param string $title Email title (main heading inside the email)
 * @param string $bodyHtml Email body HTML (already rendered content-only HTML)
 * @param array $vars Common variables (BASE_URL, TENANT_NAME, YEAR)
 * @param array $options Optional settings:
 *  - brandColor (or brand_color): hex color string (default #1a2332)
 *  - logoPath: path under BASE_URL (default /assets/images/logo.png)
 *  - preheader: hidden preview text
 * @return string
 */
function renderEmailLayout(string $title, string $bodyHtml, array $vars = [], array $options = []): string
{
    $brandColor = (string)($options['brandColor'] ?? ($options['brand_color'] ?? '#1a2332'));
    $baseUrl = (string)($vars['BASE_URL'] ?? (defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio'));
    $baseUrl = rtrim($baseUrl, '/');
    $tenantName = (string)($vars['TENANT_NAME'] ?? '');
    $year = (string)($vars['YEAR'] ?? date('Y'));
    $preheader = (string)($options['preheader'] ?? '');

    $logoPath = (string)($options['logoPath'] ?? '/assets/images/logo.png');
    $logoUrl = $baseUrl . $logoPath;

    $brandLabel = 'Nexio' . ($tenantName !== '' ? ' — ' . $tenantName : '');

    $safeTitle = cnx_email_escape($title);
    $safeBrandLabel = cnx_email_escape($brandLabel);
    $safeBaseUrl = cnx_email_escape($baseUrl);
    $safeYear = cnx_email_escape($year);
    $safeLogoUrl = cnx_email_escape($logoUrl);
    $safeBrandColor = cnx_email_escape($brandColor);
    $safePreheader = cnx_email_escape($preheader);

    // Body HTML is already rendered; insert raw.
    $body = (string)$bodyHtml;

    return '<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="x-apple-disable-message-reformatting">
  <title>' . $safeTitle . '</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;color:#111827;line-height:1.5;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $safePreheader . '</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f6f7f9;">
    <tr>
      <td align="center" style="padding:24px 12px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
          <tr>
            <td style="padding:18px 20px;border-bottom:1px solid #eef0f3;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td valign="middle" style="width:40px;padding-right:12px;">
                    <img src="' . $safeLogoUrl . '" alt="Nexio" width="32" height="32" style="display:block;border:0;outline:none;text-decoration:none;width:32px;height:32px;">
                  </td>
                  <td valign="middle" style="font-size:14px;line-height:20px;color:' . $safeBrandColor . ';font-weight:700;">
                    ' . $safeBrandLabel . '
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          ' . ($safeTitle !== '' ? ('
          <tr>
            <td style="padding:18px 20px 8px 20px;">
              <div style="font-size:18px;line-height:24px;font-weight:700;color:#111827;margin:0;">' . $safeTitle . '</div>
            </td>
          </tr>
          ') : '') . '

          <tr>
            <td style="padding:0 20px 18px 20px;">
              ' . $body . '
            </td>
          </tr>

          <tr>
            <td style="padding:14px 20px;border-top:1px solid #eef0f3;background:#fafafa;">
              <div style="font-size:12px;line-height:18px;color:#6b7280;">
                &copy; ' . $safeYear . ' Nexio. Tutti i diritti riservati.
              </div>
              <div style="margin-top:6px;font-size:12px;line-height:18px;">
                <a href="' . $safeBaseUrl . '" style="color:' . $safeBrandColor . ';text-decoration:none;">Apri Nexio</a>
              </div>
              <div style="margin-top:10px;font-size:11px;line-height:16px;color:#9ca3af;">
                Questa email è stata inviata automaticamente. Per favore non rispondere.
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}



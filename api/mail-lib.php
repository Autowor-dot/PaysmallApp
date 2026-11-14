<?php
/**
 * Outgoing email transports.
 *
 * InfinityFree's free plan does not deliver PHP mail() and blocks outbound
 * SMTP ports, but outbound HTTPS works. So besides the plain mail() transport
 * this supports two HTTP email APIs that need only an API key:
 *
 *   'mail'   – PHP mail() (works on hosts that allow it)
 *   'brevo'  – https://api.brevo.com/v3/smtp/email   (key: xkeysib-…)
 *   'resend' – https://api.resend.com/emails          (key: re_…)
 *   'mailersend' – https://api.mailersend.com/v1/email  (key: mlsn.…; MailerLite's transactional service)
 *
 * Configure in api/config.local.php:
 *   'mail_transport' => 'brevo',
 *   'mail_api_key'   => '…',
 *   'mail_from'      => 'no-reply@yourdomain',   // must be a verified sender at the provider
 *   'mail_from_name' => 'LipaLink',
 *
 * send_mail() in config.php calls deliver_email(); every attempt is written to email_log.
 */

declare(strict_types=1);

/**
 * @return array{ok: bool, error: ?string, transport: string}
 */
function deliver_email(string $to, string $subject, string $body, string $fromEmail, string $fromName): array
{
    $transport = strtolower((string) cfg('mail_transport', 'mail'));
    $html = email_html_template($subject, $body, $fromName);
    switch ($transport) {
        case 'brevo':
            return email_via_http(
                'brevo',
                'https://api.brevo.com/v3/smtp/email',
                ['api-key: ' . (string) cfg('mail_api_key', ''), 'accept: application/json', 'content-type: application/json'],
                [
                    'sender'      => ['email' => $fromEmail, 'name' => $fromName],
                    'to'          => [['email' => $to]],
                    'subject'     => $subject,
                    'textContent' => $body,
                    'htmlContent' => $html,
                ]
            );
        case 'mailersend':
            return email_via_http(
                'mailersend',
                'https://api.mailersend.com/v1/email',
                ['Authorization: Bearer ' . (string) cfg('mail_api_key', ''), 'Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'],
                [
                    'from'    => ['email' => $fromEmail, 'name' => $fromName],
                    'to'      => [['email' => $to]],
                    'subject' => $subject,
                    'text'    => $body,
                    'html'    => $html,
                ]
            );
        case 'resend':
            return email_via_http(
                'resend',
                'https://api.resend.com/emails',
                ['Authorization: Bearer ' . (string) cfg('mail_api_key', ''), 'Content-Type: application/json'],
                [
                    'from'    => $fromName . ' <' . $fromEmail . '>',
                    'to'      => [$to],
                    'subject' => $subject,
                    'text'    => $body,
                    'html'    => $html,
                ]
            );
        case 'mail':
        default:
            $headers = 'From: ' . $fromName . ' <' . $fromEmail . '>' . "\r\n"
                . 'Reply-To: ' . $fromEmail . "\r\n"
                . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
            $ok = false;
            $error = null;
            try {
                $ok = @mail($to, $subject, $body, $headers);
                if (!$ok) {
                    $last  = error_get_last();
                    $error = $last['message'] ?? 'mail() returned false (the host may not deliver PHP mail)';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
            return ['ok' => (bool) $ok, 'error' => $error, 'transport' => 'mail'];
    }
}

function email_via_http(string $name, string $url, array $headers, array $payload): array
{
    if ((string) cfg('mail_api_key', '') === '') {
        return ['ok' => false, 'error' => $name . ': mail_api_key is not configured', 'transport' => $name];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL extension unavailable', 'transport' => $name];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => $name . ': ' . ($curlErr ?: 'request failed'), 'transport' => $name];
    }
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'error' => null, 'transport' => $name];
    }
    $detail = '';
    $decoded = json_decode((string) $response, true);
    if (is_array($decoded)) {
        $detail = (string) ($decoded['message'] ?? $decoded['error'] ?? ($decoded['errors'][0]['message'] ?? ''));
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $first = reset($decoded['errors']);
            if (is_array($first) && isset($first[0]) && is_string($first[0])) { $detail .= ' - ' . $first[0]; }
        }
    }
    return ['ok' => false, 'error' => $name . ' HTTP ' . $status . ($detail !== '' ? ': ' . mb_substr($detail, 0, 160) : ''), 'transport' => $name];
}

/**
 * Branded, responsive HTML version of a plain-text email. The text stays the plain-text
 * alternative; links in the text become clickable, line breaks are preserved.
 */
function email_html_template(string $subject, string $text, string $siteName, string $siteUrl = ''): string
{
    $siteUrl = $siteUrl !== '' ? $siteUrl : app_base_url();
    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Split off the "If this message arrived in your spam folder…" footer hint added by send_mail()
    $parts  = preg_split('/\n\n--\n/', $text, 2);
    $main   = $parts[0];
    $hint   = $parts[1] ?? '';

    $bodyHtml = $esc($main);
    $bodyHtml = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" style="color:#1d4ed8;word-break:break-all;">$1</a>', $bodyHtml) ?? $bodyHtml;
    // Section headings written in CAPITALS on their own line (PAYMENT / BALANCE / AGREEMENT)
    $bodyHtml = preg_replace('/^([A-Z][A-Z &]{3,30})$/m', '<strong style="display:block;margin-top:14px;color:#0f172a;letter-spacing:.04em;font-size:12px;">$1</strong>', $bodyHtml) ?? $bodyHtml;
    $bodyHtml = nl2br($bodyHtml);

    $year = date('Y');
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $esc($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">'
        . '<div style="display:none;max-height:0;overflow:hidden;">' . $esc(mb_substr(preg_replace('/\s+/', ' ', $main) ?? '', 0, 120)) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 8px rgba(15,23,42,.08);">'
        . '<tr><td style="background:#1e3a8a;padding:18px 24px;">'
        . '<a href="' . $esc($siteUrl) . '" style="color:#ffffff;text-decoration:none;font-size:20px;font-weight:800;letter-spacing:.02em;">' . $esc($siteName) . '</a>'
        . '<div style="color:#bfdbfe;font-size:12px;margin-top:2px;">Pay small, own more.</div></td></tr>'
        . '<tr><td style="padding:24px;font-size:15px;line-height:1.6;color:#1e293b;">' . $bodyHtml . '</td></tr>'
        . ($hint !== '' ? '<tr><td style="padding:0 24px 20px;font-size:12px;line-height:1.5;color:#64748b;">' . nl2br($esc($hint)) . '</td></tr>' : '')
        . '<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 24px;font-size:12px;color:#64748b;">'
        . '&copy; ' . $year . ' ' . $esc($siteName) . ' &middot; <a href="' . $esc($siteUrl) . '" style="color:#64748b;">' . $esc(preg_replace('~^https?://~', '', $siteUrl) ?? $siteUrl) . '</a>'
        . ' &middot; This is an automated message; replies are not monitored.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

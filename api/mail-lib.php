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

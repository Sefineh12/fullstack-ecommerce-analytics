<?php
declare(strict_types=1);

// Sends mail through Gmail over SSL (port 465). Needs extension=openssl (built into the Docker image).
// Secrets come from environment variables: GMAIL_USER, GMAIL_APP_PASSWORD, MAIL_FROM_NAME.

function new_token(): string
{
    return bin2hex(random_bytes(32));
}

function site_url(): string
{
    // BASE_URL wins. Render also provides RENDER_EXTERNAL_URL. The last value is for local testing only.
    return rtrim(setting('BASE_URL', setting('RENDER_EXTERNAL_URL', 'http://localhost:8000')), '/');
}

function send_link(string $to, string $subject, string $intro, string $path): void
{
    $link = site_url() . $path;
    if (!send_mail($to, $subject, "$intro\n\n$link\n\nIf you did not ask for this, ignore this email.")) {
        error_log("Email not sent. Test link for $to: $link"); // shows in the server log while testing
    }
}

function send_mail(string $to, string $subject, string $text): bool
{
    $user = getenv('GMAIL_USER');
    $password = getenv('GMAIL_APP_PASSWORD');
    if (!$user || !$password || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $socket = @stream_socket_client('ssl://smtp.gmail.com:465', $errno, $error, 15);
    if (!$socket) {
        return false;
    }
    $reply = function () use ($socket): string {
        $all = '';
        while (($line = fgets($socket, 515)) !== false) {
            $all .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $all;
    };
    $step = function (string $command, string $expect) use ($socket, $reply): bool {
        fwrite($socket, $command . "\r\n");
        return str_starts_with($reply(), $expect);
    };
    $reply(); // greeting
    $sent = $step('EHLO localhost', '250')
        && $step('AUTH LOGIN', '334')
        && $step(base64_encode($user), '334')
        && $step(base64_encode(str_replace(' ', '', $password)), '235')
        && $step('MAIL FROM:<' . $user . '>', '250')
        && $step('RCPT TO:<' . $to . '>', '250')
        && $step('DATA', '354');
    if ($sent) {
        $headers = [
            'From: ' . setting('MAIL_FROM_NAME', 'Sefineh Electronics') . ' <' . $user . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $body = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $text));
        $sent = $step(implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.", '250');
    }
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return $sent;
}
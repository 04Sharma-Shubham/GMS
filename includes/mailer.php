<?php

declare(strict_types=1);

function send_app_mail(string $to, string $subject, string $html): bool
{
    $from = (string) env('MAIL_FROM', '');
    $fromName = (string) env('MAIL_FROM_NAME', 'GMS');
    $logged = false;
    if (env('APP_ENV', 'production') === 'local') {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . '/mail.log', gmdate('c') . " TO {$to} | {$subject}\n{$html}\n\n", FILE_APPEND);
        $logged = true;
    }
    $host = (string) env('MAIL_HOST', '');
    if ($host !== '' && class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int) env('MAIL_PORT', '587');
            $mail->SMTPAuth = (string) env('MAIL_USER', '') !== '';
            $mail->Username = (string) env('MAIL_USER', '');
            $mail->Password = (string) env('MAIL_PASS', '');
            $encryption = (string) env('MAIL_ENCRYPTION', 'tls');
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from !== '' ? $from : (string) env('MAIL_USER'), $fromName);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = trim(html_to_text($html));
            $mail->send();
            return true;
        } catch (Throwable $e) {
            app_log('error', 'SMTP send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    if ($from !== '' && function_exists('mail')) {
        $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: {$fromName} <{$from}>\r\n";
        return @mail($to, $subject, $html, $headers);
    }
    return $logged && env('APP_ENV') === 'local';
}

function html_to_text(string $html): string
{
    return trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
}

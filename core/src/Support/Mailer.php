<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\App;
use Throwable;

/**
 * Sends plain-text e-mails whose wording lives in the theme: a mail is the
 * template "emails/<name>.txt.twig" (or "@<ext id>/emails/<name>.txt.twig")
 * with the blocks "subject" and "body". Code only supplies the recipient
 * and the data.
 *
 * Transport "mail" hands the message to PHP's mail(); "log" appends it to
 * a file instead (the default while APP_ENV is "dev", so local work never
 * sends anything). The transport is the one place to extend for SMTP.
 */
final class Mailer
{
    public function __construct(private App $app)
    {
    }

    /** @param array<string, mixed> $data */
    public function send(string $to, string $template, array $data = []): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        try {
            $view = $this->app->view();
            $subject = trim($view->renderBlock($template, 'subject', $data));
            $body = trim($view->renderBlock($template, 'body', $data)) . "\n";
        } catch (Throwable $e) {
            error_log('Mail template ' . $template . ' failed: ' . $e);

            return false;
        }

        // A line break in a header would let its content add headers.
        $subject = (string) preg_replace('/[\r\n]+/', ' ', $subject);
        $config = $this->app->config['mail'];

        if ($config['transport'] === 'log') {
            $entry = "To: {$to}\nSubject: {$subject}\n\n{$body}\n--\n";

            return file_put_contents($config['log_path'], $entry, FILE_APPEND | LOCK_EX) !== false;
        }

        $headers = [
            'From' => self::encodeHeader($this->app->config['app']['name']) . ' <' . $config['from'] . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ];

        $sent = mail($to, self::encodeHeader($subject), str_replace("\n", "\r\n", $body), $headers);
        if (!$sent) {
            error_log('mail() refused the message "' . $template . '"');
        }

        return $sent;
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}

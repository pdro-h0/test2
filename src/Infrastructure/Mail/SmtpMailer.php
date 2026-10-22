<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Application\Port\Mailer;
use App\Domain\Batch;
use App\Domain\Exception\PermanentDeliveryException;
use App\Domain\Exception\TransientDeliveryException;
use InvalidArgumentException;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer implements Mailer {
    private const TIMEOUT_SECONDS = 30;

    private readonly string $secure;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $user,
        private readonly ?string $password,
        string $encryption,
    ) {
        $this->secure = match ($encryption) {
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'none' => '',
            default => throw new InvalidArgumentException(sprintf('Invalid SMTP encryption: %s', $encryption)),
        };
    }

    public function send(Batch $batch, string $recipient): void {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->Timeout = self::TIMEOUT_SECONDS;
            $mail->SMTPSecure = $this->secure;
            $mail->SMTPAuth = $this->user !== null;
            $mail->Username = (string) $this->user;
            $mail->Password = (string) $this->password;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($batch->fromEmail);
            $mail->addAddress($recipient);
            $mail->Subject = $batch->subject;
            $mail->isHTML(true);
            $mail->Body = $batch->html;
            $mail->AltBody = $batch->text;
            $mail->send();
        } catch (PhpMailerException) {
            $reply = $mail->getSMTPInstance()->getError()['smtp_code'] ?? null;
            $smtpCode = is_string($reply) ? $reply : null;
            $message = $smtpCode === null || $smtpCode === '' ? 'SMTP delivery failed' : sprintf('SMTP %s', $smtpCode);

            throw SmtpError::isPermanent($smtpCode)
                ? new PermanentDeliveryException($message)
                : new TransientDeliveryException($message);
        }
    }
}

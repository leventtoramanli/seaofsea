<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/../../vendor/autoload.php'; // PHPMailer autoload

class MailHandler {
    private $mailer;
    private static $logger;

    public function __construct() {
        $this->mailer = new PHPMailer(true);

        // Logger yapılandırması
        if (!self::$logger) {
            self::$logger = new Logger('mailer');
            self::$logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/mail.log', Logger::ERROR));
        }

        try {
            // SMTP yapılandırması
            $this->mailer->isSMTP();
            $this->mailer->Host = $_ENV['MAIL_HOST'] ?: 'mail.seaofsea.com'; // ENV'den al
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $_ENV['MAIL_USERNAME'] ?: 'no-reply@seaofsea.com'; // ENV'den al
            $this->mailer->Password = $_ENV['MAIL_PASSWORD'] ?: 'r*X4N*U}]W~c'; // ENV'den al
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $this->mailer->Port = $_ENV['MAIL_PORT']; // ENV'den al

            // Gönderen bilgileri
            $this->mailer->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
        } catch (Exception $e) {
            $this->logError($e, 'Mail configuration failed');
            throw new Exception('Mail configuration failed.');
        }
    }

    public function sendMail($to, $subject, $body, $isHtml = true) {
        try {
            // Alıcı bilgilerini ekle
            $this->mailer->clearAddresses(); // Önceki adresleri temizle
            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;

            // Gövdeyi ayarla
            if ($isHtml) {
                $this->mailer->isHTML(true);
                $this->mailer->Body = $body;
                $this->mailer->AltBody = strip_tags($body); // HTML olmayan alternatif
            } else {
                $this->mailer->isHTML(false);
                $this->mailer->Body = $body;
            }

            // Maili gönder
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            $this->logError($e, 'Mail sending failed');
            return false;
        }
    }

    // Hata loglama
    private function logError($exception, $message) {
        self::$logger->error($message, ['exception' => $exception]);
    }
}

<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

// PHPMailer loader — phase 1 of the Composer extraction (audit B.10/E.5.2):
//
// Prefer the Composer autoloader when the vendor tree is healthy. We check
// the inner autoload_real.php instead of just vendor/autoload.php because
// some checkouts have the wrapper file but a partially-stripped composer
// state directory — requiring the wrapper in that case fatals at file load
// time, which is uncatchable.
//
// Fallback path keeps the legacy vendored copy at phpmailer/ working until
// `composer install` is verified on every deploy host. Once that's done,
// the fallback block and the phpmailer/ directory itself can be deleted in
// a follow-up commit.
$cleanmenuComposerAutoload    = __DIR__ . '/vendor/autoload.php';
$cleanmenuComposerHealthProbe = __DIR__ . '/vendor/composer/autoload_real.php';
if (is_file($cleanmenuComposerAutoload) && is_file($cleanmenuComposerHealthProbe)) {
    require_once $cleanmenuComposerAutoload;
}
unset($cleanmenuComposerAutoload, $cleanmenuComposerHealthProbe);

if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';
    require_once __DIR__ . '/phpmailer/Exception.php';
}

use PHPMailer\PHPMailer\PHPMailer;
//use PHPMailer\PHPMailer\Exception;
//use PHPMailer\PHPMailer\SMTP;

class Mailer {
    private $mail;
    private $appName = 'labus';

    public function __construct() {
        $this->mail = new PHPMailer(true);
        $this->configure();
    }

    private function configure() {
        // Настройки SMTP
        $this->mail->isSMTP();
        $this->mail->Host = 'smtp.yandex.ru';
        $this->mail->SMTPAuth = true;
        $this->mail->Username = 'fruslanj@yandex.ru'; // Ваш полный email
        $this->mail->Password = 'hfwufqhckggplxvk'; // Пароль приложения
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $this->mail->Port = 465;
        $this->mail->CharSet = 'UTF-8';
        $this->appName = Database::getInstance()->getSetting('app_name') ?: 'labus';
        $this->mail->setFrom('fruslanj@yandex.ru', $this->appName);
    }

    public function sendVerificationEmail($email, $name, $token, ?string $baseUrl = null) {
        try {
            $this->mail->addAddress($email, $name);
            $this->mail->Subject = 'Подтверждение регистрации';
            
            // HTML-версия письма
            $baseUrl = rtrim($baseUrl ?: tenant_base_url(true), '/');
            $verificationLink = ($baseUrl !== '' ? $baseUrl : tenant_base_url()) . '/verify.php?token=' . rawurlencode((string)$token);
            $this->mail->isHTML(true);
            $this->mail->Body = "
                <h2>Здравствуйте, $name!</h2>
                <p>Благодарим вас за регистрацию в сервисе «{$this->appName}».</p>
                <p>Для завершения регистрации, пожалуйста, подтвердите ваш email, перейдя по ссылке:</p>
                <p><a href='$verificationLink'>$verificationLink</a></p>
                <p>Ссылка действительна в течение 24 часов.</p>
                <p>Если вы не регистрировались у нас, просто проигнорируйте это письмо.</p>
            ";
            
            // Текстовая версия для клиентов без поддержки HTML
            $this->mail->AltBody = "Здравствуйте, $name!\n\nБлагодарим вас за регистрацию в сервисе «{$this->appName}».\n\nДля завершения регистрации, пожалуйста, подтвердите ваш email, перейдя по ссылке:\n$verificationLink\n\nСсылка действительна в течение 24 часов.\n\nЕсли вы не регистрировались у нас, просто проигнорируйте это письмо.";
            
            $this->mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mail Error: ".$e->getMessage());
            return false;
        }
    }

public function sendPasswordResetEmail($email, $name, $token, ?string $baseUrl = null) {
    try {
        $this->mail->addAddress($email, $name);
        $this->mail->Subject = 'Сброс пароля';
        
        // HTML-версия письма
        $baseUrl = rtrim($baseUrl ?: tenant_base_url(true), '/');
        $resetLink = ($baseUrl !== '' ? $baseUrl : tenant_base_url()) . '/password-reset.php?token=' . rawurlencode((string)$token);
        $this->mail->isHTML(true);
        $this->mail->Body = "
            <h2>Здравствуйте, $name!</h2>
            <p>Мы получили запрос на сброс пароля для вашего аккаунта.</p>
            <p>Для установки нового пароля перейдите по ссылке:</p>
            <p><a href='$resetLink'>$resetLink</a></p>
            <p>Ссылка действительна в течение 1 часа.</p>
            <p>Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо.</p>
        ";
        
        // Текстовая версия
        $this->mail->AltBody = "Здравствуйте, $name!\n\nМы получили запрос на сброс пароля для вашего аккаунта.\n\nДля установки нового пароля перейдите по ссылке:\n$resetLink\n\nСсылка действительна в течение 1 часа.\n\nЕсли вы не запрашивали сброс пароля, просто проигнорируйте это письмо.";
        
        $this->mail->send();

        return true;
    } catch (Exception $e) {
        error_log("Mail Error: ".$e->getMessage());
        return false;
    }
}

    public function sendEmail($to, $subject, $body) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($to);
            $this->mail->Subject = $subject;
            $this->mail->isHTML(true);
            $this->mail->Body = $body;
            $this->mail->AltBody = strip_tags($body);
            $this->mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mail Error: " . $e->getMessage());
            return false;
        }
    }
}

function sendEmail($to, $subject, $body) {
    $mailer = new Mailer();
    return $mailer->sendEmail($to, $subject, $body);
}
?>
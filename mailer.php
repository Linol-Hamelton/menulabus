<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
//use PHPMailer\PHPMailer\Exception;
//use PHPMailer\PHPMailer\SMTP;

class Mailer {
    private $mail;

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
        $this->mail->setFrom('fruslanj@yandex.ru', 'labus');
    }

    public function sendVerificationEmail($email, $name, $token) {
        try {
            $this->mail->addAddress($email, $name);
            $this->mail->Subject = 'Подтверждение регистрации';
            
            // HTML-версия письма
            $verificationLink = "https://menu.labus.pro/verify.php?token=$token";
            $this->mail->isHTML(true);
            $this->mail->Body = "
                <h2>Здравствуйте, $name!</h2>
                <p>Благодарим вас за регистрацию в сервисе «labus».</p>
                <p>Для завершения регистрации, пожалуйста, подтвердите ваш email, перейдя по ссылке:</p>
                <p><a href='$verificationLink'>$verificationLink</a></p>
                <p>Ссылка действительна в течение 24 часов.</p>
                <p>Если вы не регистрировались у нас, просто проигнорируйте это письмо.</p>
            ";
            
            // Текстовая версия для клиентов без поддержки HTML
            $this->mail->AltBody = "Здравствуйте, $name!\n\nБлагодарим вас за регистрацию в сервисе «labus».\n\nДля завершения регистрации, пожалуйста, подтвердите ваш email, перейдя по ссылке:\n$verificationLink\n\nСсылка действительна в течение 24 часов.\n\nЕсли вы не регистрировались у нас, просто проигнорируйте это письмо.";
            
            $this->mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mail Error: ".$e->getMessage());
            return false;
        }
    }

public function sendPasswordResetEmail($email, $name, $token) {
    try {
        $this->mail->addAddress($email, $name);
        $this->mail->Subject = 'Сброс пароля';
        
        // HTML-версия письма
        $resetLink = "https://menu.labus.pro/password-reset.php?token=$token";
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
}
?>
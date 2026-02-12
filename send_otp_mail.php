<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

function sendOtpEmail($email, $otp, $type, $ref)
{
    switch ($type) {
        case 'forgot_password':
            $title = "รีเซ็ตรหัสผ่านของคุณ";
            $desc  = "คุณได้ร้องขอการรีเซ็ตรหัสผ่าน กรุณาใช้รหัส OTP ด้านล่าง";
            break;

        case 'change_email':
            $title = "ยืนยันการเปลี่ยนอีเมล";
            $desc  = "กรุณาใช้รหัส OTP เพื่อยืนยันการเปลี่ยนอีเมล";
            break;

        case 'first_login':
            $title = "ตั้งรหัสผ่านครั้งแรก";
            $desc  = "กรุณาใช้รหัส OTP เพื่อยืนยันตัวตน";
            break;

        default:
            $title = "รหัสยืนยันตัวตน";
            $desc  = "กรุณาใช้รหัส OTP ด้านล่าง";
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'tax_finance@sesalpglpn.go.th';
        $mail->Password   = 'hgtxhqcpmmnuahng';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('tax_finance@sesalpglpn.go.th', 'ระบบภาษี SESALPGLPN');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "รหัส OTP (Ref: $ref)";

        $mail->Body = "
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: Arial; background:#f4f4f4; padding:20px;'>
            <div style='max-width:600px;margin:auto;background:#fff;border-radius:10px;overflow:hidden'>
                <div style='background:#4b0082;padding:20px;text-align:center'>
                    <h2 style='color:#fff;margin:0'>ระบบภาษี SESALPGLPN</h2>
                </div>
                <div style='padding:30px;text-align:center'>
                    <h3>$title</h3>
                    <p>$desc</p>
                    <div style='margin:25px 0'>
                        <span style='display:inline-block;background:#2d0033;color:#fff;
                        padding:15px 35px;font-size:26px;letter-spacing:4px;border-radius:8px'>
                        $otp
                        </span>
                    </div>
                    <p style='color:#777'>รหัสอ้างอิง: $ref</p>
                    <p style='color:#999'>รหัสนี้มีอายุ 5 นาที</p>
                </div>
                <div style='background:#eee;padding:10px;text-align:center;font-size:12px;color:#666'>
                    © ระบบภาษี SESALPGLPN
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

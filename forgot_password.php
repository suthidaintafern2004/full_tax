<?php
session_start();
require_once 'config.php';
require_once 'send_otp_mail.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// ตรวจสอบการตั้งค่า OTP
$use_otp = true;
$settings_file = 'system_settings.json';
if (file_exists($settings_file)) {
    $data = json_decode(file_get_contents($settings_file), true);
    if (isset($data['otp_enabled'])) $use_otp = ($data['otp_enabled'] == '1');
}

if (!$use_otp) {
    die('<div class="container mt-5 text-center"><div class="alert alert-danger">ระบบกู้คืนรหัสผ่านปิดใช้งานอยู่</div><a href="login.php">กลับหน้าเข้าสู่ระบบ</a></div>');
}

$error = "";
$success = "";
$show_otp_modal = false;
$step = $_SESSION['fp_step'] ?? 'check_user';

// 1. ขั้นตอนส่ง OTP
if (isset($_POST['action']) && $_POST['action'] == 'request_otp') {
    $pid = mysqli_real_escape_string($conn, $_POST['pid']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    // ตรวจสอบว่าเลขบัตรและอีเมลตรงกับในระบบไหม
    $sql = "SELECT m.email FROM users u JOIN member m ON u.username = m.pid WHERE u.username = ? AND m.email = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $pid, $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {
        $otp_code = rand(100000, 999999);
        $ref_code = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ"), 0, 4);

        $_SESSION['fp_data'] = [
            'pid' => $pid,
            'email' => $email,
            'code' => $otp_code,
            'ref' => $ref_code,
            'expiry' => time() + 300
        ];

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

            $mail->setFrom('tax_finance@sesalpglpn.go.th', 'ระบบกู้คืนรหัสผ่าน SESALPGLPN');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = "รหัสยืนยันการเปลี่ยนรหัสผ่าน (Ref: $ref_code)";
            $mail->Body    = "รหัสยืนยันของคุณคือ: <h2 style='color:red;'>$otp_code</h2>";

            if ($mail->send()) {
                $show_otp_modal = true;
            }
        } catch (Exception $e) {
            $error = "ส่งอีเมลไม่สำเร็จ: " . $mail->ErrorInfo;
        }
    } else {
        $error = "ข้อมูลเลขบัตรประชาชนหรืออีเมลไม่ถูกต้อง";
    }
}

// 2. ขั้นตอนยืนยัน OTP
if (isset($_POST['action']) && $_POST['action'] == 'verify_otp') {
    $input_otp = implode('', $_POST['otp_digits']);
    $fp_data = $_SESSION['fp_data'] ?? null;

    if ($fp_data && $input_otp == $fp_data['code']) {
        $_SESSION['fp_step'] = 'reset_password';
        $step = 'reset_password';
    } else {
        $error = "รหัส OTP ไม่ถูกต้อง";
        $show_otp_modal = true;
    }
}

// 3. ขั้นตอนบันทึกรหัสผ่านใหม่
if (isset($_POST['action']) && $_POST['action'] == 'save_password') {
    $new_pass = $_POST['new_password'];
    $conf_pass = $_POST['confirm_password'];
    $pid = $_SESSION['fp_data']['pid'];

    if ($new_pass === $conf_pass && strlen($new_pass) >= 8) {
        $stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE username = ?");
        mysqli_stmt_bind_param($stmt, "ss", $new_pass, $pid);
        if (mysqli_stmt_execute($stmt)) {
            $success = "เปลี่ยนรหัสผ่านสำเร็จแล้ว!";
            unset($_SESSION['fp_data']);
            unset($_SESSION['fp_step']);
            echo "<script>setTimeout(function(){ window.location.href='login.php'; }, 2000);</script>";
        }
    } else {
        $error = "รหัสผ่านไม่ตรงกันหรือสั้นเกินไป";
    }
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>กู้คืนรหัสผ่าน - SESALPGLPN</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Sarabun', sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
        }

        .fp-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .fp-header {
            background: #eeb42a;
            color: white;
            padding: 30px;
            text-align: center;
        }

        .btn-gold {
            background: #eeb42a;
            color: white;
            border-radius: 50px;
            font-weight: 600;
            padding: 12px;
            border: none;
        }

        .btn-gold:hover {
            background: #d4a017;
            color: white;
        }

        .otp-digit {
            width: 45px;
            height: 50px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin: 0 5px;
        }

        .otp-digit:focus {
            border-color: #eeb42a;
            outline: none;
            box-shadow: 0 0 5px rgba(238, 180, 42, 0.3);
        }
    </style>
</head>

<body>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card fp-card">
                    <div class="fp-header">
                        <h4 class="mb-0 fw-bold">กู้คืนรหัสผ่าน</h4>
                        <small>ระบบจัดการภาษี สพม.ลำปาง ลำพูน</small>
                    </div>
                    <div class="card-body p-4">

                        <?php if ($error && !$show_otp_modal): ?> <div class="alert alert-danger small py-2"><?php echo $error; ?></div> <?php endif; ?>
                        <?php if ($success): ?> <div class="alert alert-success small py-2"><?php echo $success; ?></div> <?php endif; ?>

                        <?php if ($step == 'check_user'): ?>
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">เลขบัตรประชาชน</label>
                                    <input type="text" name="pid" class="form-control rounded-pill" required placeholder="กรอกเลขบัตรประชาชน">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">อีเมลที่ลงทะเบียนไว้</label>
                                    <input type="email" name="email" class="form-control rounded-pill" required placeholder="example@mail.com">
                                </div>
                                <button type="submit" name="action" value="request_otp" class="btn btn-gold w-100 shadow-sm mt-3">ขอรับรหัส OTP</button>
                            </form>

                        <?php elseif ($step == 'reset_password'): ?>
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">รหัสผ่านใหม่</label>
                                    <input type="password" name="new_password" class="form-control rounded-pill" required placeholder="อย่างน้อย 8 ตัวอักษร">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">ยืนยันรหัสผ่านใหม่</label>
                                    <input type="password" name="confirm_password" class="form-control rounded-pill" required placeholder="กรอกรหัสผ่านอีกครั้ง">
                                </div>
                                <button type="submit" name="action" value="save_password" class="btn btn-gold w-100 shadow-sm">บันทึกรหัสผ่านใหม่</button>
                            </form>
                        <?php endif; ?>

                        <div class="text-center mt-4">
                            <a href="login.php" class="text-muted small text-decoration-none"><i class="fas fa-arrow-left me-1"></i> กลับหน้าเข้าสู่ระบบ</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="otpModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0" style="border-radius: 20px;">
                <div class="modal-body p-5 text-center">
                    <div class="mb-3"><i class="fas fa-envelope-open-text fa-3x text-warning"></i></div>
                    <h5 class="fw-bold">กรอกรหัสยืนยัน OTP</h5>
                    <p class="text-muted small">รหัสถูกส่งไปที่อีเมลของคุณแล้ว<br>Ref: <span class="text-danger fw-bold"><?php echo $_SESSION['fp_data']['ref'] ?? ''; ?></span></p>

                    <?php if ($error && $show_otp_modal): ?> <p class="text-danger small mb-3"><?php echo $error; ?></p> <?php endif; ?>

                    <form method="POST">
                        <div class="d-flex justify-content-center mb-4">
                            <?php for ($i = 0; $i < 6; $i++): ?>
                                <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" pattern="\d*" inputmode="numeric" required>
                            <?php endfor; ?>
                        </div>
                        <button type="submit" name="action" value="verify_otp" class="btn btn-gold w-100 py-2 mb-3">ตรวจสอบรหัส</button>
                        <div><a href="forgot_password.php?reset=1" class="text-muted small">เปลี่ยนอีเมลหรือยกเลิก</a></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // สั่งเปิด Modal
        <?php if ($show_otp_modal): ?>
            document.addEventListener("DOMContentLoaded", function() {
                var myModal = new bootstrap.Modal(document.getElementById('otpModal'));
                myModal.show();
            });
        <?php endif; ?>

        // Auto Tab สำหรับช่อง OTP
        const inputs = document.querySelectorAll('.otp-digit');
        inputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                if (e.target.value.length === 1 && index < inputs.length - 1) inputs[index + 1].focus();
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && e.target.value.length === 0 && index > 0) inputs[index - 1].focus();
            });
        });
    </script>

</body>

</html>
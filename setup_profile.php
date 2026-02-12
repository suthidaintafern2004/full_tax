<?php
session_start();
require_once 'config.php';

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

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$error = "";
$success = "";
$show_otp_modal = false; // ตัวแปรควบคุมการเปิด Modal

// 1. ประมวลผลเมื่อกดขอ OTP
if ($use_otp && isset($_POST['action']) && $_POST['action'] == 'request_otp') {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error = "รหัสผ่านใหม่ไม่ตรงกัน";
    } elseif (strlen($new_password) < 8) {
        $error = "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร";
    } else {
        $otp_code = rand(100000, 999999);
        $ref_code = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ"), 0, 4);

        $_SESSION['otp_data'] = [
            'code' => $otp_code,
            'email' => $email,
            'new_pass' => $new_password,
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

            $mail->setFrom('tax_finance@sesalpglpn.go.th', 'ระบบภาษี SESALPGLPN');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = "รหัส OTP ของคุณคือ $otp_code (Ref: $ref_code)";
            $mail->Body    = "รหัสยืนยันคือ: <b style='font-size:24px; color:red;'>$otp_code</b>";

            if ($mail->send()) {
                $show_otp_modal = true; // ตั้งค่าให้เด้ง Modal เมื่อส่งเมลสำเร็จ
            }
        } catch (Exception $e) {
            $error = "ส่งอีเมลไม่สำเร็จ: " . $mail->ErrorInfo;
        }
    }
}

// 2. ประมวลผลเมื่อกรอก OTP และกดยืนยัน (Verify)
if ($use_otp && isset($_POST['action']) && $_POST['action'] == 'verify_otp') {
    $input_otp = implode('', $_POST['otp_digits']);
    $otp_data = $_SESSION['otp_data'] ?? null;

    if ($otp_data && $input_otp == $otp_data['code']) {
        mysqli_begin_transaction($conn);
        try {
            $stmt1 = mysqli_prepare($conn, "UPDATE users SET password = ?, is_first_login = 0 WHERE username = ?");
            mysqli_stmt_bind_param($stmt1, "ss", $otp_data['new_pass'], $username);
            mysqli_stmt_execute($stmt1);

            $stmt2 = mysqli_prepare($conn, "UPDATE member SET email = ? WHERE pid = ?");
            mysqli_stmt_bind_param($stmt2, "ss", $otp_data['email'], $username);
            mysqli_stmt_execute($stmt2);

            mysqli_commit($conn);
            unset($_SESSION['otp_data']);
            $success = "ตั้งค่าสำเร็จแล้ว! ระบบกำลังพาคุณไปหน้าหลัก...";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
        }
    } else {
        $error = "รหัส OTP ไม่ถูกต้อง";
        $show_otp_modal = true; // ถ้าผิด ให้ค้างหน้า Modal ไว้ให้กรอกใหม่
    }
}

// 3. กรณีปิด OTP: บันทึกรหัสผ่านทันที (ตรวจสอบรหัสเดิม)
if (!$use_otp && isset($_POST['action']) && $_POST['action'] == 'save_password_no_otp') {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // ตรวจสอบรหัสผ่านเดิม
    $sql_check = "SELECT password FROM users WHERE username = ?";
    $stmt_check = mysqli_prepare($conn, $sql_check);
    mysqli_stmt_bind_param($stmt_check, "s", $username);
    mysqli_stmt_execute($stmt_check);
    $res_check = mysqli_stmt_get_result($stmt_check);
    $row_check = mysqli_fetch_assoc($res_check);

    if ($row_check['password'] !== $old_password) {
        $error = "รหัสผ่านเดิมไม่ถูกต้อง";
    } elseif ($new_password !== $confirm_password) {
        $error = "รหัสผ่านใหม่ไม่ตรงกัน";
    } elseif (strlen($new_password) < 8) {
        $error = "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร";
    } else {
        // อัปเดตรหัสผ่านและปลดล็อก is_first_login
        $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, is_first_login = 0 WHERE username = ?");
        mysqli_stmt_bind_param($stmt, "ss", $new_password, $username);
        
        if (mysqli_stmt_execute($stmt)) {
            $success = "ตั้งค่าสำเร็จแล้ว! ระบบกำลังพาคุณไปหน้าหลัก...";
        } else {
            $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>Setup Profile - OTP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f4f7f6;
            font-family: 'Sarabun', sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .btn-request {
            background: #ff5e5e;
            color: white;
            border-radius: 50px;
            padding: 10px;
            border: none;
            font-weight: 600;
        }

        .btn-request:hover {
            background: #e04b4b;
        }

        /* สไตล์ช่อง OTP แบบในรูป */
        .otp-digit {
            width: 45px;
            height: 50px;
            border: 1px solid #ced4da;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin: 0 5px;
            border-radius: 5px;
        }

        .otp-digit:focus {
            border-color: #ff5e5e;
            outline: none;
            box-shadow: 0 0 5px rgba(255, 94, 94, 0.3);
        }

        .modal-content {
            border-radius: 20px;
            border: none;
        }

        .btn-verify {
            background: #ff5e5e;
            color: white;
            border-radius: 8px;
            width: 150px;
            padding: 10px;
            border: none;
        }
    </style>
</head>

<body>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card p-4">
                    <h4 class="text-center mb-4 fw-bold">ตั้งค่าบัญชีครั้งแรก</h4>

                    <?php if ($error && !$show_otp_modal): ?> <div class="alert alert-danger small"><?php echo $error; ?></div> <?php endif; ?>

                    <form method="POST">
                        <?php if ($use_otp): ?>
                        <div class="mb-3">
                            <label class="form-label small">อีเมลสำหรับรับรหัส OTP</label>
                            <input type="email" name="email" class="form-control" placeholder="example@mail.com" required value="<?php echo $_POST['email'] ?? ''; ?>">
                        </div>
                        <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label small">รหัสผ่านเดิม</label>
                            <input type="password" name="old_password" class="form-control" placeholder="รหัสผ่านปัจจุบัน" required>
                        </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label small">รหัสผ่านใหม่</label>
                            <input type="password" name="new_password" class="form-control" placeholder="รหัสผ่านใหม่ (8 ตัวขึ้นไป)" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small">ยืนยันรหัสผ่านใหม่</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="ยืนยันรหัสผ่านอีกครั้ง" required>
                        </div>
                        <?php if ($use_otp): ?>
                        <button type="submit" name="action" value="request_otp" class="btn btn-request w-100 shadow-sm">
                            ส่งรหัส OTP ไปยังอีเมล
                        </button>
                        <?php else: ?>
                        <button type="submit" name="action" value="save_password_no_otp" class="btn btn-request w-100 shadow-sm">
                            ยืนยันการแก้ไข
                        </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="otpModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-body p-5 text-center">
                    <div class="mb-3"><img src="https://cdn-icons-png.flaticon.com/512/3064/3064155.png" width="60"></div>

                    <h5 class="fw-bold">Enter your OTP code<br>to sign in.</h5>
                    <p class="text-muted small mb-4">Ref Code: <span class="text-danger fw-bold"><?php echo $_SESSION['otp_data']['ref'] ?? ''; ?></span></p>

                    <?php if ($error && $show_otp_modal): ?> <p class="text-danger small"><?php echo $error; ?></p> <?php endif; ?>

                    <form method="POST">
                        <div class="d-flex justify-content-center mb-4">
                            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required>
                            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required>
                            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required>
                            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required>
                            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required>
                            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required>
                        </div>

                        <button type="submit" name="action" value="verify_otp" class="btn btn-verify fw-bold">Verify</button>

                        <div class="mt-3">
                            <a href="setup_profile.php" class="text-muted small text-decoration-none">ยกเลิกและแก้ไขข้อมูล</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal แจ้งเตือน (Success) -->
    <div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-center p-5 border-0 shadow">
                <div class="mb-4" id="modalIcon"></div>
                <h3 id="modalTitle" class="fw-bold text-dark mb-2"></h3>
                <p id="modalMessage" class="text-muted fs-5"></p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ส่วนสำคัญ: สั่งให้ Modal เด้งด้วย JavaScript
        <?php if ($show_otp_modal): ?>
            document.addEventListener("DOMContentLoaded", function() {
                var myModal = new bootstrap.Modal(document.getElementById('otpModal'));
                myModal.show();
            });
        <?php endif; ?>

        // แสดง Popup แจ้งเตือนถ้ามี success
        <?php if ($success): ?>
            var notifModal = new bootstrap.Modal(document.getElementById('notificationModal'));
            document.getElementById('modalIcon').innerHTML = '<div class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-check text-success fa-3x"></i></div>';
            document.getElementById('modalTitle').innerText = 'ดำเนินการสำเร็จ';
            document.getElementById('modalMessage').innerText = '<?php echo $success; ?>';
            notifModal.show();
            setTimeout(function(){ window.location.href='index.php'; }, 3000);
        <?php endif; ?>

        // ระบบเลื่อนช่องกรอกอัตโนมัติ (Auto Focus)
        const inputs = document.querySelectorAll('.otp-digit');
        inputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                if (e.target.value.length === 1 && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && e.target.value.length === 0 && index > 0) {
                    inputs[index - 1].focus();
                }
            });
        });
    </script>

</body>

</html>
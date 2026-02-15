<?php
session_start();
require_once 'config.php';
require_once 'send_otp_mail.php';

$step = $_SESSION['fp_step'] ?? 'check_pid';
$error = "";
$success = "";

if (isset($_GET['reset'])) {
    session_destroy();
    header("Location: forgot_password.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if ($_POST['action'] == 'check_pid') {
        $pid = trim($_POST['pid']);
        $stmt = mysqli_prepare($conn, "SELECT email FROM member WHERE pid=?");
        mysqli_stmt_bind_param($stmt, "s", $pid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($res)) {
            $_SESSION['fp_data']['pid'] = $pid;
            if ($row['email']) {
                $otp = rand(100000, 999999);
                $ref = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ"), 0, 4);
                sendOtpEmail($row['email'], $otp, 'forgot_password', $ref);
                $_SESSION['fp_data'] += ['otp' => $otp, 'ref' => $ref, 'expiry' => time() + 300];
                $_SESSION['fp_step'] = 'verify_otp';
                header("Location: forgot_password.php");
                exit();
            } else {
                $_SESSION['fp_step'] = 'input_email';
            }
        } else $error = "ไม่พบผู้ใช้";
    } elseif ($_POST['action'] == 'send_otp_new') {
        $email = $_POST['email'];
        $otp = rand(100000, 999999);
        $ref = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ"), 0, 4);
        sendOtpEmail($email, $otp, 'forgot_password', $ref);
        $_SESSION['fp_data'] += ['email' => $email, 'otp' => $otp, 'ref' => $ref, 'expiry' => time() + 300];
        $_SESSION['fp_step'] = 'verify_otp';
        header("Location: forgot_password.php");
        exit();
    } elseif ($_POST['action'] == 'verify_otp') {
        if ($_POST['otp_input'] == $_SESSION['fp_data']['otp']) {
            $_SESSION['fp_step'] = 'reset_password';
        } else $error = "OTP ไม่ถูกต้อง";
    } elseif ($_POST['action'] == 'save_password') {
        $pid = $_SESSION['fp_data']['pid'];
        $pass = $_POST['new_password'];
        mysqli_query($conn, "UPDATE users SET password='$pass' WHERE username='$pid'");
        session_destroy();
        $success = "เปลี่ยนรหัสผ่านสำเร็จ";
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
    <link rel="stylesheet" href="css/forgot_password.css">
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
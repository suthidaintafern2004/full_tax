<?php
session_start();
require_once 'config.php';

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // ใช้ Prepared Statement เพื่อความปลอดภัย
    $sql = "SELECT * FROM users WHERE username = ? AND password = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $username, $password);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) == 1) {
        $row = mysqli_fetch_assoc($result);
        $_SESSION['username'] = $row['username'];
        header("Location: index.php");
        exit();
    } else {
        $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
    }
}

// ตรวจสอบสถานะ OTP
$use_otp = true;
$settings_file = 'system_settings.json';
if (file_exists($settings_file)) {
    $data = json_decode(file_get_contents($settings_file), true);
    if (isset($data['otp_enabled'])) $use_otp = ($data['otp_enabled'] == '1');
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบภาษี สพม.ลำปาง ลำพูน</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --main-color: #4e73df;
            --dark-color: #224abe;
        }

        body,
        html {
            height: 100%;
            margin: 0;
            font-family: 'Sarabun', sans-serif;
            overflow: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.5)),
                url('images/office.jpg') no-repeat center center fixed;
            background-size: cover;
            filter: blur(8px);
            z-index: -1;
            transform: scale(1.1);
        }

        .login-container {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            display: flex;
            width: 900px;
            max-width: 95%;
            min-height: 520px;
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
        }

        /* ฝั่งซ้าย - ข้อความต้อนรับ */
        .login-left {
            flex: 1;
            background-color: var(--main-color);
            color: white;
            padding: 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        .login-left h1 {
            font-size: 2.8rem;
            font-weight: 600;
            margin-bottom: 1.2rem;
            line-height: 1.2;
        }

        .login-left p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* ฝั่งขวา - ฟอร์มกรอกข้อมูล */
        .login-right {
            flex: 1;
            padding: 50px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .logo-img {
            width: 90px;
            margin-bottom: 15px;
        }

        .login-right h4 {
            color: var(--dark-color);
            font-weight: 600;
            margin-bottom: 25px;
            letter-spacing: 1px;
        }

        .input-group-custom {
            position: relative;
            width: 100%;
            margin-bottom: 15px;
        }

        .input-group-custom i {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--main-color);
            z-index: 10;
        }

        .form-control {
            border-radius: 50px;
            padding: 12px 20px 12px 50px;
            border: 1px solid #eee;
            background-color: #fafafa;
            font-size: 0.95rem;
        }

        .form-control:focus {
            border-color: var(--main-color);
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.1);
        }

        .btn-login {
            background-color: var(--main-color);
            border: none;
            border-radius: 50px;
            padding: 12px;
            width: 100%;
            color: white;
            font-weight: 600;
            margin-top: 10px;
            transition: all 0.3s;
        }

        .btn-login:hover {
            background-color: var(--dark-color);
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(78, 115, 223, 0.3);
        }

        .forgot-link {
            color: #888;
            font-size: 0.85rem;
            margin-top: 15px;
            text-decoration: none;
        }

        .forgot-link:hover {
            color: var(--dark-color);
        }

        @media (max-width: 768px) {
            .login-card {
                flex-direction: column;
            }

            .login-left {
                padding: 30px;
                text-align: center;
            }

            .login-left h1 {
                font-size: 2rem;
            }

            .login-right {
                padding: 40px 30px;
            }
        }
    </style>
</head>

<body>

    <div class="login-container">
        <div class="login-card">
            <div class="login-left">
                <h1>Welcome to Website</h1>
                <p>ระบบดาวน์โหลดเอกสารรับรองภาษีของบุคคลากรในเขต สพม.ลำปาง ลำพูน</p>
            </div>

            <div class="login-right">
                <img src="images/logo.png" alt="Logo" class="logo-img">
                <h4>USER LOGIN</h4>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger py-2 w-100 mb-3 small text-center" style="border-radius: 50px;">
                        <i class="fas fa-exclamation-circle me-1"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="" class="w-100">
                    <div class="input-group-custom">
                        <i class="fas fa-user-circle"></i>
                        <input type="text" name="username" class="form-control" placeholder="เลขบัตรประชาชน" required>
                    </div>

                    <div class="input-group-custom">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" class="form-control" placeholder="รหัสผ่าน" required>
                    </div>

                    <button type="submit" name="login" class="btn btn-login">Login</button>

                    <?php if ($use_otp): ?>
                        <div class="text-center">
                            <a href="forgot_password.php" class="forgot-link">ลืมรหัสผ่าน?</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
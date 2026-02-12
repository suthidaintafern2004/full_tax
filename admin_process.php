<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    $pid = $_POST['pid'];
    $prefix = $_POST['prefix'];
    $fname = $_POST['fname'];
    $lname = $_POST['lname'];
    $email = $_POST['email'];
    $role_id = $_POST['role_id']; // ตำแหน่ง (เช่น 1 หรือ 2)
    $system_role = isset($_POST['system_role']) ? $_POST['system_role'] : null; // สิทธิ์ระบบ (3 หรือ 4)
    $amount = !empty($_POST['amount_paid']) ? $_POST['amount_paid'] : 0;
    $tax = !empty($_POST['tax_withheld']) ? $_POST['tax_withheld'] : 0;

    // รหัสผ่านคือ 4 ตัวท้ายของ PID
    $password = substr($pid, -4);

    // เริ่ม Transaction เพื่อให้บันทึกสำเร็จทั้ง 2 ตารางพร้อมกัน
    mysqli_begin_transaction($conn);

    try {
        if ($action == 'add') {
            // 1. เพิ่มข้อมูลในตาราง member
            $sql1 = "INSERT INTO member (pid, prefix, fname, lname, email, role_id, amount_paid, tax_withheld) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt1 = mysqli_prepare($conn, $sql1);
            mysqli_stmt_bind_param($stmt1, "sissssdd", $pid, $prefix, $fname, $lname, $email, $role_id, $amount, $tax);
            mysqli_stmt_execute($stmt1);

            // 2. เพิ่มข้อมูลในตาราง users เพื่อให้ล็อกอินได้
            if (!empty($system_role)) {
                $sql2 = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
                $stmt2 = mysqli_prepare($conn, $sql2);
                mysqli_stmt_bind_param($stmt2, "sss", $pid, $password, $system_role);
                mysqli_stmt_execute($stmt2);
            }

            mysqli_commit($conn);
            header("Location: admin_manage.php?msg=success");
            exit();
        } else if ($action == 'edit') {
            // 1. อัปเดตข้อมูลในตาราง member
            $sql_up = "UPDATE member SET prefix=?, fname=?, lname=?, email=?, role_id=?, amount_paid=?, tax_withheld=? WHERE pid=?";
            $stmt_up = mysqli_prepare($conn, $sql_up);
            mysqli_stmt_bind_param($stmt_up, "issssdds", $prefix, $fname, $lname, $email, $role_id, $amount, $tax, $pid);
            mysqli_stmt_execute($stmt_up);

            // 2. อัปเดตสิทธิ์ในตาราง users เฉพาะเมื่อมีการเลือกสิทธิ์ใหม่
            if (!empty($system_role)) {
                $sql_up_user = "UPDATE users SET role=? WHERE username=?";
                $stmt_up_user = mysqli_prepare($conn, $sql_up_user);
                mysqli_stmt_bind_param($stmt_up_user, "ss", $system_role, $pid);
                mysqli_stmt_execute($stmt_up_user);
            }

            mysqli_commit($conn);
            header("Location: admin_manage.php?msg=success");
            exit();
        }
    } catch (Exception $e) {
        // หากเกิดข้อผิดพลาดให้ยกเลิกทั้งหมด
        mysqli_rollback($conn);
        echo "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

<?php
session_start();
require_once 'config.php';

// ตรวจสอบสิทธิ์การเข้าใช้งาน (ต้อง Login ก่อน)
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// ดึงข้อมูลสิทธิ์ผู้ใช้งาน (Role)
$user_role = 4; // ค่าเริ่มต้นเป็น User
$sql_role = "SELECT role FROM users WHERE username = ?";
$stmt_role = mysqli_prepare($conn, $sql_role);
mysqli_stmt_bind_param($stmt_role, "s", $_SESSION['username']);
mysqli_stmt_execute($stmt_role);
$result_role = mysqli_stmt_get_result($stmt_role);
if ($row_role = mysqli_fetch_assoc($result_role)) {
    $user_role = $row_role['role'];
}

$result_data = null;
$admin_result = null;
$error = "";
$display_name = $_SESSION['username']; // ค่าเริ่มต้น

if ($user_role == 3) {
    $display_name = "Admin";
    // --- ส่วนของ ADMIN ---
    // ตั้งค่า Pagination
    $limit = 50; // จำนวนรายการต่อหน้า
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $start = ($page - 1) * $limit;

    $search_term = isset($_GET['search_pid']) ? trim($_GET['search_pid']) : "";

    // Query พื้นฐาน: ใช้ UNION เพื่อรวม PID จากทั้งสองตารางเพื่อให้ได้รายชื่อครบถ้วน
    $union_subquery = "(SELECT pid FROM combined_data UNION SELECT pid FROM tax_records)";

    // เงื่อนไขการค้นหา
    $where_clause = "";
    $params = [];
    $types = "";

    if (!empty($search_term)) {
        // ค้นหาจากทั้งชื่อใน combined_data และ tax_records
        $where_clause = " WHERE main.pid LIKE ? OR c.fname LIKE ? OR c.lname LIKE ? OR t.fname LIKE ? OR t.lname LIKE ?";
        $like_term = "%" . $search_term . "%";
        $params = [$like_term, $like_term, $like_term, $like_term, $like_term];
        $types = "sssss";
    }

    // 1. หาจำนวนรายการทั้งหมด (Count)
    $sql_count = "SELECT COUNT(*) as total FROM $union_subquery AS main 
                  LEFT JOIN combined_data c ON main.pid = c.pid 
                  LEFT JOIN tax_records t ON main.pid = t.pid 
                  $where_clause";
    
    $stmt_count = mysqli_prepare($conn, $sql_count);
    if (!empty($types)) {
        mysqli_stmt_bind_param($stmt_count, $types, ...$params);
    }
    mysqli_stmt_execute($stmt_count);
    $result_count = mysqli_stmt_get_result($stmt_count);
    $row_count = mysqli_fetch_assoc($result_count);
    $total_records = $row_count['total'];
    $total_pages = ceil($total_records / $limit);

    // 2. ดึงข้อมูลมาแสดง (Data)
    $sql_data = "SELECT main.pid, 
                        COALESCE(p.prefix, p2.prefix) AS prefix_name, 
                        COALESCE(c.fname, t.fname) AS fname, 
                        COALESCE(c.lname, t.lname) AS lname, 
                        t.amount_paid, 
                        t.tax_withheld 
                 FROM $union_subquery AS main 
                 LEFT JOIN combined_data c ON main.pid = c.pid 
                 LEFT JOIN prefix p ON c.prefix = p.prefix_id 
                 LEFT JOIN tax_records t ON main.pid = t.pid 
                 LEFT JOIN prefix p2 ON t.prefix = p2.prefix_id 
                 $where_clause 
                 ORDER BY main.pid ASC 
                 LIMIT ?, ?";

    // เพิ่ม parameter สำหรับ LIMIT
    $params[] = $start;
    $params[] = $limit;
    $types .= "ii";

    $stmt = mysqli_prepare($conn, $sql_data);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $admin_result = mysqli_stmt_get_result($stmt);

} else {
    // --- ส่วนของ USER ---
    // ค้นหาข้อมูลของตัวเองเท่านั้น
    $search_pid = $_SESSION['username'];

    // ดึงข้อมูลจาก combined_data และ join กับ prefix เพื่อเอาคำนำหน้า
    // และ left join กับ tax_records เพื่อเอารายได้และภาษี (ถ้ามี)
    $sql = "SELECT c.pid, p.prefix AS prefix_name, c.fname, c.lname, t.amount_paid, t.tax_withheld 
            FROM combined_data c 
            LEFT JOIN prefix p ON c.prefix = p.prefix_id 
            LEFT JOIN tax_records t ON c.pid = t.pid 
            WHERE c.pid = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $search_pid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {
        $result_data = mysqli_fetch_assoc($result);
    } else {
        // กรณีไม่เจอใน combined_data ลองหาใน tax_records โดยตรง
        $sql = "SELECT t.pid, p.prefix AS prefix_name, t.fname, t.lname, t.amount_paid, t.tax_withheld 
                    FROM tax_records t
                    LEFT JOIN prefix p ON t.prefix = p.prefix_id
                    WHERE t.pid = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $search_pid);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) > 0) {
            $result_data = mysqli_fetch_assoc($result);
        } else {
            $error = "ไม่พบข้อมูลของคุณในระบบ";
        }
    }

    // --- ส่วนดึงข้อมูลเอกสารสำหรับ User ---
    // 1. ดึงรายการหนังสือรับรองการหักภาษี ณ ที่จ่าย (processed_PDFs) จากตาราง tax_reports
    $tax_reports_list = [];
    $sql_reports = "SELECT * FROM tax_reports WHERE pid = ? ORDER BY id ASC";
    $stmt_reports = mysqli_prepare($conn, $sql_reports);
    mysqli_stmt_bind_param($stmt_reports, "s", $search_pid);
    mysqli_stmt_execute($stmt_reports);
    $result_reports = mysqli_stmt_get_result($stmt_reports);
    while ($row = mysqli_fetch_assoc($result_reports)) {
        $tax_reports_list[] = $row;
    }

    // เรียงลำดับตาม วัน เดือน ปี (จากน้อยไปมาก)
    usort($tax_reports_list, function($a, $b) {
        $parts_a = explode('-', $a['file_name']);
        $parts_b = explode('-', $b['file_name']);

        // ตรวจสอบว่ามีข้อมูลครบถ้วนหรือไม่ (ป้องกัน error)
        if (count($parts_a) < 4 || count($parts_b) < 4) {
            return 0;
        }

        $day_a = (int)$parts_a[1];
        $month_a = (int)$parts_a[2];
        $year_a = (int)$parts_a[3];

        $day_b = (int)$parts_b[1];
        $month_b = (int)$parts_b[2];
        $year_b = (int)$parts_b[3];

        // เปรียบเทียบ ปี -> เดือน -> วัน
        if ($year_a !== $year_b) {
            return $year_a - $year_b;
        }
        if ($month_a !== $month_b) {
            return $month_a - $month_b;
        }
        return $day_a - $day_b;
    });

    // 2. ดึงเอกสารลดหย่อนภาษี (pdf_storage) จากตาราง pdf_management
    $pdf_deduction = null;
    $sql_pdf = "SELECT * FROM pdf_management WHERE pid = ?";
    $stmt_pdf = mysqli_prepare($conn, $sql_pdf);
    mysqli_stmt_bind_param($stmt_pdf, "s", $search_pid);
    mysqli_stmt_execute($stmt_pdf);
    $result_pdf = mysqli_stmt_get_result($stmt_pdf);
    if ($row = mysqli_fetch_assoc($result_pdf)) {
        $pdf_deduction = $row;
    }

    if ($result_data) {
        $prefix = $result_data['prefix_name'] ?? '';
        $display_name = $prefix . $result_data['fname'] . ' ' . $result_data['lname'];
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ค้นหารายชื่อ - ระบบภาษี</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">ระบบภาษี</a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">ผู้ใช้งาน: <?php echo htmlspecialchars($display_name); ?></span>
                <a href="logout.php" class="btn btn-danger btn-sm">ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h4 class="text-center mb-0"><?php echo ($user_role == 3) ? 'รายการข้อมูลผู้เสียภาษี' : 'ข้อมูลส่วนตัว'; ?></h4>
                    </div>
                    <div class="card-body">
                        
                        <?php if ($user_role == 3): ?>
                            <!-- ส่วนแสดงผลสำหรับ Admin -->
                            <form method="get" action="" class="mb-4">
                                <div class="input-group">
                                    <input type="text" class="form-control" name="search_pid" placeholder="ค้นหาด้วย เลขบัตรฯ หรือ ชื่อ-นามสกุล" value="<?php echo htmlspecialchars($search_term); ?>">
                                    <button class="btn btn-primary" type="submit">ค้นหา</button>
                                    <a href="index.php" class="btn btn-secondary">ล้างค่า</a>
                                </div>
                            </form>

                            <div class="mb-2 text-end text-muted">พบข้อมูลทั้งหมด <?php echo number_format($total_records); ?> รายการ</div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>เลขบัตรประชาชน</th>
                                            <th>ชื่อ-นามสกุล</th>
                                            <th class="text-end">รายได้ (บาท)</th>
                                            <th class="text-end">ภาษีที่หัก (บาท)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($admin_result && mysqli_num_rows($admin_result) > 0): ?>
                                            <?php while ($row = mysqli_fetch_assoc($admin_result)): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['pid']); ?></td>
                                                    <td>
                                                        <?php 
                                                        $prefix = $row['prefix_name'] ?? '';
                                                        echo htmlspecialchars($prefix . $row['fname'] . ' ' . $row['lname']); 
                                                        ?>
                                                    </td>
                                                    <td class="text-end text-success"><?php echo number_format($row['amount_paid'], 2); ?></td>
                                                    <td class="text-end text-danger"><?php echo number_format($row['tax_withheld'], 2); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-muted">ไม่พบข้อมูล</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <nav aria-label="Page navigation">
                                <ul class="pagination justify-content-center mt-3">
                                    <li class="page-item <?php if($page <= 1) echo 'disabled'; ?>">
                                        <a class="page-link" href="?page=<?php echo $page-1; ?>&search_pid=<?php echo urlencode($search_term); ?>">ก่อนหน้า</a>
                                    </li>
                                    
                                    <li class="page-item disabled">
                                        <span class="page-link">หน้า <?php echo $page; ?> จาก <?php echo $total_pages; ?></span>
                                    </li>

                                    <li class="page-item <?php if($page >= $total_pages) echo 'disabled'; ?>">
                                        <a class="page-link" href="?page=<?php echo $page+1; ?>&search_pid=<?php echo urlencode($search_term); ?>">ถัดไป</a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger text-center"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <?php if ($user_role != 3 && $result_data): ?>
                            <!-- ส่วนแสดงผลสำหรับ User -->
                            <div class="card mt-4 border-info">
                                <div class="card-header bg-info text-white">
                                    ข้อมูลผู้เสียภาษี
                                </div>
                                <div class="card-body">
                                    <div class="row mb-2">
                                        <div class="col-sm-4 fw-bold">ชื่อ-นามสกุล:</div>
                                        <div class="col-sm-8">
                                            <?php 
                                            $prefix = $result_data['prefix_name'] ?? '';
                                            echo htmlspecialchars($prefix . $result_data['fname'] . ' ' . $result_data['lname']); 
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <hr>
                                    
                                    <?php if (isset($result_data['amount_paid']) && !is_null($result_data['amount_paid'])): ?>
                                        <h5 class="text-success">ข้อมูลรายได้และภาษี</h5>
                                        <div class="row mb-2">
                                            <div class="col-sm-4 fw-bold">รายได้ (Amount Paid):</div>
                                            <div class="col-sm-8 text-success fw-bold"><?php echo number_format($result_data['amount_paid'], 2); ?> บาท</div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-sm-4 fw-bold">ภาษีที่หัก (Tax Withheld):</div>
                                            <div class="col-sm-8 text-danger fw-bold"><?php echo number_format($result_data['tax_withheld'], 2); ?> บาท</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning mb-0">
                                            ไม่พบข้อมูลรายได้และภาษีในระบบ (ตาราง tax_records)
                                        </div>
                                    <?php endif; ?>

                                    <hr>
                                    
                                    <!-- ส่วนแสดงเอกสารใบหักภาษี ณ ที่จ่าย -->
                                    <h5 class="text-primary mt-4">หนังสือรับรองการหักภาษี ณ ที่จ่าย</h5>
                                    <?php if (!empty($tax_reports_list)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-striped">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>วันที่ออกเอกสาร</th>
                                                        <th class="text-center">เอกสาร</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $thai_months = [
                                                        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
                                                        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
                                                        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
                                                    ];
                                                    foreach ($tax_reports_list as $report): ?>
                                                        <?php
                                                            // แยกชื่อไฟล์เพื่อเอา วันที่-เดือน-ปี
                                                            // รูปแบบ: เลขบัตร - วันที่ - เดือน - ปี - เลขหน้า.pdf
                                                            $filename = $report['file_name'];
                                                            $parts = explode('-', $filename);
                                                            // $parts[0] = PID, [1] = วันที่, [2] = เดือน, [3] = ปี (2 หลัก)
                                                            $day = isset($parts[1]) ? (int)$parts[1] : '-';
                                                            $month_num = isset($parts[2]) ? (int)$parts[2] : 0;
                                                            $month_name = $thai_months[$month_num] ?? '-';
                                                            $year = isset($parts[3]) ? '25' . $parts[3] : '-';
                                                            
                                                            $full_date = "$day $month_name $year";
                                                        ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($full_date); ?></td>
                                                            <td class="text-center">
                                                                <a href="PDF/processed_PDFs/<?php echo htmlspecialchars($filename); ?>" target="_blank" class="btn btn-sm btn-primary">
                                                                    ดูเอกสาร
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-secondary">ไม่พบเอกสารใบหักภาษี</div>
                                    <?php endif; ?>

                                    <!-- ส่วนแสดงเอกสารลดหย่อนภาษี -->
                                    <h5 class="text-primary mt-4">เอกสารลดหย่อนภาษี</h5>
                                    <?php if ($pdf_deduction): ?>
                                        <div class="mt-2">
                                            <a href="PDF/pdf_storage/<?php echo htmlspecialchars($pdf_deduction['newname']); ?>" target="_blank" class="btn btn-success">
                                                ดูเอกสารลดหย่อนภาษี
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-secondary">ไม่พบเอกสารลดหย่อนภาษี</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

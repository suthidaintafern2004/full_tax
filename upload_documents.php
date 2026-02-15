<?php
session_start();
require_once 'config.php';

// ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$sql_role = "SELECT role FROM users WHERE username = ?";
$stmt = mysqli_prepare($conn, $sql_role);
mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($user['role'] != 3) {
    header("Location: index.php");
    exit();
}

$popup_payload = null; // ตัวแปรสำหรับเก็บข้อมูล Popup

// จัดการการอัปโหลด
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // เพิ่มเวลาและหน่วยความจำสำหรับการประมวลผลไฟล์จำนวนมาก
    set_time_limit(0); // ไม่จำกัดเวลา (สำหรับแตกไฟล์ ZIP ใหญ่ๆ)
    ini_set('memory_limit', '-1'); // ไม่จำกัดแรม

    // ตรวจสอบว่าไฟล์เกินขนาด post_max_size หรือไม่
    if (empty($_FILES) && empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $popup_payload = [
            'status' => 'error',
            'title' => 'ไฟล์มีขนาดใหญ่เกินไป',
            'message' => 'ขนาดไฟล์รวมใหญ่เกินกว่าที่เซิร์ฟเวอร์กำหนด (post_max_size)'
        ];
    } elseif (isset($_FILES['files'])) {
    $upload_type = $_POST['upload_type'];
    $files = $_FILES['files'];
    $count = count($files['name']);
    $success_count = 0;
    $error_count = 0;
    $error_details = [];

    // กำหนดโฟลเดอร์และตารางตามประเภทที่เลือก
    if ($upload_type == 'deduction') {
        $target_dir = "PDF/pdf_storage/";
        $table = "pdf_management";
    } elseif ($upload_type == 'withholding') {
        $target_dir = "PDF/processed_PDFs/";
        $table = "tax_reports";
    } else {
        $popup_payload = [
            'status' => 'warning',
            'title' => 'ข้อมูลไม่ครบถ้วน',
            'message' => 'กรุณาเลือกประเภทเอกสารก่อนอัปโหลด'
        ];
    }

    if (empty($popup_payload)) {
        // สร้างโฟลเดอร์ถ้ายังไม่มี
        if (!file_exists($target_dir)) {
            if (!mkdir($target_dir, 0777, true)) {
                $popup_payload = ['status' => 'error', 'title' => 'System Error', 'message' => "ไม่สามารถสร้างโฟลเดอร์ $target_dir ได้"];
            }
        }
    }
    
    if (empty($popup_payload)) {
        // ฟังก์ชันสำหรับประมวลผลไฟล์ PDF (ใช้ซ้ำได้ทั้งจาก Upload ปกติ และจาก ZIP)
        function process_pdf_file($conn, $filename, $source_path, $target_dir, $upload_type, &$success_count, &$error_count, &$error_details) {
            $clean_name = basename($filename);
            $target_file = $target_dir . $clean_name;
            
            // ถ้าไฟล์มาจาก ZIP มันถูกวางไว้ที่ปลายทางแล้ว แต่ถ้ามาจาก Upload ปกติ ต้องย้าย
            if (is_uploaded_file($source_path)) {
                if (!move_uploaded_file($source_path, $target_file)) {
                    $error_count++;
                    $error_details[] = "ย้ายไฟล์ $filename ไม่สำเร็จ";
                    return;
                }
            } elseif ($source_path !== $target_file) {
                // กรณีมาจาก ZIP (Stream) หรืออื่นๆ ที่ไม่ใช่ uploaded file
                // (ในโค้ด ZIP ด้านล่างเราจะเขียนไฟล์ลง target โดยตรงแล้ว ดังนั้นส่วนนี้อาจไม่ต้องทำอะไรเพิ่ม)
            }

            // --- เริ่มกระบวนการบันทึกฐานข้อมูล ---
            if ($upload_type == 'deduction') {
                // 1. เอกสารลดหย่อนภาษี
                $check_sql = "SELECT id FROM pdf_management WHERE file_name = ?";
                $stmt_check = mysqli_prepare($conn, $check_sql);
                mysqli_stmt_bind_param($stmt_check, "s", $clean_name);
                mysqli_stmt_execute($stmt_check);
                mysqli_stmt_store_result($stmt_check);

                if (mysqli_stmt_num_rows($stmt_check) > 0) {
                    $success_count++;
                    mysqli_stmt_close($stmt_check);
                } else {
                    mysqli_stmt_close($stmt_check);
                    $stmt = mysqli_prepare($conn, "INSERT INTO pdf_management (file_name) VALUES (?)");
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "s", $clean_name);
                        if (mysqli_stmt_execute($stmt)) $success_count++;
                        else { $error_count++; $error_details[] = "DB Error ($filename): " . mysqli_error($conn); }
                        mysqli_stmt_close($stmt);
                    } else {
                        $error_count++; $error_details[] = "Prepare Error: " . mysqli_error($conn);
                    }
                }
            } elseif ($upload_type == 'withholding') {
                // 2. เอกสารหักภาษี
                $parts = explode('-', str_replace('.pdf', '', $clean_name));
                $pid = $parts[0];

                if (is_numeric($pid) && strlen($pid) >= 10) {
                    $check_sql = "SELECT id FROM tax_reports WHERE file_name = ?";
                    $stmt_check = mysqli_prepare($conn, $check_sql);
                    mysqli_stmt_bind_param($stmt_check, "s", $clean_name);
                    mysqli_stmt_execute($stmt_check);
                    mysqli_stmt_store_result($stmt_check);

                    if (mysqli_stmt_num_rows($stmt_check) > 0) {
                        $success_count++;
                        mysqli_stmt_close($stmt_check);
                    } else {
                        mysqli_stmt_close($stmt_check);
                        $stmt = mysqli_prepare($conn, "INSERT INTO tax_reports (file_name) VALUES (?)");
                        if ($stmt) {
                            mysqli_stmt_bind_param($stmt, "s", $clean_name);
                            if (mysqli_stmt_execute($stmt)) $success_count++;
                            else { $error_count++; $error_details[] = "DB Error ($filename): " . mysqli_error($conn); }
                            mysqli_stmt_close($stmt);
                        } else {
                            $error_count++; $error_details[] = "Prepare Error: " . mysqli_error($conn);
                        }
                    }
                } else {
                    $error_count++; 
                    $error_details[] = "ไฟล์ $clean_name ชื่อไม่ถูกต้อง (ต้องขึ้นต้นด้วยตัวเลข)";
                    // ลบไฟล์ทิ้งถ้าชื่อไม่ถูก
                    @unlink($target_file);
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $filename = $files['name'][$i];
            $tmp_name = $files['tmp_name'][$i];
            $error = $files['error'][$i];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if ($error === 0) {
                if ($ext == 'zip') {
                    // --- กรณีเป็นไฟล์ ZIP ---
                    $zip = new ZipArchive;
                    if ($zip->open($tmp_name) === TRUE) {
                        for ($j = 0; $j < $zip->numFiles; $j++) {
                            $entryName = $zip->getNameIndex($j);
                            // ข้ามโฟลเดอร์และไฟล์ที่ไม่ใช่ PDF
                            if (substr($entryName, -1) == '/') continue;
                            if (strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) != 'pdf') continue;
                            
                            // ป้องกัน Path Traversal และเอาแค่ชื่อไฟล์
                            $cleanEntryName = basename($entryName);
                            $targetEntryFile = $target_dir . $cleanEntryName;

                            // แตกไฟล์ลงปลายทางโดยตรง
                            $stream = $zip->getStream($entryName);
                            if ($stream) {
                                $fp = fopen($targetEntryFile, 'w');
                                while (!feof($stream)) {
                                    fwrite($fp, fread($stream, 8192));
                                }
                                fclose($fp);
                                fclose($stream);
                                
                                // เรียกฟังก์ชันประมวลผล (ส่ง path ปลายทางไปเลย เพราะไฟล์ถูกเขียนแล้ว)
                                process_pdf_file($conn, $cleanEntryName, $targetEntryFile, $target_dir, $upload_type, $success_count, $error_count, $error_details);
                            }
                        }
                        $zip->close();
                    } else {
                        $error_count++;
                        $error_details[] = "ไม่สามารถเปิดไฟล์ ZIP: $filename ได้";
                    }
                } elseif ($ext == 'pdf') {
                    // --- กรณีเป็นไฟล์ PDF ปกติ ---
                    process_pdf_file($conn, $filename, $tmp_name, $target_dir, $upload_type, $success_count, $error_count, $error_details);
                }
            } else {
                // กรณีเกิด Error จากการอัปโหลด (เช่น ไฟล์ใหญ่เกินไป)
                $error_count++;
                $error_details[] = "ไฟล์ $filename อัปโหลดไม่สำเร็จ (Error Code: $error)";
            }
        }

        if ($success_count > 0) {
            $popup_payload = [
                'status' => 'success',
                'title' => 'อัปโหลดเสร็จสิ้น',
                'message' => "บันทึกสำเร็จ $success_count ไฟล์" . ($error_count > 0 ? "<br><small class='text-danger'>พบปัญหา $error_count ไฟล์</small>" : "")
            ];
        } else {
            $err_msg = implode("<br>", $error_details);
            if (empty($err_msg)) $err_msg = "เกิดข้อผิดพลาดในการอัปโหลด หรือรูปแบบไฟล์ไม่ถูกต้อง";
            $popup_payload = [
                'status' => 'error',
                'title' => 'พบข้อผิดพลาด',
                'message' => $err_msg
            ];
        }
    }

    // ส่งค่ากลับแบบ JSON สำหรับ AJAX (Batch Upload / ZIP Upload)
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success_count,
            'error' => $error_count,
            'details' => $error_details
        ]);
        exit();
    }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>อัปโหลดเอกสาร - ระบบภาษี</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/upload_documents.css">
</head>
<body class="d-flex flex-column min-vh-100">
    <nav class="navbar navbar-expand-lg navbar-dark mb-5 shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-file-invoice-dollar me-2"></i>ระบบดาวน์โหลดเอกสารรับรองภาษี</a>
            <div class="ms-auto">
                <a href="admin_menu.php" class="btn btn-outline-light btn-sm px-3 rounded-pill"><i class="fas fa-arrow-left me-1"></i> กลับเมนูหลัก</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7 col-md-9">
                <div class="card card-upload">
                    <div class="card-header-custom">
                        <h3 class="fw-bold mb-1"><i class="fas fa-cloud-upload-alt me-2"></i>อัปโหลดเอกสาร</h3>
                        <p class="mb-0 opacity-75 small">รองรับไฟล์ PDF หรือ ZIP (สำหรับอัปโหลดทีละโฟลเดอร์)</p>
                    </div>
                    <div class="card-body p-4 p-md-5">

                        <div class="alert alert-info border-0 shadow-sm rounded-3 mb-4">
                            <h6 class="fw-bold"><i class="fas fa-exclamation-circle me-2"></i>ข้อกำหนดและคำแนะนำ</h6>
                            <ul class="mb-0 small ps-3">
                                <li class="mb-1">รองรับเฉพาะไฟล์นามสกุล <strong>.pdf</strong> และ <strong>.zip</strong> เท่านั้น</li>
                                <li class="mb-1">สามารถเลือกไฟล์ได้ทีละ 20 ไฟล์ หรือลากไฟล์มาวางในกรอบ</li>
                                <li class="mb-1">ชื่อไฟล์ต้องขึ้นต้นด้วย <strong>เลขบัตรประชาชน-วัน-เดือน-ปีที่ออกเอกสาร</strong> <br>(ตัวอย่าง: <code>1234567890123-1-1-67.pdf</code>)</li>
                                <li>หากต้องการอัปโหลดไฟล์จำนวนมาก (20+) แนะนำให้บีบอัดเป็นไฟล์ <strong>.zip</strong> แล้วอัปโหลดทีเดียว</li>
                            </ul>
                        </div>

                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <div class="mb-4">
                                <label class="form-label fw-bold text-dark mb-2"><i class="fas fa-tag me-2 text-primary"></i>ประเภทเอกสาร</label>
                                <select name="upload_type" class="form-select form-select-lg shadow-sm border-0 bg-light" required style="border-radius: 15px;">
                                    <option value="" selected disabled>-- กรุณาเลือก --</option>
                                    <option value="deduction">📂 เอกสารลดหย่อนภาษี</option>
                                    <option value="withholding">📄 เอกสารหักภาษี ณ ที่จ่าย</option>
                                </select>
                            </div>

                            <div class="mb-5">
                                <label class="form-label fw-bold text-dark mb-2"><i class="fas fa-file-pdf me-2 text-danger"></i>ไฟล์เอกสาร</label>
                                <div class="upload-area" id="dropZone" onclick="document.getElementById('fileInput').click()">
                                    <div class="mb-3">
                                        <i class="fas fa-cloud-arrow-up upload-icon"></i>
                                    </div>
                                    <h5 class="fw-bold text-dark">คลิกเพื่อเลือกไฟล์</h5>
                                    <p class="text-muted small mb-0">ลากไฟล์ PDF หรือ ZIP มาวางที่นี่ (แนะนำ ZIP สำหรับไฟล์จำนวนมาก)</p>
                                    <input type="file" name="files[]" id="fileInput" class="d-none" multiple accept=".pdf,.zip" required>
                                    
                                    <div id="fileList" class="mt-3 d-none">
                                        <span class="badge bg-primary rounded-pill px-3 py-2">
                                            <i class="fas fa-check me-1"></i> เลือกแล้ว <span id="fileCount">0</span> ไฟล์
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <div class="progress mb-4 d-none" id="uploadProgressContainer" style="height: 25px; border-radius: 15px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%" id="uploadProgressBar">0%</div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg btn-upload rounded-pill shadow" id="submitBtn">
                                    <i class="fas fa-paper-plane me-2"></i> ยืนยันการอัปโหลด
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>
    <!-- Modal แจ้งเตือน (Popup) -->
    <div class="modal fade" id="resultModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-body p-5 text-center">
                    <div class="mb-4" id="modalIcon"></div>
                    <h3 class="fw-bold mb-2" id="modalTitle"></h3>
                    <p class="text-muted mb-4" id="modalMessage"></p>
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // จัดการ Drag & Drop และแสดงชื่อไฟล์
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const fileCount = document.getElementById('fileCount');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
        });

        dropZone.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            updateFileDisplay();
        }

        fileInput.addEventListener('change', updateFileDisplay);

        function updateFileDisplay() {
            if (fileInput.files.length > 0) {
                fileList.classList.remove('d-none');
                fileCount.innerText = fileInput.files.length;
            } else {
                fileList.classList.add('d-none');
            }
        }

        // ฟังก์ชันแสดง Modal (ใช้ร่วมกัน)
        function showResultModal(status, title, message) {
            var myModal = new bootstrap.Modal(document.getElementById('resultModal'));
            var iconHtml = '';
            
            if(status === 'success') {
                iconHtml = '<div class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-check text-success fa-3x"></i></div>';
            } else if(status === 'error') {
                iconHtml = '<div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-times text-danger fa-3x"></i></div>';
            } else {
                iconHtml = '<div class="rounded-circle bg-warning bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;"><i class="fas fa-exclamation text-warning fa-3x"></i></div>';
            }

            document.getElementById('modalIcon').innerHTML = iconHtml;
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalMessage').innerHTML = message;
            
            myModal.show();
        }

        // จัดการ Submit แบบ Batch Upload (ทยอยส่งทีละชุด)
        document.getElementById('uploadForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const uploadType = document.querySelector('select[name="upload_type"]').value;
            if (!uploadType) { showResultModal('warning', 'แจ้งเตือน', 'กรุณาเลือกประเภทเอกสาร'); return; }

            const files = fileInput.files;
            if (files.length === 0) { showResultModal('warning', 'แจ้งเตือน', 'กรุณาเลือกไฟล์เอกสาร'); return; }

            const btn = document.getElementById('submitBtn');
            const originalBtnText = btn.innerHTML;
            const progressContainer = document.getElementById('uploadProgressContainer');
            const progressBar = document.getElementById('uploadProgressBar');

            // เริ่มต้นการอัปโหลด
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> กำลังประมวลผล...';
            progressContainer.classList.remove('d-none');
            progressBar.style.width = '0%';
            progressBar.innerText = '0%';

            const BATCH_SIZE = 5; // ลดจำนวนต่อรอบลงเหลือ 5 ไฟล์ เพื่อป้องกัน Timeout และ Error
            let totalSuccess = 0;
            let totalError = 0;
            let errorDetails = [];
            let processedCount = 0;

            // วนลูปส่งไฟล์ทีละชุด
            for (let i = 0; i < files.length; i += BATCH_SIZE) {
                const chunk = Array.from(files).slice(i, i + BATCH_SIZE);
                const formData = new FormData();
                formData.append('upload_type', uploadType);
                formData.append('ajax', '1'); // บอก Server ว่าเป็น AJAX
                chunk.forEach(file => formData.append('files[]', file));

                try {
                    const response = await fetch('upload_documents.php', { method: 'POST', body: formData });
                    
                    if (!response.ok) {
                        throw new Error(`Server Error (${response.status})`);
                    }

                    const responseText = await response.text();
                    let result;
                    try {
                        result = JSON.parse(responseText);
                    } catch (e) {
                        throw new Error('รูปแบบข้อมูลตอบกลับไม่ถูกต้อง');
                    }
                    
                    totalSuccess += result.success;
                    totalError += result.error;
                    if (result.details && result.details.length > 0) errorDetails.push(...result.details);
                } catch (err) {
                    console.error(err);
                    totalError += chunk.length;
                    errorDetails.push(`ชุดที่ ${(i/BATCH_SIZE + 1)} ล้มเหลว: ${err.message}`);
                }

                processedCount += chunk.length;
                const percent = Math.round((processedCount / files.length) * 100);
                progressBar.style.width = percent + '%';
                progressBar.innerText = percent + '%';
            }

            // คืนค่าปุ่มและแสดงผลลัพธ์
            btn.disabled = false;
            btn.innerHTML = originalBtnText;
            setTimeout(() => { progressContainer.classList.add('d-none'); }, 1000);

            const status = (totalError === 0 && totalSuccess > 0) ? 'success' : 'error';
            const title = (totalError === 0) ? 'อัปโหลดเสร็จสิ้นสมบูรณ์' : 'อัปโหลดเสร็จสิ้น (พบปัญหา)';
            let message = `บันทึกสำเร็จ ${totalSuccess} ไฟล์`;
            if (totalError > 0) {
                message += `<br><span class="text-danger">ไม่สำเร็จ ${totalError} ไฟล์</span>`;
                if (errorDetails.length > 0) message += `<div class="mt-2 small text-muted text-start" style="max-height:100px;overflow-y:auto;">${errorDetails.join('<br>')}</div>`;
            }

            showResultModal(status, title, message);
        });

        // แสดง Popup แจ้งเตือนถ้ามีข้อมูลจาก PHP
        <?php if ($popup_payload): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showResultModal('<?php echo $popup_payload['status']; ?>', '<?php echo $popup_payload['title']; ?>', '<?php echo $popup_payload['message']; ?>');
            });
        <?php endif; ?>
    </script>
</body>
</html>
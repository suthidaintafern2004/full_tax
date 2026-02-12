<?php
// view_pdf.php

// ตรวจสอบว่ามีไฟล์ autoload ของ Composer หรือไม่
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
}

use setasign\Fpdi\Fpdi;

// ตรวจสอบว่า Class Fpdi ถูกโหลดมาหรือไม่ (ป้องกัน Fatal Error)
if (!class_exists('setasign\Fpdi\Fpdi')) {
    die('<div style="font-family: sans-serif; padding: 20px; background: #ffebee; border: 1px solid #f44336; color: #c62828; border-radius: 5px; max-width: 800px; margin: 20px auto;">
            <h3 style="margin-top:0;">เกิดข้อผิดพลาด: ไม่พบไลบรารี FPDI</h3>
            <p>ระบบไม่สามารถสร้างเอกสารที่มีลายเซ็นได้เนื่องจากขาดไฟล์ไลบรารีที่จำเป็น</p>
            <hr style="border: 0; border-top: 1px solid #ffcdd2;">
            <strong>วิธีแก้ไขสำหรับผู้ดูแลระบบ:</strong><br>
            กรุณาเปิด Terminal/Command Prompt ที่โฟลเดอร์โปรเจกต์ (<code>' . __DIR__ . '</code>) แล้วรันคำสั่ง:<br>
            <code style="background: #333; color: #fff; padding: 10px; display: block; margin: 10px 0; border-radius: 4px;">composer require setasign/fpdi</code>
            <div style="text-align: center; margin-top: 20px;">
                <button onclick="location.reload()" style="padding: 10px 25px; background-color: #4e73df; color: white; border: none; border-radius: 50px; cursor: pointer; font-weight: bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">ตรวจสอบอีกครั้ง (Reload)</button>
            </div>
         </div>');
}

if (isset($_GET['file'])) {
    // กำหนด Path ของไฟล์ต้นฉบับและรูปลายเซ็น
    $fileName = basename($_GET['file']);
    $filePath = 'PDF/processed_PDFs/' . $fileName;
    $signaturePath = 'images/wm.png';

    if (file_exists($filePath)) {
        try {
            $pdf = new Fpdi();

            // อ่านไฟล์ PDF ต้นฉบับ
            $pageCount = $pdf->setSourceFile($filePath);
            $templateId = $pdf->importPage(1); // ดึงหน้า 1
            $size = $pdf->getTemplateSize($templateId);

            // สร้างหน้า PDF ใหม่ตามขนาดเดิม
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            // --- ส่วนการวางลายเซ็น ---
            // ปรับค่า x (ซ้าย-ขวา) และ y (บน-ลง) ตามต้องการ (หน่วยเป็นมิลลิเมตร)
            $x = 117;
            $y = 222;
            $width = 60;

            if (file_exists($signaturePath)) {
                $pdf->Image($signaturePath, $x, $y, $width);
            }

            // ส่งออกไฟล์ไปยัง Browser
            header('Content-Type: application/pdf');
            $pdf->Output('I', 'signed_' . $fileName);
            exit;
        } catch (Exception $e) {
            die("เกิดข้อผิดพลาด: " . $e->getMessage());
        }
    } else {
        die("ไม่พบไฟล์เอกสารต้นฉบับในโฟลเดอร์ processed_PDFs");
    }
} else {
    die("ไม่ระบุชื่อไฟล์");
}

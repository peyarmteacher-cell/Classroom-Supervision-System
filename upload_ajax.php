<?php
/**
 * AJAX Image Upload Endpoint
 * อัปโหลดรูปภาพคุณครูโดยอัตโนมัติเมื่อเลือกไฟล์ พร้อมอัปเดตขึ้น Google Drive หากระบุการเชื่อมโยงไว้
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// ตรวจสอบสิทธิ์ผู้ใช้งานเบื้องต้น
if (empty($_SESSION['username'])) {
    echo json_encode([
        'success' => false,
        'error' => 'เซสชันหมดอายุ กรุณาลงชื่อเข้าสู่ระบบใหม่อีกครั้ง'
    ]);
    exit;
}

$school_code = $_SESSION['school_code'] ?? '31054002';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode([
            'success' => false,
            'error' => 'การส่งข้อมูลล้มเหลว รหัสข้อผิดพลาด: ' . $file['error']
        ]);
        exit;
    }
    
    $file_tmp = $file['tmp_name'];
    $file_name = $file['name'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (!in_array($file_ext, $allowed_exts)) {
        echo json_encode([
            'success' => false,
            'error' => 'อนุญาตให้เฉพาะไฟล์ภาพสกุล JPG, JPEG, PNG หรือ GIF เท่านั้น'
        ]);
        exit;
    }
    
    // ตั้งชื่อไฟล์ชั่วคราว/ถาวร
    $new_file_name = 'ajax_teacher_' . uniqid() . '_' . time() . '.' . $file_ext;
    $dest_path = 'uploads/' . $new_file_name;
    
    // ตรวจสอบโฟลเดอร์ uploads
    if (!is_dir(__DIR__ . '/uploads')) {
        mkdir(__DIR__ . '/uploads', 0755, true);
    }
    
    if (move_uploaded_file($file_tmp, __DIR__ . '/' . $dest_path)) {
        // อัปโหลดรูปภาพขึ้น Google Drive หากเปิดใช้งานไว้
        try {
            $gdrive_url = upload_image_to_gdrive_if_configured($dest_path, $new_file_name, $school_code, $pdo);
            $photo_url = $gdrive_url ?: $dest_path;
            
            // แปลงหากเป็นลิงก์ Google Drive เพื่อให้สามารถแสดงผลบนเว็บได้โดยตรง
            $direct_url = convert_gdrive_url_to_direct($photo_url);
            
            echo json_encode([
                'success' => true,
                'url' => $direct_url,
                'message' => 'อัปโหลดรูปภาพสำเร็จเรียบร้อยแล้ว'
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => 'เกิดข้อผิดพลาดระหว่างส่งภาพขึ้นคลาวด์: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'ไม่สามารถจัดเก็บรูปภาพในระบบได้ (ปัญหาโฟลเดอร์ปลายทาง)'
        ]);
        exit;
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'คำขอไม่ถูกต้อง'
    ]);
    exit;
}

<?php
/**
 * AJAX Image Upload Endpoint (Universal & Bulletproof)
 * อัปโหลดรูปภาพคุณครูโดยอัตโนมัติเมื่อเลือกไฟล์ 
 * รองรับทั้งการส่งแบบ Base64 (ย่อขนาดบน Client) และ Multipart เพื่อความเสถียรสูงสุดในการบันทึกภาพ
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

// 1. ตรวจสอบและสร้างโฟลเดอร์ปลายทางด้วยสิทธิ์เต็มรูปแบบ (0777)
$target_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true);
}
@chmod($target_dir, 0777);

// 2. ตรวจสอบว่ามีการส่งรูปภาพเป็น Base64 หรือไม่ (วิธีนี้เสถียรและทนทานที่สุด ป้องกันปัญหาระบบไฟล์ temp)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['image_base64'])) {
    $image_data = $_POST['image_base64'];
    
    if (strpos($image_data, 'data:image/') !== 0) {
        echo json_encode([
            'success' => false,
            'error' => 'รูปแบบข้อมูลภาพ Base64 ไม่ถูกต้อง'
        ]);
        exit;
    }
    
    // หานามสกุลไฟล์จาก mime-type
    $ext = 'jpg';
    if (strpos($image_data, 'image/png') !== false) {
        $ext = 'png';
    } elseif (strpos($image_data, 'image/gif') !== false) {
        $ext = 'gif';
    }
    
    $new_file_name = 'ajax_teacher_' . uniqid() . '_' . time() . '.' . $ext;
    $dest_path = 'uploads/' . $new_file_name;
    $full_dest_path = __DIR__ . DIRECTORY_SEPARATOR . $dest_path;
    
    // ถอดรหัส Base64
    $parts = explode(',', $image_data);
    $payload = $parts[1] ?? '';
    $file_content = base64_decode($payload);
    
    if (file_put_contents($full_dest_path, $file_content) !== false) {
        @chmod($full_dest_path, 0666);
        
        // ส่งขึ้น Google Drive หากมีการเปิดการเชื่อมต่อไว้
        try {
            $gdrive_url = upload_image_to_gdrive_if_configured($dest_path, $new_file_name, $school_code, $pdo);
            $photo_url = $gdrive_url ?: $dest_path;
            
            // แปลงลิกค์ Google Drive ให้เป็น direct link
            $direct_url = convert_gdrive_url_to_direct($photo_url);
            
            echo json_encode([
                'success' => true,
                'url' => $direct_url,
                'message' => 'อัปโหลดรูปภาพสำเร็จเรียบร้อยแล้ว'
            ]);
            exit;
        } catch (Exception $e) {
            // หาก Google Drive พัง ให้ทำงานต่อด้วยรูปภาพบนเครื่องเซิร์ฟเวอร์
            echo json_encode([
                'success' => true,
                'url' => $dest_path,
                'message' => 'บันทึกภาพลงเครื่องสำเร็จ (แต่คลาวด์ขัดข้อง: ' . $e->getMessage() . ')'
            ]);
            exit;
        }
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'ไม่สามารถเขียนไฟล์ภาพลงเซิร์ฟเวอร์โรงเรียนได้ (ปัญหาโฟลเดอร์ปลายทาง)'
        ]);
        exit;
    }
}

// 3. วิธีสำรอง: การส่งแบบไฟล์ดิบ (Multipart $_FILES) ในกรณีที่ไม่ได้ใช้ Base64
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
    
    $new_file_name = 'ajax_teacher_' . uniqid() . '_' . time() . '.' . $file_ext;
    $dest_path = 'uploads/' . $new_file_name;
    $full_dest_path = __DIR__ . DIRECTORY_SEPARATOR . $dest_path;
    
    // ทดลองบันทึกไฟล์ด้วยหลากหลายวิธี (move, copy, หรือ read-write) เพื่อหลีกเลี่ยงข้อจำกัด Permission ของระบบ
    $upload_success = false;
    if (move_uploaded_file($file_tmp, $full_dest_path)) {
        $upload_success = true;
    } elseif (copy($file_tmp, $full_dest_path)) {
        $upload_success = true;
    } elseif (file_put_contents($full_dest_path, file_get_contents($file_tmp)) !== false) {
        $upload_success = true;
    }
    
    if ($upload_success) {
        @chmod($full_dest_path, 0666);
        
        try {
            $gdrive_url = upload_image_to_gdrive_if_configured($dest_path, $new_file_name, $school_code, $pdo);
            $photo_url = $gdrive_url ?: $dest_path;
            
            $direct_url = convert_gdrive_url_to_direct($photo_url);
            
            echo json_encode([
                'success' => true,
                'url' => $direct_url,
                'message' => 'อัปโหลดรูปภาพสำเร็จเรียบร้อยแล้ว'
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode([
                'success' => true,
                'url' => $dest_path,
                'message' => 'บันทึกภาพลงเครื่องสำเร็จ (แต่คลาวด์ขัดข้อง: ' . $e->getMessage() . ')'
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
}

echo json_encode([
    'success' => false,
    'error' => 'คำขอไม่ถูกต้องหรือไม่มีไฟล์ส่งเข้ามา'
]);
exit;

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

// ตรวจสอบก่อนว่าโรงเรียนตั้งค่า Google Drive สำเร็จหรือไม่
$stmt = $pdo->prepare("SELECT gdrive_app_url, gdrive_folder_id FROM schools WHERE school_code = ?");
$stmt->execute([$school_code]);
$gdrive = $stmt->fetch();
$has_gdrive = ($gdrive && !empty($gdrive['gdrive_app_url']) && !empty($gdrive['gdrive_folder_id']));

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
    
    // หากมีการกำหนดค่า Google Drive ให้ส่งขึ้น Drive โดยตรงทันทีโดยไม่ต้องเขียนลงดิสก์เซิร์ฟเวอร์ก่อน!
    if ($has_gdrive) {
        try {
            // ส่งค่า $image_data ซึ่งเป็น Base64 เข้าฟังก์ชันตรงๆ ฟังก์ชันนี้จะส่งขึ้น Google Drive โดยตรง
            $gdrive_url = upload_image_to_gdrive_if_configured($image_data, $new_file_name, $school_code, $pdo);
            if ($gdrive_url && (strpos($gdrive_url, 'http://') === 0 || strpos($gdrive_url, 'https://') === 0)) {
                $direct_url = convert_gdrive_url_to_direct($gdrive_url);
                echo json_encode([
                    'success' => true,
                    'url' => $direct_url,
                    'message' => 'อัปโหลดรูปภาพไปยัง Google Drive สำเร็จเรียบร้อยแล้ว'
                ]);
                exit;
            }
        } catch (Exception $e) {
            // หากเกิดข้อผิดพลาดในการอัปโหลดไปยัง Google Drive ให้พยายามเขียนลงเครื่องต่อเป็น fallback
        }
    }
    
    // Fallback: หากไม่ได้ต่อ Google Drive หรืออัปโหลดล้มเหลว ให้บันทึกลงเซิร์ฟเวอร์โลคัล
    $target_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($target_dir)) {
        @mkdir($target_dir, 0777, true);
    }
    @chmod($target_dir, 0777);
    
    $dest_path = 'uploads/' . $new_file_name;
    $full_dest_path = $target_dir . DIRECTORY_SEPARATOR . $new_file_name;
    
    // ถอดรหัส Base64
    $parts = explode(',', $image_data);
    $payload = $parts[1] ?? '';
    $file_content = base64_decode($payload);
    
    if (file_put_contents($full_dest_path, $file_content) !== false) {
        @chmod($full_dest_path, 0666);
        echo json_encode([
            'success' => true,
            'url' => $dest_path,
            'message' => 'อัปโหลดรูปภาพสำเร็จ (บันทึกลงเครื่องเนื่องจากไม่มีการตั้งค่าคลาวด์)'
        ]);
        exit;
    } else {
        // หากเขียนลงเซิร์ฟเวอร์โรงเรียนไม่ได้เนื่องจากปัญหาโฟลเดอร์ปลายทาง/สิทธิ์การเข้าถึง ให้ใช้ Base64 ส่งกลับเป็นข้อมูลภาพตรงบันทึกลงฐานข้อมูลแทน
        echo json_encode([
            'success' => true,
            'url' => $image_data, // ส่งกลับเป็น Base64 Data-URI
            'message' => 'อัปโหลดรูปภาพสำเร็จ (จัดเก็บแบบฐานข้อมูลตรงเนื่องจากโฟลเดอร์ปลายทางของเซิร์ฟเวอร์ไม่มีสิทธิ์เขียน)'
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
    
    // หากเชื่อมต่อ Google Drive ไว้และส่งไฟล์ดิบเข้ามา ให้แปลงเป็น base64 แล้วอัปโหลดตรงข้ามเครือข่ายเลย
    if ($has_gdrive && file_exists($file_tmp)) {
        try {
            $raw_content = file_get_contents($file_tmp);
            $mime_type = mime_content_type($file_tmp) ?: 'image/jpeg';
            $base64_payload = 'data:' . $mime_type . ';base64,' . base64_encode($raw_content);
            
            $gdrive_url = upload_image_to_gdrive_if_configured($base64_payload, $new_file_name, $school_code, $pdo);
            if ($gdrive_url && (strpos($gdrive_url, 'http://') === 0 || strpos($gdrive_url, 'https://') === 0)) {
                $direct_url = convert_gdrive_url_to_direct($gdrive_url);
                echo json_encode([
                    'success' => true,
                    'url' => $direct_url,
                    'message' => 'อัปโหลดรูปภาพไปยัง Google Drive สำเร็จเรียบร้อยแล้ว'
                ]);
                exit;
            }
        } catch (Exception $e) {
            // ดำเนินการ fallback ต่อ
        }
    }
    
    // Fallback: เขียนลงเครื่องเซิร์ฟเวอร์โลคัล
    $target_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($target_dir)) {
        @mkdir($target_dir, 0777, true);
    }
    @chmod($target_dir, 0777);
    
    $dest_path = 'uploads/' . $new_file_name;
    $full_dest_path = $target_dir . DIRECTORY_SEPARATOR . $new_file_name;
    
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
        echo json_encode([
            'success' => true,
            'url' => $dest_path,
            'message' => 'อัปโหลดรูปภาพสำเร็จ (บันทึกลงเครื่องเนื่องจากไม่มีการตั้งค่าคลาวด์)'
        ]);
        exit;
    } else {
        // หากเขียนลงเซิร์ฟเวอร์โลคัลไม่ได้จริงๆ ให้แปลงไฟล์ดิบ $_FILES เป็น Base64 แล้วส่งกลับเพื่อให้บันทึกในฐานข้อมูลตรง!
        $raw_content = file_get_contents($file_tmp);
        $mime_type = mime_content_type($file_tmp) ?: 'image/jpeg';
        $base64_payload = 'data:' . $mime_type . ';base64,' . base64_encode($raw_content);
        
        echo json_encode([
            'success' => true,
            'url' => $base64_payload,
            'message' => 'อัปโหลดรูปภาพสำเร็จ (จัดเก็บแบบฐานข้อมูลตรงเนื่องจากโฟลเดอร์ปลายทางของเซิร์ฟเวอร์ไม่มีสิทธิ์เขียน)'
        ]);
        exit;
    }
}

echo json_encode([
    'success' => false,
    'error' => 'คำขอไม่ถูกต้องหรือไม่มีไฟล์ส่งเข้ามา'
]);
exit;

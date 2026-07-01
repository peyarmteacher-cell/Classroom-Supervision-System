<?php
// ==========================================
// super_admin.php - ระบบดูแลและบริการโรงเรียนกลุ่มภายนอก (Super Admin Control Panel)
// ==========================================

require_once 'config.php';

// ตรวจวัดความปลอดภัยสิทธิ์ Super Admin
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: dashboard.php");
    exit;
}

$success_msg = isset($_GET['success_msg']) ? $_GET['success_msg'] : '';
$error_msg = isset($_GET['error_msg']) ? $_GET['error_msg'] : '';

// การจัดการแก้ไข Username และ Password ของ Super Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_super_admin'])) {
    $current_username = $_SESSION['username'];
    $new_username = trim($_POST['super_username'] ?? '');
    $new_password = $_POST['super_password'] ?? '';
    $confirm_password = $_POST['super_confirm_password'] ?? '';
    
    if (empty($new_username)) {
        $error_msg = "กรุณากรอก Username";
    } else {
        $pdo->beginTransaction();
        try {
            // เช็คว่า Username ซ้ำกับคนอื่นไหม (ยกเว้นตัวเอง)
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND username != ?");
            $stmt_check->execute([$new_username, $current_username]);
            if ($stmt_check->fetchColumn() > 0) {
                $error_msg = "Username นี้ถูกใช้งานแล้ว กรุณาเลือกใช้ชื่ออื่น";
            } else {
                if (!empty($new_password)) {
                    if (strlen($new_password) < 6) {
                        $error_msg = "รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร";
                    } elseif ($new_password !== $confirm_password) {
                        $error_msg = "รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน";
                    } else {
                        // เปลี่ยนทั้ง Username และ Password
                        $hashed_pwd = password_hash($new_password, PASSWORD_DEFAULT);
                        
                        if ($current_username !== $new_username) {
                            $stmt_up = $pdo->prepare("UPDATE users SET username = ?, password = ? WHERE username = ? AND role = 'super_admin'");
                            $stmt_up->execute([$new_username, $hashed_pwd, $current_username]);
                        } else {
                            $stmt_up = $pdo->prepare("UPDATE users SET password = ? WHERE username = ? AND role = 'super_admin'");
                            $stmt_up->execute([$hashed_pwd, $current_username]);
                        }
                        
                        $_SESSION['username'] = $new_username;
                        $success_msg = "แก้ไขบัญชี Super Admin เรียบร้อยแล้ว (เปลี่ยนรหัสผ่านสำเร็จ)";
                    }
                } else {
                    // เปลี่ยนเฉพาะ Username
                    if ($current_username !== $new_username) {
                        $stmt_up = $pdo->prepare("UPDATE users SET username = ? WHERE username = ? AND role = 'super_admin'");
                        $stmt_up->execute([$new_username, $current_username]);
                        $_SESSION['username'] = $new_username;
                        $success_msg = "แก้ไข Username ของ Super Admin เรียบร้อยแล้ว (รหัสผ่านคงเดิม)";
                    } else {
                        $success_msg = "ไม่มีข้อมูลความเปลี่ยนแปลงทางบัญชีใดๆ";
                    }
                }
            }
            $pdo->commit();
            if (empty($error_msg)) {
                header("Location: super_admin.php?success_msg=" . urlencode($success_msg));
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        }
    }
}

// การจัดการอัปโหลดโลโก้ระบบและ PWA App Icon โดย Super Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_upload_pwa_logo'])) {
    $processed = false;
    $base64_data = $_POST['pwa_logo_base64'] ?? '';
    
    // วิธีหลัก: ผ่าน Base64 ที่ประมวลผลย่อและแปลงเสร็จสรรพจากเบราว์เซอร์แล้ว (เสถียรที่สุด ปราศจากปัญหาโฟลเดอร์ Read-only)
    if (!empty($base64_data)) {
        if (strpos($base64_data, 'data:image/') === 0) {
            try {
                // บันทึกระบบฐานข้อมูลคู่ (SYSTEM และโรงเรียนเริ่มต้น 31054002) เพื่อให้เรียกใช้งานได้ในทุกหน้าจอแบบไร้รอยต่อ
                $stmt = $pdo->prepare("INSERT INTO school_settings (school_code, setting_key, setting_value) 
                                       VALUES ('SYSTEM', 'system_logo', ?) 
                                       ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$base64_data, $base64_data]);
                
                $stmt2 = $pdo->prepare("INSERT INTO school_settings (school_code, setting_key, setting_value) 
                                       VALUES ('31054002', 'system_logo', ?) 
                                       ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt2->execute([$base64_data, $base64_data]);
                
                $processed = true;
            } catch (Exception $db_err) {
                // ละเว้นหากฐานข้อมูลยังไม่มีคอลัมน์แล้วพยายามทำส่วนถัดไป
            }
            
            // พยายามบันทึกเขียนลงดิสก์กายภาพของระบบ (ถ้าสิทธิ์ Permission อนุญาต) เพื่อความสมบูรณ์แบบ
            try {
                $dest_dir = __DIR__ . '/src/assets/images';
                if (!is_dir($dest_dir)) {
                    @mkdir($dest_dir, 0755, true);
                }
                $dest_path = $dest_dir . '/pwa_app_icon.jpg';
                
                $parts = explode(',', $base64_data);
                $payload = $parts[1] ?? '';
                $file_content = base64_decode($payload);
                if ($file_content) {
                    if (@file_put_contents($dest_path, $file_content) !== false) {
                        @chmod($dest_path, 0666);
                        $processed = true;
                    }
                }
            } catch (Exception $file_err) {
                // หากเขียนไฟล์ไม่ได้แต่บันทึกลง DB สำเร็จแล้ว ให้ดำเนินการต่อได้ทันที
            }
        }
    }
    
    // วิธีสำรอง: กรณีเบราว์เซอร์ปิดจาวาสคริปต์หรือใช้ฟอร์มอัปโหลดตรงธรรมดา
    if (!$processed && isset($_FILES['pwa_logo_file']) && $_FILES['pwa_logo_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['pwa_logo_file']['tmp_name'];
        $file_name = $_FILES['pwa_logo_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_ext, $allowed_exts)) {
            $raw_content = file_get_contents($file_tmp);
            if ($raw_content) {
                $mime_type = 'image/jpeg';
                if ($file_ext === 'png') $mime_type = 'image/png';
                elseif ($file_ext === 'gif') $mime_type = 'image/gif';
                
                $base64_val = 'data:' . $mime_type . ';base64,' . base64_encode($raw_content);
                
                try {
                    $stmt = $pdo->prepare("INSERT INTO school_settings (school_code, setting_key, setting_value) 
                                           VALUES ('SYSTEM', 'system_logo', ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$base64_val, $base64_val]);
                    
                    $stmt2 = $pdo->prepare("INSERT INTO school_settings (school_code, setting_key, setting_value) 
                                           VALUES ('31054002', 'system_logo', ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt2->execute([$base64_val, $base64_val]);
                    
                    $processed = true;
                } catch (Exception $db_err) {}
                
                try {
                    $dest_dir = __DIR__ . '/src/assets/images';
                    if (!is_dir($dest_dir)) {
                        @mkdir($dest_dir, 0755, true);
                    }
                    $dest_path = $dest_dir . '/pwa_app_icon.jpg';
                    if (@file_put_contents($dest_path, $raw_content) !== false) {
                        @chmod($dest_path, 0666);
                        $processed = true;
                    }
                } catch (Exception $file_err) {}
            }
        } else {
            $error_msg = "อนุญาตเฉพาะไฟล์รูปภาพสกุล JPG, JPEG, PNG หรือ GIF เท่านั้น";
        }
    }
    
    if ($processed) {
        // อัปเดตไฟล์ manifest.json สำหรับแคชไอคอน PWA ล่าสุด ให้เปลี่ยนแคชบัสเพื่ออัปเดตบนมือถือทันที
        try {
            $manifest_path = __DIR__ . '/manifest.json';
            $manifest = [
                "name" => "ระบบนิเทศชั้นเรียน",
                "short_name" => "ระบบนิเทศชั้นเรียน",
                "description" => "ระบบนิเทศการจัดการเรียนการสอนระดับโรงเรียน",
                "start_url" => "/dashboard.php",
                "display" => "standalone",
                "background_color" => "#F5F7FA",
                "theme_color" => "#1565C0",
                "orientation" => "portrait",
                "scope" => "/",
                "icons" => [
                    [
                        "src" => "/get_logo.php?v=" . time(),
                        "sizes" => "512x512",
                        "type" => "image/jpeg",
                        "purpose" => "any maskable"
                    ]
                ]
            ];
            @file_put_contents($manifest_path, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (Exception $manifest_err) {}

        $success_msg = "อัปเดตโลโก้ระบบและ PWA App Icon สำหรับใช้ติดตั้งบนสมาร์ตโฟน/แท็บเล็ต สำเร็จเรียบร้อยแล้ว!";
        header("Location: super_admin.php?success_msg=" . urlencode($success_msg));
        exit;
    } else {
        if (!isset($error_msg)) {
            $error_msg = "เกิดข้อผิดพลาดในการบันทึกภาพโลโก้ กรุณาลองอัปโหลดใหม่อีกครั้ง";
        }
    }
}

// การอัปเดตสถานะโรงเรียนและการจัดลงทะเบียนตั้งต้น (Bootstrap school)
if (isset($_GET['approve_code'])) {
    $approve_code = trim($_GET['approve_code']);
    
    // โหลดรายละเอียดโรงเรียน
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE school_code = ?");
    $stmt->execute([$approve_code]);
    $school = $stmt->fetch();
    
    if ($school) {
        $pdo->beginTransaction();
        try {
            // อัปเดตสถานะในตารางโรงเรียน
            $stmt_up = $pdo->prepare("UPDATE schools SET status = 'approved' WHERE school_code = ?");
            $stmt_up->execute([$approve_code]);
            
            // ตรวจสอบเช็คว่ามีการจัดลงตั้งชื่อตารางตั้งค่าไว้หรือยัง
            $check_settings = $pdo->prepare("SELECT COUNT(*) FROM school_settings WHERE school_code = ?");
            $check_settings->execute([$approve_code]);
            if ($check_settings->fetchColumn() == 0) {
                // ลงข้อมูลตั้งค่าเริ่มต้นสำหรับโรงเรียนใหม่นี้
                $stmt_sett = $pdo->prepare("INSERT INTO school_settings (school_code, setting_key, setting_value) VALUES (?, ?, ?)");
                $stmt_sett->execute([$approve_code, 'school_logo', '']);
                $stmt_sett->execute([$approve_code, 'school_name', "ระบบนิเทศการจัดการเรียนการสอน" . htmlspecialchars($school['school_name']) . " " . htmlspecialchars($school['affiliation'])]);
            }
            
            // ลงระดับชั้นเรียนตั้งต้นตามมาตรฐานไทย
            $check_classes = $pdo->prepare("SELECT COUNT(*) FROM classrooms WHERE school_code = ?");
            $check_classes->execute([$approve_code]);
            if ($check_classes->fetchColumn() == 0) {
                $default_classes = [
                    'ประถมศึกษาปีที่ 1', 'ประถมศึกษาปีที่ 2', 'ประถมศึกษาปีที่ 3',
                    'ประถมศึกษาปีที่ 4', 'ประถมศึกษาปีที่ 5', 'ประถมศึกษาปีที่ 6',
                    'มัธยมศึกษาปีที่ 1', 'มัธยมศึกษาปีที่ 2', 'มัธยมศึกษาปีที่ 3',
                    'มัธยมศึกษาปีที่ 4', 'มัธยมศึกษาปีที่ 5', 'มัธยมศึกษาปีที่ 6'
                ];
                $stmt_class = $pdo->prepare("INSERT IGNORE INTO classrooms (class_name, school_code) VALUES (?, ?)");
                foreach ($default_classes as $cls) {
                    $stmt_class->execute([$cls, $approve_code]);
                }
            }
            
            // ลงปีการศึกษาตั้งต้นเพื่อไม่ให้แดชบอร์ดล้มคำนวณ
            $check_years = $pdo->prepare("SELECT COUNT(*) FROM academic_years WHERE school_code = ?");
            $check_years->execute([$approve_code]);
            if ($check_years->fetchColumn() == 0) {
                $current_year = date('Y') + 543;
                $new_year_id = "YR{$current_year}-1-{$approve_code}";
                $stmt_year = $pdo->prepare("INSERT INTO academic_years (year_id, year, semester, school_code) VALUES (?, ?, '1', ?)");
                $stmt_year->execute([$new_year_id, $current_year, $approve_code]);
            }

            $pdo->commit();
            $success_msg = "อนุมัติเปิดสิทธิ์งานใช้งานโรงเรียน '{$school['school_name']}' และลงรหัสข้อมูลมาตรฐานเริ่มต้นพร้อมทำงานแล้วเรียบร้อย";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "ผิดพลาดในระบบอนุมัติบูทสแตรป: " . $e->getMessage();
        }
    } else {
        $error_msg = "ไม่พบโรงเรียนในสารบบบันทึกประวัติ";
    }
}

// การระงับสิทธิ์การใช้งาน
if (isset($_GET['deactivate_code'])) {
    $deactivate_code = trim($_GET['deactivate_code']);
    $stmt = $pdo->prepare("UPDATE schools SET status = 'deactivated' WHERE school_code = ?");
    $stmt->execute([$deactivate_code]);
    $success_msg = "ระงับสิทธิ์เข้าใช้งานระบบตามรหัส SMISS {$deactivate_code} เรียบร้อยแล้ว";
}

// การตั้งค่า/แก้ไขพาธ Google Drive และพารามิเตอร์ Apps Script ของแต่ละโรงเรียน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_gdrive'])) {
    $edit_school_code = trim($_POST['edit_school_code'] ?? '');
    $gdrive_app_url = trim($_POST['gdrive_app_url'] ?? '');
    $gdrive_folder_id = trim($_POST['gdrive_folder_id'] ?? '');
    
    if (!empty($edit_school_code)) {
        $stmt_g = $pdo->prepare("UPDATE schools SET gdrive_app_url = ?, gdrive_folder_id = ? WHERE school_code = ?");
        $stmt_g->execute([$gdrive_app_url, $gdrive_folder_id, $edit_school_code]);
        $success_msg = "ปรับปรุงข้อมูลจุดเชื่อมต่อ Google Drive สำหรับโรงเรียนรหัส {$edit_school_code} เรียบร้อยแล้ว";
    } else {
        $error_msg = "กรุณาระบุกรอกข้อมูลโรงเรียนให้ถูกต้อง";
    }
}

// การตั้งค่า/แก้ไขข้อมูลพื้นฐานโรงเรียน (รวมถึงการปรับปรุงรหัส SMISS เครือข่ายแบบ Cascade เพื่อไม่ให้เกิดข้อมูลค้าง/สูญหาย)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_school'])) {
    $old_school_code = trim($_POST['old_school_code'] ?? '');
    $new_school_code = trim($_POST['new_school_code'] ?? '');
    $school_name = trim($_POST['school_name'] ?? '');
    $affiliation = trim($_POST['affiliation'] ?? '');
    
    if (empty($old_school_code) || empty($new_school_code) || empty($school_name) || empty($affiliation)) {
        $error_msg = "กรุณาระบุกรอกข้อมูลโรงเรียนให้ครบถ้วนทุกช่อง";
    } else if (strlen($new_school_code) !== 8 || !is_numeric($new_school_code)) {
        $error_msg = "รหัส SMISS โรงเรียนต้องเป็นตัวเลขความยาว 8 หลักเท่านั้น";
    } else {
        // ตรวจสอบความซ้ำซ้อนของรหัสใหม่ (หากมีการเปลี่ยนแปลงรหัสโรงเรียน)
        $stmt_chk = $pdo->prepare("SELECT COUNT(*) FROM schools WHERE school_code = ? AND school_code != ?");
        $stmt_chk->execute([$new_school_code, $old_school_code]);
        if ($stmt_chk->fetchColumn() > 0) {
            $error_msg = "รหัส SMISS ใหม่ '{$new_school_code}' ได้ถูกจดทะเบียนไว้ในระบบแล้ว กรุณาเลือกใช้รหัสอื่น";
        } else {
            $pdo->beginTransaction();
            try {
                if ($old_school_code !== $new_school_code) {
                    // ดึงรายละเอียดเก่าเพื่อรักษาสถานะดั้งเดิมไว้
                    $stmt_cur = $pdo->prepare("SELECT * FROM schools WHERE school_code = ?");
                    $stmt_cur->execute([$old_school_code]);
                    $cur_sch = $stmt_cur->fetch();
                    
                    if ($cur_sch) {
                        // ปูพรมแทรกแถวรหัสโรงเรียนใหม่เข้าไป
                        $stmt_ins = $pdo->prepare("INSERT INTO schools (school_code, school_name, affiliation, status, gdrive_app_url, gdrive_folder_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt_ins->execute([
                            $new_school_code,
                            $school_name,
                            $affiliation,
                            $cur_sch['status'],
                            $cur_sch['gdrive_app_url'],
                            $cur_sch['gdrive_folder_id'],
                            $cur_sch['created_at']
                        ]);

                        // ทยอยอัพเดทตารางบริวารทั้งหมดแบบประสานกุญแจหลัก
                        $tables = ['school_settings', 'teachers', 'academic_years', 'supervisions', 'classrooms', 'users'];
                        foreach ($tables as $tbl) {
                            $stmt_up = $pdo->prepare("UPDATE `{$tbl}` SET school_code = ? WHERE school_code = ?");
                            $stmt_up->execute([$new_school_code, $old_school_code]);
                        }
                        
                        // ลบแถวเก่าออกอย่างเป็นระเบียบ
                        $stmt_del = $pdo->prepare("DELETE FROM schools WHERE school_code = ?");
                        $stmt_del->execute([$old_school_code]);
                    }
                } else {
                    // หากไม่มีการเปลี่ยนรหัสโรงเรียน ให้อัปเดตรายละเอียดทั่วไปเท่านั้น
                    $stmt_up = $pdo->prepare("UPDATE schools SET school_name = ?, affiliation = ? WHERE school_code = ?");
                    $stmt_up->execute([$school_name, $affiliation, $old_school_code]);
                }
                
                $pdo->commit();
                $success_msg = "ปรับปรุงข้อมูลโรงเรียนและรหัส SMISS ย้ายเครือข่ายเป็น {$new_school_code} สำเร็จเรียบร้อย ลิงก์คุณครูและประเมินทั้งหมดได้รับการอัปเดตอย่างสมบูรณ์";
                header("Location: super_admin.php?success_msg=" . urlencode($success_msg));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_msg = "เกิดข้อผิดพลาดในการอัปเดตข้อมูลแบบกลุ่ม: " . $e->getMessage();
            }
        }
    }
}

// โหลดรายชื่อโรงเรียนทั้งหมดในฐานระบบ
$all_schools = $pdo->query("SELECT * FROM schools ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ส่วนควบคุมส่วนระบบสูงสุด - Super Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', 'Inter', sans-serif; } </style>
</head>
<body class="bg-[#FAF8F5] min-h-screen text-slate-900 duration-200">

    <!-- Navbar Super Admin Portal -->
    <header class="bg-[#0f172a] text-white border-b-4 border-amber-500 shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-4 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-3">
                <span class="text-3xl">🛡️</span>
                <div>
                    <h1 class="text-base sm:text-lg font-extrabold tracking-wide text-white leading-snug">
                        หน้าต่างควบคุมและพิจารณาอนุมัติระบบเครือข่ายโรงเรียน (Super Admin Portal)
                    </h1>
                    <p class="text-[10px] text-amber-400 font-bold block uppercase tracking-wider">
                        Consolidated Client-Tenant Network Administration Dashboard
                    </p>
                </div>
            </div>

            <!-- Profile & Portal buttons -->
            <div class="flex items-center gap-3 text-xs bg-slate-800 p-2 px-4 rounded-xl border border-white/10">
                <div class="text-right">
                    <span class="font-bold text-amber-200 block text-[11px]">ADMINISTRATOR</span>
                    <span class="text-[9px] text-slate-300">สิทธิ์: SUPER ADMIN</span>
                </div>
                <div>
                    <a href="logout.php" class="bg-red-650 hover:bg-red-700 text-white font-bold p-1 px-2.5 rounded text-[10px] text-center transition shadow block">ออกจากระบบ</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Workspace -->
    <main class="max-w-7xl mx-auto px-4 py-8 space-y-6">

        <!-- Top Overview Alerts -->
        <?php if ($success_msg): ?>
            <div class="text-xs bg-emerald-50 border border-emerald-250 text-emerald-800 p-4 rounded-2xl font-bold">
                🎉 สำเร็จ: <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="text-xs bg-rose-50 border border-rose-250 text-rose-800 p-4 rounded-2xl font-bold">
                ⚠️ พลาด: <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

            <!-- Section 1: Schools Management Table (lg:col-span-8) -->
            <div class="lg:col-span-8 bg-white border border-slate-200 rounded-3xl shadow-sm p-6 space-y-4">
                <div class="border-b pb-3 flex justify-between items-center flex-wrap gap-2">
                    <div>
                        <h2 class="font-extrabold text-[#0A3370] text-sm">สารบบโรงเรียนเครือข่ายพันธมิตรร่วมระบบ</h2>
                        <p class="text-[10px] text-slate-400 mt-0.5">พิจารณากลุ่มสิทธิ์ความปลอดภัย การสมัครงานเข้าใช้ และตั้งค่าที่เก็บไฟล์ของแต่ละโรงเรียน</p>
                    </div>
                    <span class="px-2.5 py-0.5 bg-[#0f172a] text-amber-400 text-[10px] font-bold rounded-full">
                        ลงทะเบียนแล้ว <?php echo count($all_schools); ?> โรงเรียน
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-slate-50 text-slate-650 font-extrabold text-[10.5px] uppercase">
                            <tr>
                                <th class="p-3 border-b border-slate-100">รหัส SMISS</th>
                                <th class="p-3 border-b border-slate-100">ชื่อโรงเรียน / สังกัดผู้บริการ</th>
                                <th class="p-3 border-b border-slate-100 text-center">สถานะ</th>
                                <th class="p-3 border-b border-slate-100 text-center">ปฏิบัติการพิกัดสิทธิ์</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs text-slate-700">
                            <?php if (empty($all_schools)): ?>
                                <tr>
                                    <td colspan="4" class="p-8 text-center text-slate-500">📭 ไม่มีประวัติโรงเรียนอื่นเข้ามาขอสมัครขณะนี้</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_schools as $sch): ?>
                                    <tr class="hover:bg-slate-50/70 transition">
                                        <!-- SMISS Code -->
                                        <td class="p-3 font-mono font-bold text-[#0A3370]">
                                            <?php echo htmlspecialchars($sch['school_code']); ?>
                                        </td>
                                        <!-- School context -->
                                        <td class="p-3 font-medium">
                                            <span class="font-bold text-slate-900 block"><?php echo htmlspecialchars($sch['school_name']); ?></span>
                                            <span class="text-[9.5px] text-slate-500 block mt-0.5"><?php echo htmlspecialchars($sch['affiliation']); ?></span>
                                            
                                            <!-- Configured Drive info badge -->
                                            <?php if (!empty($sch['gdrive_app_url']) && !empty($sch['gdrive_folder_id'])): ?>
                                                <div class="mt-1 flex items-center gap-1">
                                                    <span class="px-1.5 py-0.2 bg-indigo-50 text-indigo-700 border border-indigo-150 rounded text-[8.5px] font-bold">☁️ เชื่อมต่อ Google Drive สำเร็จ</span>
                                                </div>
                                            <?php else: ?>
                                                <div class="mt-1 flex items-center gap-1">
                                                    <span class="px-1.5 py-0.2 bg-slate-50 text-slate-400 border border-slate-150 rounded text-[8.5px]">💾 บันทึกรูปภาพในเซิร์ฟเวอร์หลัก (Local)</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Status Badge -->
                                        <td class="p-3 text-center">
                                            <?php if ($sch['status'] === 'approved'): ?>
                                                <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded font-bold text-[10px]">🟢 อนุมัติใช้งาน</span>
                                            <?php elseif ($sch['status'] === 'deactivated'): ?>
                                                <span class="px-2 py-0.5 bg-rose-100 text-rose-800 rounded font-bold text-[10px]">🔴 ระงับใช้งาน</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 bg-amber-100 text-amber-800 rounded font-bold text-[10px] animate-pulse">🟡 รอพิจารณาอนุมัติ</span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Operations actions -->
                                        <td class="p-3 text-center">
                                            <div class="flex flex-col gap-1.5 items-center justify-center">
                                                <div class="flex gap-1 flex-wrap justify-center">
                                                    <?php if ($sch['status'] !== 'approved'): ?>
                                                        <a href="super_admin.php?approve_code=<?php echo urlencode($sch['school_code']); ?>" class="p-1 px-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-250 rounded font-bold text-[10px]" title="อนุมัติการใช้งาน">
                                                            ✅ อนุมัติ
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="super_admin.php?deactivate_code=<?php echo urlencode($sch['school_code']); ?>" onclick="return confirm('ยืนยันประสงค์ต้องการระงับสิทธิ์การใช้งานของโรงเรียนนี้ชั่วคราว? ผู้ใช้งานทั้งหมดสังกัดโรงเรียนจะไม่สามารถเข้าสู่ระบบประเมินได้')" class="p-1 px-2 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-250 rounded font-bold text-[10px]" title="ระงับความสิทธิ์">
                                                            🚫 ระงับใช้งาน
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <button type="button" onclick="toggleEditSchoolForm('<?php echo htmlspecialchars($sch['school_code']); ?>', '<?php echo htmlspecialchars($sch['school_name']); ?>', '<?php echo htmlspecialchars($sch['affiliation']); ?>')" class="p-1 px-2 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-250 rounded font-bold text-[10px]" title="แก้ไขข้อมูลโรงเรียน">
                                                        ✏️ แก้ไขข้อมูล
                                                    </button>

                                                    <!-- Javascript toggle formula to display Drive input modal/panel below -->
                                                    <button type="button" onclick="toggleDriveForm('<?php echo htmlspecialchars($sch['school_code']); ?>', '<?php echo htmlspecialchars($sch['gdrive_app_url']); ?>', '<?php echo htmlspecialchars($sch['gdrive_folder_id']); ?>')" class="p-1 px-2 bg-slate-50 hover:bg-indigo-50 text-indigo-700 border border-slate-200 rounded font-bold text-[10px]" title="ตั้งค่าคลังภาพ Google Drive">
                                                        ⚙️ ตั้งค่าคลังภาพ
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Hidden / Dynamic Drive Configuration Card -->
                <div id="drive_config_card" class="hidden bg-slate-50 border border-slate-200 rounded-2xl p-4 mt-4 space-y-3">
                    <h3 class="font-extrabold text-[#0A3370] text-xs">ตั้งค่าคลังจัดเก็บ Google Drive รายโรงเรียน <span id="drive_target_school" class="font-mono text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded ml-1"></span></h3>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-12 gap-3 text-xs">
                        <input type="hidden" name="action_save_gdrive" value="1">
                        <input type="hidden" name="edit_school_code" id="drive_input_school_code" value="">
                        
                        <div class="md:col-span-5 space-y-1">
                            <label class="font-bold text-slate-500 block">Google Apps Script Web App URL *</label>
                            <input type="url" name="gdrive_app_url" id="drive_input_app_url" placeholder="https://script.google.com/macros/s/.../exec" required class="w-full p-2 bg-white border border-slate-200 rounded-lg outline-none font-mono">
                        </div>
                        <div class="md:col-span-4 space-y-1">
                            <label class="font-bold text-slate-500 block">Google Drive Folder ID *</label>
                            <input type="text" name="gdrive_folder_id" id="drive_input_folder_id" placeholder="รหัสโฟลเดอร์ใน Google Drive" required class="w-full p-2 bg-white border border-slate-200 rounded-lg outline-none font-mono">
                        </div>
                        <div class="md:col-span-3 flex items-end gap-1">
                            <button type="submit" class="w-full py-2 bg-[#0A3370] text-white font-bold rounded-lg transition-transform hover:scale-95 cursor-pointer text-center">
                                บันทึกชุดตั้งค่า
                            </button>
                            <button type="button" onclick="document.getElementById('drive_config_card').classList.add('hidden')" class="py-2 px-3 bg-slate-200 text-slate-600 font-bold rounded-lg hover:bg-slate-300">
                                ปิด
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Hidden / Dynamic School Edit Card -->
                <div id="school_edit_card" class="hidden bg-amber-50/40 border border-amber-200 rounded-2xl p-5 mt-4 space-y-3 shadow-inner">
                    <h3 class="font-extrabold text-[#0A3370] text-xs flex items-center gap-1.5">
                        <span>✏️ แก้ไขข้อมูลโรงเรียน / รหัส SMISS เครือข่าย</span>
                        <span id="edit_target_school" class="font-mono text-amber-700 bg-amber-100/80 px-2 py-0.5 rounded text-[10px] ml-1 font-bold"></span>
                    </h3>
                    <p class="text-[10px] text-slate-500 leading-normal">
                        <strong>🛡️ คำเตือนกรณีแก้ไขรหัสโรงเรียน (SMISS Code):</strong> 
                        เมื่อเปลี่ยนรหัส SMISS ระบบจะทำการอัปเดตแกนความสัมพันธ์ (<code class="bg-[#e2e8f0] px-1 rounded font-mono font-bold text-slate-700">school_code</code>) 
                        ของบัญชีผู้ใช้ครูผู้สอน, ปีการศึกษา, ระเบียบสถิติสิทธิ์ทั้งหมด เข้าสู่เลขรหัสใหม่ให้อัตโนมัติ ป้องกันข้อมูลสูญหายโดยสิ้นเชิง
                    </p>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-12 gap-3 text-xs">
                        <input type="hidden" name="action_edit_school" value="1">
                        <input type="hidden" name="old_school_code" id="edit_input_old_school_code" value="">
                        
                        <div class="md:col-span-3 space-y-1">
                            <label class="font-bold text-slate-600 block">รหัส SMISS 8 หลักใหม่ *</label>
                            <input type="text" name="new_school_code" id="edit_input_new_school_code" maxlength="8" required class="w-full p-2 bg-white border border-slate-200 rounded-lg outline-none font-bold text-amber-800 font-mono tracking-wider focus:ring-2 focus:ring-[#0A3370]">
                        </div>
                        <div class="md:col-span-5 space-y-1">
                            <label class="font-bold text-slate-600 block">ชื่อโรงเรียนสถานศึกษาจริง *</label>
                            <input type="text" name="school_name" id="edit_input_school_name" required class="w-full p-2 bg-white border border-slate-200 rounded-lg outline-none font-semibold text-slate-800 focus:ring-2 focus:ring-[#0A3370]">
                        </div>
                        <div class="md:col-span-4 space-y-1">
                            <label class="font-bold text-slate-600 block">ชื่อส่วนงานสังกัดราชการ *</label>
                            <input type="text" name="affiliation" id="edit_input_affiliation" required class="w-full p-2 bg-white border border-slate-200 rounded-lg outline-none text-slate-700 focus:ring-2 focus:ring-[#0A3370]">
                        </div>
                        
                        <div class="md:col-span-12 flex justify-end gap-1.5 pt-1">
                            <button type="submit" class="py-2 px-5 bg-gradient-to-r from-[#0A3370] to-indigo-850 text-white font-bold rounded-lg transition hover:opacity-95 shadow cursor-pointer border-none text-center">
                                💾 บันทึกปรับปรุงข้อมูล
                            </button>
                            <button type="button" onclick="document.getElementById('school_edit_card').classList.add('hidden')" class="py-2 px-4 bg-slate-200 hover:bg-slate-300 text-slate-600 font-bold rounded-lg transition border-none cursor-pointer">
                                ยกเลิกการแก้ไข
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Column 2 (lg:col-span-4) -->
            <div class="lg:col-span-4 space-y-6">

                <!-- Section: Edit Super Admin Account -->
                <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm space-y-4">
                    <div class="border-b pb-3">
                        <h2 class="font-extrabold text-[#0A3370] text-sm flex items-center gap-1.5">
                            <span>🔑 ตั้งค่าบัญชี Super Admin</span>
                        </h2>
                        <p class="text-[10px] text-slate-400 mt-0.5">แก้ไข Username และรหัสผ่านเข้าศูนย์ควบคุมระบบสูงสุด</p>
                    </div>

                    <form method="POST" class="space-y-4 text-xs">
                        <input type="hidden" name="action_update_super_admin" value="1">
                        
                        <div class="space-y-1">
                            <label class="font-bold text-slate-500 block">Username สูงสุด *</label>
                            <input type="text" name="super_username" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" required class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none font-bold text-[#0A3370] font-mono tracking-wider focus:ring-1 focus:ring-[#0A3370] focus:bg-white transition-all">
                        </div>

                        <div class="space-y-1">
                            <label class="font-bold text-slate-500 block">รหัสผ่านใหม่ <span class="text-slate-400 font-normal">(เว้นว่างหากไม่ต้องการเปลี่ยน)</span></label>
                            <input type="password" name="super_password" placeholder="ป้อนรหัสผ่านใหม่ (อย่างน้อย 6 ตัวอักษร)" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none font-mono focus:ring-1 focus:ring-[#0A3370] focus:bg-white transition-all">
                        </div>

                        <div class="space-y-1">
                            <label class="font-bold text-slate-500 block">ยืนยันรหัสผ่านใหม่อีกครั้ง</label>
                            <input type="password" name="super_confirm_password" placeholder="ยืนยันรหัสผ่านใหม่อีกครั้ง" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none font-mono focus:ring-1 focus:ring-[#0A3370] focus:bg-white transition-all">
                        </div>

                        <button type="submit" class="w-full py-2.5 bg-[#0A3370] hover:bg-[#0f172a] text-white font-bold rounded-xl transition-all shadow cursor-pointer border-none text-center">
                            💾 บันทึกการเปลี่ยนแปลงบัญชี
                        </button>
                    </form>
                </div>

                <!-- Section: System Logo & PWA App Icon Setup -->
                <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm space-y-4">
                    <div class="border-b pb-3">
                        <h2 class="font-extrabold text-[#0A3370] text-sm flex items-center gap-1.5">
                            <span>📱 ตั้งค่าโลโก้ระบบ & PWA App Icon</span>
                        </h2>
                        <p class="text-[10px] text-slate-400 mt-0.5">เปลี่ยนรูปภาพโลโก้หลักของระบบ และไอคอนที่แสดงตอนติดตั้งบนโทรศัพท์มือถือ แท็บเล็ต หรือพีซี</p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs" id="pwa-logo-form">
                        <input type="hidden" name="action_upload_pwa_logo" value="1">
                        <input type="hidden" name="pwa_logo_base64" id="pwa_logo_base64" value="">
                        
                        <!-- Current Logo Display & Interactive Preview Container -->
                        <div class="flex items-center gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-100 relative overflow-hidden">
                            <div class="relative w-16 h-16 rounded-2xl overflow-hidden border-2 border-slate-200 bg-white flex-shrink-0 flex items-center justify-center shadow-inner group">
                                <img id="pwa-logo-preview" src="<?php echo get_system_logo_url(); ?>" class="w-full h-full object-cover transition-all duration-300" alt="System Logo Preview">
                                
                                <!-- Inner Spinner Overlay -->
                                <div id="pwa-logo-spinner" class="absolute inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center text-white opacity-0 transition-opacity duration-200 pointer-events-none">
                                    <svg class="animate-spin h-5 w-5 text-sky-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="space-y-1.5 flex-1 min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[10px] text-slate-400 font-extrabold uppercase tracking-wider">สถานะรูปภาพ</span>
                                    <span id="pwa-logo-status-badge" class="bg-blue-50 text-blue-700 border border-blue-150 px-2 py-0.5 rounded text-[9px] font-extrabold transition-all">ดึงจากฐานข้อมูลระบบ</span>
                                </div>
                                <h4 class="font-extrabold text-slate-700 text-xs truncate" id="pwa-logo-filename">pwa_app_icon.jpg</h4>
                                <p class="text-[9px] text-slate-400 leading-normal">ใช้ประทับตราหัวเมนู แฟฟิคอนเว็บแอพฯ และภาพไอคอนติดตั้ง PWA บนโทรศัพท์มือถือ</p>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label class="font-bold text-slate-600 block">เลือกไฟล์รูปภาพโลโก้ใหม่ *</label>
                            <input type="file" id="pwa_logo_file_input" accept="image/*" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-[10.5px] file:font-semibold file:bg-blue-50 file:text-[#0A3370] hover:file:bg-blue-100 cursor-pointer">
                            <p class="text-[9px] text-slate-400 leading-normal mt-1">💡 แนะนำ: ใช้ภาพรูปทรงจัตุรัสระบบจะทำการแปลงและย่อภาพเป็น 512x512 พิกเซลที่มีสัดส่วนสมบูรณ์แบบบนโทรศัพท์มือถือ/แท็บเล็ตให้ทันทีก่อนการจัดเก็บ</p>
                        </div>

                        <button type="submit" id="pwa-submit-btn" class="w-full py-2.5 bg-gradient-to-r from-blue-900 to-[#0A3370] hover:opacity-95 text-white font-bold rounded-xl transition-all shadow-md cursor-pointer border-none text-center flex items-center justify-center gap-1.5">
                            <span>📤 อัปโหลดและเปลี่ยนโลโก้ระบบ</span>
                        </button>
                    </form>

                    <!-- Client-side Interactive Core Canvas Logic -->
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const fileInput = document.getElementById('pwa_logo_file_input');
                        const base64Input = document.getElementById('pwa_logo_base64');
                        const previewImg = document.getElementById('pwa-logo-preview');
                        const statusBadge = document.getElementById('pwa-logo-status-badge');
                        const filenameLabel = document.getElementById('pwa-logo-filename');
                        const spinner = document.getElementById('pwa-logo-spinner');

                        if (fileInput) {
                            fileInput.addEventListener('change', function() {
                                if (this.files && this.files[0]) {
                                    const file = this.files[0];
                                    filenameLabel.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                                    
                                    // แสดงสปินเนอร์และอัปเดตสถานะบาร์
                                    spinner.style.opacity = '1';
                                    statusBadge.textContent = 'กำลังประมวลผลรูปภาพ...';
                                    statusBadge.className = 'bg-amber-50 text-amber-700 border border-amber-150 px-2 py-0.5 rounded text-[9px] font-extrabold animate-pulse';
                                    
                                    const reader = new FileReader();
                                    reader.onload = function(e) {
                                        const img = new Image();
                                        img.onload = function() {
                                            // ปรับความละเอียดให้สมบูรณ์สำหรับ PWA Icon (512x512)
                                            const size = 512;
                                            const canvas = document.createElement('canvas');
                                            canvas.width = size;
                                            canvas.height = size;
                                            
                                            const ctx = canvas.getContext('2d');
                                            // ทำพื้นหลังสีขาวกรณีภาพโปร่งแสง (.PNG)
                                            ctx.fillStyle = '#FFFFFF';
                                            ctx.fillRect(0, 0, size, size);
                                            
                                            // ทำการคำนวณตัดภาพให้เป็นทรงจัตุรัสแบบกึ่งกลางพอดี (Center Cropping)
                                            let srcX = 0;
                                            let srcY = 0;
                                            let srcSize = img.width;
                                            
                                            if (img.width > img.height) {
                                                srcSize = img.height;
                                                srcX = (img.width - img.height) / 2;
                                            } else {
                                                srcSize = img.width;
                                                srcY = (img.height - img.width) / 2;
                                            }
                                            
                                            // วาดลงแคนวาส
                                            ctx.drawImage(img, srcX, srcY, srcSize, srcSize, 0, 0, size, size);
                                            
                                            // แปลงเป็นข้อมูลภาพ Base64 คุณภาพสูงแบบ JPEG
                                            const compressedBase64 = canvas.toDataURL('image/jpeg', 0.95);
                                            
                                            // เก็บค่า Base64 ลง Hidden Input และเปลี่ยนรูปตัวอย่างทันที
                                            base64Input.value = compressedBase64;
                                            previewImg.src = compressedBase64;
                                            
                                            // ปิดสปินเนอร์อัปเดตความเสถียร
                                            spinner.style.opacity = '0';
                                            statusBadge.textContent = '✓ ภาพตัวอย่างใหม่พร้อมบันทึก';
                                            statusBadge.className = 'bg-emerald-50 text-emerald-700 border border-emerald-150 px-2 py-0.5 rounded text-[9px] font-extrabold';
                                        };
                                        img.onerror = function() {
                                            spinner.style.opacity = '0';
                                            statusBadge.textContent = '❌ โหลดภาพไม่สำเร็จ';
                                            statusBadge.className = 'bg-rose-50 text-rose-700 border border-rose-150 px-2 py-0.5 rounded text-[9px] font-extrabold';
                                        };
                                        img.src = e.target.result;
                                    };
                                    reader.onerror = function() {
                                        spinner.style.opacity = '0';
                                        statusBadge.textContent = '❌ อ่านไฟล์ภาพไม่สำเร็จ';
                                        statusBadge.className = 'bg-rose-50 text-rose-700 border border-rose-150 px-2 py-0.5 rounded text-[9px] font-extrabold';
                                    };
                                    reader.readAsDataURL(file);
                                }
                            });
                        }
                    });
                    </script>
                </div>

                <!-- Section 2: Instructions and Google Apps Script Code block -->
                <div class="bg-[#0f172a] text-slate-200 border border-slate-800 rounded-3xl p-6 shadow-xl space-y-4">
                <div>
                    <h2 class="font-extrabold text-amber-400 text-sm flex items-center gap-1">🛠️ คู่มือชุดเชื่อม Google Drive (GAS Engine)</h2>
                    <p class="text-[9.5px] text-slate-400 mt-1 leading-relaxed">คัดลอกรหัสซอร์สโค้ดด้านล่าง เพื่อนำไปใช้สร้างชุดบริการ Google Apps Script ไปยังสัญญูของบุคคลผู้ใช้อีเมลโรงเรียนนั้นๆ เพื่อให้อัปโหลดจัดเก็บรูปคุณครูและรูปประเมินนิเทศลง Google Drive ของโรงเรียนนั้นโดยอัตโนมัติ</p>
                </div>

                <!-- Steps Description -->
                <div class="text-[10px] space-y-2 text-slate-300 border-l border-slate-700 pl-3 leading-relaxed">
                    <p><strong class="text-amber-300">ขั้นตอนที่ 1:</strong> เข้าสู่หน้าเว็บ <a href="https://script.google.com" target="_blank" class="text-sky-400 underline">script.google.com</a> ด้วยบัญชีของโรงเรียนนั้นๆ</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 2:</strong> กดสร้าง "โครงการใหม่" (New Project)</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 3:</strong> ลบโค้ดเดิมออกทั้งหมด และวางสคริปต์ (GAS Code) ด้านล่างลงไปแทนที่</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 4:</strong> กดปุ่ม "การทำให้ใช้งานได้" (Deploy) > เลือก "การทำให้ใช้งานได้เพื่อเป็นเว็บแอป" (New Deployment as Web App)</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 5:</strong> ตั่งคำสั่งสิทธิ์เข้าถึง: <strong>"ผู้ที่มีสิทธิ์เข้าถึง: ทุกคน (Anyone)"</strong> และคลิก Deploy เพื่อเชื่อมอนุญาตยอมรับสิทธิ์ไดรฟ์</p>
                    <p><strong class="text-amber-300">ขั้นตอนที่ 6:</strong> คัดลอก "URL ของเว็บแอป" (Web App URL) แนะนำส่งให้ Super Admin กรอกใส่ระบบพอร์ตโรงเรียน</p>
                </div>

                <!-- Google Apps Script Code Copy area -->
                <div class="space-y-1">
                    <div class="flex justify-between items-center text-[9px] text-slate-400">
                        <span>📋 ซอร์สโค้ด Google Apps Script (คลิกเพื่อคัดลอกทั้งหมด):</span>
                    </div>
                    <textarea class="w-full h-44 bg-slate-950 text-emerald-400 font-mono text-[9px] p-2.5 rounded-xl border border-slate-800 focus:outline-none cursor-pointer" readonly onclick="this.select(); alert('คัดลอกซอร์สโค้ด GAS เรียบร้อยแล้ว! สามารถนำไปวางที่หน้า Google Apps Script ได้ทันทีครับ')"><?php echo htmlspecialchars('/**
 * ระบบเชื่อมตรงคลังรูปภาพนิเทศ (Classroom Evaluation Google Drive Bridge)
 * พัฒนาเพื่อบูรณาการส่งข้อมูลเซ็นเซอร์เก็บไฟล์ในไดรฟ์ของเครือข่ายโรงเรียน
 */
function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    var folderId = data.folder_id;
    var filename = data.filename;
    var filedata = data.filedata; // ชุดรูปแปลง Base64
    var mimeType = data.mime_type || "image/jpeg";
    
    // ตรวจหาโฟลเดอร์ปลายทาง
    var folder = DriveApp.getFolderById(folderId);
    var decoded = Utilities.base64Decode(filedata);
    var blob = Utilities.newBlob(decoded, mimeType, filename);
    var file = folder.createFile(blob);
    
    // กำหนดสิทธิ์ให้สากลลิงก์สามารถเปิดดูเพื่อดึงเป็นรูปประกอบรายงาน
    file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
    
    return ContentService.createTextOutput(JSON.stringify({
      success: true,
      url: "https://lh3.googleusercontent.com/d/" + file.getId(),
      id: file.getId()
    })).setMimeType(ContentService.MimeType.JSON);
    
  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({
      success: false,
      error: error.toString()
    })).setMimeType(ContentService.MimeType.JSON);
  }
}'); ?></textarea>
                </div>

                <div class="text-[9.5px] bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-amber-500 leading-relaxed font-bold">
                    💡 โน้ตความปลอดภัย: ระบบภาพคุณครูและภาพนิเทศบรรยากาศจะถูกส่งขึ้นไดรฟ์ของเจ้าของโรงเรียนนั้นๆ ส่งผลให้ลดภาระที่จัดเก็บ และทำให้ภาพนั้นอยู่ในการรับผิดชอบของบุคลากรโรงเรียนที่ดูแลสิทธิ์โดยตรง
                </div>
            </div> <!-- End of Section 2 -->

            </div> <!-- End of Column 2 wrapper -->

        </div>

        <div class="pt-6 border-t border-slate-200">
            <a href="dashboard.php" class="px-5 py-2.5 bg-gradient-to-r from-slate-700 to-slate-800 hover:opacity-95 text-xs text-white font-bold rounded-xl shadow transition tracking-wide block text-center md:inline-block">
                🔙 ย้อนกลับไปยัง แดชบอร์ดหลักของระบบ
            </a>
        </div>

    </main>

    <!-- JS Utility formula -->
    <script>
        function toggleDriveForm(schoolCode, appUrl, folderId) {
            const card = document.getElementById('drive_config_card');
            const editCard = document.getElementById('school_edit_card');
            const targetSchoolSpan = document.getElementById('drive_target_school');
            const inputSchoolCode = document.getElementById('drive_input_school_code');
            const inputAppUrl = document.getElementById('drive_input_app_url');
            const inputFolderId = document.getElementById('drive_input_folder_id');
            
            if (editCard) editCard.classList.add('hidden');
            
            targetSchoolSpan.innerText = 'รหัส SMISS: ' + schoolCode;
            inputSchoolCode.value = schoolCode;
            inputAppUrl.value = appUrl;
            inputFolderId.value = folderId;
            
            card.classList.remove('hidden');
            card.scrollIntoView({ behavior: 'smooth' });
        }

        function toggleEditSchoolForm(schoolCode, schoolName, affiliation) {
            const card = document.getElementById('school_edit_card');
            const driveCard = document.getElementById('drive_config_card');
            const targetSchoolSpan = document.getElementById('edit_target_school');
            const inputOldSchoolCode = document.getElementById('edit_input_old_school_code');
            const inputNewSchoolCode = document.getElementById('edit_input_new_school_code');
            const inputSchoolName = document.getElementById('edit_input_school_name');
            const inputAffiliation = document.getElementById('edit_input_affiliation');
            
            if (driveCard) driveCard.classList.add('hidden');
            
            targetSchoolSpan.innerText = 'รหัสเดิม: ' + schoolCode;
            inputOldSchoolCode.value = schoolCode;
            inputNewSchoolCode.value = schoolCode;
            inputSchoolName.value = schoolName;
            inputAffiliation.value = affiliation;
            
            card.classList.remove('hidden');
            card.scrollIntoView({ behavior: 'smooth' });
        }
    </script>

    <!-- Footer block -->
    <footer class="py-6 mt-12 border-t border-slate-200 bg-white text-center text-[11px] text-slate-400 select-none">
        <p>ระบบเว็บแอพพลิเคชันเพื่อสุขภาวะและวิทยฐานะประกอบคุณครู เครือข่ายการจัดการศึกษาสู่ฐานสมรรถนะ</p>
    </footer>

</body>
</html>

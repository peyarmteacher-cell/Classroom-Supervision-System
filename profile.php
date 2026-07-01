<?php
// ==========================================
// profile.php - แก้ไขข้อมูลส่วนตัว บัญชี และรหัสผ่าน
// ==========================================

require_once 'config.php';

// ตรวจสอบสิทธิ์การเข้าใช้งาน
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$session_user = $_SESSION['username'];
$user_role = $_SESSION['role'] ?? 'teacher';
$school_code = $_SESSION['school_code'] ?? '31054002';

$success_msg = '';
$error_msg = '';

// โหลดข้อมูลบัญชีหลักของผู้ใช้ปัจจุบัน
$stmt_u = $pdo->prepare("SELECT * FROM users WHERE username = ? AND school_code = ?");
$stmt_u->execute([$session_user, $school_code]);
$u_data = $stmt_u->fetch();

if (!$u_data) {
    if ($user_role === 'teacher') {
        $stmt_t = $pdo->prepare("SELECT * FROM teachers WHERE username = ? AND school_code = ?");
        $stmt_t->execute([$session_user, $school_code]);
        $teacher_data = $stmt_t->fetch();
        if ($teacher_data) {
            // สร้างข้อมูลลงในตาราง users อัตโนมัติ
            $hashed_pwd = password_hash('123456', PASSWORD_DEFAULT);
            $stmt_ins = $pdo->prepare("INSERT INTO users (username, password, fullname, role, school_code) VALUES (?, ?, ?, 'teacher', ?)");
            $stmt_ins->execute([$session_user, $hashed_pwd, $teacher_data['teacher_name'], $school_code]);
            
            // โหลดข้อมูลใหม่อีกครั้ง
            $stmt_u->execute([$session_user, $school_code]);
            $u_data = $stmt_u->fetch();
        }
    }
}

if (!$u_data) {
    header("Location: logout.php");
    exit;
}

// ดึงรายละเอียดเพิ่มเติมเฉพาะสิทธิ์ของคุณครู
$teacher_data = null;
if ($user_role === 'teacher') {
    $stmt_t = $pdo->prepare("SELECT * FROM teachers WHERE username = ? AND school_code = ?");
    $stmt_t->execute([$session_user, $school_code]);
    $teacher_data = $stmt_t->fetch();
}

// โหลดข้อมูลภาพโลโก้และชื่อโรงเรียนปัจจุบัน
$stmt_logo = $pdo->prepare("SELECT setting_value FROM school_settings WHERE setting_key = 'school_logo' AND school_code = ?");
$stmt_logo->execute([$school_code]);
$current_logo = $stmt_logo->fetchColumn() ?: '';

$stmt_sname = $pdo->prepare("SELECT setting_value FROM school_settings WHERE setting_key = 'school_name' AND school_code = ?");
$stmt_sname->execute([$school_code]);
$current_school_name = $stmt_sname->fetchColumn() ?: 'ระบบนิเทศการจัดการเรียนการสอนโรงเรียนบ้านหนองหว้า อำเภอหนองกี่ จังหวัดบุรีรัมย์';
if ($current_school_name === 'ระบบนิเทศการจัดการเรียนการสอนระดับโรงเรียน') {
    $current_school_name = 'ระบบนิเทศการจัดการเรียนการสอนโรงเรียนบ้านหนองหว้า อำเภอหนองกี่ จังหวัดบุรีรัมย์';
}

// โหลดข้อมูลภาพรวมโรงเรียน เช่น Google Drive
$stmt_sch = $pdo->prepare("SELECT * FROM schools WHERE school_code = ?");
$stmt_sch->execute([$school_code]);
$school_record = $stmt_sch->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_school_settings'])) {
    if ($user_role === 'admin') {
        $school_name = trim($_POST['school_name'] ?? '');
        $gdrive_app_url = trim($_POST['gdrive_app_url'] ?? '');
        $gdrive_folder_id = trim($_POST['gdrive_folder_id'] ?? '');
        $logo_saved = $current_logo;
        
        if (isset($_FILES['school_logo_file']) && $_FILES['school_logo_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['school_logo_file']['tmp_name'];
            $file_name = $_FILES['school_logo_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (!in_array($file_ext, $allowed_exts)) {
                $error_msg = 'อนุญาตเฉพาะไฟล์รูปภาพสกุล JPG, JPEG, PNG หรือ GIF เท่านั้น';
            } else {
                $img_data = file_get_contents($file_tmp);
                $base64_img = 'data:image/' . $file_ext . ';base64,' . base64_encode($img_data);
                $logo_saved = $base64_img;
            }
        } else if (isset($_POST['remove_logo']) && $_POST['remove_logo'] == '1') {
            $logo_saved = '';
        }

        if (empty($error_msg)) {
            try {
                $stmt_up_logo = $pdo->prepare("UPDATE school_settings SET setting_value = ? WHERE setting_key = 'school_logo' AND school_code = ?");
                $stmt_up_logo->execute([$logo_saved, $school_code]);

                $stmt_up_name = $pdo->prepare("UPDATE school_settings SET setting_value = ? WHERE setting_key = 'school_name' AND school_code = ?");
                $stmt_up_name->execute([$school_name, $school_code]);

                $stmt_up_gdrive = $pdo->prepare("UPDATE schools SET gdrive_app_url = ?, gdrive_folder_id = ? WHERE school_code = ?");
                $stmt_up_gdrive->execute([$gdrive_app_url, $gdrive_folder_id, $school_code]);

                $current_logo = $logo_saved;
                $current_school_name = $school_name;
                
                // รีโหลดข้อมูลโรงเรียน
                $stmt_sch->execute([$school_code]);
                $school_record = $stmt_sch->fetch();

                $success_msg = 'อัปเดตข้อมูลโรงเรียน โลโก้ และจุดเชื่อมต่อ Google Drive เรียบร้อยแล้ว!';
            } catch (Exception $e) {
                $error_msg = 'เกิดปัญหาในการบันทึกการตั้งค่าลงฐานข้อมูล: ' . $e->getMessage();
            }
        }
    } else {
        $error_msg = 'เฉพาะผู้ดูแลระบบ (Admin) เท่านั้นที่ได้รับสิทธิ์ในการเปลี่ยนแปลงการตั้งค่าโรงเรียน';
    }
}

// ดำเนินการอัพเดตข้อมูลเมื่อมีการส่งฟอร์ม POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_profile'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $new_username = trim($_POST['username'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // ข้อมูลจำเพาะกลุ่มย่อยของคุณครู
    $position = trim($_POST['position'] ?? '');
    $subject_group = trim($_POST['subject_group'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // ตรวจสอบเงื่อนไขข้อมูลทั่วไป
    if (empty($fullname)) {
        $error_msg = 'กรุณาระบุชื่อ-นามสกุลจริงของคุณสะกดให้ถูกต้อง';
    } else if (empty($new_username)) {
        $error_msg = 'กรุณากรอกชื่อล็อกอินผู้ใช้งาน (Username) ห้ามปล่อยว่าง';
    } else if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $new_username)) {
        $error_msg = 'ชื่อล็อกอินต้องประกอบด้วยภาษาอังกฤษ ตัวเลข เครื่องหมาย (_) (-) หรือ (.) เท่านั้น';
    } else {
        // ตรวจสอบชื่อรหัสผู้ใช้ซ้ำซ้อนในระบบกับผู้อื่นของโรงเรียนนี้
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND username != ? AND school_code = ?");
        $stmt_check->execute([$new_username, $session_user, $school_code]);
        $exists = $stmt_check->fetchColumn();

        if ($exists > 0) {
            $error_msg = "ชื่อล็อกอิน '{$new_username}' ถูกใช้งานโดยผู้อื่นในเซิร์ฟเวอร์ระบบโรงเรียนแล้ว กรุณาลองเปลี่ยนชื่อเข้าใช้อื่น";
        } else {
            // ตรวจสอบเงื่อนไขความยาวและการจับคู่อัพเดตรหัสผ่านใหม่
            $change_password = false;
            $hashed_password = '';
            if (!empty($new_password)) {
                if (strlen($new_password) < 4) {
                    $error_msg = 'รหัสผ่านใหม่ควรมีความยาวไม่ต่ำกว่า 4 ตัวอักษรเพื่อความมั่นคงปลอดภัย';
                } else if ($new_password !== $confirm_password) {
                    $error_msg = 'การยืนยันรหัสผ่านใหม่ไม่ตรงกัน กรุณาตรวจสอบรหัสผ่านอีกครั้ง';
                } else {
                    $change_password = true;
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                }
            }

            if (empty($error_msg)) {
                // อัปโหลดไฟล์รูปภาพหากเป็นสิทธิ์ผู้ใช้งานกลุ่มครูผู้รับนิเทศ
                $photo_uploaded_path = $teacher_data['photo_path'] ?? null;
                
                $teacher_photo_url = trim($_POST['teacher_photo_url'] ?? '');
                if ($user_role === 'teacher' && !empty($teacher_photo_url)) {
                    $photo_uploaded_path = convert_gdrive_url_to_direct($teacher_photo_url);
                }
                
                if ($user_role === 'teacher' && isset($_FILES['teacher_photo']) && $_FILES['teacher_photo']['error'] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['teacher_photo']['tmp_name'];
                    $file_name = $_FILES['teacher_photo']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
                    
                    if (!in_array($file_ext, $allowed_exts)) {
                        $error_msg = 'อนุญาตเฉพาะไฟล์รูปภาพสกุล JPG, JPEG, PNG หรือ GIF เท่านั้น';
                    } else {
                        $new_file_name = 'teacher_' . ($teacher_data['teacher_id'] ?? 'temp') . '_' . time() . '.' . $file_ext;
                        $dest_path = 'uploads/' . $new_file_name;
                        
                        if (move_uploaded_file($file_tmp, $dest_path)) {
                            // อัพโหลดเข้า Google Drive ถ้าติดตั้ง GAS
                            $gdrive_url = upload_image_to_gdrive_if_configured($dest_path, $new_file_name, $school_code, $pdo);
                            $photo_uploaded_path = $gdrive_url ?: $dest_path;
                        } else {
                            // FALLBACK: หากเขียนรูปภาพลงเครื่องเซิร์ฟเวอร์ไม่ได้ ให้แปลงรูปจาก tmp เป็น base64 แล้วเซฟลงฐานข้อมูลตรง
                            $raw_content = @file_get_contents($file_tmp);
                            if ($raw_content !== false) {
                                $mime_type = @mime_content_type($file_tmp) ?: 'image/jpeg';
                                $photo_uploaded_path = 'data:' . $mime_type . ';base64,' . base64_encode($raw_content);
                            } else {
                                $error_msg = 'เกิดความผิดพลาดระหว่างเซฟไฟล์รูปภาพลงเซิร์ฟเวอร์โรงเรียน (ไม่พบไฟล์ชั่วคราว)';
                            }
                        }
                    }
                }

                if (empty($error_msg)) {
                    $pdo->beginTransaction();
                    try {
                        // 1. ตรวจสอบการอัปเดตตารางครูหากล็อกอินเข้ามาด้วยฐานสิทธิ์คุณครู
                        if ($user_role === 'teacher' && $teacher_data) {
                            $stmt_up_t = $pdo->prepare("UPDATE teachers SET teacher_name = ?, position = ?, subject_group = ?, phone = ?, username = ?, photo_path = ? WHERE teacher_id = ? AND school_code = ?");
                            $stmt_up_t->execute([$fullname, $position, $subject_group, $phone, $new_username, $photo_uploaded_path, $teacher_data['teacher_id'], $school_code]);
                        }

                        // 2. อัปเดตพัสดุตารางผู้ใช้งานหลัก
                        if ($change_password) {
                            $stmt_up_u = $pdo->prepare("UPDATE users SET username = ?, password = ?, fullname = ? WHERE username = ? AND school_code = ?");
                            $stmt_up_u->execute([$new_username, $hashed_password, $fullname, $session_user, $school_code]);
                        } else {
                            $stmt_up_u = $pdo->prepare("UPDATE users SET username = ?, fullname = ? WHERE username = ? AND school_code = ?");
                            $stmt_up_u->execute([$new_username, $fullname, $session_user, $school_code]);
                        }

                        $pdo->commit();

                    // ตั้งค่าซีเควนซ์เซสชันใหม่เพื่อให้สารบัญเว็บอัปเดตชื่อผู้ใช้งานทันที
                    $_SESSION['username'] = $new_username;
                    $_SESSION['fullname'] = $fullname;
                    $session_user = $new_username;
                    
                    // ดึงชุดข้อมูลรอบใหม่เพื่อใช้เรนเดอร์ลงใน UI
                    $stmt_u = $pdo->prepare("SELECT * FROM users WHERE username = ? AND school_code = ?");
                    $stmt_u->execute([$session_user, $school_code]);
                    $u_data = $stmt_u->fetch();

                    if ($user_role === 'teacher') {
                        $stmt_t = $pdo->prepare("SELECT * FROM teachers WHERE username = ? AND school_code = ?");
                        $stmt_t->execute([$session_user, $school_code]);
                        $teacher_data = $stmt_t->fetch();
                    }

                    $success_msg = 'ปรับปรุงข้อมูลประวัติ บัญชีผู้ใช้งาน และรหัสผ่านใหม่สำเร็จสมบูรณ์!';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error_msg = 'เกิดความผิดพลาดในการเขียนโปรแกรมฐานข้อมูล: ' . $e->getMessage();
                }
            }
        }
    }
}
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าบัญชีและประวัติส่วนตัว - ระบบนิเทศออนไลน์</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Sarabun', 'Inter', sans-serif; 
            background-color: #F5F7FA;
        }
        .glass-header {
            background: linear-gradient(135deg, #1565C0, #0D47A1);
            border-bottom: 4px solid #FFC107;
        }
        .card-glass {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            transition: all 0.25s ease;
        }
    </style>
</head>
<body class="bg-[#F5F7FA] min-h-screen text-slate-800 pb-16 md:pb-0">

    <!-- Navbar Container -->
    <header class="glass-header text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-4.5 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-3">
                <img src="/src/assets/images/pwa_app_icon.jpg" alt="Logo" class="w-12 h-12 rounded-xl object-cover border border-white/20 shadow-md" referrerPolicy="no-referrer">
                <div>
                    <h1 class="text-base sm:text-lg font-extrabold tracking-wide text-white leading-snug">
                        ระบบนิเทศการจัดการเรียนการสอน
                    </h1>
                    <p class="text-[10px] text-[#FFC107] font-bold block uppercase tracking-wider font-mono">
                        Active Classroom Learning Supervision & Evaluation Hub
                    </p>
                </div>
            </div>

            <!-- Quick Profile status -->
            <div class="flex items-center gap-3 text-xs bg-white/10 backdrop-blur-md p-2.5 px-4 rounded-2xl border border-white/10 shadow-inner">
                <div class="text-right">
                    <span class="font-bold text-[#FFC107] block text-[11px]"><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>
                    <span class="text-[9px] text-slate-300">สิทธิ์: <?php echo strtoupper($_SESSION['role']); ?></span>
                </div>
                <a href="logout.php" class="bg-rose-600 hover:bg-rose-700 text-white font-bold p-1 px-2.5 rounded-lg text-[10px] transition shadow">ออกจากระบบ</a>
            </div>
        </div>
    </header>

    <!-- Main Workspace Container -->
    <main class="max-w-7xl mx-auto px-4 py-6 space-y-6">

        <!-- Top Shortcuts Links Navigation Ribbon (Hidden on Mobile) -->
        <div class="hidden md:flex bg-white border border-slate-200/80 p-2 rounded-2xl shadow-sm flex-wrap gap-2 text-xs font-semibold">
            <a href="dashboard.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                📊 แดชบอร์ดสถิติรวม
            </a>
            <?php if ($user_role !== 'teacher'): ?>
                <a href="supervision.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                    ➕ บันทึกนิเทศคาบเรียนใหม่
                </a>
            <?php endif; ?>
            <a href="comparison.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                🔎 วิเคราะห์ครูรายบุคคล/เปรียบเทียบ
            </a>
            <?php if ($user_role === 'admin' || $user_role === 'director'): ?>
                <a href="teachers.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                    👥 ทะเบียนครูผู้สอน
                </a>
                <a href="academic_years.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                    📅 ปีการศึกษา
                </a>
            <?php endif; ?>
            <a href="profile.php" class="px-4 py-2 bg-[#1565C0] text-white rounded-xl shadow-sm font-bold flex items-center gap-1.5">
                ⚙️ ตั้งค่าบัญชีของฉัน
            </a>
        </div>

        <?php if ($success_msg): ?>
            <div class="text-xs bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-2xl font-bold">
                ✅ <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="text-xs bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-2xl font-bold">
                ⚠️ <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <!-- Info Alert: Teacher Photo Upload Instructions -->
        <div class="bg-amber-50 border border-amber-200 text-[#78350F] p-5 rounded-2xl text-xs leading-relaxed space-y-1.5 shadow-xs">
            <span class="font-extrabold text-[#78350F] flex items-center gap-1.5 text-[13px]">📸 คำแนะนำ: นำรูปถ่ายของคุณครูใส่ในข้อมูลทั่วไปและการพิมพ์เอกสาร</span>
            <div class="font-medium text-[11.5px] space-y-1">
                <p>
                    ท่านสามารถทำการอัปโหลดหรือปรับเปลี่ยนรูปภาพประจำตัวของคุณครูเพื่อไปแสดงในเอกสารรายงานการนิเทศได้ <strong>2 ช่องทาง</strong> ดังนี้:
                </p>
                <ol class="list-decimal pl-5 mt-1 space-y-1">
                    <li><strong>สำหรับแอดมิน/ผู้อำนวยการ:</strong> ไปที่เมนู <a href="teachers.php" class="font-bold text-amber-900 underline hover:text-[#9A3412]">👥 ทะเบียนครูผู้สอน</a> แล้วคลิกที่ปุ่ม <span class="bg-amber-100 px-1 py-0.5 rounded text-amber-950">แก้ไข</span> หลังรายชื่อคุณครูที่ท่านต้องการ จากนั้นเลือกอัปโหลดไฟล์รูปภาพ แล้วกดบันทึก ข้อมูลรูปจะถูกนำไปใช้งานทันที</li>
                    <li><strong>สำหรับตัวคุณครูเอง:</strong> สามารถเข้าสู่ระบบด้วยบัญชีตนเอง แล้วอัปโหลดได้แถบฟิลด์ "อัพโหลดรูปถ่ายประจำตัวของคุณครู" ด้านล่างของหน้านี้</li>
                </ol>
            </div>
            <p class="text-[10.5px] text-amber-700/90 font-bold border-t border-amber-200/60 pt-1.5">
                💡 ระบบได้อัปเกรดให้ดึงรูปภาพประจำตัวเหล่านี้ไปจัดพิมพ์ประกอบใบรายงานนิเทศและการนิเทศข้อมูลทั่วไปให้อัตโนมัติในหัวกระดาษแล้ว!
            </p>
        </div>

        <!-- Form and account details layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Side Card: Account Info -->
            <div class="lg:col-span-1 bg-white border border-slate-200 rounded-2xl shadow-sm p-5 space-y-4">
                <div class="text-center space-y-2">
                    <div class="w-24 h-24 bg-amber-100/70 border border-amber-200 text-amber-700 flex items-center justify-center text-4xl rounded-xl mx-auto overflow-hidden relative shadow-inner">
                        <img id="profile-photo-preview" src="<?php echo ($user_role === 'teacher' && !empty($teacher_data['photo_path']) && is_valid_photo($teacher_data['photo_path'])) ? htmlspecialchars($teacher_data['photo_path']) : ''; ?>" 
                             alt="รูปภาพประจำตัวครู" 
                             class="w-full h-full object-cover <?php echo ($user_role === 'teacher' && !empty($teacher_data['photo_path']) && is_valid_photo($teacher_data['photo_path'])) ? '' : 'hidden'; ?>">
                        
                        <span id="profile-photo-placeholder" class="text-4xl <?php echo ($user_role === 'teacher' && !empty($teacher_data['photo_path']) && is_valid_photo($teacher_data['photo_path'])) ? 'hidden' : ''; ?>"><?php echo $user_role === 'teacher' ? '👩‍🏫' : '👑'; ?></span>
                        
                        <!-- Loading Spinner Overlay -->
                        <div id="profile-upload-spinner" class="absolute inset-0 bg-slate-900/60 backdrop-blur-xs flex flex-col items-center justify-center text-white transition-opacity duration-250 opacity-0 pointer-events-none">
                            <svg class="animate-spin h-5 w-5 text-amber-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-slate-800 text-sm"><?php echo htmlspecialchars($u_data['fullname']); ?></h3>
                        <span class="inline-block mt-1 font-bold px-2 py-0.5 rounded-full text-[9px] uppercase tracking-wider bg-[#0A3370] text-white">
                            <?php echo $user_role === 'teacher' ? 'คุณครูผู้รับนิเทศ' : ($user_role === 'director' ? 'ผู้อำนวยการโรงเรียน' : 'ผู้ดูแลระบบ'); ?>
                        </span>
                        <div class="mt-1 flex justify-center">
                            <span id="profile-upload-status" class="hidden px-2 py-0.5 rounded text-[8px] font-extrabold animate-pulse"></span>
                        </div>
                    </div>
                </div>

                <div class="border-t pt-4 space-y-2 text-xs font-semibold text-slate-600">
                    <div class="flex justify-between border-b pb-1.5">
                        <span>ชื่อล็อกอินปัจจุบัน:</span>
                        <span class="font-mono text-blue-900"><?php echo htmlspecialchars($u_data['username']); ?></span>
                    </div>
                    <?php if ($teacher_data): ?>
                        <div class="flex justify-between border-b pb-1.5">
                            <span>รหัสคุณครู:</span>
                            <span class="font-mono text-slate-800"><?php echo htmlspecialchars($teacher_data['teacher_id']); ?></span>
                        </div>
                        <div class="flex justify-between border-b pb-1.5">
                            <span>ตำแหน่ง:</span>
                            <span class="text-slate-800"><?php echo htmlspecialchars($teacher_data['position']); ?></span>
                        </div>
                        <div class="flex justify-between border-b pb-1.5">
                            <span>เบอร์ผู้ติดต่อ:</span>
                            <span class="font-mono text-slate-800"><?php echo htmlspecialchars($teacher_data['phone'] ?: '-'); ?></span>
                        </div>
                        <div class="flex flex-col gap-0.5">
                            <span>สังกัดสาระการเรียนรู้:</span>
                            <span class="text-[10px] text-amber-700 font-bold"><?php echo htmlspecialchars($teacher_data['subject_group']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Content Card: Edit Forms -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Account Profile Edit Form Card -->
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-6">
                    <div class="border-b pb-2">
                        <h2 class="font-extrabold text-[#0A3370] text-sm">การแก้ไขข้อมูลบัญชีและข้อมูลติดต่อหน่วยนิเทศ</h2>
                        <p class="text-[10px] text-slate-400 mt-0.5">คุณสามารถปรับเปลี่ยนชื่อล็อกอิน รายละเอียดติดต่อ และพาสเวิร์ดที่ใช้ในการเข้าสู่ระบบ</p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs font-semibold text-slate-600">
                        <input type="hidden" name="action_update_profile" value="1">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Fullname -->
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-slate-500">ชื่อ - นามสกุลจริงของคุณ *</label>
                                <input type="text" name="fullname" required value="<?php echo htmlspecialchars($u_data['fullname']); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:ring-1 focus:ring-blue-900">
                            </div>

                            <!-- Username -->
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-slate-500">ชื่อล็อกอินผู้ใช้งาน (Username) *</label>
                                <input type="text" name="username" required value="<?php echo htmlspecialchars($u_data['username']); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none font-mono focus:ring-1 focus:ring-blue-900">
                            </div>
                        </div>

                        <?php if ($teacher_data): ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 border-t border-dashed pt-4">
                                <!-- Position -->
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-slate-500">ตำแหน่งงาน / วิทยฐานะ *</label>
                                    <select name="position" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs cursor-pointer outline-none">
                                        <option value="ครู คศ.1" <?php if ($teacher_data['position'] === 'ครู คศ.1') echo 'selected'; ?>>ครู คศ.1</option>
                                        <option value="ครูชำนาญการ" <?php if ($teacher_data['position'] === 'ครูชำนาญการ') echo 'selected'; ?>>ครูชำนาญการ</option>
                                        <option value="ครูชำนาญการพิเศษ" <?php if ($teacher_data['position'] === 'ครูชำนาญการพิเศษ') echo 'selected'; ?>>ครูชำนาญการพิเศษ</option>
                                        <option value="ครูผู้ช่วย" <?php if ($teacher_data['position'] === 'ครูผู้ช่วย') echo 'selected'; ?>>ครูผู้ช่วย</option>
                                        <option value="ครู คศ.3 / ผู้อำนวยการ" <?php if ($teacher_data['position'] === 'ครู คศ.3 / ผู้อำนวยการ') echo 'selected'; ?>>ครู คศ.3 / ผู้อำนวยการ</option>
                                    </select>
                                </div>

                                <!-- Subject group -->
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-slate-500">กลุ่มสาระการเรียนรู้ *</label>
                                    <select name="subject_group" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs cursor-pointer outline-none">
                                        <option value="กลุ่มสาระการเรียนรู้ภาษาไทย" <?php if ($teacher_data['subject_group'] === 'กลุ่มสาระการเรียนรู้ภาษาไทย') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้ภาษาไทย</option>
                                        <option value="กลุ่มสาระการเรียนรู้คณิตศาสตร์" <?php if ($teacher_data['subject_group'] === 'กลุ่มสาระการเรียนรู้คณิตศาสตร์') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้คณิตศาสตร์</option>
                                        <option value="กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี" <?php if ($teacher_data['subject_group'] === 'กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี</option>
                                        <option value="กลุ่มสาระการเรียนรู้สังคมศึกษา ศาสนา และวัฒนธรรม" <?php if ($teacher_data['subject_group'] === 'กลุ่มสาระการเรียนรู้สังคมศึกษา ศาสนา และวัฒนธรรม') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้สังคมศึกษา ศาสนา และวัฒนธรรม</option>
                                        <option value="กลุ่มสาระการเรียนรู้สุขศึกษาและพลศึกษา" <?php if ($teacher_data['subject_group'] === 'กลุ่มสาระการเรียนรู้สุขศึกษาและพลศึกษา') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้สุขศึกษาและพลศึกษา</option>
                                        <option value="กลุ่มสาระการเรียนรู้ศิลปะ" <?php if ($teacher_data['subject_group'] === 'กลุ่มสาระการเรียนรู้ศิลปะ') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้ศิลปะ</option>
                                        <option value="กลุ่มสาระการเรียนรู้ภาษาต่างประเทศ" <?php if ($teacher_data['subject_group'] === 'กลุ่มสาระการเรียนรู้ภาษาต่างประเทศ') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้ภาษาต่างประเทศ</option>
                                    </select>
                                </div>

                                <!-- Phone -->
                                <div class="space-y-1 sm:col-span-2">
                                    <label class="text-xs font-bold text-slate-500">เบอร์โทรศัพท์ติดต่อ</label>
                                    <input type="text" name="phone" value="<?php echo htmlspecialchars($teacher_data['phone']); ?>" placeholder="เช่น 081-XXXXXXX" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none font-mono">
                                </div>

                                <!-- Photo Upload -->
                                <div class="space-y-2 sm:col-span-2 border-t border-dashed pt-4 mt-1">
                                    <label class="text-xs font-bold text-amber-700 block">📸 ภาพถ่ายประจำตัวของคุณครู (สำหรับหน้าข้อมูลและการนิเทศ)</label>
                                    
                                    <div class="space-y-1">
                                        <span class="text-[10px] text-slate-400 block font-bold">วิธีที่ 1: อัปโหลดไฟล์ภาพโดยตรง (อัปโหลดอัตโนมัติทันทีที่เลือกไฟล์)</span>
                                        <input type="file" id="teacher_photo_input" accept="image/*" class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-[11px] file:font-semibold file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100 cursor-pointer">
                                    </div>
                                    
                                    <div class="space-y-1 pt-1">
                                        <span class="text-[10px] text-slate-400 block font-bold">วิธีที่ 2: หรือระบุลิงก์รูปภาพ Google Drive / รูปภาพทั่วไป</span>
                                        <input type="text" id="teacher_photo_url_input" name="teacher_photo_url" value="<?php echo htmlspecialchars($teacher_data['photo_path'] ?? ''); ?>" placeholder="เช่น https://drive.google.com/file/d/... หรือลิงก์รูปภาพ" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:ring-1 focus:ring-amber-500 font-mono">
                                        <p class="text-[9px] text-slate-400 leading-normal">ระบบจะทำการแปลงลิงก์แชร์ของ Google Drive ให้สามารถดึงภาพขึ้นมาแสดงผลได้โดยอัตโนมัติ</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="border-t border-dashed pt-4 space-y-4">
                            <span class="text-xs font-extrabold text-amber-600 block">🔒 หากประสงค์แก้ไขเปลี่ยนรหัสผ่านใหม่ (หากไม่ต้องการเปลี่ยนให้ปล่อยว่างไว้):</span>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-slate-500">รหัสผ่านใหม่ (New Password)</label>
                                    <input type="password" name="new_password" placeholder="พิมพ์รหัสเข้าใช้งานแทนตัวเก่า" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-slate-500">ยืนยันรหัสผ่านใหม่อีกครั้ง</label>
                                    <input type="password" name="confirm_password" placeholder="ระบุยืนยันพาสเวิร์ดใหม่ให้ตรงกัน" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none">
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t flex justify-end gap-2.5">
                            <a href="dashboard.php" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-center">ยกเลิกกลับแดชบอร์ด</a>
                            <button type="submit" class="px-6 py-2.5 bg-[#0A3370] hover:bg-[#07244F] text-white font-bold rounded-xl shadow cursor-pointer">
                                💾 บันทึกการเปลี่ยนแปลงข้อมูลบัญชี
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Admin Only: School Settings & Logo Management Card -->
                <?php if ($user_role === 'admin'): ?>
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-6">
                    <div class="border-b pb-2">
                        <h2 class="font-extrabold text-[#0D9488] text-sm flex items-center gap-2">🏫 ตั้งค่าตราสัญลักษณ์ (Logo) และข้อมูลสถาบันการศึกษา</h2>
                        <p class="text-[10px] text-slate-400 mt-0.5">ปรับเปลี่ยนภาพโลโก้ประจำโรงเรียนของตนเอง เพื่อแสดงในส่วนหัวของเอกสารรายงานผลการนิเทศชั้นเรียน คาบเรียน ตลอดจนตั้งชื่อสถาบันการศึกษา</p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs font-semibold text-slate-600">
                        <input type="hidden" name="action_update_school_settings" value="1">

                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-500 block">ชื่อโรงเรียน / ชื่อย่อสถานศึกษา *</label>
                            <input type="text" name="school_name" required value="<?php echo htmlspecialchars($current_school_name); ?>" class="w-full px-3 py-2 bg-slate-50 border border-[#0D9488]/30 rounded-xl text-xs outline-none focus:ring-1 focus:ring-teal-500">
                        </div>

                        <div class="space-y-2 border-t border-dashed pt-4">
                            <label class="text-xs font-bold text-[#0D9488] block">🛡️ อัปโหลดตราโลโก้โรงเรียน (School Logo)</label>
                            
                            <div class="flex flex-col sm:flex-row items-center gap-4 bg-slate-50 p-4 rounded-xl border border-slate-200">
                                <div class="w-20 h-20 bg-white border rounded-xl flex items-center justify-center overflow-hidden flex-shrink-0 shadow-inner relative">
                                    <?php if (!empty($current_logo)): ?>
                                        <img src="<?php echo $current_logo; ?>" alt="ตราสัญลักษณ์สถาบัน" class="w-full h-full object-contain">
                                    <?php else: ?>
                                        <div class="text-center text-slate-350">
                                            <span class="text-3xl block">🏫</span>
                                            <span class="text-[8px] font-bold text-slate-400 block mt-0.5">ไม่มีโลโก้</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 space-y-2 w-full text-center sm:text-left">
                                    <input type="file" name="school_logo_file" accept="image/*" class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-[11px] file:font-semibold file:bg-teal-50 file:text-[#0D9488] hover:file:bg-teal-100 cursor-pointer">
                                    <p class="text-[9px] text-slate-400 leading-normal">รองรับไฟล์รูปภาพทั่วไป (JPG, JPEG, PNG, GIF) โดยรูปภาพจะถูกจัดเก็บในรูป Base64 สะดวกและปลอดภัยยิ่งขึ้น</p>
                                    
                                    <?php if (!empty($current_logo)): ?>
                                        <label class="inline-flex items-center gap-1.5 text-xs text-rose-600 font-extrabold cursor-pointer mt-1">
                                            <input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300 text-rose-600 cursor-pointer">
                                            <span>ลบโลโก้ปัจจุบัน (เพื่อย้อนกลับไปใช้ตราเริ่มต้น)</span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                             </div>
                         </div>

                        <!-- Google Drive API Bridge Settings -->
                        <div class="space-y-4 border-t border-dashed pt-4">
                            <label class="text-xs font-bold text-[#0D9488] block flex items-center gap-1">
                                <span>🌐 จุดเชื่อมต่อ Google Drive & คลังรูปภาพสำหรับส่งรูปภาพของโรงเรียนตนเอง</span>
                            </label>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <label class="text-[11px] font-bold text-slate-500">Google Apps Script URL (Web App Link) *</label>
                                    <input type="url" name="gdrive_app_url" placeholder="https://script.google.com/macros/s/.../exec" value="<?php echo htmlspecialchars($school_record['gdrive_app_url'] ?? ''); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none font-mono focus:ring-1 focus:ring-teal-500">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[11px] font-bold text-slate-500">Google Drive Folder ID *</label>
                                    <input type="text" name="gdrive_folder_id" placeholder="รหัสโฟลเดอร์ยาวๆ เช่น 1A2b3C_..." value="<?php echo htmlspecialchars($school_record['gdrive_folder_id'] ?? ''); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none font-mono focus:ring-1 focus:ring-teal-500">
                                </div>
                            </div>
                            
                            <p class="text-[10px] text-slate-400 leading-normal bg-teal-50/50 p-2.5 rounded-lg border border-teal-100">
                                💡 <strong>สำหรับโรงเรียนตนเองโดยเฉพาะ:</strong> การระบุค่าทั้งสองช่องนี้จะส่งผลให้ภาพครูและภาพบันทึกนิเทศของโรงเรียนคุณถูกส่งตรงไปเก็บที่ Google Drive ใต้บัญชีของทางโรงเรียนท่านเองโดยตรงอย่างเป็นเอกเทศ ไม่สร้างภาระพื้นที่จัดเก็บ และไม่ปะปนกับข้อมูลของสถาบันอื่นในสารบบระบบเครือข่าย
                            </p>
                        </div>

                         <div class="pt-4 border-t flex justify-end">
                             <button type="submit" class="px-6 py-2.5 bg-[#0D9488] hover:bg-[#0F766E] text-white font-extrabold rounded-xl shadow cursor-pointer transition">
                                 💾 บันทึกตั้งค่าสถาบันและโลโก้
                             </button>
                         </div>
                     </form>
                 </div>

                 <!-- Section 2: Instructions and Google Apps Script Code block for School Admin -->
                 <div class="bg-[#0f172a] text-slate-200 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
                     <div>
                         <h2 class="font-extrabold text-amber-400 text-sm flex items-center gap-1.5">🛠️ คู่มือชุดเชื่อม Google Drive (สำหรับบริการโรงเรียนของคุณ)</h2>
                         <p class="text-[9.5px] text-slate-400 mt-1 leading-relaxed">คัดลอกซอร์สโค้ดด้านล่างนี้ไปติดตั้งบนบริการ Google Apps Script ของหน่วยงาน/โรงเรียนท่าน เพื่อแยกจัดเก็บภาพต่างๆ และรายงานลงบน Google Drive ของคุณเอง</p>
                     </div>

                     <!-- Steps Description -->
                     <div class="text-[10px] space-y-2 text-slate-300 border-l border-slate-700 pl-3 leading-relaxed">
                         <p><strong class="text-amber-300">ขั้นตอนที่ 1:</strong> เข้าสู่หน้าเว็บ <a href="https://script.google.com" target="_blank" class="text-sky-400 underline">script.google.com</a> ด้วยบัญชีของโรงเรียนนั้นๆ</p>
                         <p><strong class="text-amber-300">ขั้นตอนที่ 2:</strong> กดสร้าง "โครงการใหม่" (New Project)</p>
                         <p><strong class="text-amber-300">ขั้นตอนที่ 3:</strong> ลบโค้ดเดิมออกทั้งหมด และวางสคริปต์ (GAS Code) ด้านล่างลงไปแทนที่</p>
                         <p><strong class="text-amber-300">ขั้นตอนที่ 4:</strong> กดปุ่ม "การทำให้ใช้งานได้" (Deploy) > เลือก "การทำให้ใช้งานได้เพื่อเป็นเว็บแอป" (New Deployment as Web App)</p>
                         <p><strong class="text-amber-300">ขั้นตอนที่ 5:</strong> ตั้งค่าสิทธิ์การเข้าถึงให้เป็น: <strong>"ผู้ที่มีสิทธิ์เข้าถึง: ทุกคน (Anyone)"</strong> และคลิก Deploy เพื่อเชื่อมและอนุญาตใช้สิทธิ์เข้าถึงไดรฟ์</p>
                         <p><strong class="text-amber-300">ขั้นตอนที่ 6:</strong> คัดลอก "URL ของเว็บแอป" (Web App URL) นำมากรอกใส่ช่อง "Google Apps Script URL" ด้านบน และนำรหัส ID ของ Google Drive Folder มาใส่ในช่อง "Google Drive Folder ID" จากนั้นกดบันทึก</p>
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
                         💡 หมายเหตุความปลอดภัย: ไฟล์ทุกอย่างจะถูกเก็บใน Google Drive ของโรงเรียนท่านโดยตรง ไม่ไหลไปปะปนกับบุคคลภายนอกเด็ดขาด
                     </div>
                 </div>
                 <?php endif; ?>
            </div>

        </div>

    </main>

    <!-- Spacing at the bottom for mobile navigation bar -->
    <div class="h-20 md:hidden"></div>

    <!-- Beautiful Bottom Navigation Bar for Mobile App Feeling -->
    <div class="fixed bottom-0 inset-x-0 bg-white/95 backdrop-blur-md border-t border-slate-200/80 z-40 md:hidden flex justify-around items-center py-2 px-1 shadow-[0_-4px_24px_rgba(0,0,0,0.06)] rounded-t-[18px]">
        <a href="dashboard.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-slate-400 hover:text-slate-600">
            <span class="text-xl">🏠</span>
            <span class="text-[9px]">หน้าหลัก</span>
        </a>
        <a href="supervision.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-slate-400 hover:text-slate-600">
            <span class="text-xl">📝</span>
            <span class="text-[9px]">นิเทศ</span>
        </a>
        <a href="comparison.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-slate-400 hover:text-slate-600">
            <span class="text-xl">📊</span>
            <span class="text-[9px]">รายงาน</span>
        </a>
        <a href="teachers.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-slate-400 hover:text-slate-600">
            <span class="text-xl">👥</span>
            <span class="text-[9px]">ทะเบียนครู</span>
        </a>
        <a href="profile.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-[#1565C0] font-bold">
            <span class="text-xl">⚙️</span>
            <span class="text-[9px]">ตั้งค่า</span>
        </a>
    </div>

    <!-- Client-side Interactive Photo Logic for profile -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const photoInput = document.getElementById('teacher_photo_input');
            const photoUrlInput = document.getElementById('teacher_photo_url_input');
            const previewImg = document.getElementById('profile-photo-preview');
            const placeholderSpan = document.getElementById('profile-photo-placeholder');
            const spinner = document.getElementById('profile-upload-spinner');
            const statusBadge = document.getElementById('profile-upload-status');

            function showStatus(text, isSuccess) {
                if (!statusBadge) return;
                statusBadge.textContent = text;
                statusBadge.className = isSuccess 
                    ? "px-2 py-0.5 rounded text-[9px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-150 mt-1 inline-block"
                    : "px-2 py-0.5 rounded text-[9px] font-extrabold bg-rose-50 text-rose-700 border border-rose-150 mt-1 inline-block";
                statusBadge.classList.remove('hidden');
                setTimeout(() => {
                    statusBadge.classList.add('hidden');
                }, 4000);
            }

            // แปลงลิงก์ Google Drive ในฝั่ง Client เพื่อแสดงพรีวิวแบบ Real-time ทันที
            function convertGDriveUrlToDirect(url) {
                if (!url) return url;
                if (url.indexOf('drive.google.com') !== -1) {
                    let fileId = '';
                    let match1 = url.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
                    if (match1) {
                        fileId = match1[1];
                    } else {
                        let match2 = url.match(/[?&]id=([a-zA-Z0-9_-]+)/);
                        if (match2) {
                            fileId = match2[1];
                        }
                    }
                    if (fileId) {
                        return "https://lh3.googleusercontent.com/d/" + fileId;
                    }
                }
                return url;
            }

            // ฟังก์ชันช่วยแสดงภาพพรีวิว
            function updatePreview(url) {
                if (!previewImg || !placeholderSpan) return;
                if (url) {
                    previewImg.src = url;
                    previewImg.classList.remove('hidden');
                    placeholderSpan.classList.add('hidden');
                } else {
                    previewImg.src = '';
                    previewImg.classList.add('hidden');
                    placeholderSpan.classList.remove('hidden');
                }
            }

            // อัปโหลดไฟล์อัตโนมัติเมื่อเลือกไฟล์ (AJAX)
            if (photoInput) {
                photoInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const file = this.files[0];
                        
                        // 1. แสดงสถานะกำลังอัปโหลด
                        if (spinner) spinner.style.opacity = '1';
                        
                        // ทำการย่อขนาดไฟล์ภาพและแปลงเป็น Base64 บนอุปกรณ์เพื่อความเสถียรสูงสุด
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const img = new Image();
                            img.onload = function() {
                                const MAX_WIDTH = 800;
                                const MAX_HEIGHT = 800;
                                let width = img.width;
                                let height = img.height;
                                
                                if (width > height) {
                                    if (width > MAX_WIDTH) {
                                        height *= MAX_WIDTH / width;
                                        width = MAX_WIDTH;
                                    }
                                } else {
                                    if (height > MAX_HEIGHT) {
                                        width *= MAX_HEIGHT / height;
                                        height = MAX_HEIGHT;
                                    }
                                }
                                
                                const canvas = document.createElement('canvas');
                                canvas.width = width;
                                canvas.height = height;
                                
                                const ctx = canvas.getContext('2d');
                                ctx.drawImage(img, 0, 0, width, height);
                                
                                const compressedBase64 = canvas.toDataURL('image/jpeg', 0.6);
                                
                                const formData = new FormData();
                                formData.append('image_base64', compressedBase64);
                                
                                sendUploadRequest(formData);
                            };
                            img.onerror = function() {
                                const formData = new FormData();
                                formData.append('file', file);
                                sendUploadRequest(formData);
                            };
                            img.src = e.target.result;
                        };
                        reader.onerror = function() {
                            const formData = new FormData();
                            formData.append('file', file);
                            sendUploadRequest(formData);
                        };
                        reader.readAsDataURL(file);
                        
                        function sendUploadRequest(fd) {
                            fetch('upload_ajax.php', {
                                method: 'POST',
                                body: fd
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (spinner) spinner.style.opacity = '0';
                                if (data.success) {
                                    // อัปเดตลิงก์ใน Input และแสดงพรีวิวทันที
                                    if (photoUrlInput) photoUrlInput.value = data.url;
                                    updatePreview(data.url);
                                    showStatus('✓ อัปโหลดสำเร็จ', true);
                                } else {
                                    showStatus('❌ ' + data.error, false);
                                }
                            })
                            .catch(error => {
                                if (spinner) spinner.style.opacity = '0';
                                showStatus('❌ อัปโหลดล้มเหลว', false);
                                console.error('Upload error:', error);
                            });
                        }
                    }
                });
            }

            // สังเกตการณ์การป้อนลิงก์ เพื่อแปลงและอัปเดตพรีวิวแบบสดๆ ทันที
            if (photoUrlInput) {
                const handleUrlChange = function() {
                    const rawUrl = this.value.trim();
                    const convertedUrl = convertGDriveUrlToDirect(rawUrl);
                    if (rawUrl !== convertedUrl) {
                        this.value = convertedUrl;
                    }
                    updatePreview(convertedUrl);
                };

                photoUrlInput.addEventListener('input', handleUrlChange);
                photoUrlInput.addEventListener('change', handleUrlChange);
                photoUrlInput.addEventListener('paste', function() {
                    setTimeout(() => { handleUrlChange.call(photoUrlInput); }, 50);
                });
            }
        });
    </script>

    <!-- Clean Footer block -->
    <footer class="py-6 mt-12 border-t border-slate-200 bg-white text-center text-[11px] text-slate-400 select-none leading-relaxed">
        <p>ระบบนิเทศการจัดการเรียนการสอน - สังกัดกระทรวงศึกษาธิการ</p>
        <p class="mt-1">พัฒนารหัสด้วยมาตรฐานสูงสุด <strong>PHP 8.2+</strong> & <strong>MySQL 8</strong> อัปเดตฐานข้อมูลอัตโนมัติ</p>
    </footer>

</body>
</html>

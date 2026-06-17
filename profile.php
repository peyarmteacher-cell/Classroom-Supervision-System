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

$success_msg = '';
$error_msg = '';

// โหลดข้อมูลบัญชีหลักของผู้ใช้ปัจจุบัน
$stmt_u = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt_u->execute([$session_user]);
$u_data = $stmt_u->fetch();

if (!$u_data) {
    if ($user_role === 'teacher') {
        $stmt_t = $pdo->prepare("SELECT * FROM teachers WHERE username = ?");
        $stmt_t->execute([$session_user]);
        $teacher_data = $stmt_t->fetch();
        if ($teacher_data) {
            // สร้างข้อมูลลงในตาราง users อัตโนมัติ
            $hashed_pwd = password_hash('123456', PASSWORD_DEFAULT);
            $stmt_ins = $pdo->prepare("INSERT INTO users (username, password, fullname, role) VALUES (?, ?, ?, 'teacher')");
            $stmt_ins->execute([$session_user, $hashed_pwd, $teacher_data['teacher_name']]);
            
            // โหลดข้อมูลใหม่อีกครั้ง
            $stmt_u->execute([$session_user]);
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
    $stmt_t = $pdo->prepare("SELECT * FROM teachers WHERE username = ?");
    $stmt_t->execute([$session_user]);
    $teacher_data = $stmt_t->fetch();
}

// โหลดข้อมูลภาพโลโก้และชื่อโรงเรียนปัจจุบัน
$stmt_logo = $pdo->prepare("SELECT setting_value FROM school_settings WHERE setting_key = 'school_logo'");
$stmt_logo->execute();
$current_logo = $stmt_logo->fetchColumn() ?: '';

$stmt_sname = $pdo->prepare("SELECT setting_value FROM school_settings WHERE setting_key = 'school_name'");
$stmt_sname->execute();
$current_school_name = $stmt_sname->fetchColumn() ?: 'ระบบนิเทศการจัดการเรียนการสอนระดับโรงเรียน';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_school_settings'])) {
    if ($user_role === 'admin') {
        $school_name = trim($_POST['school_name'] ?? '');
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
                $stmt_up_logo = $pdo->prepare("UPDATE school_settings SET setting_value = ? WHERE setting_key = 'school_logo'");
                $stmt_up_logo->execute([$logo_saved]);

                $stmt_up_name = $pdo->prepare("UPDATE school_settings SET setting_value = ? WHERE setting_key = 'school_name'");
                $stmt_up_name->execute([$school_name]);

                $current_logo = $logo_saved;
                $current_school_name = $school_name;
                $success_msg = 'อัปเดตข้อมูลตั้งค่าโรงเรียนและโลโก้นิเทศประจำสถาบันเรียบร้อยแล้ว!';
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
        // ตรวจสอบชื่อรหัสผู้ใช้ซ้ำซ้อนในระบบกับผู้อื่น
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND username != ?");
        $stmt_check->execute([$new_username, $session_user]);
        $exists = $stmt_check->fetchColumn();

        if ($exists > 0) {
            $error_msg = "ชื่อล็อกอิน '{$new_username}' ถูกใช้งานโดยผู้อื่นในเซิร์ฟเวอร์แล้ว กรุณาลองเปลี่ยนชื่อเข้าใช้อื่น";
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
                            $photo_uploaded_path = $dest_path;
                        } else {
                            $error_msg = 'เกิดความผิดพลาดระหว่างเซฟไฟล์รูปภาพลงเซิร์ฟเวอร์โรงเรียน';
                        }
                    }
                }

                if (empty($error_msg)) {
                    $pdo->beginTransaction();
                    try {
                        // 1. ตรวจสอบการอัปเดตตารางครูหากล็อกอินเข้ามาด้วยฐานสิทธิ์คุณครู
                        if ($user_role === 'teacher' && $teacher_data) {
                            $stmt_up_t = $pdo->prepare("UPDATE teachers SET teacher_name = ?, position = ?, subject_group = ?, phone = ?, username = ?, photo_path = ? WHERE teacher_id = ?");
                            $stmt_up_t->execute([$fullname, $position, $subject_group, $phone, $new_username, $photo_uploaded_path, $teacher_data['teacher_id']]);
                        }

                        // 2. อัปเดตพัสดุตารางผู้ใช้งานหลัก
                        if ($change_password) {
                            $stmt_up_u = $pdo->prepare("UPDATE users SET username = ?, password = ?, fullname = ? WHERE username = ?");
                            $stmt_up_u->execute([$new_username, $hashed_password, $fullname, $session_user]);
                        } else {
                            $stmt_up_u = $pdo->prepare("UPDATE users SET username = ?, fullname = ? WHERE username = ?");
                            $stmt_up_u->execute([$new_username, $fullname, $session_user]);
                        }

                        $pdo->commit();

                    // ตั้งค่าซีเควนซ์เซสชันใหม่เพื่อให้สารบัญเว็บอัปเดตชื่อผู้ใช้งานทันที
                    $_SESSION['username'] = $new_username;
                    $_SESSION['fullname'] = $fullname;
                    $session_user = $new_username;
                    
                    // ดึงชุดข้อมูลรอบใหม่เพื่อใช้เรนเดอร์ลงใน UI
                    $stmt_u = $pdo->prepare("SELECT * FROM users WHERE username = ?");
                    $stmt_u->execute([$session_user]);
                    $u_data = $stmt_u->fetch();

                    if ($user_role === 'teacher') {
                        $stmt_t = $pdo->prepare("SELECT * FROM teachers WHERE username = ?");
                        $stmt_t->execute([$session_user]);
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
    <title>ตั้งค่าบัญชีและประวัติส่วนตัว - ระบบนิเทศการจัดการเรียนการสอน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', 'Inter', sans-serif; } </style>
</head>
<body class="bg-[#FAF8F5] min-h-screen text-slate-900 duration-200">

    <!-- Navbar Container -->
    <header class="bg-[#0A3370] text-white border-b-4 border-[#F59E0B] shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-4 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-3">
                <span class="text-3xl">🏫</span>
                <div>
                    <h1 class="text-base sm:text-lg font-extrabold tracking-wide text-white leading-snug">
                        ระบบนิเทศการจัดการเรียนการสอนระดับโรงเรียน
                    </h1>
                    <p class="text-[10px] text-amber-300 font-bold block uppercase tracking-wider">
                        Active Classroom Learning Supervision & Evaluation Hub
                    </p>
                </div>
            </div>

            <!-- Quick Profile status -->
            <div class="flex items-center gap-3 text-xs bg-black/20 p-2 px-4 rounded-xl border border-white/10">
                <div class="text-right">
                    <span class="font-bold text-amber-200 block text-[11px]"><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>
                    <span class="text-[9px] text-slate-300">สิทธิ์: <?php echo strtoupper($_SESSION['role']); ?></span>
                </div>
                <a href="logout.php" class="bg-red-600 hover:bg-red-700 text-white font-bold p-1 px-2.5 rounded text-[10px] transition shadow">ออกจากระบบ</a>
            </div>
        </div>
    </header>

    <!-- Main Workspace Container -->
    <main class="max-w-7xl mx-auto px-4 py-6 space-y-6">

        <!-- Top Shortcuts Links Navigation Ribbon -->
        <div class="bg-white border border-slate-200 p-2.5 rounded-2xl shadow-sm flex flex-wrap gap-2 text-xs font-semibold">
            <a href="dashboard.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                📊 แดชบอร์ดสถิติรวม
            </a>
            <?php if ($user_role !== 'teacher'): ?>
                <a href="supervision.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                    ➕ บันทึกนิเทศคาบเรียนใหม่
                </a>
            <?php endif; ?>
            <a href="comparison.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                🔎 วิเคราะห์ครูรายบุคคล/เปรียบเทียบ
            </a>
            <?php if ($user_role === 'admin' || $user_role === 'director'): ?>
                <a href="teachers.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                    👥 ทะเบียนครูผู้สอน
                </a>
                <a href="academic_years.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                    📅 ปีการศึกษา
                </a>
            <?php endif; ?>
            <a href="profile.php" class="px-4 py-2 bg-amber-500 text-white font-bold rounded-xl shadow-xs flex items-center gap-1.5">
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

        <!-- Form and account details layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Side Card: Account Info -->
            <div class="lg:col-span-1 bg-white border border-slate-200 rounded-2xl shadow-sm p-5 space-y-4">
                <div class="text-center space-y-2">
                    <div class="w-24 h-24 bg-amber-100/70 border border-amber-200 text-amber-700 flex items-center justify-center text-4xl rounded-xl mx-auto overflow-hidden relative shadow-inner">
                        <?php if ($user_role === 'teacher' && !empty($teacher_data['photo_path']) && file_exists($teacher_data['photo_path'])): ?>
                            <img src="<?php echo htmlspecialchars($teacher_data['photo_path']); ?>" alt="รูปภาพประจำตัวครู" class="w-full h-full object-cover">
                        <?php else: ?>
                            <span class="text-4xl"><?php echo $user_role === 'teacher' ? '👩‍🏫' : '👑'; ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-slate-800 text-sm"><?php echo htmlspecialchars($u_data['fullname']); ?></h3>
                        <span class="inline-block mt-1 font-bold px-2 py-0.5 rounded-full text-[9px] uppercase tracking-wider bg-[#0A3370] text-white">
                            <?php echo $user_role === 'teacher' ? 'คุณครูผู้รับนิเทศ' : ($user_role === 'director' ? 'ผู้อำนวยการโรงเรียน' : 'ผู้ดูแลระบบ'); ?>
                        </span>
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
                                    <label class="text-xs font-bold text-amber-700 block">📸 อัพโหลดรูปถ่ายประจำตัวของคุณครู (สำหรับหน้าข้อมูลและการนิเทศ)</label>
                                    <div class="flex items-center gap-3">
                                        <input type="file" name="teacher_photo" accept="image/*" class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-[11px] file:font-semibold file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100 cursor-pointer">
                                    </div>
                                    <p class="text-[9px] text-slate-400">รองรับไฟล์รูปภาพเท่านั้น (JPG, JPEG, PNG, GIF) เพื่อให้ผู้อำนวยการโรงเรียนรู้จักหน้าคุณครูในการทำรายงานนิเทศ</p>
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

                        <div class="pt-4 border-t flex justify-end">
                            <button type="submit" class="px-6 py-2.5 bg-[#0D9488] hover:bg-[#0F766E] text-white font-extrabold rounded-xl shadow cursor-pointer transition">
                                💾 บันทึกตั้งค่าสถาบันและโลโก้
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>

        </div>

    </main>

    <!-- Clean Footer block -->
    <footer class="py-6 mt-12 border-t border-slate-200 bg-white text-center text-[11px] text-slate-400 select-none leading-relaxed">
        <p>ระบบเว็บแอพพลิเคชันเพื่อสุขภาวะและวิทยฐานะประกอบคุณครู สังกัดกระทรวงศึกษาธิการ ประเทศไทย</p>
        <p class="mt-1">พัฒนารหัสด้วยมาตรฐานสูงสุด <strong>PHP 8.2+</strong> & <strong>MySQL 8</strong> อัปเดตฐานข้อมูลไดนามิกอเนกประสงค์</p>
    </footer>

</body>
</html>

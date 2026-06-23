<?php
// ==========================================
// teachers.php - จัดการข้อมูลบุคลากรครูและการเปิดบัญชีอัตโนมัติ
// ==========================================

require_once 'config.php';

// ตรวจสอบความปลอดภัยบทบาทแอดมินหรือผู้อำนวยการ
if (!isset($_SESSION['username']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'director')) {
    header("Location: dashboard.php");
    exit;
}

$success_msg = '';
$error_msg = '';

// ค่าฟอร์มสำหรับสร้าง / แก้ไข
$edit_id = $_GET['edit_id'] ?? null;
$teacher_name = '';
$position = 'ครู คศ.1';
$subject_group = 'กลุ่มสาระการเรียนรู้ภาษาไทย';
$phone = '';
$username_field = '';
$classroom = 'ชั้นประถมศึกษาปีที่ 1/1';
$teaching_hours = 8;
$work_status = 'ปกติ';
$teacher_photo_url = '';

// กรณีคลิกกดโหลดข้อมูลคุณครูมาเตรียมแก้ไข
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE teacher_id = ? AND school_code = ?");
    $stmt->execute([$edit_id, $_SESSION['school_code'] ?? '31054002']);
    $t_data = $stmt->fetch();
    if ($t_data) {
        $teacher_name = $t_data['teacher_name'];
        $position = $t_data['position'];
        $subject_group = $t_data['subject_group'];
        $phone = $t_data['phone'];
        $username_field = $t_data['username'] ?? '';
        $classroom = $t_data['classroom'] ?? 'ชั้นประถมศึกษาปีที่ 1/1';
        $teaching_hours = (int)($t_data['teaching_hours'] ?? 8);
        $work_status = $t_data['work_status'] ?? 'ปกติ';
        $teacher_photo_url = $t_data['photo_path'] ?? '';
    }
}

// ล้างข้อมูลจำลองสำหรับการทดสอบระบบเพื่อเตรียมพร้อมใช้งานจริง
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_clear_mock_data'])) {
    $school_code = $_SESSION['school_code'] ?? '31054002';
    // 1. ลบรายงานนิเทศของโรงเรียนตนเอง
    $pdo->prepare("DELETE FROM `supervisions` WHERE school_code = ?")->execute([$school_code]);
    
    // 2. ลบคุณครูจำลองและบัญชีเฉพาะโรงเรียนตนเอง
    $pdo->prepare("DELETE FROM `users` WHERE school_code = ? AND role = 'teacher'")->execute([$school_code]);
    $pdo->prepare("DELETE FROM `teachers` WHERE school_code = ?")->execute([$school_code]);
    
    // 3. ปักธงระบบว่าได้ผ่านการล้าง/ลงข้อมูลแล้วเพื่อไม่ให้คุณครูจำลองงอกกลับมาใหม่อีก
    $pdo->prepare("INSERT INTO `school_settings` (school_code, `setting_key`, `setting_value`) 
                VALUES (?, 'system_init_seeded', '1') 
                ON DUPLICATE KEY UPDATE `setting_value` = '1'")->execute([$school_code, 'system_init_seeded']);

    $success_msg = 'ระบบได้ทำการล้างข้อมูลจำลอง ทั้งประวัตินิเทศ และคุณครูทดสอบ ออกจากสารบบของสถาบันศึกษาเรียบร้อยแล้ว พร้อมต้อนรับข้อมูลจริงของโรงเรียนทันทีครับ';
    header("Location: teachers.php?success_msg=" . urlencode($success_msg));
    exit;
}

// ลบคุณครูออกจากระดับสารบบถาวร
if (isset($_GET['delete_id'])) {
    $del_id = $_GET['delete_id'];
    $school_code = $_SESSION['school_code'] ?? '31054002';
    
    // ดึงชื่อคุณครูมาดูล๊อกอินประกอบเพื่อจะเคลียร์ตารางผู้ใช้ออกด้วย
    $get_u = $pdo->prepare("SELECT username FROM teachers WHERE teacher_id = ? AND school_code = ?");
    $get_u->execute([$del_id, $school_code]);
    $u_name = $get_u->fetchColumn();

    if ($u_name) {
        $pdo->prepare("DELETE FROM users WHERE username = ? AND school_code = ?")->execute([$u_name, $school_code]);
    }

    $stmt = $pdo->prepare("DELETE FROM teachers WHERE teacher_id = ? AND school_code = ?");
    $stmt->execute([$del_id, $school_code]);

    $success_msg = 'ลบข้อมูลคุณครูและพัสดุบัญชีผู้ใช้งานออกจากสารบบเสร็จชอบธรรมแล้ว';
    header("Location: teachers.php?success_msg=" . urlencode($success_msg));
    exit;
}

// ฟรุ๊ปผลลัพธ์ย้อนหลังสตรีมแอดมินส่งฟอร์ม CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_teacher'])) {
    $teacher_name = trim($_POST['teacher_name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $subject_group = trim($_POST['subject_group'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $username_field = trim($_POST['username_field'] ?? '');
    $password_field = $_POST['password_field'] ?? '';
    $classroom = trim($_POST['classroom'] ?? 'ชั้นประถมศึกษาปีที่ 1/1');
    $teaching_hours = (int)($_POST['teaching_hours'] ?? 8);
    $work_status = trim($_POST['work_status'] ?? 'ปกติ');
    $teacher_photo_url = trim($_POST['teacher_photo_url'] ?? '');
    $teacher_photo_url = convert_gdrive_url_to_direct($teacher_photo_url);

    if (!empty($teacher_name)) {
        $school_code = $_SESSION['school_code'] ?? '31054002';
        if ($edit_id) {
            // ค้นหา username เก่าเพื่ออัปเดตอย่างสมบูรณ์เชื่อมถึง
            $stmt_old_u = $pdo->prepare("SELECT username, photo_path FROM teachers WHERE teacher_id = ? AND school_code = ?");
            $stmt_old_u->execute([$edit_id, $school_code]);
            $old_data = $stmt_old_u->fetch();
            if ($old_data) {
                $old_u = $old_data['username'];
                
                // คำนวณรักษารูปเดิม
                $photo_uploaded_path = $old_data['photo_path'] ?? null;
                
                // หากมีการระบุ URL รูปภาพโดยตรง ให้ใช้ URL นั้นนำร่วง
                if (!empty($teacher_photo_url)) {
                    $photo_uploaded_path = $teacher_photo_url;
                }

                if (isset($_FILES['teacher_photo']) && $_FILES['teacher_photo']['error'] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['teacher_photo']['tmp_name'];
                    $file_name = $_FILES['teacher_photo']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
                    if (in_array($file_ext, $allowed_exts)) {
                        $new_file_name = 'teacher_' . $edit_id . '_' . time() . '.' . $file_ext;
                        $dest_path = 'uploads/' . $new_file_name;
                        if (move_uploaded_file($file_tmp, $dest_path)) {
                            // อัพโหลดเข้ากูเกิลไดรฟ์ของระบบโรงเรียนถ้ามีตั้งค่าเชื่อมต่อ
                            $gdrive_url = upload_image_to_gdrive_if_configured($dest_path, $new_file_name, $school_code, $pdo);
                            $photo_uploaded_path = $gdrive_url ?: $dest_path;
                        }
                    }
                }

                if (empty($username_field)) {
                    $username_field = $old_u;
                }

                // คำนวณความปลอดภัยตรวจสอบตัวอักษร
                if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username_field)) {
                    $error_msg = 'ชื่อล็อกอินผู้ใช้ต้องประกอบด้วยภาษาอังกฤษหรือตัวเลขห้ามมีเว้นวรรค';
                } else {
                    // ตรวจชื่อผู้ใช้งานชนซ้ำในตารางผู้ใช้หลักไหม
                    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND username != ? AND school_code = ?");
                    $stmt_check->execute([$username_field, $old_u, $school_code]);
                    $exists = $stmt_check->fetchColumn();

                    if ($exists > 0) {
                        $error_msg = "ชื่อล็อกอิน '{$username_field}' มีหัวหน้าหมวดหรือคนอื่นใช้ไปแล้วในระบบ กรุณาใช้ชื่ออื่น";
                    } else {
                        $pdo->beginTransaction();
                        try {
                            // อัพเดตตารางคุณครูผู้สอน
                            $stmt = $pdo->prepare("UPDATE teachers SET teacher_name = ?, position = ?, subject_group = ?, phone = ?, username = ?, photo_path = ?, classroom = ?, teaching_hours = ?, work_status = ? WHERE teacher_id = ? AND school_code = ?");
                            $stmt->execute([$teacher_name, $position, $subject_group, $phone, $username_field, $photo_uploaded_path, $classroom, $teaching_hours, $work_status, $edit_id, $school_code]);
                            
                            // อัปเดตตาราง users
                            if (!empty($password_field)) {
                                $hashed_pass = password_hash($password_field, PASSWORD_DEFAULT);
                                $stmt_u_up = $pdo->prepare("UPDATE users SET username = ?, password = ?, fullname = ? WHERE username = ? AND school_code = ?");
                                $stmt_u_up->execute([$username_field, $hashed_pass, $teacher_name, $old_u, $school_code]);
                            } else {
                                $stmt_u_up = $pdo->prepare("UPDATE users SET username = ?, fullname = ? WHERE username = ? AND school_code = ?");
                                $stmt_u_up->execute([$username_field, $teacher_name, $old_u, $school_code]);
                            }

                            $pdo->commit();

                            $success_msg = "แก้ไขประวัติและรหัสผ่านเข้าใช้งานคุณครู {$teacher_name} เรียบร้อยแล้ว";
                            header("Location: teachers.php?success_msg=" . urlencode($success_msg));
                            exit;
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $error_msg = "เกิดความผิดพลาด: " . $e->getMessage();
                        }
                    }
                }
            }
        } else {
            // การจดรับเพิ่มใหม่
            // สร้างไอดีที่มีสัญลักษณ์กระทรวงศึกษา เช่น T-31054002-001
            $curr_max_id_num = 0;
            $stmt_t_all = $pdo->prepare("SELECT teacher_id FROM teachers WHERE school_code = ?");
            $stmt_t_all->execute([$school_code]);
            $all_tc = $stmt_t_all->fetchAll(PDO::FETCH_COLUMN);
            foreach ($all_tc as $tc_id) {
                $clean_id = str_replace('T-' . $school_code . '-', '', $tc_id);
                $clean_id = str_replace('T-', '', $clean_id);
                $num = (int)$clean_id;
                if ($num > $curr_max_id_num) {
                    $curr_max_id_num = $num;
                }
            }
            $next_num = $curr_max_id_num + 1;
            $new_teacher_id = 'T-' . $school_code . '-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
            
            $photo_uploaded_path = null;
            if (!empty($teacher_photo_url)) {
                $photo_uploaded_path = $teacher_photo_url;
            }
            
            if (isset($_FILES['teacher_photo']) && $_FILES['teacher_photo']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['teacher_photo']['tmp_name'];
                $file_name = $_FILES['teacher_photo']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
                if (in_array($file_ext, $allowed_exts)) {
                    $new_file_name = 'teacher_' . $new_teacher_id . '_' . time() . '.' . $file_ext;
                    $dest_path = 'uploads/' . $new_file_name;
                    if (move_uploaded_file($file_tmp, $dest_path)) {
                        // อัพโหลดเข้ากูเกิลไดรฟ์ของระบบโรงเรียนถ้ามีตั้งค่าเชื่อมต่อ
                        $gdrive_url = upload_image_to_gdrive_if_configured($dest_path, $new_file_name, $school_code, $pdo);
                        $photo_uploaded_path = $gdrive_url ?: $dest_path;
                    }
                }
            }

            if (empty($username_field)) {
                $username_field = "teacher_t{$next_num}";
            }
            
            $input_password = !empty($password_field) ? $password_field : '123456';

            // ตรวจสอบความถูกต้องของอักขระ
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username_field)) {
                $error_msg = 'ระบุชื่อล็อกอินเฉพาะตัวเลข ภาษาอังกฤษ และสัญลักษณ์สากลเท่านั้น';
            } else {
                // ตรวจซ้ำก่อนลงทะเบียนเฉพาะโรงเรียนเพื่อรองรับ username เดียวกันคนละครู
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND school_code = ?");
                $stmt_check->execute([$username_field, $school_code]);
                $exists = $stmt_check->fetchColumn();

                if ($exists > 0) {
                    $error_msg = "ชื่อล็อกอินผู้ใช้งาน '{$username_field}' ถูกใช้งานแล้วโดยเจ้าหน้าที่ท่านอื่นในระบบของโรงเรียนท่าน";
                } else {
                    $pdo->beginTransaction();
                    try {
                        // บันทึกลงตารางครูผู้สอน
                        $stmt = $pdo->prepare("INSERT INTO teachers (teacher_id, teacher_name, position, subject_group, phone, username, photo_path, classroom, teaching_hours, work_status, school_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$new_teacher_id, $teacher_name, $position, $subject_group, $phone, $username_field, $photo_uploaded_path, $classroom, $teaching_hours, $work_status, $school_code]);

                        // สร้างคู่ไอดีบัญชีล็อกอินในระบบ
                        $hashed_pass = password_hash($input_password, PASSWORD_DEFAULT);
                        $stmt_user = $pdo->prepare("INSERT INTO users (username, password, fullname, role, school_code) VALUES (?, ?, ?, 'teacher', ?)");
                        $stmt_user->execute([$username_field, $hashed_pass, $teacher_name, $school_code]);

                        $pdo->commit();

                        $success_msg = "ลงทะเบียนครูใหม่ {$new_teacher_id} คุณครู {$teacher_name} ชื่อล็อกอินคือ {$username_field} รหัสผ่านคือ {$input_password}";
                        header("Location: teachers.php?success_msg=" . urlencode($success_msg));
                        exit;
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error_msg = "เกิดความผิดพลาดในการเขียนโปรแกรม: " . $e->getMessage();
                    }
                }
            }
        }
    } else {
        $error_msg = 'กรุณากรอกระบุชื่อ-นามสกุลของคุณครูให้ชัดเจน';
    }
}

// ตรวจจับส่งคำสำเร็จจาก URL
if (isset($_GET['success_msg'])) {
    $success_msg = $_GET['success_msg'];
}

// โหลดลิสต์คุณครูทั้งหมดสังกัดปัจจุบัน
$school_code_current = $_SESSION['school_code'] ?? '31054002';
$stmt_get_all = $pdo->prepare("SELECT * FROM teachers WHERE school_code = ? ORDER BY teacher_id ASC");
$stmt_get_all->execute([$school_code_current]);
$all_teachers = $stmt_get_all->fetchAll();

// โหลดระดับชั้นเรียนทั้งหมดในโรงเรียนเพื่อรองรับการจัดทำตัวเลือก
$stmt_cls_drop = $pdo->prepare("SELECT * FROM classrooms WHERE school_code = ? ORDER BY class_name ASC");
$stmt_cls_drop->execute([$school_code_current]);
$all_classrooms_list = $stmt_cls_drop->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บริหารจัดการครูออนไลน์ - ระบบประเมินนิเทศ</title>
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
                    <span class="text-[9px] text-slate-200 uppercase font-bold tracking-wider">สิทธิ์: <?php echo strtoupper($_SESSION['role']); ?></span>
                </div>
                <div class="flex flex-col gap-1">
                    <a href="profile.php" class="bg-[#FFC107] hover:bg-amber-600 text-slate-900 font-bold p-1 px-2 rounded-lg text-[9px] text-center transition shadow-sm border-none">ตั้งค่า</a>
                    <a href="logout.php" class="bg-rose-600 hover:bg-rose-700 text-white font-bold p-1 px-2 rounded-lg text-[9px] text-center transition shadow-sm border-none">ออก</a>
                </div>
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
            <a href="supervision.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                ➕ บันทึกนิเทศคาบเรียนใหม่
            </a>
            <a href="comparison.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                🔎 วิเคราะห์ครูรายบุคคล/เปรียบเทียบ
            </a>
            <a href="teachers.php" class="px-4 py-2 bg-[#1565C0] text-white rounded-xl shadow-sm font-bold flex items-center gap-1.5">
                👥 ทะเบียนครูผู้สอน
            </a>
            <a href="academic_years.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                📅 ปีการศึกษา
            </a>
            <a href="classrooms.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                🚪 ระดับชั้นเรียน
            </a>
            <a href="profile.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                ⚙️ ตั้งค่าบัญชีของฉัน
            </a>
        </div>

        <?php if ($success_msg): ?>
            <div class="text-xs bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-2xl font-bold flex items-center gap-1.5">
                ✅ <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="text-xs bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-2xl font-bold flex items-center gap-1.5">
                ⚠️ <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Side Form: Add or Edit Teacher -->
            <div class="lg:col-span-4 bg-white border border-slate-200 p-5 rounded-2xl shadow-sm space-y-4">
                <div class="border-b pb-2">
                    <h3 class="font-extrabold text-[#0A3370] text-sm">
                        <?php echo $edit_id ? 'แก้ไขประวัติข้อมูลครู' : 'ลงทะเบียนครูผู้สอนใหม่'; ?>
                    </h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">ระบบจะสร้างบัญชีผู้ใช้งานสำหรับล็อกอินอัตโนมัติ</p>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs font-medium">
                    <input type="hidden" name="action_save_teacher" value="1">
                    
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">ชื่อ - นามสกุลครูผู้สอน *</label>
                        <input type="text" name="teacher_name" required value="<?php echo htmlspecialchars($teacher_name); ?>" placeholder="เช่น นางสาวมาลี รักการเรียน" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:ring-1 focus:ring-blue-900">
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">ตำแหน่งงาน / วิทยฐานะ *</label>
                        <select name="position" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs cursor-pointer outline-none">
                            <option value="ครู คศ.1" <?php if ($position === 'ครู คศ.1') echo 'selected'; ?>>ครู คศ.1</option>
                            <option value="ครูชำนาญการ" <?php if ($position === 'ครูชำนาญการ') echo 'selected'; ?>>ครูชำนาญการ</option>
                            <option value="ครูชำนาญการพิเศษ" <?php if ($position === 'ครูชำนาญการพิเศษ') echo 'selected'; ?>>ครูชำนาญการพิเศษ</option>
                            <option value="ครูผู้ช่วย" <?php if ($position === 'ครูผู้ช่วย') echo 'selected'; ?>>ครูผู้ช่วย</option>
                            <option value="ครู คศ.3 / ผู้อำนวยการ" <?php if ($position === 'ครู คศ.3 / ผู้อำนวยการ') echo 'selected'; ?>>ครู คศ.3 / ผู้อำนวยการ</option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">กลุ่มสาระการเรียนรู้ *</label>
                        <select name="subject_group" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs cursor-pointer outline-none">
                            <option value="กลุ่มสาระการเรียนรู้ภาษาไทย" <?php if ($subject_group === 'กลุ่มสาระการเรียนรู้ภาษาไทย') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้ภาษาไทย</option>
                            <option value="กลุ่มสาระการเรียนรู้คณิตศาสตร์" <?php if ($subject_group === 'กลุ่มสาระการเรียนรู้คณิตศาสตร์') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้คณิตศาสตร์</option>
                            <option value="กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี" <?php if ($subject_group === 'กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี</option>
                            <option value="กลุ่มสาระการเรียนรู้สังคมศึกษา ศาสนา และวัฒนธรรม" <?php if ($subject_group === 'กลุ่มสาระการเรียนรู้สังคมศึกษา ศาสนา และวัฒนธรรม') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้สังคมศึกษา ศาสนา และวัฒนธรรม</option>
                            <option value="กลุ่มสาระการเรียนรู้สุขศึกษาและพลศึกษา" <?php if ($subject_group === 'กลุ่มสาระการเรียนรู้สุขศึกษาและพลศึกษา') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้สุขศึกษาและพลศึกษา</option>
                            <option value="กลุ่มสาระการเรียนรู้ศิลปะ" <?php if ($subject_group === 'กลุ่มสาระการเรียนรู้ศิลปะ') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้ศิลปะ</option>
                            <option value="กลุ่มสาระการเรียนรู้ภาษาต่างประเทศ" <?php if ($subject_group === 'กลุ่มสาระการเรียนรู้ภาษาต่างประเทศ') echo 'selected'; ?>>กลุ่มสาระการเรียนรู้ภาษาต่างประเทศ</option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">เบอร์โทรศัพท์ติดต่อ</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>" placeholder="เช่น 081-XXXXXXX" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none font-mono">
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">ห้องเรียนประจำตัวประจำหลัก *</label>
                        <select name="classroom" required class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs cursor-pointer outline-none">
                            <option value="">-- เลือกห้องเรียนประจำหลัก --</option>
                            <?php foreach ($all_classrooms_list as $cls_item): ?>
                                <option value="<?php echo htmlspecialchars($cls_item['class_name']); ?>" <?php if ($classroom === $cls_item['class_name']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($cls_item['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($all_classrooms_list)): ?>
                                <option value="ชั้นประถมศึกษาปีที่ 1/1" <?php if ($classroom === 'ชั้นประถมศึกษาปีที่ 1/1') echo 'selected'; ?>>ชั้นประถมศึกษาปีที่ 1/1</option>
                                <option value="ชั้นประถมศึกษาปีที่ 2/2" <?php if ($classroom === 'ชั้นประถมศึกษาปีที่ 2/2') echo 'selected'; ?>>ชั้นประถมศึกษาปีที่ 2/2</option>
                                <option value="ชั้นประถมศึกษาปีที่ 4/1" <?php if ($classroom === 'ชั้นประถมศึกษาปีที่ 4/1') echo 'selected'; ?>>ชั้นประถมศึกษาปีที่ 4/1</option>
                                <option value="ชั้นประถมศึกษาปีที่ 6/3" <?php if ($classroom === 'ชั้นประถมศึกษาปีที่ 6/3') echo 'selected'; ?>>ชั้นประถมศึกษาปีที่ 6/3</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">จำนวนคาบสอนต่อสัปดาห์ *</label>
                        <input type="number" name="teaching_hours" required value="<?php echo htmlspecialchars($teaching_hours); ?>" min="1" max="50" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none">
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">สถานะปฏิบัติราชการขณะนี้ *</label>
                        <select name="work_status" required class="w-full px-3 py-2 bg-slate-50 border border-slate-205 rounded-xl text-xs cursor-pointer outline-none font-bold">
                            <option value="ปกติ" <?php if ($work_status === 'ปกติ') echo 'selected'; ?>>ปกติ (นิเทศการสอนได้)</option>
                            <option value="ลาป่วย" <?php if ($work_status === 'ลาป่วย') echo 'selected'; ?>>🤒 ลาป่วย (งดการนิเทศ)</option>
                            <option value="ลากิจ" <?php if ($work_status === 'ลากิจ') echo 'selected'; ?>>ลากิจ (งดการนิเทศ)</option>
                            <option value="ลาพักผ่อน" <?php if ($work_status === 'ลาพักผ่อน') echo 'selected'; ?>>ลาพักผ่อน (งดการนิเทศ)</option>
                            <option value="ไปราชการ" <?php if ($work_status === 'ไปราชการ') echo 'selected'; ?>>ไปราชการ (งดการนิเทศ)</option>
                        </select>
                    </div>

                    <!-- Photo Upload -->
                    <div class="space-y-2 border-t border-dashed pt-4 mt-1">
                        <label class="text-xs font-bold text-amber-700 block">📸 ภาพถ่ายประจำตัวคุณครู</label>
                        <?php if ($edit_id && !empty($t_data['photo_path']) && is_valid_photo($t_data['photo_path'])): ?>
                            <div class="w-16 h-16 rounded-xl overflow-hidden border border-slate-200 mb-1.5 shadow-sm">
                                <img src="<?php echo htmlspecialchars($t_data['photo_path']); ?>" class="w-full h-full object-cover">
                            </div>
                        <?php endif; ?>
                        
                        <div class="space-y-1">
                            <span class="text-[10px] text-slate-400 block font-bold">วิธีที่ 1: อัปโหลดไฟล์ภาพโดยตรง</span>
                            <input type="file" name="teacher_photo" accept="image/*" class="w-full text-xs text-slate-505 file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-[11px] file:font-semibold file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100 cursor-pointer">
                        </div>
                        
                        <div class="space-y-1 pt-1">
                            <span class="text-[10px] text-slate-400 block font-bold">วิธีที่ 2: หรือระบุลิงก์รูปภาพ Google Drive / รูปภาพทั่วไป</span>
                            <input type="text" name="teacher_photo_url" value="<?php echo htmlspecialchars($teacher_photo_url); ?>" placeholder="เช่น https://drive.google.com/file/d/... หรือลิงก์รูปภาพ" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:ring-1 focus:ring-amber-500 font-mono">
                            <p class="text-[9px] text-slate-400 leading-normal">ระบบจะทำการแปลงลิงก์แชร์ของ Google Drive ให้สามารถดึงภาพขึ้นมาแสดงผลได้โดยอัตโนมัติ</p>
                        </div>
                    </div>

                    <div class="space-y-1 border-t border-dashed pt-4">
                        <label class="text-xs font-bold text-amber-700">ชื่อล็อกอินผู้ใช้งาน (Username) <?php echo !$edit_id ? '(เว้นว่างเพื่อตั้งอัตโนมัติ)' : '*'; ?></label>
                        <input type="text" name="username_field" value="<?php echo htmlspecialchars($username_field); ?>" placeholder="<?php echo $edit_id ? 'เช่น t_malee' : 'ระบบตั้งค่าให้อัตโนมัติหากปล่อยว่าง'; ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none font-mono focus:ring-1 focus:ring-blue-900">
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-amber-700">รหัสผ่านสำหรับล็อกอิน <?php echo !$edit_id ? '(เว้นว่างสำหรับพาสเวิร์ดดีฟอลต์ 123456)' : '(เว้นวางหากไม่ต้องเปลี่ยน)'; ?></label>
                        <input type="password" name="password_field" placeholder="<?php echo $edit_id ? 'พิมพ์แทนของเก่าเพื่อเปลี่ยนพาสเวิร์ด' : 'รหัสความเข้าใช้งาน หรือปล่อยว่างได้'; ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:ring-1 focus:ring-blue-900">
                    </div>

                    <div class="pt-2 flex gap-2">
                        <?php if ($edit_id): ?>
                            <a href="teachers.php" class="w-1/2 py-2 bg-slate-100 text-slate-600 rounded-xl font-bold text-center block leading-relaxed hover:bg-slate-200">ยกเลิกแก้ไข</a>
                        <?php endif; ?>
                        <button type="submit" class="<?php echo $edit_id ? 'w-1/2 bg-amber-500 hover:bg-amber-600' : 'w-full bg-[#0A3370] hover:bg-[#07244F]'; ?> py-2 text-white font-bold rounded-xl shadow cursor-pointer text-center">
                            <?php echo $edit_id ? 'บันทึกแก้ไข' : 'ลงชื่อบันทึกคุณครู'; ?>
                        </button>
                    </div>

                </form>

                <div class="bg-blue-50/50 border border-dashed border-blue-200 text-blue-900 p-3 rounded-lg text-[10.5px] leading-relaxed space-y-1 font-medium">
                    <span class="font-bold flex items-center gap-1">🔑 รายละเอียดความมั่นคงปลอดภัย:</span>
                    <p>เมื่อเพิ่มครู ระบบจะออกไอดีล็อกอินอัตโนมัติเพื่อใช้ประเมินตนเองหรือสืบค้นประวัติ โดยมีชื่อผู้ใช้งานดังตารางขวามือ และมีรหัสผ่านเริ่มต้นร่วมกันคือ <code class="bg-[#EFF6FF] text-[#0A3370] px-1 font-extrabold rounded">123456</code> เสมอ</p>
                </div>

                <!-- เครื่องมือล้างข้อมูลจำลองเพื่อขึ้นระบบจริง -->
                <div class="bg-rose-50 border border-rose-250 p-4 rounded-xl space-y-3">
                    <span class="font-extrabold text-rose-800 text-[11px] block flex items-center gap-1.5">🛠️ การเตรียมความพร้อมขึ้นระบบจริง</span>
                    <p class="text-[10px] text-slate-500 leading-normal">
                        ท่านสามารถล้างข้อมูลคุณครูตัวอย่างจำลอง (ครูมาลี, ครูสมยศ, ครูดรุณี) และเคลียร์รายงานบันทึกประเมินคะแนนนิเทศที่ใช้ทดสอบออกทั้งหมดอย่างสะอาด พร้อมปักธงให้รหัสครูจำลองไม่กลับมางอกซ้ำอีก เมื่อกดล้างข้อมูลระบบจะเปิดโอกาสให้ป้อนข้อมูลบุคลากรครูจริงทันที
                    </p>
                    <form method="POST" onsubmit="return confirm('⚠️ คำเตือน: ระบบจะดำเนินการเคลียร์ประวัติคะแนนนิเทศ และนำบัญชีคุณครูจำลอง T-001 ถึง T-003 ออกจากข้อมูลถาวร เพื่อเตรียมใช้งานระบบจริง? ประสงค์ดำเนินการ?')">
                        <input type="hidden" name="action_clear_mock_data" value="1">
                        <button type="submit" class="w-full py-2 bg-rose-600 hover:bg-rose-750 text-white text-[10.5px] font-bold rounded-lg shadow-sm transition flex items-center justify-center gap-1 cursor-pointer">
                            🗑️ ล้างข้อมูลจำลองและผลนิเทศทดสอบทั้งหมด
                        </button>
                    </form>
                </div>
            </div>

            <!-- List of current teachers -->
            <div class="lg:col-span-8 bg-white border border-slate-200 p-5 rounded-2xl shadow-sm text-xs space-y-4">
                <div class="border-b pb-2 flex justify-between items-center">
                    <h3 class="font-extrabold text-[#0A3370] text-sm">รายชื่อคุณครูทั้งหมดในโรงเรียน (<?php echo count($all_teachers); ?> ท่าน)</h3>
                    <span class="text-[10px] text-slate-400">ข้อมูลอัปเดตแบบเรียลไทม์จากเซิร์ฟเวอร์โรงเรียน</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 text-slate-650 font-extrabold text-[11px]">
                            <tr>
                                <th class="p-3">รหัสคุณครู</th>
                                <th class="p-3">ชื่อ-นามสกุล</th>
                                <th class="p-3">ตำแหน่ง / ระดับชั้นหลัก</th>
                                <th class="p-3">กลุ่มสาระ / คาบสอน</th>
                                <th class="p-3">ชื่อล็อกอินผู้ใช้งาน</th>
                                <th class="p-3 text-center">สถานะ</th>
                                <th class="p-3 text-center">ปฏิบัติการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php foreach ($all_teachers as $t): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-3 font-mono font-bold text-blue-900"><?php echo htmlspecialchars($t['teacher_id']); ?></td>
                                    <td class="p-3 font-bold text-slate-900">
                                        <div class="flex items-center gap-2">
                                            <div class="w-8 h-8 rounded-full overflow-hidden bg-slate-100 border border-slate-200 flex-shrink-0 flex items-center justify-center">
                                                <?php if (!empty($t['photo_path']) && is_valid_photo($t['photo_path'])): ?>
                                                    <img src="<?php echo htmlspecialchars($t['photo_path']); ?>" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <span class="text-xs">👩‍🏫</span>
                                                <?php endif; ?>
                                            </div>
                                            <span><?php echo htmlspecialchars($t['teacher_name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="p-3 text-slate-600">
                                        <div class="font-bold text-slate-700"><?php echo htmlspecialchars($t['position']); ?></div>
                                        <div class="text-[10px] text-[#0A3370] font-bold">🏫 <?php echo htmlspecialchars($t['classroom'] ?? 'ชั้นประถมศึกษาปีที่ 1/1'); ?></div>
                                    </td>
                                    <td class="p-3 text-slate-550 font-medium">
                                        <div><?php echo htmlspecialchars($t['subject_group']); ?></div>
                                        <div class="text-[10px] text-indigo-700 font-bold font-mono">📅 <?php echo (int)($t['teaching_hours'] ?? 8); ?> คาบ/สัปดาห์</div>
                                    </td>
                                    <td class="p-3"><span class="font-mono font-extrabold text-amber-600 bg-amber-50/20 px-1.5 py-0.5 rounded text-[10px] border border-amber-100/50"><?php echo htmlspecialchars($t['username']); ?></span></td>
                                    <td class="p-3 text-center">
                                        <?php if (($t['work_status'] ?? 'ปกติ') === 'ปกติ'): ?>
                                            <span class="p-1 px-2.5 bg-emerald-50 text-emerald-700 border border-emerald-150 rounded-full text-[10px] font-bold">ปกติ</span>
                                        <?php else: ?>
                                            <span class="p-1 px-2.5 bg-rose-50 text-rose-700 border border-rose-150 rounded-full text-[10px] font-bold">🤒 <?php echo htmlspecialchars($t['work_status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-center">
                                        <div class="flex justify-center gap-1.5">
                                            <a href="teachers.php?edit_id=<?php echo urlencode($t['teacher_id']); ?>" class="bg-amber-50 border border-amber-200 text-amber-700 font-bold py-1 px-2 rounded-md hover:bg-amber-100" title="แก้ไข">แก้ไข</a>
                                            <a href="teachers.php?delete_id=<?php echo urlencode($t['teacher_id']); ?>" onclick="return confirm('ยืนยันลบข้อมูลคุณครู <?php echo htmlspecialchars($t['teacher_name']); ?> ออกจากสารบบโรงเรียน? ทั้งนี้บัญชีล็อกอินและสถิตินิเทศทั้งหมดของคุณครูจะถูกลบทิ้งไปพร้อมกันถาวร')" class="bg-rose-50 border border-rose-200 text-rose-600 font-bold py-1 px-2 rounded-md hover:bg-rose-100" title="ลบ">ลบ</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
        <a href="teachers.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-[#1565C0] font-bold">
            <span class="text-xl">👥</span>
            <span class="text-[9px]">ทะเบียนครู</span>
        </a>
        <a href="profile.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-slate-400 hover:text-slate-600">
            <span class="text-xl">⚙️</span>
            <span class="text-[9px]">ตั้งค่า</span>
        </a>
    </div>

    <!-- Clean Footer block -->
    <footer class="py-6 mt-12 border-t border-slate-200 bg-white text-center text-[11px] text-slate-400 select-none leading-relaxed">
        <p>ระบบนิเทศการจัดการเรียนการสอน - สังกัดกระทรวงศึกษาธิการ</p>
        <p class="mt-1">พัฒนารหัสด้วยมาตรฐานสูงสุด <strong>PHP 8.2+</strong> & <strong>MySQL 8</strong> อัปเดตฐานข้อมูลอัตโนมัติ</p>
    </footer>

</body>
</html>

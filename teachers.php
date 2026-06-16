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

// กรณีคลิกกดโหลดข้อมูลคุณครูมาเตรียมแก้ไข
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE teacher_id = ?");
    $stmt->execute([$edit_id]);
    $t_data = $stmt->fetch();
    if ($t_data) {
        $teacher_name = $t_data['teacher_name'];
        $position = $t_data['position'];
        $subject_group = $t_data['subject_group'];
        $phone = $t_data['phone'];
        $username_field = $t_data['username'] ?? '';
    }
}

// ลบคุณครูออกจากระดับสารบบถาวร
if (isset($_GET['delete_id'])) {
    $del_id = $_GET['delete_id'];
    
    // ดึงชื่อคุณครูมาดูล๊อกอินประกอบเพื่อจะเคลียร์ตารางผู้ใช้ออกด้วย
    $get_u = $pdo->prepare("SELECT username FROM teachers WHERE teacher_id = ?");
    $get_u->execute([$del_id]);
    $u_name = $get_u->fetchColumn();

    if ($u_name) {
        $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$u_name]);
    }

    $stmt = $pdo->prepare("DELETE FROM teachers WHERE teacher_id = ?");
    $stmt->execute([$del_id]);

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

    if (!empty($teacher_name)) {
        if ($edit_id) {
            // ค้นหา username เก่าเพื่ออัปเดตอย่างสมบูรณ์เชื่อมถึง
            $stmt_old_u = $pdo->prepare("SELECT username FROM teachers WHERE teacher_id = ?");
            $stmt_old_u->execute([$edit_id]);
            $old_u = $stmt_old_u->fetchColumn();

            if (empty($username_field)) {
                $username_field = $old_u;
            }

            // คำนวณความปลอดภัยตรวจสอบตัวอักษร
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username_field)) {
                $error_msg = 'ชื่อล็อกอินผู้ใช้ต้องประกอบด้วยภาษาอังกฤษหรือตัวเลขห้ามมีเว้นวรรค';
            } else {
                // ตรวจชื่อผู้ใช้งานชนซ้ำในตารางผู้ใช้หลักไหม
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND username != ?");
                $stmt_check->execute([$username_field, $old_u]);
                $exists = $stmt_check->fetchColumn();

                if ($exists > 0) {
                    $error_msg = "ชื่อล็อกอิน '{$username_field}' มีหัวหน้าหมวดหรือคนอื่นใช้ไปแล้วในระบบ กรุณาใช้ชื่ออื่น";
                } else {
                    $pdo->beginTransaction();
                    try {
                        // อัพเดตตารางคุณครูผู้สอน
                        $stmt = $pdo->prepare("UPDATE teachers SET teacher_name = ?, position = ?, subject_group = ?, phone = ?, username = ? WHERE teacher_id = ?");
                        $stmt->execute([$teacher_name, $position, $subject_group, $phone, $username_field, $edit_id]);
                        
                        // อัปเดตตาราง users
                        if (!empty($password_field)) {
                            $hashed_pass = password_hash($password_field, PASSWORD_DEFAULT);
                            $stmt_u_up = $pdo->prepare("UPDATE users SET username = ?, password = ?, fullname = ? WHERE username = ?");
                            $stmt_u_up->execute([$username_field, $hashed_pass, $teacher_name, $old_u]);
                        } else {
                            $stmt_u_up = $pdo->prepare("UPDATE users SET username = ?, fullname = ? WHERE username = ?");
                            $stmt_u_up->execute([$username_field, $teacher_name, $old_u]);
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
        } else {
            // การจดรับเพิ่มใหม่
            // สร้างไอดีที่มีสัญลักษณ์กระทรวงศึกษา เช่น T-00X
            $curr_max_id_num = 0;
            $all_tc = $pdo->query("SELECT teacher_id FROM teachers")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($all_tc as $tc_id) {
                $num = (int)str_replace('T-', '', $tc_id);
                if ($num > $curr_max_id_num) {
                    $curr_max_id_num = $num;
                }
            }
            $next_num = $curr_max_id_num + 1;
            $new_teacher_id = 'T-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
            
            if (empty($username_field)) {
                $username_field = "teacher_t{$next_num}";
            }
            
            $input_password = !empty($password_field) ? $password_field : '123456';

            // ตรวจสอบความถูกต้องของอักขระ
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username_field)) {
                $error_msg = 'ระบุชื่อล็อกอินเฉพาะตัวเลข ภาษาอังกฤษ และสัญลักษณ์สากลเท่านั้น';
            } else {
                // ตรวจซ้ำก่อนลงทะเบียน
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt_check->execute([$username_field]);
                $exists = $stmt_check->fetchColumn();

                if ($exists > 0) {
                    $error_msg = "ชื่อล็อกอินผู้ใช้งาน '{$username_field}' ถูกใช้งานแล้วโดยเจ้าหน้าที่ท่านอื่นในระบบ";
                } else {
                    $pdo->beginTransaction();
                    try {
                        // บันทึกลงตารางครูผู้สอน
                        $stmt = $pdo->prepare("INSERT INTO teachers (teacher_id, teacher_name, position, subject_group, phone, username) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$new_teacher_id, $teacher_name, $position, $subject_group, $phone, $username_field]);

                        // สร้างคู่ไอดีบัญชีล็อกอินในระบบโดยใช้อัลกอรึทึมความปลอดภัยมาตรฐานสากล
                        $hashed_pass = password_hash($input_password, PASSWORD_DEFAULT);
                        $stmt_user = $pdo->prepare("INSERT INTO users (username, password, fullname, role) VALUES (?, ?, ?, 'teacher')");
                        $stmt_user->execute([$username_field, $hashed_pass, $teacher_name]);

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
$all_teachers = $pdo->query("SELECT * FROM teachers ORDER BY teacher_id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บริหารจัดการครูออนไลน์ - ระบบประเมินนิเทศ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;505;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
                <div class="flex flex-col gap-1">
                    <a href="profile.php" class="bg-amber-500 hover:bg-amber-600 text-white font-bold p-1 px-2 rounded text-[9px] text-center transition shadow">ตั้งค่าบัญชี</a>
                    <a href="logout.php" class="bg-red-600 hover:bg-red-700 text-white font-bold p-1 px-2.5 rounded text-[9px] text-center transition shadow">ออกจากระบบ</a>
                </div>
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
            <a href="supervision.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                ➕ บันทึกนิเทศคาบเรียนใหม่
            </a>
            <a href="comparison.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                🔎 วิเคราะห์ครูรายบุคคล/เปรียบเทียบ
            </a>
            <a href="teachers.php" class="px-4 py-2 bg-[#0A3370] text-white rounded-xl shadow-xs font-bold flex items-center gap-1.5">
                👥 ทะเบียนครูผู้สอน
            </a>
            <a href="academic_years.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                📅 สารบบปีการศึกษา
            </a>
            <a href="profile.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
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

                <form method="POST" class="space-y-4 text-xs font-medium">
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
            </div>

            <!-- List of current teachers -->
            <div class="lg:col-span-8 bg-white border border-slate-200 p-5 rounded-2xl shadow-sm text-xs space-y-4">
                <div class="border-b pb-2 flex justify-between items-center">
                    <h3 class="font-extrabold text-[#0A3370] text-sm">รายนามลิสต์กำลังพลครูในสถาบันทั้งหมด (<?php echo count($all_teachers); ?> ท่าน)</h3>
                    <span class="text-[10px] text-slate-400">ข้อมูลอัปเดตแบบเรียลไทม์จากเซิร์ฟเวอร์โรงเรียน</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 text-slate-650 font-extrabold text-[11px]">
                            <tr>
                                <th class="p-3">รหัสคุณครู</th>
                                <th class="p-3">ชื่อ-นามสกุล</th>
                                <th class="p-3">ตำแหน่ง / วิทยฐานะ</th>
                                <th class="p-3">กลุ่มสาระการเรียนรู้</th>
                                <th class="p-3">ชื่อล็อกอินผู้ใช้งาน</th>
                                <th class="p-3">เบอร์ติดต่อ</th>
                                <th class="p-3 text-center">ปฏิบัติการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php foreach ($all_teachers as $t): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-3 font-mono font-bold text-blue-900"><?php echo htmlspecialchars($t['teacher_id']); ?></td>
                                    <td class="p-3 font-bold text-slate-900"><?php echo htmlspecialchars($t['teacher_name']); ?></td>
                                    <td class="p-3 text-slate-600"><?php echo htmlspecialchars($t['position']); ?></td>
                                    <td class="p-3 text-slate-550 font-medium"><?php echo htmlspecialchars($t['subject_group']); ?></td>
                                    <td class="p-3 font-mono font-extrabold text-amber-600 bg-amber-50/20 px-1.5 py-0.5 rounded text-[10px] inline-block mt-3 ml-2 border border-amber-100/50"><?php echo htmlspecialchars($t['username']); ?></td>
                                    <td class="p-3 font-mono text-slate-500"><?php echo htmlspecialchars($t['phone'] ?: '-'); ?></td>
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

    <!-- Clean Footer block -->
    <footer class="py-6 mt-12 border-t border-slate-200 bg-white text-center text-[11px] text-slate-400 select-none leading-relaxed">
        <p>ระบบเว็บแอพพลิเคชันเพื่อสุขภาวะและวิทยฐานะประกอบคุณครู สังกัดกระทรวงศึกษาธิการ ประเทศไทย</p>
        <p class="mt-1">พัฒนารหัสด้วยมาตรฐานสูงสุด <strong>PHP 8.2+</strong> & <strong>MySQL 8</strong> อัปเดตฐานข้อมูลไดนามิกอเนกประสงค์</p>
    </footer>

</body>
</html>

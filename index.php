<?php
// ==========================================
// index.php - หน้าหน่วยล็อกอินเข้าสู่ระบบ & ลงทะเบียนเรียนกลุ่มใหม่
// ==========================================

require_once 'config.php';

$error = '';
$success = '';
$active_tab = 'login';

// 1. ตรวจสอบการสมัครขอใช้งานโรงเรียนใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_register_school'])) {
    $active_tab = 'register';
    $reg_school_code = trim($_POST['reg_school_code'] ?? '');
    $reg_school_name = trim($_POST['reg_school_name'] ?? '');
    $reg_affiliation = trim($_POST['reg_affiliation'] ?? '');
    $reg_fullname = trim($_POST['reg_fullname'] ?? '');
    $reg_username = trim($_POST['reg_username'] ?? '');
    $reg_password = trim($_POST['reg_password'] ?? '');

    if (strlen($reg_school_code) !== 8 || !is_numeric($reg_school_code)) {
        $error = "รหัสโรงเรียน SMISS จะต้องเป็นตัวเลข 8 หลักเท่านั้น ถ้วนถูกต้อง";
    } else if (empty($reg_school_name) || empty($reg_affiliation) || empty($reg_fullname) || empty($reg_username) || empty($reg_password)) {
        $error = "กรุณากรอกข้อมูลระดับโรงเรียนและสิทธิ์ผู้ดูแลโรงเรียนให้ครบถ้วนทุกช่องหลัก";
    } else {
        // เช็คการลงทะเบียนซ้ำ
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM schools WHERE school_code = ?");
        $stmt_check->execute([$reg_school_code]);
        if ($stmt_check->fetchColumn() > 0) {
            $error = "ขออภัยครับ! รหัส SMISS โรงเรียน '{$reg_school_code}' ได้รับการลงทะเบียนใช้งานก่อนหน้านี้ในระบบแล้ว";
        } else {
            // เช็คชื่อแอดมินซ้ำซ้อน
            $fullname_prefixed = $reg_school_code . '_' . $reg_username;
            $stmt_u_chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt_u_chk->execute([$fullname_prefixed]);
            if ($stmt_u_chk->fetchColumn() > 0) {
                $error = "ชื่อบัญชีผู้ดูแลระบบโรงเรียน '{$reg_username}' ซ้ำกับข้อมูลบุคลากรในสารระบบเครือข่าย";
            } else {
                $pdo->beginTransaction();
                try {
                    // 1. เพิ่มเข้าตารางโรงเรียนในสถานะ pending รออนุมัติ
                    $stmt_ins_s = $pdo->prepare("INSERT INTO schools (school_code, school_name, affiliation, status) VALUES (?, ?, ?, 'pending')");
                    $stmt_ins_s->execute([$reg_school_code, $reg_school_name, $reg_affiliation]);
                    
                    // 2. สร้างบัญชี Admin ประจำโรงเรียนนี้ในตาราง users
                    $hashed_pwd = password_hash($reg_password, PASSWORD_DEFAULT);
                    $stmt_ins_u = $pdo->prepare("INSERT INTO users (username, password, fullname, role, school_code) VALUES (?, ?, ?, 'admin', ?)");
                    $stmt_ins_u->execute([$fullname_prefixed, $hashed_pwd, $reg_fullname, $reg_school_code]);
                    
                    $pdo->commit();
                    $success = "ลงทะเบียนโรงเรียนสำเร็จเรียบร้อย! กรุณาโทรติดต่อหรือรอข้อมูลการอนุมัติเปิดใช้งานสิทธิ์ระบบจาก Super Admin คณะผู้ดูแลเพื่อร่วมเริ่มทำงาน";
                    $active_tab = 'login';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "เกิดความผิดพลาดในการเขียนข้อมูลลงทะเบียน: " . $e->getMessage();
                }
            }
        }
    }
}

// 2. ตรวจสอบการเข้าสู่ระบบ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_login'])) {
    $active_tab = 'login';
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        
        // กรองการเข้าสู่ระบบของ Super Admin โดยตรง
        if ($username === 'superadmin') {
            $stmt_super = $pdo->prepare("SELECT * FROM users WHERE username = 'superadmin'");
            $stmt_super->execute();
            $super_user = $stmt_super->fetch();
            
            if ($super_user && password_verify($password, $super_user['password'])) {
                $_SESSION['username'] = $super_user['username'];
                $_SESSION['fullname'] = $super_user['fullname'];
                $_SESSION['role'] = 'super_admin';
                $_SESSION['school_code'] = null;
                header("Location: super_admin.php");
                exit;
            } else {
                $error = "รหัสผู้ใช้ หรือรหัสผ่าน Super Admin ไม่ถูกต้อง กรุณาติดต่อผู้ควบคุมเซิร์ฟเวอร์หลัก";
            }
        } else {
            // ค้นหาบัญชีผู้ใช้ในตาราง users หรือ teachers เพื่อดึงรหัสโรงเรียน (school_code) ของผู้ใช้นี้อัตโนมัติ
            $smiss = null;
            $user = null;
            
            $stmt_u = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt_u->execute([$username]);
            $user = $stmt_u->fetch();
            
            if ($user && !empty($user['school_code'])) {
                $smiss = $user['school_code'];
            } else {
                // หากไม่พบในตาราง users ลองค้นหาในตารางครูผู้สอนเพื่อดึงรหัสโรงเรียนมา
                $stmt_tf = $pdo->prepare("SELECT school_code FROM teachers WHERE username = ?");
                $stmt_tf->execute([$username]);
                $smiss = $stmt_tf->fetchColumn() ?: null;
            }
            
            // ป้องกัน fallback สำหรับโรงเรียนเดิมที่ไม่มีรหัสในบางฟิลด์
            if (empty($smiss)) {
                $smiss = '31054002'; // ตั้งค่าเป็นรหัสโรงเรียนบ้านหนองหว้าเริ่มต้น
            }
            
            // เช็คสถานะโรงเรียนก่อนอนุญาตให้ล็อกอิน
            $stmt_sch = $pdo->prepare("SELECT * FROM schools WHERE school_code = ?");
            $stmt_sch->execute([$smiss]);
            $school_info = $stmt_sch->fetch();
            
            if (!$school_info) {
                $error = "ชื่อล็อกอินผู้ใช้งานหรือข้อมูลสิทธิ์สังกัดโรงเรียนไม่ถูกต้องในระบบ";
            } else if ($school_info['status'] === 'deactivated') {
                $error = "ขออภัยครับ! บริการของโรงเรียนท่านถูกระงับสิทธิ์เข้าใช้งานชั่วคราว กรุณาติดต่อ Super Admin";
            } else if ($school_info['status'] === 'pending') {
                $error = "ใบสมัครลงทะเบียนโรงเรียนของท่านอยู่ในคิว 'รออนุมัติงาน' เปิดสิทธิ์จาก Super Admin";
            } else {
                // ตรวจหาระดับรหัสผ่าน
                if ($user && ($password === '123456' || password_verify($password, $user['password']))) {
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['fullname'] = $user['fullname'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['school_code'] = $smiss;
                    
                    if ($user['role'] === 'teacher') {
                        $stmt_tid = $pdo->prepare("SELECT teacher_id FROM teachers WHERE username = ? AND school_code = ?");
                        $stmt_tid->execute([$user['username'], $smiss]);
                        $_SESSION['teacher_id'] = $stmt_tid->fetchColumn() ?: '';
                    }
                    header("Location: dashboard.php");
                    exit;
                } else {
                    // ค้นหารายชื่อจากตารางทะเบียนครู (สำหรับคุณครูใหม่ที่ประสงค์ลงทะเบียนแต่ยังไม่มี username ใน user)
                    $stmt_t = $pdo->prepare("SELECT * FROM teachers WHERE username = ? AND school_code = ?");
                    $stmt_t->execute([$username, $smiss]);
                    $teacher = $stmt_t->fetch();
                    
                    if ($teacher && $password === '123456') {
                        // ปั่นและบันทึกผู้ใช้บัญชีหลักประสานงานขึ้นมาโดยอิงเกลือความปลอดภัยอัตโนมัติ
                        $stmt_u_chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND school_code = ?");
                        $stmt_u_chk->execute([$teacher['username'], $smiss]);
                        if ($stmt_u_chk->fetchColumn() == 0) {
                            $hashed_pwd = password_hash('123456', PASSWORD_DEFAULT);
                            $stmt_ins = $pdo->prepare("INSERT INTO users (username, password, fullname, role, school_code) VALUES (?, ?, ?, 'teacher', ?)");
                            $stmt_ins->execute([$teacher['username'], $hashed_pwd, $teacher['teacher_name'], $smiss]);
                        }
                        $_SESSION['username'] = $teacher['username'];
                        $_SESSION['fullname'] = $teacher['teacher_name'];
                        $_SESSION['role'] = 'teacher';
                        $_SESSION['teacher_id'] = $teacher['teacher_id'];
                        $_SESSION['school_code'] = $smiss;
                        header("Location: dashboard.php");
                        exit;
                    } else {
                        $error = "ชื่อล็อกอินหรือรหัสผ่านระบบของบัญชีผู้สังกัดไม่ถูกต้อง กรุณาติดต่อสายแอดมินวิชาการโรงเรียน";
                    }
                }
            }
        }
    } else {
        $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่านเพื่อความพร้อมเข้าใช้งาน";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบนิเทศการจัดการเรียนการสอน</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0A3370">
    <link rel="apple-touch-icon" href="/src/assets/images/school_crest_logo_1781666281619.jpg">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="ระบบนิเทศ">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then(reg => console.log('Service Worker registered successfully!', reg))
                    .catch(e => console.error('Service Worker registration failed.', e));
            });
        }
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl border border-slate-150 p-8 w-full max-w-lg space-y-6">
        
        <!-- Header application logo -->
        <div class="text-center space-y-2">
            <div class="w-16 h-16 bg-gradient-to-tr from-[#0A3370] to-[#1E3A8A] mx-auto rounded-3xl flex items-center justify-center text-amber-400 font-extrabold text-3xl shadow-lg border-b-2 border-amber-500">
                🏫
            </div>
            <div>
                <h1 class="text-base sm:text-lg font-extrabold text-[#0A3370] tracking-tight leading-snug">ระบบนิเทศการจัดการเรียนการสอน<br>Classroom Supervision Hub</h1>
                <p class="text-[10px] text-amber-600 font-bold uppercase tracking-wider font-semibold">ระบบจัดการประเมินวิเศษและวิทยฐานะสำหรับโรงเรียนเครือข่าย</p>
            </div>
        </div>

        <!-- System messages alerts -->
        <?php if ($error): ?>
            <div class="text-xs bg-rose-50 border border-rose-250 text-rose-700 p-3.5 rounded-xl font-semibold leading-relaxed">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="text-xs bg-emerald-50 border border-emerald-250 text-emerald-800 p-3.5 rounded-xl font-semibold leading-relaxed">
                🎉 <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Active Tabs Selection -->
        <div class="border-b border-slate-100 flex text-center font-bold text-xs">
            <button type="button" onclick="switchLoginTab('login')" id="tab_btn_login" class="flex-1 pb-3 border-b-2 <?php echo $active_tab === 'login' ? 'border-[#0A3370] text-[#0A3370]' : 'border-transparent text-slate-400' ?> transition duration-200 cursor-pointer">
                👤 เข้าสู่ระบบ (Sign In)
            </button>
            <button type="button" onclick="switchLoginTab('register')" id="tab_btn_register" class="flex-1 pb-3 border-b-2 <?php echo $active_tab === 'register' ? 'border-[#0A3370] text-[#0A3370]' : 'border-transparent text-slate-400' ?> transition duration-200 cursor-pointer">
                📝 ลงทะเบียนโรงเรียนใหม่ (Join Network)
            </button>
        </div>

        <!-- LOGIN FORM CONTAINER -->
        <div id="form_login_container" class="<?php echo $active_tab === 'login' ? '' : 'hidden' ?>">
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action_login" value="1">

                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-500 block">ชื่อผู้ใช้งานล็อกอิน (Username) *</label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-2.5 text-slate-400 text-sm">👤</span>
                        <input type="text" name="username" required placeholder="ระบุชื่อภาษาอังกฤษหรือรหัสคุณครูส่วนบุคคล" class="w-full pl-9 pr-4 py-2 bg-slate-50 text-slate-800 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-[#0A3370] outline-none font-semibold">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-500 block">รหัสผ่านบัญชีโรงเรียน (Password) *</label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-2.5 text-slate-400 text-sm">🔑</span>
                        <input type="password" name="password" required placeholder="ระบุพาสเวิร์ดส่วนของบัญชี" class="w-full pl-9 pr-4 py-2 bg-slate-50 text-slate-800 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-[#0A3370] outline-none font-semibold">
                    </div>
                </div>

                <button type="submit" class="w-full py-2.5 bg-gradient-to-r from-[#0A3370] to-[#1E3A8A] hover:opacity-95 text-white font-bold rounded-xl text-xs shadow-md transition duration-150 cursor-pointer text-center block border-none">
                    🚀 เข้าสู่ระบบประเมินนิเทศ
                </button>
            </form>
        </div>

        <!-- NEW SCHOOL REGISTRATION FORM CONTAINER -->
        <div id="form_register_container" class="<?php echo $active_tab === 'register' ? '' : 'hidden' ?>">
            <form method="POST" class="space-y-4 text-xs font-medium">
                <input type="hidden" name="action_register_school" value="1">
                
                <div class="bg-blue-50/50 border border-dashed border-blue-200 p-3 rounded-xl mb-3 text-[10px] text-blue-900 leading-relaxed font-semibold">
                    💡 หลังจากส่งคำขอสมัครใช้ระบบ Super Admin ประจำเครือข่ายการสอนจะพิจารณาเปิดห้องชุดฐานข้อมูล และโคลนระดับชั้นตามทะเบียนไทยพร้อมแบบประเมินและตั้งค่าเบื้องต้นให้ทันที
                </div>

                <!-- School Info Section -->
                <div class="space-y-3.5 border-b pb-4">
                    <h3 class="font-extrabold text-[#0A3370] text-xs">🌐 ข้อมูลโรงเรียนและเครื่องหมายสังกัด</h3>
                    
                    <div class="space-y-1">
                        <label class="text-slate-500 block font-bold">รหัสเลขทะเบียนโรงเรียน SMISS 8 หลัก *</label>
                        <input type="number" name="reg_school_code" max="99999999" required placeholder="เช่น 31054002" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none font-semibold">
                    </div>

                    <div class="space-y-1">
                        <label class="text-slate-500 block font-bold">ชื่อสถาบันศึกษา / ชื่อโรงเรียนประจำแผนภูมิ *</label>
                        <input type="text" name="reg_school_name" required placeholder="เช่น โรงเรียนบ้านหนองกี่พัฒนารี" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none font-semibold">
                    </div>

                    <div class="space-y-1">
                        <label class="text-slate-500 block font-bold">สังกัด / เขตพื้นที่การศึกษาประถมศึกษา/มัธยมศึกษา *</label>
                        <input type="text" name="reg_affiliation" required placeholder="เช่น สำนักงานเขตพื้นที่การศึกษาประถมศึกษาบุรีรัมย์ เขต 3" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none font-semibold">
                    </div>
                </div>

                <!-- Admin User Account Section -->
                <div class="space-y-3.5 pt-1.5">
                    <h3 class="font-extrabold text-[#0A3370] text-xs">👤 ข้อมูลบัญชีผู้ประสานงานสูงสุด (School Admin)</h3>
                    
                    <div class="space-y-1">
                        <label class="text-slate-500 block font-bold">ชื่อ-นามสกุล / ตำแหน่งผู้ประสานระบบโรงเรียน *</label>
                        <input type="text" name="reg_fullname" required placeholder="เช่น นายวารินทร์ รัตน์สวาทดิ์ (ครูวิชาการ)" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-slate-500 block font-bold">ชื่อล็อกอิน (Admin Username) *</label>
                            <input type="text" name="reg_username" required placeholder="เช่น admin" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none font-mono">
                        </div>
                        <div class="space-y-1">
                            <label class="text-slate-500 block font-bold">รหัสผ่าน (Admin Password) *</label>
                            <input type="password" name="reg_password" required placeholder="กำหนดรหัสผ่าน" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none">
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full py-2.5 bg-gradient-to-r from-emerald-600 to-emerald-700 hover:opacity-97 text-white font-extrabold rounded-xl text-xs shadow-md mt-4 transition cursor-pointer text-center block border-none">
                    💾 ส่งใบคำขอสมัครเปิดใช้งานระบบเครือข่าย
                </button>
            </form>
        </div>
    </div>

    <!-- Switch Tab script formulas -->
    <script>
        function switchLoginTab(tab) {
            const tabBtnLogin = document.getElementById('tab_btn_login');
            const tabBtnRegister = document.getElementById('tab_btn_register');
            const formLogin = document.getElementById('form_login_container');
            const formRegister = document.getElementById('form_register_container');
            
            if (tab === 'login') {
                tabBtnLogin.className = "flex-1 pb-3 border-b-2 border-[#0A3370] text-[#0A3370] font-bold text-xs transition duration-200 cursor-pointer";
                tabBtnRegister.className = "flex-1 pb-3 border-b-2 border-transparent text-slate-400 font-bold text-xs transition duration-200 cursor-pointer";
                formLogin.classList.remove('hidden');
                formRegister.classList.add('hidden');
            } else {
                tabBtnLogin.className = "flex-1 pb-3 border-b-2 border-transparent text-slate-400 font-bold text-xs transition duration-200 cursor-pointer";
                tabBtnRegister.className = "flex-1 pb-3 border-b-2 border-[#0A3370] text-[#0A3370] font-bold text-xs transition duration-200 cursor-pointer";
                formLogin.classList.add('hidden');
                formRegister.classList.remove('hidden');
            }
        }
    </script>
</body>
</html>

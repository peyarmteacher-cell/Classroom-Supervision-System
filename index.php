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
    $reg_position = trim($_POST['reg_position'] ?? '');
    $reg_citizen_id = trim($_POST['reg_citizen_id'] ?? '');
    $reg_password = '123456'; // บังคับรหัสผ่านครั้งแรกเป็น 1-6

    if (strlen($reg_school_code) !== 8 || !is_numeric($reg_school_code)) {
        $error = "รหัสโรงเรียน SMISS จะต้องเป็นตัวเลข 8 หลักเท่านั้น โปรดตรวจสอบความถูกต้อง";
    } else if (strlen($reg_citizen_id) !== 13 || !is_numeric($reg_citizen_id)) {
        $error = "หมายเลขประจำตัวประชาชนของผู้ดูแลระบบจะต้องเป็นตัวเลข 13 หลักเท่านั้น";
    } else if (empty($reg_school_name) || empty($reg_affiliation) || empty($reg_fullname) || empty($reg_position)) {
        $error = "กรุณากรอกข้อมูลระดับโรงเรียน และข้อมูลผู้ดูแลระบบให้ครบถ้วนทุกช่องหลัก";
    } else {
        // เช็คการลงทะเบียนซ้ำ
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM schools WHERE school_code = ?");
        $stmt_check->execute([$reg_school_code]);
        if ($stmt_check->fetchColumn() > 0) {
            $error = "ขออภัยครับ! รหัส SMISS โรงเรียน '{$reg_school_code}' ได้รับการลงทะเบียนใช้งานก่อนหน้านี้ในระบบแล้ว";
        } else {
            // เช็คชื่อแอดมินซ้ำซ้อน (เช็คจากเลขประจำตัวประชาชน)
            $stmt_u_chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt_u_chk->execute([$reg_citizen_id]);
            if ($stmt_u_chk->fetchColumn() > 0) {
                $error = "ขออภัย! หมายเลขประจำตัวประชาชน '{$reg_citizen_id}' นี้ มีในฐานข้อมูลระบบอยู่แล้ว";
            } else {
                $pdo->beginTransaction();
                try {
                    // 1. เพิ่มเข้าตารางโรงเรียนในสถานะ pending รออนุมัติ
                    $stmt_ins_s = $pdo->prepare("INSERT INTO schools (school_code, school_name, affiliation, status) VALUES (?, ?, ?, 'pending')");
                    $stmt_ins_s->execute([$reg_school_code, $reg_school_name, $reg_affiliation]);
                    
                    // 2. สร้างบัญชี Admin ประจำโรงเรียนนี้ในตาราง users (username = เลขประจำตัวประชาชน 13 หลัก)
                    $hashed_pwd = password_hash($reg_password, PASSWORD_DEFAULT);
                    $fullname_with_pos = $reg_fullname . ' (' . $reg_position . ')';
                    $stmt_ins_u = $pdo->prepare("INSERT INTO users (username, password, fullname, role, school_code) VALUES (?, ?, ?, 'admin', ?)");
                    $stmt_ins_u->execute([$reg_citizen_id, $hashed_pwd, $fullname_with_pos, $reg_school_code]);
                    
                    $pdo->commit();
                    $success = "สมัครเข้าใช้งานเรียบร้อยแล้ว! ไอดีล็อกอินคือหมายเลขประจำตัวประชาชน 13 หลัก และรหัสผ่านตั้งต้นใช้ครั้งแรกคือ 123456 กรุณารอผู้ดูแลระบบอนุมัติใช้งานครับ";
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
                $error = "สมัครเข้าใช้งานเรียบร้อยแล้ว กรุณารอการอนุมัติจากผู้ดูแลระบบก่อนนะครับ";
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
        body { 
            font-family: 'Sarabun', 'Inter', sans-serif;
            background-color: #F5F7FA;
        }
        .glass-panel {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        @keyframes progress-slide {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(300%); }
        }
        .animate-progress-slide {
            animation: progress-slide 1.5s infinite linear;
        }
        @keyframes bounce-slow {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        .animate-bounce-slow {
            animation: bounce-slow 3s infinite ease-in-out;
        }
    </style>
</head>
<body class="bg-gradient-to-tr from-[#EEEFF4] to-[#F5F7FA] min-h-screen flex items-center justify-center p-4">

    <!-- Splash Screen Opener -->
    <div id="app_splash" class="fixed inset-0 bg-gradient-to-tr from-[#1565C0] to-[#0D47A1] z-50 flex flex-col items-center justify-center text-white transition-all duration-700 ease-out">
        <div class="text-center space-y-4 animate-bounce-slow">
            <div class="relative w-28 h-28 bg-white/10 backdrop-blur-md p-1.5 rounded-[32px] shadow-2xl mx-auto flex items-center justify-center border border-white/20 animate-pulse">
                <img src="<?php echo get_system_logo_url(); ?>" alt="School Logo" class="w-full h-full object-cover rounded-[24px]" referrerPolicy="no-referrer">
            </div>
            <div class="space-y-1.5 px-6">
                <h2 class="text-2xl font-extrabold tracking-tight">ระบบนิเทศชั้นเรียน</h2>
                <p class="text-[10px] text-blue-200 uppercase tracking-widest font-semibold font-mono">Classroom Supervision PWA</p>
            </div>
            <div class="w-40 h-1 bg-white/20 rounded-full mx-auto overflow-hidden relative mt-8">
                <div class="absolute inset-y-0 left-0 bg-[#FFC107] w-1/3 rounded-full animate-progress-slide"></div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const splash = document.getElementById('app_splash');
            if (splash) {
                setTimeout(() => {
                    splash.classList.add('opacity-0', 'pointer-events-none');
                    setTimeout(() => splash.remove(), 700);
                }, 1800);
            }
        });
    </script>

    <div class="glass-panel rounded-[24px] shadow-2xl p-6 sm:p-8 w-full max-w-lg space-y-6 transition-all duration-300">
        
        <!-- Header application logo -->
        <div class="text-center space-y-3">
            <div class="w-20 h-20 bg-gradient-to-tr from-[#1565C0] to-[#0D47A1] mx-auto rounded-[24px] flex items-center justify-center text-white font-extrabold text-3xl shadow-lg border-b-4 border-[#FFC107]">
                <img src="<?php echo get_system_logo_url(); ?>" class="w-full h-full object-cover rounded-[20px]" alt="School Crest" referrerPolicy="no-referrer">
            </div>
            <div>
                <h1 class="text-lg sm:text-xl font-extrabold text-[#1565C0] tracking-tight leading-snug">ระบบนิเทศชั้นเรียนออนไลน์</h1>
                <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider mt-0.5">Classroom Supervision Hub</p>
            </div>
        </div>

        <!-- System messages alerts -->
        <?php if ($error): ?>
            <div class="text-xs bg-rose-50 border border-rose-200 text-rose-700 p-4 rounded-2xl font-bold leading-relaxed shadow-sm">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="text-xs bg-emerald-50 border border-emerald-200 text-emerald-850 p-4 rounded-2xl font-bold leading-relaxed shadow-sm">
                🎉 <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Active Tabs Selection -->
        <div class="bg-slate-100/80 p-1 rounded-xl flex text-center font-bold text-xs">
            <button type="button" onclick="switchLoginTab('login')" id="tab_btn_login" class="flex-1 py-2.5 rounded-lg <?php echo $active_tab === 'login' ? 'bg-[#1565C0] text-white shadow' : 'text-slate-500 hover:text-slate-800' ?> transition-all duration-200 cursor-pointer">
                👤 เข้าสู่ระบบ
            </button>
            <button type="button" onclick="switchLoginTab('register')" id="tab_btn_register" class="flex-1 py-2.5 rounded-lg <?php echo $active_tab === 'register' ? 'bg-[#1565C0] text-white shadow' : 'text-slate-500 hover:text-slate-800' ?> transition-all duration-200 cursor-pointer">
                📝 ลงทะเบียนใหม่
            </button>
        </div>

        <!-- LOGIN FORM CONTAINER -->
        <div id="form_login_container" class="<?php echo $active_tab === 'login' ? '' : 'hidden' ?>">
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action_login" value="1">

                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-500 block">ชื่อผู้ใช้งานล็อกอิน (Username) *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-3 text-slate-400 text-base">👤</span>
                        <input type="text" name="username" required placeholder="ชื่อภาษาอังกฤษ รหัสครู หรือเลขบัตรประชาชน 13 หลัก" class="w-full pl-10 pr-4 py-3 bg-white text-slate-800 border border-slate-200 rounded-2xl text-xs focus:border-[#1565C0] focus:ring-4 focus:ring-blue-100 outline-none font-semibold transition-all duration-200 shadow-sm">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-500 block">รหัสผ่านบัญชีโรงเรียน (Password) *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-3 text-slate-400 text-base">🔑</span>
                        <input type="password" name="password" required placeholder="ระบุพาสเวิร์ดส่วนของบัญชี" class="w-full pl-10 pr-4 py-3 bg-white text-slate-800 border border-slate-200 rounded-2xl text-xs focus:border-[#1565C0] focus:ring-4 focus:ring-blue-100 outline-none font-semibold transition-all duration-200 shadow-sm">
                    </div>
                </div>

                <div class="bg-amber-50/70 border border-amber-100 p-2.5 rounded-xl text-[10.5px] text-amber-805 leading-relaxed font-semibold">
                    💡 <strong>โรงเรียนสมัครใหม่:</strong> บัญชีผู้ดูแลระบบ (School Admin) ที่ผ่านการอนุมัติแล้ว ให้เข้าใช้ด้วย <strong>หมายเลขประจำตัวประชาชน 13 หลัก</strong> ที่ลงทะเบียนไว้ และรหัสผ่านตั้งต้นครั้งแรกคือ <strong>123456</strong> ครับ
                </div>

                <button type="submit" class="w-full py-3 bg-gradient-to-r from-[#1565C0] to-[#0D47A1] hover:opacity-95 text-white font-bold rounded-2xl text-xs shadow-md shadow-blue-200 transition-all active:scale-[0.98] duration-150 cursor-pointer text-center block border-none">
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
                    <h3 class="font-extrabold text-[#0A3370] text-xs">👤 ข้อมูลผู้ดูแลระบบระดับโรงเรียน (School Admin)</h3>
                    
                    <div class="space-y-1">
                        <label class="text-slate-500 block font-bold">ชื่อ-นามสกุล ของครูที่จะดูแลระบบ *</label>
                        <input type="text" name="reg_fullname" required placeholder="เช่น นายสมชาย ใจดี" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none font-semibold">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-slate-500 block font-bold">ตำแหน่งของครูผู้ดูแลระบบประจำโรงเรียน *</label>
                            <input type="text" name="reg_position" required placeholder="เช่น ครูวิชาการ, รักษาการผู้อำนวยการ" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none font-semibold">
                        </div>
                        <div class="space-y-1">
                            <label class="text-slate-500 block font-bold">หมายเลขประจำตัวประชาชน 13 หลัก * <span class="text-emerald-600 font-extrabold">(ใช้เป็น Username ล็อกอิน)</span></label>
                            <input type="text" name="reg_citizen_id" required maxlength="13" placeholder="ระบุเลขบัตรประชาชน 13 หลัก" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none font-mono font-bold text-slate-800 tracking-wider">
                        </div>
                    </div>

                    <div class="bg-amber-50/70 border border-amber-150 p-3 rounded-lg text-[10px] text-amber-800 leading-normal font-semibold">
                        🔑 <strong>เงื่อนไขความเป็นส่วนตัวและความปลอดภัย:</strong> การสมัครใช้ระบบนี้จะใช้ <strong>หมายเลขประจำตัวประชาชน 13 หลัก เป็นชื่อผู้ใช้งาน (Username) ป้องกันการสับสนและชนกันของบัญชี</strong> และระบบจะทำการกำหนด <strong>รหัสผ่านสำหรับเข้าใช้งานครั้งแรกเป็น "123456"</strong> ซึ่งหลังจากที่ได้รับการอนุมัติเปิดใช้านระบบโรงเรียนเรียบร้อยแล้ว คุณครูสามารถเข้าไปปรับเปลี่ยนหรือแก้ไขรหัสผ่านใหม่ได้ทันทีผ่านหน้าเมนูตั้งค่าโปรไฟล์ส่วนตัว
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

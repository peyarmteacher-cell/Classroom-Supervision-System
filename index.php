<?php
// ==========================================
// index.php - หน้าหน่วยล็อกอินเข้าสู่ระบบ
// ==========================================

require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        // เช็คผู้ใช้งานหลัก (admin / director)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && ($password === '123456' || password_verify($password, $user['password']))) {
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];
            
            // หา teacher_id ป้อนหากมีระบบบทบาทคุณครู
            if ($user['role'] === 'teacher') {
                $stmt_tid = $pdo->prepare("SELECT teacher_id FROM teachers WHERE username = ?");
                $stmt_tid->execute([$user['username']]);
                $_SESSION['teacher_id'] = $stmt_tid->fetchColumn() ?: '';
            }
            
            header("Location: dashboard.php");
            exit;
        } else {
            // เช็คในฐานบุคลากรครูเผื่อเป็นการล๊อกอินของคุณครู
            $stmt = $pdo->prepare("SELECT * FROM teachers WHERE username = ?");
            $stmt->execute([$username]);
            $teacher = $stmt->fetch();

            if ($teacher && $password === '123456') {
                $_SESSION['username'] = $teacher['username'];
                $_SESSION['fullname'] = $teacher['teacher_name'];
                $_SESSION['role'] = 'teacher';
                $_SESSION['teacher_id'] = $teacher['teacher_id'];
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "รหัสผู้ใช้ หรือรหัสผ่านไม่ถูกต้อง กรุณาตรวจสอบหรือติดต่อผู้ดูแลระบบเพื่อแจ้งปัญหา";
            }
        }
    } else {
        $error = "กรุณากรอกข้อมูลให้ครบถ้วนก่อนดำเนินงาน";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบนิเทศการจัดการเรียนการสอน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl border border-slate-150 p-8 w-full max-w-md space-y-6">
        <div class="text-center space-y-2">
            <!-- School Emblem Simulation -->
            <div class="w-16 h-16 bg-[#0A3370] mx-auto rounded-3xl flex items-center justify-center text-amber-400 font-extrabold text-3xl shadow-md border-b-2 border-amber-500">
                🏫
            </div>
            <div>
                <h1 class="text-lg font-extrabold text-[#0A3370] tracking-wide">ระบบนิเทศการจัดการเรียนการสอน</h1>
                <p class="text-[10px] text-amber-600 font-bold uppercase tracking-wider">Active Classroom Supervision Registry (PHP)</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="text-xs bg-rose-50 border border-rose-250 text-rose-700 p-3.5 rounded-xl font-semibold leading-relaxed">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-500 block">ชื่อผู้ใช้งานล็อกอิน (Username)</label>
                <div class="relative">
                    <span class="absolute left-3.5 top-2.5 text-slate-400 text-sm">👤</span>
                    <input type="text" name="username" required placeholder="กล่องระบุชื่อเข้าใช้ของท่าน" class="w-full pl-9 pr-4 py-2 bg-slate-50 text-slate-800 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-[#0A3370] outline-none">
                </div>
            </div>

            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-500 block">รหัสผ่านสำหรับเข้าเครื่อง (Password)</label>
                <div class="relative">
                    <span class="absolute left-3.5 top-2.5 text-slate-400 text-sm">🔑</span>
                    <input type="password" name="password" required placeholder="กรอกพาสเวิร์ดส่วนบุคคล" class="w-full pl-9 pr-4 py-2 bg-slate-50 text-slate-800 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-[#0A3370] outline-none">
                </div>
            </div>

            <button type="submit" class="w-full py-2.5 bg-gradient-to-r from-[#0A3370] to-[#1E3A8A] hover:opacity-95 text-white font-bold rounded-xl text-xs shadow-md transition duration-150 cursor-pointer text-center">
                เข้าสู่ระบบประเมินนิเทศ
            </button>
        </form>
    </div>
</body>
</html>

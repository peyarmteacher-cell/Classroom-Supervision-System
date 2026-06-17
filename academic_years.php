<?php
// ==========================================
// academic_years.php - สารบบปีการศึกษาและภาคเรียน
// ==========================================

require_once 'config.php';

// ตรวจสอบความปลอดภัยบทบาทแอดมินหรือผู้อำนวยการ
if (!isset($_SESSION['username']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'director')) {
    header("Location: dashboard.php");
    exit;
}

$success_msg = '';
$error_msg = '';

// ลบเทอมปีการศึกษาออกจากทำเนียบสถิติ
if (isset($_GET['delete_id'])) {
    $del_id = $_GET['delete_id'];
    
    // ลบประเด็นที่โยงแบบ Cascade หรือป้องกันข้อซักถาม
    $stmt = $pdo->prepare("DELETE FROM academic_years WHERE year_id = ?");
    $stmt->execute([$del_id]);

    $success_msg = 'ลบปีการศึกษาและภาคเรียนออกจากสารบบทะเบียนเรียบร้อยแล้ว';
    header("Location: academic_years.php?success_msg=" . urlencode($success_msg));
    exit;
}

// แอดมินจดส่งเพิ่มใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_year'])) {
    $year = trim($_POST['year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');

    if (!empty($year) && !empty($semester)) {
        $new_year_id = "YR{$year}-{$semester}";

        // เช็คมิดิซ้ำความพยายามเดิม
        $chk = $pdo->prepare("SELECT COUNT(*) FROM academic_years WHERE year_id = ?");
        $chk->execute([$new_year_id]);
        if ($chk->fetchColumn() > 0) {
            $error_msg = "ปีการศึกษา {$year} ภาคเรียนที่ {$semester} ได้รับการพัสดุลงทะเบียนจัดเก็บไว้ก่อนหน้าเรียบร้อยแล้ว";
        } else {
            $stmt = $pdo->prepare("INSERT INTO academic_years (year_id, year, semester) VALUES (?, ?, ?)");
            $stmt->execute([$new_year_id, $year, $semester]);

            $success_msg = "เพิ่มภาคการเรียน ปีการศึกษา {$year} เทอม {$semester} สู่สมรรถนะความรู้สำเร็จ";
            header("Location: academic_years.php?success_msg=" . urlencode($success_msg));
            exit;
        }
    } else {
        $error_msg = 'กรุณากรอกข้อมูลปีและเลือกภาคเรียนให้ถูกต้อง';
    }
}

// ตรวจจับส่งคำสำเร็จจาก URL
if (isset($_GET['success_msg'])) {
    $success_msg = $_GET['success_msg'];
}

// โหลดข้อมูลเทอมทั้งหมด
$all_years = $pdo->query("SELECT * FROM academic_years ORDER BY year DESC, semester DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บริหารจัดการปีการศึกษา - ระบบนิเทศการสอน</title>
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
                        ระบบนิเทศการจัดการเรียนการสอนโรงเรียนบ้านหนองหว้า อำเภอหนองกี่ จังหวัดบุรีรัมย์
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

        <!-- Shortcuts Ribbon -->
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
            <a href="teachers.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                👥 ทะเบียนครูผู้สอน
            </a>
            <a href="academic_years.php" class="px-4 py-2 bg-[#0A3370] text-white rounded-xl shadow-xs font-bold flex items-center gap-1.5">
                📅 ปีการศึกษา
            </a>
            <a href="classrooms.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                🚪 ระดับชั้นเรียน
            </a>
            <a href="profile.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
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

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Side Form: Create Academic Term -->
            <div class="lg:col-span-4 bg-white border border-slate-200 p-5 rounded-2xl shadow-sm space-y-4">
                <div class="border-b pb-2">
                    <h3 class="font-extrabold text-[#0A3370] text-sm">เพิ่มปีการศึกษาและภาคเรียนใหม่</h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">ระบุปี พ.ศ. ของภาคการศึกษาเพื่อเริ่มเป็นปีการศึกษาจัดเก็บ</p>
                </div>

                <form method="POST" class="space-y-4 text-xs font-medium">
                    <input type="hidden" name="action_add_year" value="1">

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">ปีการศึกษา (พ.ศ.) *</label>
                        <input type="number" name="year" required min="2500" max="2700" value="<?php echo date('Y') + 543; ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold outline-none focus:ring-1 focus:ring-blue-900">
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">ภาคเรียน *</label>
                        <select name="semester" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs cursor-pointer outline-none">
                            <option value="1">ภาคเรียนที่ 1</option>
                            <option value="2">ภาคเรียนที่ 2</option>
                        </select>
                    </div>

                    <button type="submit" class="w-full py-2 bg-[#0A3370] hover:bg-[#07244F] text-white font-bold rounded-xl text-xs shadow cursor-pointer text-center">
                        เพิ่มปีการศึกษา
                    </button>
                </form>

                <div class="bg-blue-50/50 border border-dashed border-blue-200 text-blue-900 p-3 rounded-lg text-[10.5px] leading-relaxed space-y-1 font-medium">
                    <span class="font-bold text-blue-800 block">⚠️ ข้อพึงระมัดระวังเป็นกรณีวิเศษ:</span>
                    <p>การลบภาคการศึกษา/ปีการศึกษาใดๆ จะทำให้ข้อมูลคุณสมบัตินิเทศการสอนทั้งหมดในเทอมศึกษานั้นถูกกวาดล้างออกไปทั้งหมดจาก MySQL เพื่อรักษาความพึงสัมพันธ์ของโครงสร้างความถูกต้อง (Cascade Integrity)</p>
                </div>
            </div>

            <!-- List Grid View of Registered Academic Terms -->
            <div class="lg:col-span-8 bg-white border border-slate-200 p-5 rounded-2xl shadow-sm text-xs space-y-4">
                <div class="border-b pb-2 flex justify-between items-center">
                    <h3 class="font-extrabold text-[#0A3370] text-sm">แฟ้มทะเบียนชุดภาคการศึกษาทั้งหมดที่เปิดให้บริการ (<?php echo count($all_years); ?> รายการ)</h3>
                    <span class="text-[10px] text-slate-400">ระบบควบคุมโดยดาต้าเบสสัมพันธ์โรงเรียน</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 text-slate-650 font-extrabold text-[11px]">
                            <tr>
                                <th class="p-3">รหัสปีการศึกษา</th>
                                <th class="p-3">ภาคการศึกษา</th>
                                <th class="p-3">ภาคภาคเรียน</th>
                                <th class="p-3 text-center">จัดการคำสั่งลบ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php foreach ($all_years as $y): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-3 font-mono font-bold text-blue-900"><?php echo htmlspecialchars($y['year_id']); ?></td>
                                    <td class="p-3 font-bold text-slate-900">ปีการศึกษา พ.ศ. <?php echo htmlspecialchars($y['year']); ?></td>
                                    <td class="p-3 text-slate-600">ภาคเรียนที่ <?php echo htmlspecialchars($y['semester']); ?></td>
                                    <td class="p-3 text-center">
                                        <a href="academic_years.php?delete_id=<?php echo urlencode($y['year_id']); ?>" onclick="return confirm('ยืนยันประสงค์ในการลบปีการศึกษา <?php echo htmlspecialchars($y['year_id']); ?> ออกจากระบบ? การลบนี้จะทำลายประวัติใบประเมินนิเทศที่เชื่อมโยงกับค่านี้ทั้งหมด')" class="bg-rose-50 border border-rose-200 text-rose-600 font-bold py-1 px-2.5 rounded-md text-[10.5px] hover:bg-rose-100">
                                            ลบทิ้ง
                                        </a>
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

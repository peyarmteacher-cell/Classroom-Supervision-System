<?php
// ==========================================
// classrooms.php - สารบบจัดเตรียมระดับชั้นสำหรับการนิเทศอย่างมีมาตรฐาน
// ==========================================

require_once 'config.php';

// ตรวจสอบความปลอดภัยบทบาทแอดมินหรือผู้อำนวยการ
if (!isset($_SESSION['username']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'director')) {
    header("Location: dashboard.php");
    exit;
}

$success_msg = '';
$error_msg = '';

$edit_id = $_GET['edit_id'] ?? null;
$edit_name = '';

// โหลดข้อมูลหากอยู่ในโหมดแก้ไข
if ($edit_id) {
    $stmt_load = $pdo->prepare("SELECT * FROM classrooms WHERE class_id = ?");
    $stmt_load->execute([$edit_id]);
    $class_data = $stmt_load->fetch();
    if ($class_data) {
        $edit_name = $class_data['class_name'];
    }
}

// ลบระดับชั้นเรียนออก
if (isset($_GET['delete_id'])) {
    if ($_SESSION['role'] !== 'admin') {
        $error_msg = 'เฉพาะสิทธิ์แอดมิน (Admin) เท่านั้นที่สามารถลบข้อมูลระดับชั้นเรียนได้';
    } else {
        $del_id = $_GET['delete_id'];
        
        try {
            $stmt = $pdo->prepare("DELETE FROM classrooms WHERE class_id = ?");
            $stmt->execute([$del_id]);
            $success_msg = 'ลบระดับชั้นเรียนออกจากสารบบเลือกเรียบร้อยแล้ว';
            header("Location: classrooms.php?success_msg=" . urlencode($success_msg));
            exit;
        } catch (Exception $e) {
            $error_msg = 'ไม่สามารถลบได้เนื่องจากมีการอ้างอิงระดับชั้นเรียนนี้ในข้อมูลการประเมินวิธียฐานะสะสม';
        }
    }
}

// แอดมินจดส่งเพิ่มหรือแก้ไขใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_class'])) {
    if ($_SESSION['role'] !== 'admin') {
        $error_msg = 'เฉพาะสิทธิ์แอดมิน (Admin) เท่านั้นที่สามารถบันทึกหรือแก้ไขระดับชั้นเรียนได้';
    } else {
        $class_name = trim($_POST['class_name'] ?? '');

        if (!empty($class_name)) {
            try {
                if ($edit_id) {
                    // อัพเดตระดับชั้นเดิม
                    $stmt_update = $pdo->prepare("UPDATE classrooms SET class_name = ? WHERE class_id = ?");
                    $stmt_update->execute([$class_name, $edit_id]);
                    $success_msg = 'แก้ไขรายละเอียดระดับชั้นเรียนเรียบร้อยแล้ว';
                } else {
                    // เขียนเพิ่มระดับชั้นใหม่ลงดาต้าเบส
                    $stmt_insert = $pdo->prepare("INSERT INTO classrooms (class_name) VALUES (?)");
                    $stmt_insert->execute([$class_name]);
                    $success_msg = 'เพิ่มระดับชั้นเรียนมาตรฐานลงระบบสำเร็จเรียบร้อยแล้ว';
                }
                header("Location: classrooms.php?success_msg=" . urlencode($success_msg));
                exit;
            } catch (PDOException $pdo_ex) {
                if ($pdo_ex->getCode() == 23000) {
                    $error_msg = 'พบตัวเลือกระดับชั้นเรียนนี้ซ้ำซ้อนในระบบแล้ว';
                } else {
                    $error_msg = 'ผิดพลาดในการเขียนข้อมูล: ' . $pdo_ex->getMessage();
                }
            }
        } else {
            $error_msg = 'กรุณากรอกชื่อระดับชั้นเรียนให้ถูกต้องสมบูรณ์';
        }
    }
}

// ตรวจจับส่งคำสำเร็จจาก URL
if (isset($_GET['success_msg'])) {
    $success_msg = $_GET['success_msg'];
}

// โหลดระดับชั้นเรียนทั้งหมด
$all_classrooms = $pdo->query("SELECT * FROM classrooms ORDER BY class_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการระดับชั้นเรียน - ระบบนิเทศการสอน</title>
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
            <?php if ($_SESSION['role'] !== 'teacher'): ?>
                <a href="supervision.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                    ➕ บันทึกนิเทศคาบเรียนใหม่
                </a>
            <?php endif; ?>
            <a href="comparison.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                🔎 วิเคราะห์ครูรายบุคคล/เปรียบเทียบ
            </a>
            <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'director'): ?>
                <a href="teachers.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                    👥 ทะเบียนครูผู้สอน
                </a>
                <a href="academic_years.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                    📅 สารบบปีการศึกษา
                </a>
                <a href="classrooms.php" class="px-4 py-2 bg-[#0A3370] text-white rounded-xl shadow-xs font-bold flex items-center gap-1.5">
                    🚪 สารบบระดับชั้นเรียน
                </a>
            <?php endif; ?>
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

            <!-- Side Form: Create Classroom Level -->
            <div class="lg:col-span-4 bg-white border border-slate-200 p-5 rounded-2xl shadow-sm space-y-4">
                <div class="border-b pb-2">
                    <h3 class="font-extrabold text-[#0A3370] text-sm"><?php echo $edit_id ? 'แก้ไขระดับชั้นเรียน' : 'เพิ่มระดับชั้นเรียนใหม่'; ?></h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">ระบุชื่อระดับกลุ่มสาขาเรียน เช่น "ประถมศึกษาปีที่ 1/1" เพื่อความถูกต้องในรายงานนิเทศ</p>
                </div>

                <form method="POST" class="space-y-4 text-xs font-medium">
                    <input type="hidden" name="action_save_class" value="1">

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-500">ชื่อระดับชั้นเรียน (เช่น มัธยมศึกษาปีที่ 1/2) *</label>
                        <input type="text" name="class_name" required placeholder="มัธยมศึกษาปีที่ 1/1 หรือ ประถมศึกษาปีที่ 3/2" value="<?php echo htmlspecialchars($edit_name); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold outline-none focus:ring-1 focus:ring-blue-900 border-slate-300 shadow-inner">
                    </div>

                    <div class="pt-2 flex gap-2">
                        <button type="submit" class="w-full py-2.5 bg-[#0A3370] hover:bg-[#072450] text-white font-extrabold rounded-xl text-xs shadow-sm cursor-pointer transition text-center">
                            💾 บันทึกระดับชั้นเรียน
                        </button>
                        <?php if ($edit_id): ?>
                            <a href="classrooms.php" class="w-24 text-center py-2.5 bg-slate-150 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs">
                                ยกเลิก
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="text-[10px] text-amber-700 bg-amber-50/50 p-3 rounded-xl border border-amber-250 leading-relaxed space-y-1 font-semibold">
                    <span class="font-bold">💡 ประโยชน์ของระบบ Standard Levels:</span>
                    <p>การกำหนดระดับชั้นเรียนเฉพาะจะช่วยลดปัญหาการพิมพ์ชื่อชั้นซ้ำซ้อนกันหรือพิมผิดคลาดเคลื่อน (เช่น ม.1, ม.1/1, ม.1/2) ทำให้สามารถรวมสถิติคะแนนเฉลี่ยจำแนกตามชั้นเรียนร่วมกันได้อย่างถูกต้องไม่มีค่าคลาดเคลื่อน</p>
                </div>
            </div>

            <!-- Main List Table: All Registered Classrooms -->
            <div class="lg:col-span-8 bg-white border border-slate-200 p-5 rounded-2xl shadow-sm text-xs space-y-4">
                <div class="border-b pb-2 flex justify-between items-center">
                    <div>
                        <h3 class="font-extrabold text-[#0A3370] text-sm">สารบบระดับชั้นเรียนที่มีอยู่ในฐานข้อมูล</h3>
                        <p class="text-[10px] text-slate-400 mt-0.5">ตารางตัวเลือกและลำดับระดับชั้นในทำเนียบนิเทศ (ทั้งหมด <?php echo count($all_classrooms); ?> ชั้นเรียน)</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 text-slate-650 font-extrabold text-[11px]">
                            <tr>
                                <th class="p-3">ลำดับไอดีประจำระบบ</th>
                                <th class="p-3 font-semibold">ชื่อระดับชั้นเรียนมาตรฐาน</th>
                                <th class="p-3 text-center">ปฏิบัติการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php if (empty($all_classrooms)): ?>
                                <tr>
                                    <td colspan="3" class="p-10 text-center text-slate-400 font-bold">
                                        📭 ยังไม่มีระดับชั้นเรียนมาตรฐานในระบบ กรุณาป้อนขึ้นเพื่อเป็นแกนทางสถิติกระบอกเสียง
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $counter = 1; ?>
                                <?php foreach ($all_classrooms as $cls): ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="p-3 font-mono font-bold text-blue-900"><?php echo htmlspecialchars($cls['class_id']); ?></td>
                                        <td class="p-3 font-bold text-slate-900"><?php echo htmlspecialchars($cls['class_name']); ?></td>
                                        <td class="p-3 text-center">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <a href="classrooms.php?edit_id=<?php echo urlencode($cls['class_id']); ?>" class="p-1 px-3.5 bg-slate-50 hover:bg-amber-100 border border-slate-200 hover:border-amber-300 rounded text-[10px] font-bold text-slate-650 transition">
                                                    ✏️ แก้ไข
                                                </a>
                                                <a href="classrooms.php?delete_id=<?php echo urlencode($cls['class_id']); ?>" onclick="return confirm('ยืนยันประสงค์ในการลบระดับชั้นเรียนนี้หรือไม่?')" class="p-1 px-3.5 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 rounded text-[10px] font-bold transition">
                                                    🗑️ ลบ
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $counter++; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>

    <!-- Clean Footer -->
    <footer class="py-6 mt-12 border-t border-slate-200 bg-white text-center text-[11px] text-slate-400 select-none leading-relaxed">
        <p>ระบบเว็บแอพพลิเคชันเพื่อสุขภาวะและวิทยฐานะประกอบคุณครู สังกัดกระทรวงศึกษาธิการ ประเทศไทย</p>
        <p class="mt-1">พัฒนารหัสด้วยมาตรฐานสูงสุด <strong>PHP 8.2+</strong> & <strong>MySQL 8</strong> อัปเดตฐานข้อมูลไดนามิกอเนกประสงค์</p>
    </footer>

</body>
</html>

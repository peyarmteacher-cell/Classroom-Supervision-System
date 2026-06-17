<?php
// ==========================================
// dashboard.php - แผงสารสนเทศและแดชบอร์ดหลักของโรงเรียน
// ==========================================

require_once 'config.php';

// ตรวจสอบสิทธิ์ล็อกอิน
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$user_role = $_SESSION['role'] ?? 'teacher';
$user_fullname = $_SESSION['fullname'] ?? '';
$my_teacher_id = $_SESSION['teacher_id'] ?? '';

if ($user_role === 'teacher' && empty($my_teacher_id) && isset($_SESSION['username'])) {
    $stmt_tid = $pdo->prepare("SELECT teacher_id FROM teachers WHERE username = ?");
    $stmt_tid->execute([$_SESSION['username']]);
    $my_teacher_id = $stmt_tid->fetchColumn() ?: '';
    $_SESSION['teacher_id'] = $my_teacher_id;
}

// รับค่าฟิลเตอร์ต่างๆ
$filter_year = $_GET['filter_year'] ?? 'all';
$search_query = trim($_GET['search'] ?? '');

// ปฏิบัติการลบเรคอร์ดนิเทศ
if (isset($_GET['delete_record_id']) && ($user_role === 'admin' || $user_role === 'director')) {
    $delete_id = $_GET['delete_record_id'];
    $stmt = $pdo->prepare("DELETE FROM supervisions WHERE record_id = ?");
    $stmt->execute([$delete_id]);
    header("Location: dashboard.php");
    exit;
}

// ------------------------------------------
// โหลดสารบบทางสถิติมาเพื่อวาดบัตรประเมิน (Metrics Stats)
// ------------------------------------------
$total_teachers = $pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
$total_years = $pdo->query("SELECT COUNT(*) FROM academic_years")->fetchColumn();

// ค้นหาจำเพาะจำนวนการประเมิน
if ($user_role === 'teacher') {
    $total_records = $pdo->prepare("SELECT COUNT(*) FROM supervisions WHERE teacher_id = ?");
    $total_records->execute([$my_teacher_id]);
    $total_records = $total_records->fetchColumn();

    $total_submitted = $pdo->prepare("SELECT COUNT(*) FROM supervisions WHERE teacher_id = ? AND status = 'submitted'");
    $total_submitted->execute([$my_teacher_id]);
    $total_submitted = $total_submitted->fetchColumn();
} else {
    $total_records = $pdo->query("SELECT COUNT(*) FROM supervisions")->fetchColumn();
    $total_submitted = $pdo->query("SELECT COUNT(*) FROM supervisions WHERE status = 'submitted'")->fetchColumn();
}

// คำนวณร้อยอัตรารวมของคะแนนเฉลี่ยคุณครูสะสม
$score_average_pct = 0;
$records_for_avg_query = "SELECT scores_json FROM supervisions";
if ($user_role === 'teacher') {
    $records_for_avg = $pdo->prepare("SELECT scores_json FROM supervisions WHERE teacher_id = ? AND status = 'submitted'");
    $records_for_avg->execute([$my_teacher_id]);
    $records_for_avg = $records_for_avg->fetchAll(PDO::FETCH_COLUMN);
} else {
    $records_for_avg = $pdo->query("SELECT scores_json FROM supervisions WHERE status = 'submitted'")->fetchAll(PDO::FETCH_COLUMN);
}

if (!empty($records_for_avg)) {
    $total_points = 0;
    $total_items_count = 0;
    foreach ($records_for_avg as $scores_json) {
        $arr = json_decode($scores_json, true);
        if ($arr) {
            foreach ($arr as $val) {
                $total_points += (float)$val;
                $total_items_count++;
            }
        }
    }
    if ($total_items_count > 0) {
        $score_average_pct = round(($total_points / ($total_items_count * 5)) * 100, 1);
    }
}

// โหลดรายการปีการศึกษาเพื่อทำ Dropdown Filter
$years_list = $pdo->query("SELECT * FROM academic_years ORDER BY year DESC, semester DESC")->fetchAll();

// ------------------------------------------
// การจัดสืบค้นข้อมูลบันทึกนิเทศคาบเรียนพร้อมการทำ Filter
// ------------------------------------------
$params = [];
$query_parts = [];

if ($user_role === 'teacher') {
    $query_parts[] = "s.teacher_id = :my_teacher_id";
    $params[':my_teacher_id'] = $my_teacher_id;
}

if ($filter_year !== 'all') {
    $query_parts[] = "s.year_id = :filter_year";
    $params[':filter_year'] = $filter_year;
}

if ($search_query !== '') {
    $query_parts[] = "(t.teacher_name LIKE :search OR s.subject_name LIKE :search OR s.class_name LIKE :search OR s.evaluator_name LIKE :search)";
    $params[':search'] = "%{$search_query}%";
}

$where_clause = "";
if (!empty($query_parts)) {
    $where_clause = "WHERE " . implode(" AND ", $query_parts);
}

$records_query_str = "
    SELECT s.*, t.teacher_name, t.position, t.subject_group, y.year, y.semester
    FROM supervisions s
    JOIN teachers t ON s.teacher_id = t.teacher_id
    JOIN academic_years y ON s.year_id = y.year_id
    {$where_clause}
    ORDER BY s.date_string DESC
";

$stmt = $pdo->prepare($records_query_str);
$stmt->execute($params);
$supervision_records = $stmt->fetchAll();

// ป้อนระบบ Export รายงานข้อมูล CSV ทั้งหมด
if (isset($_GET['action_export_csv'])) {
    // Generate UTF-8 BOM CSV File
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="school_supervisions_excel_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    // เขียนเครื่องหมาย BOM เพื่อเปิดบน Excel ไม่ต่างดาว
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header
    fputcsv($output, [
        'รหัสบันทึก', 'ครูผู้รับนิเทศ', 'ตำแหน่ง', 'กลุ่มสาระการเรียนรู้', 
        'วิชาที่สอน', 'ระดับชั้นเรียน', 'วันที่ประเมิน', 'คะแนนสะสม (เต็ม 100)', 
        'คะแนนเฉลี่ยร้อยละ', 'ระดับประเมินคุณภาพ', 'ชื่อผู้นิเทศ', 'ตำแหน่งผู้นิเทศ', 'สถานะ'
    ]);
    
    foreach ($supervision_records as $rec) {
        $scores = json_decode($rec['scores_json'], true) ?: [];
        $sum = array_sum($scores);
        $pct = ($sum / 100) * 100;
        
        $grade = 'ปรับปรุง';
        if ($pct >= 90) $grade = 'ดีเยี่ยม';
        else if ($pct >= 80) $grade = 'ดีมาก';
        else if ($pct >= 70) $grade = 'ดี';
        else if ($pct >= 60) $grade = 'พอใช้';
        
        fputcsv($output, [
            $rec['record_id'],
            $rec['teacher_name'],
            $rec['position'],
            $rec['subject_group'],
            $rec['subject_name'],
            $rec['class_name'],
            $rec['date_string'],
            "{$sum}/100",
            "{$pct}%",
            $grade,
            $rec['evaluator_name'],
            $rec['evaluator_position'],
            $rec['status'] === 'submitted' ? 'ส่งแล้ว' : 'แบบร่าง'
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้ารวมแดชบอร์ด - ระบบนิเทศการจัดการเรียนการสอน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-[#FAF8F5] min-h-screen text-slate-900 transition-colors duration-200">

    <!-- Top Navigation Ribbon -->
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
                    <span class="font-bold text-amber-200 block text-[11px]"><?php echo htmlspecialchars($user_fullname); ?></span>
                    <span class="text-[9px] text-slate-300">สิทธิ์: <?php echo strtoupper($user_role); ?></span>
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
            <a href="dashboard.php" class="px-4 py-2 bg-[#0A3370] text-white rounded-xl shadow-xs font-bold flex items-center gap-1.5">
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
                <a href="teachers.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                    👥 ทะเบียนครูผู้สอน
                </a>
                <a href="academic_years.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                    📅 ปีการศึกษา
                </a>
                <a href="classrooms.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                    🚪 ระดับชั้นเรียน
                </a>
            <?php endif; ?>
            <a href="profile.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                ⚙️ ตั้งค่าบัญชีของฉัน
            </a>
        </div>

        <!-- 4 Column KPI Cards Block -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-50 text-blue-900 rounded-xl flex items-center justify-center text-xl shadow-xs font-bold">👥</div>
                <div>
                    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">กำลังพลครูทั้งหมด</div>
                    <div class="text-xl font-bold mt-0.5 text-slate-800"><?php echo $total_teachers; ?> ท่าน</div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 bg-purple-50 text-purple-605 rounded-xl flex items-center justify-center text-xl shadow-xs font-bold">📅</div>
                <div>
                    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">ปีการศึกษา</div>
                    <div class="text-xl font-bold mt-0.5 text-slate-800"><?php echo $total_years; ?> เทอมศึกษา</div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-xl flex items-center justify-center text-xl shadow-xs font-bold">📝</div>
                <div>
                    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">บันทึกนิเทศวิทยฐานะ</div>
                    <div class="text-xl font-bold mt-0.5 text-slate-800"><?php echo $total_records; ?> คาบวิชา <span class="text-xs text-emerald-650 font-semibold">(ส่ง <?php echo $total_submitted; ?>)</span></div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center text-xl shadow-xs font-bold">🎯</div>
                <div>
                    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">คะแนนมาตรฐานเฉลี่ยสะสม</div>
                    <div class="text-xl font-bold mt-0.5 text-slate-800"><?php echo $score_average_pct; ?>% <span class="text-[10px] text-slate-400">จากเกณฑ์หลัก</span></div>
                </div>
            </div>

        </div>

        <!-- Toolbar: Search and Filter Row -->
        <div class="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm flex flex-col md:flex-row gap-3 justify-between items-center text-xs">
            <form method="GET" class="w-full flex flex-col sm:flex-row gap-3">
                
                <!-- Search text box -->
                <div class="flex-1 relative">
                    <span class="absolute left-3 top-2.5 text-slate-405">🔍</span>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="พิมพ์ค้นหาชื่อครู, วิชา, คลาสชั้นเรียน หรือผู้นิเทศ..." class="w-full pl-8 pr-3 py-2 bg-slate-55 border border-slate-200 rounded-xl text-xs focus:ring-1 focus:ring-blue-900 outline-none text-slate-850">
                </div>

                <!-- Academic term select dropdown -->
                <div class="w-full sm:w-48">
                    <select name="filter_year" onchange="this.form.submit()" class="w-full px-3 py-2 bg-slate-55 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 cursor-pointer outline-none">
                        <option value="all">-- ทุกภาคเรียน --</option>
                        <?php foreach ($years_list as $yr): ?>
                            <option value="<?php echo htmlspecialchars($yr['year_id']); ?>" <?php if ($filter_year === $yr['year_id']) echo 'selected'; ?>>
                                ปีการศึกษา <?php echo htmlspecialchars($yr['year']); ?> / ภาคเรียน <?php echo htmlspecialchars($yr['semester']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-750 font-bold rounded-xl whitespace-nowrap cursor-pointer">
                        กรองข้อมูล
                    </button>
                    <?php if ($search_query !== '' || $filter_year !== 'all'): ?>
                        <a href="dashboard.php" class="px-3 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold rounded-xl whitespace-nowrap flex items-center justify-center">
                            ❌ ล้างฟิลเตอร์
                        </a>
                    <?php endif; ?>
                </div>

            </form>

            <div class="w-full md:w-auto">
                <a href="dashboard.php?action_export_csv=1&filter_year=<?php echo urlencode($filter_year); ?>&search=<?php echo urlencode($search_query); ?>" class="w-full md:w-auto px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl flex items-center justify-center gap-1.5 shadow-xs border-b-2 border-emerald-850 transition">
                    📥 ดาวน์โหลดรายงาน Excel (CSV UTF-8 BOM)
                </a>
            </div>
        </div>

        <!-- Database Update Notification Panel -->
        <div class="bg-[#EFF6FF] border border-blue-200 p-4 rounded-2xl flex flex-col sm:flex-row justify-between items-center gap-3 text-xs text-blue-905">
            <div class="flex items-center gap-2.5">
                <span class="text-xl">🛠️</span>
                <div>
                    <span class="font-extrabold block">ระบบตรวจสอบตารางฐานข้อมูลโรงเรียนอัตโนมัติ (Automated Schema Auditor)</span>
                    <span class="text-[11px] text-blue-700 block mt-0.5">ระบบจะสืบค้น ทดสอบข้อมูล ดำเนินงานแก้ไขโครงสร้างฟิลด์ และรัน Schema ตาราง MySQL ให้อยู่ในเวอร์ชันที่ทันสมัยที่สุดโดยอัตโนมัติในการรันโค้ด</span>
                </div>
            </div>
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="teachers.php" class="text-center w-full sm:w-auto px-3.5 py-1.5 bg-white border border-blue-200 rounded-lg font-bold text-blue-900 hover:bg-blue-50">👥 เพิ่มคุณครูใหม่</a>
            </div>
        </div>

        <!-- Main Records Table Block -->
        <div class="bg-white border border-slate-200 p-5 rounded-2xl shadow-sm text-xs space-y-4">
            <div class="flex justify-between items-center border-b pb-2">
                <h3 class="font-extrabold text-[#0A3370] text-sm">
                    ทะเบียนรายชื่อการประเมินคาบเรียนล่าสุด (จำนวนตามจริง <?php echo count($supervision_records); ?> คาบ)
                </h3>
                <span class="text-[11px] font-bold text-slate-400">เรียงตามวันที่เริ่มนิเทศล่าสุด</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 text-slate-600 font-extrabold text-[11px]">
                        <tr>
                            <th class="p-3">รหัสสารบบ</th>
                            <th class="p-3">คุณครูผู้รับนิเทศ</th>
                            <th class="p-3">กลุ่มสาระการเรียนรู้</th>
                            <th class="p-3">ระดับชั้น / วิชาวิชาการ</th>
                            <th class="p-3">ปีการศึกษา</th>
                            <th class="p-3 text-center">คะแนนรวม</th>
                            <th class="p-3 text-center">ระดับเกรด</th>
                            <th class="p-3 text-center">สถานะ</th>
                            <th class="p-3 text-center">จัดการเอกสาร</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($supervision_records)): ?>
                            <tr>
                                <td colspan="9" class="p-10 text-center text-slate-400 font-semibold space-y-1">
                                    <div class="text-3xl">📭</div>
                                    <p>ยังไม่มีข้อมูลเอกสารนิเทศจัดเก็บในระบบที่ตรงกับเงื่อนไขของท่านในสารบบโรงเรียนขณะนี้</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($supervision_records as $rec): 
                                $scores = json_decode($rec['scores_json'], true) ?: [];
                                $scoreSum = array_sum($scores);
                                $pct = ($scoreSum / 100) * 100;

                                $grade = 'ต้องปรับปรุง';
                                $gradeColor = 'text-rose-600 bg-rose-50';
                                if ($pct >= 90) { $grade = 'ดีเยี่ยม'; $gradeColor = 'text-emerald-700 bg-emerald-50'; }
                                else if ($pct >= 80) { $grade = 'ดีมาก'; $gradeColor = 'text-green-700 bg-green-50'; }
                                else if ($pct >= 70) { $grade = 'ดี'; $gradeColor = 'text-blue-700 bg-blue-50'; }
                                else if ($pct >= 60) { $grade = 'พอใช้'; $gradeColor = 'text-amber-700 bg-amber-50'; }
                            ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <!-- ID -->
                                    <td class="p-3 font-mono font-bold text-blue-900"><?php echo htmlspecialchars($rec['record_id']); ?></td>
                                    
                                    <!-- Teacher -->
                                    <td class="p-3">
                                        <div class="font-bold text-slate-900"><?php echo htmlspecialchars($rec['teacher_name']); ?></div>
                                        <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($rec['position']); ?></div>
                                    </td>

                                    <!-- Subject group -->
                                    <td class="p-3 text-slate-500 font-medium"><?php echo htmlspecialchars($rec['subject_group']); ?></td>

                                    <!-- Class / Subject name -->
                                    <td class="p-3">
                                        <div class="font-bold text-slate-800"><?php echo htmlspecialchars($rec['subject_name']); ?></div>
                                        <div class="text-[10px] text-slate-400 font-semibold">ระดับชั้น: <?php echo htmlspecialchars($rec['class_name']); ?></div>
                                    </td>

                                    <!-- Academic year -->
                                    <td class="p-3 font-semibold text-slate-650">ปี <?php echo htmlspecialchars($rec['year']); ?> เทอม <?php echo htmlspecialchars($rec['semester']); ?></td>

                                    <!-- Score -->
                                    <td class="p-3 text-center font-mono font-extrabold text-blue-950"><?php echo $scoreSum; ?> / 100</td>

                                    <!-- Grade of excellence -->
                                    <td class="p-3 text-center">
                                        <span class="inline-block px-2.5 py-1 rounded-full font-bold text-[10px] <?php echo $gradeColor; ?>">
                                            <?php echo $grade; ?>
                                        </span>
                                    </td>

                                    <!-- Status -->
                                    <td class="p-3 text-center">
                                        <?php if ($rec['status'] === 'submitted'): ?>
                                            <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded text-[9px] font-bold">ส่งแล้ว</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-amber-100 text-amber-800 rounded text-[9px] font-bold">ร่างเขียน</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Actions control -->
                                    <td class="p-3 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <a href="report.php?id=<?php echo urlencode($rec['record_id']); ?>" target="_blank" class="p-1 px-2.5 bg-blue-50 hover:bg-blue-100 text-blue-900 border border-blue-200 rounded font-bold text-[10px] shadow-xs flex items-center gap-1 transition" title="พิมพ์รายงานประเมินคาบสอน">
                                                📄 พิมพ์ A4/PDF
                                            </a>
                                            
                                            <?php if ($user_role !== 'teacher'): ?>
                                                <a href="supervision.php?edit_id=<?php echo urlencode($rec['record_id']); ?>" class="p-1 px-2 bg-slate-100 hover:bg-amber-100 hover:text-amber-805 text-slate-600 border border-slate-200 rounded font-semibold text-[10px]" title="แก้ไขผลนิเทศกระทรวง">
                                                    แก้ไข
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($user_role === 'admin' || $user_role === 'director'): ?>
                                                <a href="dashboard.php?delete_record_id=<?php echo urlencode($rec['record_id']); ?>" onclick="return confirm('ยืนยันประสงค์ในการลบสารระบบการนิเทศชุดนี้ถาวรหรือไม่? การกระทำนี้ไม่สามารถเรียกคืนค่าได้')" class="p-1 px-2 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 rounded font-semibold text-[10px]" title="ลบข้อมูลชิ้นเอกสาร">
                                                    ลบ
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
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

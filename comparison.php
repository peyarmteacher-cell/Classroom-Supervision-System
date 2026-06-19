<?php
// ==========================================
// comparison.php - รายงานเปรียบเทียบและการวิเคราะห์สมรรถนะครูรายบุคคล
// ==========================================

require_once 'config.php';

// ความปลอดภัยสิทธิ์บุคคลที่ประเมิน
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$user_role = $_SESSION['role'] ?? 'teacher';

$school_code = $_SESSION['school_code'] ?? '31054002';

// ดึงปีการศึกษาเพื่อทำตัวเลือกในการเปรียบเทียบ ของแต่ละโรงเรียนโดยตรง
$stmt_yl = $pdo->prepare("SELECT * FROM academic_years WHERE school_code = ? ORDER BY year DESC, semester DESC");
$stmt_yl->execute([$school_code]);
$years_list = $stmt_yl->fetchAll();
$filter_year = $_GET['filter_year'] ?? 'all';

// โฮสต์จัดประเภทเกณฑ์ 4 หมวดหลักเป็นโครงสร้างอาร์เรย์ (รวม 20 ข้อเต็ม)
$categories = [
    'cat1' => ['label' => '1. สภาพห้องเรียน', 'items' => [1, 2, 3, 4, 5], 'max' => 25],
    'cat2' => ['label' => '2. การบริหารจัดการห้องเรียน', 'items' => [6, 7, 8], 'max' => 15],
    'cat3' => ['label' => '3. ครูผู้สอน', 'items' => [9, 10, 11, 12, 13, 14, 15], 'max' => 35],
    'cat4' => ['label' => '4. นักเรียน', 'items' => [16, 17, 18, 19, 20], 'max' => 25]
];

// โหลดรายชื่อครูที่มีสิทธิ์แสดงผลในตาราง
if ($user_role === 'teacher') {
    $my_teacher_username = $_SESSION['username'] ?? '';
    $stmt_teachers = $pdo->prepare("SELECT * FROM teachers WHERE username = ? AND school_code = ?");
    $stmt_teachers->execute([$my_teacher_username, $school_code]);
    $teachers = $stmt_teachers->fetchAll();
} else {
    $stmt_teachers = $pdo->prepare("SELECT * FROM teachers WHERE school_code = ? ORDER BY teacher_id ASC");
    $stmt_teachers->execute([$school_code]);
    $teachers = $stmt_teachers->fetchAll();
}

// อ่างรายงานวิเคราะห์ครูแต่ละคนเพื่อป้อนลงโมเดลตารางเปรียบเทียบ
$comparison_data = [];

foreach ($teachers as $t) {
    // โหลดประวัติความคืบหน้านิเทศของคุณครูคนนี้โดยเฉพาะ
    $query_str = "SELECT scores_json FROM supervisions WHERE teacher_id = ? AND status = 'submitted'";
    $query_params = [$t['teacher_id']];
    
    if ($filter_year !== 'all') {
        $query_str .= " AND year_id = ?";
        $query_params[] = $filter_year;
    }

    $stmt = $pdo->prepare($query_str);
    $stmt->execute($query_params);
    $records_scores = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $total_done_sessions = count($records_scores);
    
    // ตั้งค่าเฉลี่ยเริ่มต้นของคะแนนแต่ละหมวดเป็น 0%
    $category_avgs = [
        'cat1' => 0, 'cat2' => 0, 'cat3' => 0, 'cat4' => 0, 'overall' => 0
    ];

    if ($total_done_sessions > 0) {
        $scores_sum_by_cat = [
            'cat1' => 0, 'cat2' => 0, 'cat3' => 0, 'cat4' => 0
        ];
        
        foreach ($records_scores as $scores_json) {
            $scores_arr = json_decode($scores_json, true) ?: [];
            
            // หาเรทคะแนนเฉลี่ยจำเพาะในแต่ละกล่องเกณฑ์ย่อย
            foreach ($categories as $cat_key => $cat_info) {
                $sub_sum = 0;
                foreach ($cat_info['items'] as $item_num) {
                    $sub_sum += (int)($scores_arr[$item_num] ?? 5);
                }
                // เฉลี่ยในหมวดนั้นๆ เต็มคะแนนของหมวด
                $scores_sum_by_cat[$cat_key] += ($sub_sum / $cat_info['max']) * 100;
            }
        }
        
        // เฉลี่ยตามจำนวนครั้งปฏิบัติประจบ
        $overall_sum = 0;
        foreach ($categories as $cat_key => $cat_info) {
            $category_avgs[$cat_key] = round($scores_sum_by_cat[$cat_key] / $total_done_sessions, 1);
            $overall_sum += $category_avgs[$cat_key];
        }
        $category_avgs['overall'] = round($overall_sum / 4, 1);
    }

    $comparison_data[] = [
        'teacher_id' => $t['teacher_id'],
        'teacher_name' => $t['teacher_name'],
        'position' => $t['position'],
        'subject_group' => $t['subject_group'],
        'sessions_count' => $total_done_sessions,
        'averages' => $category_avgs
    ];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานวิเคราะห์คุณภาพครูผู้สอน - ระบบนิเทศออนไลน์</title>
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

    <!-- Header Navbar -->
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

        <!-- Shortcuts Ribbon (Hidden on Mobile) -->
        <div class="hidden md:flex bg-white border border-slate-200/80 p-2 rounded-2xl shadow-sm flex-wrap gap-2 text-xs font-semibold">
            <a href="dashboard.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                📊 แดชบอร์ดสถิติรวม
            </a>
            <?php if ($user_role !== 'teacher'): ?>
                <a href="supervision.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                    ➕ บันทึกนิเทศคาบเรียนใหม่
                </a>
            <?php endif; ?>
            <a href="comparison.php" class="px-4 py-2 bg-[#1565C0] text-white rounded-xl shadow-sm font-bold flex items-center gap-1.5">
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
            <a href="profile.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                ⚙️ ตั้งค่าบัญชีของฉัน
            </a>
        </div>

        <!-- Filter Term Option -->
        <div class="bg-white border p-4 rounded-2xl shadow-xs flex flex-col sm:flex-row justify-between items-center sm:gap-4 gap-2 text-xs">
            <div class="flex items-center gap-2">
                <span class="text-xl">📊</span>
                <div>
                    <span class="font-extrabold block text-slate-805">ตารางเปรียบเทียบคะแนนเฉลี่ยผลการนิเทศชั้นเรียนจำแนกตามหมวดการประเมิน</span>
                    <span class="text-[10px] text-slate-400 block mt-0.5">เพื่อใช้วิเคราะห์จุดเด่น จุดที่ควรพัฒนา และวางแผนการพัฒนาคุณภาพการจัดการเรียนรู้ของครูผู้สอน</span>
                </div>
            </div>
            
            <form method="GET" class="w-full sm:w-auto flex items-center gap-2">
                <span class="font-bold text-slate-505 whitespace-nowrap">เลือกปีเทอม:</span>
                <select name="filter_year" onchange="this.form.submit()" class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-semibold cursor-pointer outline-none">
                    <option value="all">-- ทุกปีการศึกษาทั้งหมด --</option>
                    <?php foreach ($years_list as $y): ?>
                        <option value="<?php echo htmlspecialchars($y['year_id']); ?>" <?php if ($filter_year === $y['year_id']) echo 'selected'; ?>>
                            ปีการศึกษา <?php echo htmlspecialchars($y['year']); ?> / เทอม <?php echo htmlspecialchars($y['semester']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <!-- Comparison Analytical Matrix table -->
        <div class="bg-white border rounded-2xl p-5 shadow-sm text-xs space-y-4">
            <div class="border-b pb-2">
                <h3 class="font-extrabold text-[#0A3370] text-sm">ตารางเปรียบเทียบผลการนิเทศชั้นเรียนรายครู จำแนกตามหมวดการประเมินและแสดงผลเป็นร้อยละ (%)</h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 text-slate-650 font-extrabold text-[11px]">
                        <tr>
                            <th class="p-3">ครูผู้รับการนิเทศ</th>
                            <th class="p-3">ตำแหน่ง</th>
                            <th class="p-3 text-center">จำนวนครั้งที่ได้รับการนิเทศ</th>
                            <th class="p-3 text-center text-blue-900">หมวด 1 (ห้องเรียน)</th>
                            <th class="p-3 text-center text-purple-800">หมวด 2 (การบริหารจัดการห้องเรียน)</th>
                            <th class="p-3 text-center text-amber-700">หมวด 3 (ครูผู้สอน)</th>
                            <th class="p-3 text-center text-teal-800">หมวด 4 (นักเรียน)</th>
                            <th class="p-3 text-center bg-[#0A3370]/5 text-[#0A3370] font-black">เฉลี่ยรวม (%)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700 font-medium">
                        <?php foreach ($comparison_data as $data): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="p-3">
                                    <div class="font-bold text-slate-900"><?php echo htmlspecialchars($data['teacher_name']); ?></div>
                                    <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($data['subject_group']); ?></div>
                                </td>
                                <td class="p-3 text-slate-500 font-medium"><?php echo htmlspecialchars($data['position']); ?></td>
                                <td class="p-3 text-center">
                                    <?php if ($data['sessions_count'] > 0): ?>
                                        <span class="px-2 py-0.5 bg-emerald-50 text-emerald-800 font-bold rounded text-[10px]">
                                            <?php echo $data['sessions_count']; ?> ครั้ง
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 bg-slate-50 text-slate-400 text-[9px] rounded">ไม่มีข้อมูล</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Categories averages with beautiful background color weight indicators based on grades values -->
                                <?php foreach (['cat1', 'cat2', 'cat3', 'cat4'] as $ckey): 
                                    $val = $data['averages'][$ckey];
                                    $col = 'text-slate-400';
                                    if ($val >= 90) $col = 'text-emerald-750 font-bold';
                                    else if ($val >= 80) $col = 'text-green-650 font-semibold';
                                    else if ($val >= 75) $col = 'text-blue-650 font-semibold';
                                    else if ($val > 0) $col = 'text-amber-600 font-semibold';
                                ?>
                                    <td class="p-3 text-center font-mono text-[11px] <?php echo $col; ?>">
                                        <?php echo $val > 0 ? "{$val}%" : '-'; ?>
                                    </td>
                                <?php endforeach; ?>

                                <!-- Combined Total average -->
                                <td class="p-3 text-center bg-[#0A3370]/5 text-blue-900 font-extrabold font-mono text-[12px]">
                                    <?php echo $data['averages']['overall'] > 0 ? "{$data['averages']['overall']}%" : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Legends guides explanation -->
            <div class="bg-[#FAF8F5] p-3 rounded-xl border border-dashed border-slate-200 grid grid-cols-1 sm:grid-cols-4 gap-3 text-slate-500 text-[10.5px] leading-relaxed select-none">
                <div><strong>หมวด 1 สภาพห้องเรียน:</strong> มีป้ายนิเทศ ข้อมูลห้องเรียนเป็นปัจจุบัน แสดงผลงานนักเรียน และมีบรรยากาศเอื้อต่อการเรียนรู้</div>
                <div><strong>หมวด 2 การบริหารจัดการห้องเรียน:</strong> ใช้การเสริมแรงเชิงบวก จัดกิจกรรมกลุ่ม และส่งเสริมการมีส่วนร่วมของนักเรียนทุกคน</div>
                <div><strong>หมวด 3 ครูผู้สอน:</strong> มีแผนการสอน ใช้สื่อเทคโนโลยี ดูแลนักเรียนรายบุคคล ดำเนินการวิจัยในชั้นเรียน และปฏิบัติตนเหมาะสม</div>
                <div><strong>หมวด 4 นักเรียน:</strong> ปฏิบัติงานตามที่ได้รับมอบหมาย บรรลุจุดประสงค์การเรียนรู้ มีความกระตือรือร้น มีวินัย และแต่งกายถูกระเบียบ</div>
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
        <a href="comparison.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-[#1565C0] font-bold">
            <span class="text-xl">📊</span>
            <span class="text-[9px]">รายงาน</span>
        </a>
        <a href="teachers.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-slate-400 hover:text-slate-600">
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

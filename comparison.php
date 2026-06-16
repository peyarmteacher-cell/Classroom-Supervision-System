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

// ดึงปีการศึกษาเพื่อทำตัวเลือกในการเปรียบเทียบ
$years_list = $pdo->query("SELECT * FROM academic_years ORDER BY year DESC, semester DESC")->fetchAll();
$filter_year = $_GET['filter_year'] ?? 'all';

// โฮสต์จัดประเภทเกณฑ์ 5 หมวดหลักเป็นโครงสร้างอาร์เรย์
$categories = [
    'cat1' => ['label' => 'หมวดที่ 1: การเตรียมเรียนรู้ออนไลน์', 'items' => [1, 2, 3, 4]],
    'cat2' => ['label' => 'หมวดที่ 2: บรรยากาศแวดล้อมชั้นเรียน', 'items' => [5, 6, 7, 8]],
    'cat3' => ['label' => 'หมวดที่ 3: เชิงรุก Active Learning', 'items' => [9, 10, 11, 12]],
    'cat4' => ['label' => 'หมวดที่ 4: การใช้สื่อวัดประเมินไอที', 'items' => [13, 14, 15, 16]],
    'cat5' => ['label' => 'หมวดที่ 5: พฤติกรรมอุปนิสัยผู้เรียน', 'items' => [17, 18, 19, 20]]
];

// โหลดรายชื่อครูทั้งหมดที่มีในตาราง
$teachers = $pdo->query("SELECT * FROM teachers ORDER BY teacher_id ASC")->fetchAll();

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
        'cat1' => 0, 'cat2' => 0, 'cat3' => 0, 'cat4' => 0, 'cat5' => 0, 'overall' => 0
    ];

    if ($total_done_sessions > 0) {
        $scores_sum_by_cat = [
            'cat1' => 0, 'cat2' => 0, 'cat3' => 0, 'cat4' => 0, 'cat5' => 0
        ];
        
        foreach ($records_scores as $scores_json) {
            $scores_arr = json_decode($scores_json, true) ?: [];
            
            // หาเรทคะแนนเฉลี่ยจำเพาะในแต่ละกล่องเกณฑ์ย่อย
            foreach ($categories as $cat_key => $cat_info) {
                $sub_sum = 0;
                foreach ($cat_info['items'] as $item_num) {
                    $sub_sum += (int)($scores_arr[$item_num] ?? 5);
                }
                // เฉลี่ยในหมวดนั้นๆ (เต็ม 20 คะแนน เพราะหมวดละ 4 ข้อ ข้อละ 5 คะแนน)
                $scores_sum_by_cat[$cat_key] += ($sub_sum / 20) * 100;
            }
        }
        
        // เฉลี่ยตามจำนวนครั้งปฏิบัติประจบ
        $overall_sum = 0;
        foreach ($categories as $cat_key => $cat_info) {
            $category_avgs[$cat_key] = round($scores_sum_by_cat[$cat_key] / $total_done_sessions, 1);
            $overall_sum += $category_avgs[$cat_key];
        }
        $category_avgs['overall'] = round($overall_sum / 5, 1);
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
    <title>รายงานวิเคราะห์คุณภาพครูผู้สอน - ระบบนิเทศโรงเรียน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', 'Inter', sans-serif; } </style>
</head>
<body class="bg-[#FAF8F5] min-h-screen text-slate-900 duration-200">

    <!-- Header Navbar -->
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
        <div class="bg-white border border-slate-200 p-2.5 rounded-2xl shadow-sm flex flex-wrap gap-2 text-xs font-semibold font-semibold">
            <a href="dashboard.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                📊 แดชบอร์ดสถิติรวม
            </a>
            <a href="supervision.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                ➕ บันทึกนิเทศคาบเรียนใหม่
            </a>
            <a href="comparison.php" class="px-4 py-2 bg-[#0A3370] text-white rounded-xl shadow-xs font-bold flex items-center gap-1.5">
                🔎 วิเคราะห์ครูรายบุคคล/เปรียบเทียบ
            </a>
            <a href="teachers.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                👥 ทะเบียนครูผู้สอน
            </a>
            <a href="academic_years.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                📅 สารบบปีการศึกษา
            </a>
            <a href="profile.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                ⚙️ ตั้งค่าบัญชีของฉัน
            </a>
        </div>

        <!-- Filter Term Option -->
        <div class="bg-white border p-4 rounded-2xl shadow-xs flex flex-col sm:flex-row justify-between items-center sm:gap-4 gap-2 text-xs">
            <div class="flex items-center gap-2">
                <span class="text-xl">📊</span>
                <div>
                    <span class="font-extrabold block text-slate-805">เปรียบเทียบตารางค่าเฉลี่ยจำแนกตามหมวดเกรด</span>
                    <span class="text-[10px] text-slate-400 block mt-0.5">ใช้ค้นหาจุดที่สมควรเสริมแรงหรือการประเมินวิทยวิชาการของคุณครู</span>
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
                <h3 class="font-extrabold text-[#0A3370] text-sm">ตารางผลการเรียนรู้และสุขภาวะครูจำแนกคุณภาพร้อยละ (%)</h3>
                <p class="text-[10px] text-slate-400 mt-0.5">คะแนนสะกดเป็นเปอร์เซ็นต์ย่อยในแต่ละหมวดคำถามกระทรวงศึกษาธิการ เพื่อสะดวกในการสังเกต</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 text-slate-650 font-extrabold text-[11px]">
                        <tr>
                            <th class="p-3">ครูผู้รับระเบียนนิเทศ</th>
                            <th class="p-3">ตำแหน่ง / ดำรงงาน</th>
                            <th class="p-3 text-center">ประเมินสะสม</th>
                            <th class="p-3 text-center text-blue-900">หมวด 1</th>
                            <th class="p-3 text-center text-purple-800">หมวด 2</th>
                            <th class="p-3 text-center text-amber-700">หมวด 3</th>
                            <th class="p-3 text-center text-teal-800">หมวด 4</th>
                            <th class="p-3 text-center text-indigo-800">หมวด 5</th>
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
                                            <?php echo $data['sessions_count']; ?> คาบศึกษา
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 bg-slate-50 text-slate-400 text-[9px] rounded">ไม่มีข้อมูล</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Categories averages with beautiful background color weight indicators based on grades values -->
                                <?php foreach (['cat1', 'cat2', 'cat3', 'cat4', 'cat5'] as $ckey): 
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
            <div class="bg-[#FAF8F5] p-3 rounded-xl border border-dashed border-slate-200 grid grid-cols-1 sm:grid-cols-5 gap-3 text-slate-500 text-[10px] leading-relaxed select-none">
                <div><strong>หมวด 1:</strong> การเตรียมเรียนรู้อันดับต้นและการจัดแผนการเรียนการสอนรายปี</div>
                <div><strong>หมวด 2:</strong> การจัดบรรยากาศชั้นเรียน สภาพความพึงประสงค์กายภาพและความมั่นคงปลอดภัย</div>
                <div><strong>หมวด 3:</strong> กระบวนการเชิงรุก (Active Learning) จากทฤษฎีสู่การปฏิบัติร่วมกัน</div>
                <div><strong>หมวด 4:</strong> เครื่องมือวัด ตรวจสะท้อน และสื่อไอทีเทคโนโลยีการสอนสมัยใหม่</div>
                <div><strong>หมวด 5:</strong> ผลงานสัมฤทธิ์ปลายคาบและพฤติกรรมอรรถคุณลักษณะของนักเรียน</div>
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

<?php
// ==========================================
// supervision.php - ฟอร์มกรอกและบันทึกคะแนนนิเทศ (20 เกณฑ์ 5 หมวดหลัก)
// ==========================================

require_once 'config.php';

// ตรวจสอบสิทธิ์สิทธิ์การเข้าใช้งานคุณครูทั่วไปไม่รับสิทธิ์เขียนในฟอร์มนี้สงวนเพื่อผู้นิเทศ
if (!isset($_SESSION['username']) || $_SESSION['role'] === 'teacher') {
    header("Location: dashboard.php");
    exit;
}

$error_msg = '';
$success_msg = '';

$edit_id = $_GET['edit_id'] ?? null;
$teacher_id = $_GET['teacher_id'] ?? '';

// ข้อมูลหลักฟอร์มเซ็ตมาตรฐาน
$year_id = '';
$class_name = '';
$subject_name = '';
$date_string = date('Y-m-d');
$comments_strengths = '';
$comments_suggestions = '';
$comments_development = '';
$evaluator_name = $_SESSION['fullname'] ?? '';
$evaluator_position = ($_SESSION['role'] === 'director') ? 'ผู้อำนวยการโรงเรียน' : 'รองผู้อำนวยการ';
$status = 'draft';
$photos_json = '[]';

// อนุกรมคะแนนเริ่มต้น 20 ช่อง (ทุกช่องได้เต็ม 5 คะแนนไว้ก่อนเพื่ออำนวยความสะดวก)
$scores = array_fill(1, 20, 5);

// โหลดข้อมูลแบบประเมินเก่ามาแก้ถ้าระบุไอดี
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM supervisions WHERE record_id = ?");
    $stmt->execute([$edit_id]);
    $rec = $stmt->fetch();
    if ($rec) {
        $teacher_id = $rec['teacher_id'];
        $year_id = $rec['year_id'];
        $class_name = $rec['class_name'];
        $subject_name = $rec['subject_name'];
        $date_string = $rec['date_string'];
        $comments_strengths = $rec['comments_strengths'];
        $comments_suggestions = $rec['comments_suggestions'];
        $comments_development = $rec['comments_development'];
        $evaluator_name = $rec['evaluator_name'];
        $evaluator_position = $rec['evaluator_position'];
        $status = $rec['status'];
        $photos_json = $rec['photos_json'];
        
        $saved_scores = json_decode($rec['scores_json'], true);
        if ($saved_scores) {
            foreach ($saved_scores as $item_num => $v) {
                $scores[(int)$item_num] = (int)$v;
            }
        }
    }
}

$current_photos = json_decode($photos_json, true) ?: [];

$school_code = $_SESSION['school_code'] ?? '31054002';

// โหลดทะเบียนคุณครู & ปีการศึกษามาทำ Dropdown ของแต่ละโรงเรียนโดยตรง
$stmt_teachers_drop = $pdo->prepare("SELECT * FROM teachers WHERE school_code = ? ORDER BY teacher_id ASC");
$stmt_teachers_drop->execute([$school_code]);
$dropdown_teachers = $stmt_teachers_drop->fetchAll();

$stmt_years_drop = $pdo->prepare("SELECT * FROM academic_years WHERE school_code = ? ORDER BY year DESC, semester DESC");
$stmt_years_drop->execute([$school_code]);
$dropdown_years = $stmt_years_drop->fetchAll();

$stmt_classrooms_drop = $pdo->prepare("SELECT * FROM classrooms WHERE school_code = ? ORDER BY class_name ASC");
$stmt_classrooms_drop->execute([$school_code]);
$dropdown_classrooms = $stmt_classrooms_drop->fetchAll();

// หากระบุคุณครูเข้ามาแล้ว โหลดค่าสถานะห้องเรียนและรายวิชาอัตโนมัติ
if (!empty($teacher_id)) {
    $stmt_sel_teacher = $pdo->prepare("SELECT * FROM teachers WHERE teacher_id = ? AND school_code = ?");
    $stmt_sel_teacher->execute([$teacher_id, $school_code]);
    $sel_teacher_data = $stmt_sel_teacher->fetch();
    if ($sel_teacher_data && empty($edit_id)) {
        $class_name = $sel_teacher_data['classroom'] ?? '';
        $subject_name = str_replace('กลุ่มสาระการเรียนรู้', '', $sel_teacher_data['subject_group'] ?? '');
    }
}

// ตรวจสอบเครื่องหมายถูกสีเขียวหรือนาฬิกาประเมินล่าช้า
$teacher_evaluation_status = [];
try {
    $stmt_status_check = $pdo->prepare("SELECT teacher_id, status FROM supervisions WHERE school_code = ?");
    $stmt_status_check->execute([$school_code]);
    $status_records = $stmt_status_check->fetchAll();
    foreach ($status_records as $sr) {
        if ($sr['status'] === 'submitted') {
            $teacher_evaluation_status[$sr['teacher_id']] = 'submitted';
        } elseif ($sr['status'] === 'draft' && !isset($teacher_evaluation_status[$sr['teacher_id']])) {
            $teacher_evaluation_status[$sr['teacher_id']] = 'draft';
        }
    }
} catch (Exception $e) {
    //
}

// โหลดข้อคำถามนิเทศกลุ่มกระทรวง 5 หมวดหลักจากดาต้าเบส
$evaluation_items = $pdo->query("SELECT * FROM evaluation_items ORDER BY CAST(item_id AS UNSIGNED) ASC")->fetchAll();

// การบันทึกและรันข้อมูลประมวลฟอร์มนิเทศ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_supervision'])) {
    $teacher_id = $_POST['teacher_id'] ?? '';
    $year_id = $_POST['year_id'] ?? '';
    $class_name = trim($_POST['class_name'] ?? '');
    $subject_name = trim($_POST['subject_name'] ?? '');
    $date_string = $_POST['date_string'] ?? date('Y-m-d');
    $comments_strengths = trim($_POST['comments_strengths'] ?? '');
    $comments_suggestions = trim($_POST['comments_suggestions'] ?? '');
    $comments_development = trim($_POST['comments_development'] ?? '');
    $evaluator_name = trim($_POST['evaluator_name'] ?? '');
    $evaluator_position = trim($_POST['evaluator_position'] ?? '');
    $status = $_POST['status'] ?? 'draft';

    // ดึงคะแนน 20 ข้อจากหน้าเว็บ
    $post_scores = [];
    for ($i = 1; $i <= 20; $i++) {
        $post_scores[$i] = (int)($_POST["score_{$i}"] ?? 5);
    }
    $scores_json_str = json_encode($post_scores);

    // ประมวลผลรูปภาพนิเทศชั้นเรียน (ได้รับ JSON ของ poolรูปภาพล่าสุดจากฝั่งไคลเอนต์เพื่อตัดปัญหารูปภาพโดนเขียนทับ)
    $uploaded_photos = [];
    if (!empty($_POST['compressed_photos_json'])) {
        $client_photos = json_decode($_POST['compressed_photos_json'], true);
        if (is_array($client_photos)) {
            foreach ($client_photos as $p) {
                if (strpos($p, 'data:image/') === 0 || strpos($p, 'http') === 0) {
                    $uploaded_photos[] = $p;
                }
            }
        }
    } else {
        // Fallback หากไม่มีการระบุส่งมาผ่านโมดูลบีบอัด ให้ดึงประวัติเดิม
        if ($edit_id) {
            $stmt_photo = $pdo->prepare("SELECT photos_json FROM supervisions WHERE record_id = ?");
            $stmt_photo->execute([$edit_id]);
            $existing_photos_str = $stmt_photo->fetchColumn();
            $uploaded_photos = json_decode($existing_photos_str ?: '[]', true) ?: [];
        }
    }

    // จำกัดจำนวนรูปภาพประกอบเล่มนิเทศไว้ไม่เกิน 4 รูปสูงสุด เพื่อความสวยงามในการจัดพิมพ์ 2 คอลัมน์
    $uploaded_photos = array_slice($uploaded_photos, 0, 4);

    // แปลงรูปภาพทั้งหมดเป็น JSON String
    if (empty($uploaded_photos) && !$edit_id) {
        // กรณีแบบประเมินใหม่แล้วไม่ได้อัปโหลดรูป ให้เป็น dummy เพื่อความสมบูรณ์ในการพิมพ์และดูรายงาน
        $photos_json_saved = json_encode([
            "https://images.unsplash.com/photo-1577896851231-70ef18881754?auto=format&fit=crop&w=400&q=80",
            "https://images.unsplash.com/photo-1427504494785-3a9ca7044f45?auto=format&fit=crop&w=400&q=80",
            "https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=400&q=80",
            "https://images.unsplash.com/photo-1509062522246-3755977927d7?auto=format&fit=crop&w=400&q=80"
        ]);
    } else {
        $photos_json_saved = json_encode($uploaded_photos);
    }

    if (!empty($teacher_id) && !empty($year_id) && !empty($class_name) && !empty($subject_name)) {
        if ($edit_id) {
            // ดำเนินการแก้ไขไอดีเรคอร์ดเดิม
            $query = "
                UPDATE supervisions SET
                    teacher_id = ?, year_id = ?, class_name = ?, subject_name = ?, date_string = ?,
                    scores_json = ?, comments_strengths = ?, comments_suggestions = ?, comments_development = ?,
                    evaluator_name = ?, evaluator_position = ?, status = ?, photos_json = ?
                WHERE record_id = ?
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $teacher_id, $year_id, $class_name, $subject_name, $date_string,
                $scores_json_str, $comments_strengths, $comments_suggestions, $comments_development,
                $evaluator_name, $evaluator_position, $status, $photos_json_saved, $edit_id
            ]);

            $success_msg = "ปรับปรุงเอกสารทะเบียนนิเทศ {$edit_id} เป็นที่เรียบร้อยพร้อมพิมพ์ประวัติ";
            header("Location: dashboard.php?success_msg=" . urlencode($success_msg));
            exit;
        } else {
            // การเพิ่มแบบประเมินนิเทศใหม่ โดยสร้างไอดีอิงตามสากลกระทรวงศาสนาพุทธ เช่น REC-2569-001
            $get_y = $pdo->prepare("SELECT year FROM academic_years WHERE year_id = ? AND school_code = ?");
            $get_y->execute([$year_id, $school_code]);
            $year_val = $get_y->fetchColumn() ?: '2569';

            // นับเลขลำดับเพื่อความสอดคล้อง เฉพาะภายในโรงเรียนปัจจุบัน
            $curr_max_num = 0;
            $stmt_recs = $pdo->prepare("SELECT record_id FROM supervisions WHERE school_code = ?");
            $stmt_recs->execute([$school_code]);
            $all_recs = $stmt_recs->fetchAll(PDO::FETCH_COLUMN);
            foreach ($all_recs as $r_id) {
                // รูปแบบ REC-YYYY-XXX หรือ REC-YYYY-XXX-SCHOOLCODE
                if (preg_match('/REC-\d+-(\d+)/', $r_id, $matches)) {
                    $num = (int)$matches[1];
                    if ($num > $curr_max_num) {
                        $curr_max_num = $num;
                    }
                }
            }
            $next_num = $curr_max_num + 1;
            $new_record_id = "REC-{$year_val}-" . str_pad($next_num, 3, '0', STR_PAD_LEFT) . "-" . $school_code;

            $query = "
                INSERT INTO supervisions (
                    record_id, teacher_id, year_id, class_name, subject_name, date_string,
                    scores_json, comments_strengths, comments_suggestions, comments_development,
                    evaluator_name, evaluator_position, status, photos_json, school_code
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $new_record_id, $teacher_id, $year_id, $class_name, $subject_name, $date_string,
                $scores_json_str, $comments_strengths, $comments_suggestions, $comments_development,
                $evaluator_name, $evaluator_position, $status, $photos_json_saved, $school_code
            ]);

            $success_msg = "ประเมินนิเทศคาบสอนครู {$new_record_id} ลงระบบสารสนเทศเรียบร้อย!";
            header("Location: dashboard.php?success_msg=" . urlencode($success_msg));
            exit;
        }
    } else {
        $error_msg = 'โปรดแน่ใจว่าท่านระบุคุณครู, ภาคการศึกษา, วิชาที่ประเมิน และระดับชั้นเรียนครบถ้วนเรียบร้อย';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึกคะแนนและประเมินนิเทศคุณครู - ระบบนิเทศออนไลน์</title>
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
        }
    </style>
</head>
<body class="bg-[#F5F7FA] min-h-screen text-slate-800 pb-16 md:pb-0">

    <!-- Navbar Header -->
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
            <a href="dashboard.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                📊 แดชบอร์ดสถิติรวม
            </a>
            <a href="supervision.php" class="px-4 py-2 bg-[#1565C0] text-white rounded-xl shadow-sm font-bold flex items-center gap-1.5">
                ➕ บันทึกนิเทศคาบเรียนใหม่
            </a>
            <a href="comparison.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                🔎 วิเคราะห์ครูรายบุคคล/เปรียบเทียบ
            </a>
            <a href="teachers.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5">
                👥 ทะเบียนครูผู้สอน
            </a>
            <a href="academic_years.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                📅 ปีการศึกษา
            </a>
            <a href="profile.php" class="px-4 py-2 text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-xl transition flex items-center gap-1.5 font-semibold">
                ⚙️ ตั้งค่าบัญชีของฉัน
            </a>
        </div>

        <?php if ($error_msg): ?>
            <div class="text-xs bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-2xl font-bold">
                ⚠️ <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($teacher_id)): ?>
            <!-- STEP 1: CHOOSE TEACHER SCREEN -->
            <div class="bg-white border border-slate-200 p-6 sm:p-8 rounded-3xl shadow-sm space-y-6">
                
                <!-- Header section of teacher selection -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 border-b pb-4">
                    <div>
                        <h2 class="text-base font-extrabold text-[#0A3370] tracking-tight flex items-center gap-2">
                            🔎 เลือกคุณครูเพื่อเริ่มต้นการบันทึกการนิเทศ
                        </h2>
                        <p class="text-[11px] text-slate-400 mt-0.5">
                            กรุณาคลิกเลือกชื่อคุณครูจากด้านล่างเพื่อเริ่มการประเมินวิธีกระบวนการสอนในชั้นเรียนจริง
                        </p>
                    </div>
                    
                    <!-- Filter buttons (ประถม / มัธยม) -->
                    <div class="flex items-center gap-1.5 bg-slate-50 p-1 rounded-xl border border-slate-200/60 font-semibold text-xs text-slate-650 flex-wrap">
                        <button type="button" onclick="filterLevel('all')" id="tab-all" class="px-4 py-2 bg-[#0A3370] text-white rounded-lg shadow-2xs font-extrabold transition-all cursor-pointer">
                            ทั้งหมด (<?php echo count($dropdown_teachers); ?>)
                        </button>
                        <button type="button" onclick="filterLevel('prathom')" id="tab-prathom" class="px-3 py-2 hover:bg-slate-200 text-slate-700 rounded-lg transition-all cursor-pointer">
                            ระดับประถม & ปฐมวัย
                        </button>
                        <button type="button" onclick="filterLevel('mattayom')" id="tab-mattayom" class="px-3 py-2 hover:bg-slate-200 text-slate-700 rounded-lg transition-all cursor-pointer">
                            ระดับมัธยมศึกษา
                        </button>
                    </div>
                </div>

                <!-- Search input bar -->
                <div class="relative max-w-sm">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                        🔎
                    </span>
                    <input type="text" id="teacher-search-input" onkeyup="searchTeachers()" placeholder="พิมพ์ ค้นหาชื่อคุณครู หรือวิชาเอกกลุ่มสาระ..." 
                    class="w-full pl-9 pr-4 py-2 bg-slate-50 hover:bg-slate-100/50 border border-slate-200 rounded-xl text-xs outline-none focus:ring-1 focus:ring-blue-900 focus:bg-white transition-all font-medium">
                </div>

                <!-- Teachers List Container Dynamic -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 animate-fade-in" id="teachers-grid">
                    <?php foreach ($dropdown_teachers as $t): 
                        // Tier level check
                        $cls_tier = $t['classroom'] ?? '';
                        $level = 'prathom';
                        if (strpos($cls_tier, 'มัธยม') !== false || strpos($cls_tier, 'ม.') !== false || strpos($cls_tier, 'Secondary') !== false) {
                            $level = 'mattayom';
                        }
                        
                        $sh_subject = str_replace('กลุ่มสาระการเรียนรู้', '', $t['subject_group'] ?? '');
                        $st_eval = $teacher_evaluation_status[$t['teacher_id']] ?? null;
                        $wk_status = $t['work_status'] ?? 'ปกติ';
                    ?>
                        <div class="bg-slate-50/40 border border-slate-200 p-5 rounded-2xl flex flex-col justify-between hover:scale-[1.01] transition-all duration-200 hover:border-blue-900/50 hover:bg-white teacher-card shadow-2xs" 
                             data-level="<?php echo $level; ?>"
                             data-search-name="<?php echo htmlspecialchars(mb_strtolower($t['teacher_name'])); ?>"
                             data-search-subject="<?php echo htmlspecialchars(mb_strtolower($sh_subject)); ?>">
                            
                            <div>
                                <!-- Image & status markers -->
                                <div class="flex items-start justify-between gap-3 mb-4">
                                    <div class="relative shrink-0">
                                        <div class="w-16 h-16 rounded-xl overflow-hidden bg-slate-100 border border-slate-200 flex items-center justify-center">
                                            <?php if (!empty($t['photo_path']) && is_valid_photo($t['photo_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($t['photo_path']); ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <span class="text-2xl">👩‍🏫</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($st_eval === 'submitted'): ?>
                                            <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-emerald-500 text-white rounded-full flex items-center justify-center text-[9px] font-bold border-2 border-white shadow-xs" title="บันทึกประเมินนิเทศเรียบร้อยแล้ว">✓</div>
                                        <?php elseif ($st_eval === 'draft'): ?>
                                            <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-orange-500 text-white rounded-full flex items-center justify-center text-[9px] font-bold border-2 border-white shadow-xs" title="กำลังบันทึกร่างค้างอยู่">⏳</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="text-right flex flex-col items-end gap-1 flex-1">
                                        <?php if ($st_eval === 'submitted'): ?>
                                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-150 rounded-md text-[9px] font-extrabold flex items-center gap-0.5">✓ ประเมินแล้ว</span>
                                        <?php elseif ($st_eval === 'draft'): ?>
                                            <span class="px-2 py-0.5 bg-amber-50 text-amber-700 border border-amber-150 rounded-md text-[9px] font-extrabold flex items-center gap-0.5">⏳ แบบร่างคา้าง</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-slate-100 text-slate-500 border border-slate-200 rounded-md text-[9px] font-bold">รอนิเทศ</span>
                                        <?php endif; ?>

                                        <?php if ($wk_status !== 'ปกติ'): ?>
                                            <span class="px-2 py-0.5 bg-rose-50 text-rose-700 border border-rose-150 rounded-md text-[9px] font-extrabold animation-pulse mt-1">🤒 <?php echo htmlspecialchars($wk_status); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="space-y-1">
                                    <h3 class="text-slate-900 font-extrabold text-xs tracking-tight">
                                        คุณครู<?php echo htmlspecialchars($t['teacher_name']); ?>
                                    </h3>
                                    <p class="text-[10px] text-slate-500 font-bold">
                                        <?php echo htmlspecialchars($t['position']); ?>
                                    </p>
                                    <div class="text-[9.5px] text-slate-400 font-semibold mt-1">
                                        🏫 ประจำห้อง: <?php echo htmlspecialchars($t['classroom'] ?? 'ชั้นประถมศึกษาปีที่ 1/1'); ?>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-1 mt-3">
                                    <span class="bg-amber-100/70 text-amber-900 border border-amber-200/50 px-2 py-0.5 rounded-lg text-[9px] font-bold">
                                        <?php echo htmlspecialchars($sh_subject); ?>
                                    </span>
                                    <span class="bg-indigo-50 text-indigo-700 border border-indigo-100 px-2 py-0.5 rounded-lg text-[9px] font-mono font-bold">
                                        📅 <?php echo (int)($t['teaching_hours'] ?? 8); ?> คาบ/สัปดาห์
                                    </span>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-t border-dashed border-slate-200/85">
                                <?php if ($wk_status === 'ปกติ'): ?>
                                    <?php if ($st_eval === 'draft'): ?>
                                        <a href="supervision.php?teacher_id=<?php echo urlencode($t['teacher_id']); ?>" 
                                           class="w-full bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold py-2 px-3 rounded-xl text-[11px] text-center flex items-center justify-center gap-1 transition shadow-xs">
                                            📝 เขียนต่อ (แบบร่างค้าง)
                                        </a>
                                    <?php else: ?>
                                        <a href="supervision.php?teacher_id=<?php echo urlencode($t['teacher_id']); ?>" 
                                           class="w-full bg-[#0A3370] hover:bg-blue-900 text-white font-extrabold py-2 px-3 rounded-xl text-[11px] text-center flex items-center justify-center gap-1 transition shadow-xs">
                                            📋 บันทึกประเมินนิเทศ
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button type="button" disabled class="w-full bg-slate-100 text-slate-400 font-extrabold py-2 px-3 rounded-xl text-[10.5px] cursor-not-allowed text-center">
                                        🚫 งดการเข้าประเมินนิเทศ (<?php echo htmlspecialchars($wk_status); ?>)
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Floating bottom-right action trigger -->
                <div class="fixed bottom-6 right-6 z-40 select-none">
                    <a href="teachers.php" class="bg-[#FFC107] hover:bg-amber-500 text-slate-900 font-extrabold px-5 py-3 rounded-full shadow-lg justify-center items-center gap-1.5 flex text-xs transition border-none decoration-transparent">
                        <span class="text-sm font-black">+</span> 
                        <span>ลงทะเบียนครูท่านอื่น</span>
                    </a>
                </div>

            </div>

            <script>
                let currentLevelFilter = 'all';

                function filterLevel(level) {
                    currentLevelFilter = level;
                    
                    const tabAll = document.getElementById('tab-all');
                    const tabPrathom = document.getElementById('tab-prathom');
                    const tabMattayom = document.getElementById('tab-mattayom');

                    [tabAll, tabPrathom, tabMattayom].forEach(tab => {
                        if (tab) {
                            tab.classList.remove('bg-[#0A3370]', 'text-white', 'shadow-2xs', 'font-extrabold');
                            tab.classList.add('hover:bg-slate-200', 'text-slate-700');
                        }
                    });

                    const clickedTab = document.getElementById('tab-' + level);
                    if (clickedTab) {
                        clickedTab.classList.add('bg-[#0A3370]', 'text-white', 'shadow-2xs', 'font-extrabold');
                        clickedTab.classList.remove('hover:bg-slate-200', 'text-slate-700');
                    }

                    applyFilters();
                }

                function searchTeachers() {
                    applyFilters();
                }

                function applyFilters() {
                    const searchQ = document.getElementById('teacher-search-input').value.toLowerCase().trim();
                    const cards = document.querySelectorAll('.teacher-card');

                    cards.forEach(card => {
                        const level = card.dataset.level;
                        const name = card.dataset.searchName;
                        const subject = card.dataset.searchSubject;

                        const matchesLevel = (currentLevelFilter === 'all' || level === currentLevelFilter);
                        const matchesSearch = (!searchQ || name.includes(searchQ) || subject.includes(searchQ));

                        if (matchesLevel && matchesSearch) {
                            card.classList.remove('hidden');
                        } else {
                            card.classList.add('hidden');
                        }
                    });
                }
            </script>

        <?php else: ?>
            <!-- STEP 2: EVALUATION FORM SCREEN -->
            
            <div class="bg-indigo-50/60 border border-indigo-100 p-4 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl overflow-hidden bg-white border border-slate-200 flex-shrink-0 flex items-center justify-center shadow-xs">
                        <?php if ($sel_teacher_data && !empty($sel_teacher_data['photo_path']) && is_valid_photo($sel_teacher_data['photo_path'])): ?>
                            <img src="<?php echo htmlspecialchars($sel_teacher_data['photo_path']); ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <span class="text-xl">👩‍🏫</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="text-[10px] text-slate-500 font-extrabold uppercase tracking-wide">คุณครูผู้รับสิทธิ์การประเมินนิเทศปัจจุบัน</div>
                        <div class="text-xs font-extrabold text-blue-950 flex items-center gap-1.5 flex-wrap">
                            <span class="text-sm font-black">คุณครู<?php echo htmlspecialchars($sel_teacher_data['teacher_name'] ?? ''); ?></span>
                            <span class="px-2 py-0.5 bg-indigo-100 text-[#0A3370] rounded font-extrabold text-[10px]"><?php echo htmlspecialchars($sel_teacher_data['position'] ?? ''); ?></span>
                        </div>
                        <div class="text-[10px] text-[#0A3370] font-bold mt-0.5">
                            🏫 ประจำชั้นเรียน: <?php echo htmlspecialchars($sel_teacher_data['classroom'] ?? 'ชั้นประถมศึกษาปีที่ 1/1'); ?>
                            | กลุ่มเอกสาระ: <?php echo htmlspecialchars($sel_teacher_data['subject_group'] ?? ''); ?>
                        </div>
                    </div>
                </div>
                <a href="supervision.php" class="bg-white hover:bg-slate-50 text-slate-700 p-2 px-4 rounded-xl text-xs font-bold border border-slate-200/80 transition flex items-center gap-1 shrink-0 shadow-2xs">
                    🔄 เปลี่ยนตัวคุณครูผู้สอน
                </a>
            </div>

            <!-- Evaluation Form container -->
            <form method="POST" enctype="multipart/form-data" class="bg-white border border-slate-200 p-6 sm:p-8 rounded-3xl shadow-sm space-y-8">
                <input type="hidden" name="action_save_supervision" value="1">
                <input type="hidden" name="teacher_id" value="<?php echo htmlspecialchars($teacher_id); ?>">

                <!-- Phase 1: General Header details metadata -->
                <div class="space-y-4">
                    <div class="border-b pb-3">
                        <h2 class="text-[#0A3370] font-extrabold text-base">ส่วนที่ 1: ข้อมูลปฐมภูมิประกอบการนิเทศชั้นเรียน</h2>
                        <p class="text-[11px] text-slate-400 mt-0.5">กรุณาระบุรายละเอียดคาบเรียนวิชาวิชาการให้ถูกต้องตามจริง</p>
                    </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs font-medium">
                    <!-- Teacher select drop down -->
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-550 block">คุณครูผู้รับการนิเทศชั้นเรียน *</label>
                        <select name="teacher_id" required class="w-full px-3 py-2.5 bg-slate-55 border border-slate-200 rounded-xl text-xs cursor-pointer outline-none font-semibold text-slate-700">
                            <option value="">-- กรุณาเลือกครูผู้สอน --</option>
                            <?php foreach ($dropdown_teachers as $t): ?>
                                <option value="<?php echo htmlspecialchars($t['teacher_id']); ?>" <?php if ($teacher_id === $t['teacher_id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($t['teacher_name']); ?> (<?php echo htmlspecialchars($t['position']); ?> - <?php echo htmlspecialchars($t['subject_group']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Year select drop down -->
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-550 block">ปีการศึกษา / ภาคการศึกษา *</label>
                        <select name="year_id" required class="w-full px-3 py-2.5 bg-slate-55 border border-slate-200 rounded-xl text-xs cursor-pointer outline-none font-semibold text-slate-700">
                            <option value="">-- เลือกภาควิชาการเรียน --</option>
                            <?php foreach ($dropdown_years as $y): ?>
                                <option value="<?php echo htmlspecialchars($y['year_id']); ?>" <?php if ($year_id === $y['year_id']) echo 'selected'; ?>>
                                    ปีการศึกษา <?php echo htmlspecialchars($y['year']); ?> / ภาคเรียนที่ <?php echo htmlspecialchars($y['semester']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Date field -->
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-550 block">วันที่ทำการประเมินนิเทศจริง *</label>
                        <input type="date" name="date_string" required value="<?php echo htmlspecialchars($date_string); ?>" class="w-full px-3 py-2 bg-slate-55 border border-slate-200 rounded-xl text-xs outline-none text-slate-700 font-semibold font-mono">
                    </div>

                    <!-- Class level dropdown select -->
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-550 block">ระดับชั้นเรียนมาตรฐาน *</label>
                        <select name="class_name" required class="w-full px-3 py-2.5 bg-slate-55 border border-slate-200 rounded-xl text-xs cursor-pointer outline-none font-semibold text-slate-700">
                            <option value="">-- กรุณาเลือกระดับชั้นเรียน --</option>
                            <?php foreach ($dropdown_classrooms as $cls): ?>
                                <option value="<?php echo htmlspecialchars($cls['class_name']); ?>" <?php if ($class_name === $cls['class_name']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($cls['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Subject field -->
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-550 block">ชื่อรายวิชาการเรียนรู้ที่สอน *</label>
                        <input type="text" name="subject_name" required value="<?php echo htmlspecialchars($subject_name); ?>" placeholder="เช่น คาบเรียนภาษาไทย 1, คณิตศาสตร์เชิงสร้างสรรค์" class="w-full px-3 py-1.5 bg-slate-55 border border-slate-200 rounded-xl text-xs outline-none text-slate-700">
                    </div>
                </div>
            </div>

            <!-- Phase 2: Beautiful standard 20 elements evaluation matrix -->
            <div class="space-y-6">
                <div class="border-b pb-3 flex flex-col sm:flex-row justify-between sm:items-center gap-1">
                    <div>
                        <h2 class="text-[#0A3370] font-extrabold text-base">ส่วนที่ 2: มาตราส่วนเกณฑ์บันทึกนิเทศคุณครู 20 ประเด็นตามสถิติกระทรวง</h2>
                        <p class="text-[11px] text-slate-400 mt-0.5">ระบุระดับความสัมฤทธิ์ในชั้นเรียนโดยใช้วงกลมระดับคะแนน 1-5 ดาวเพื่อเก็บผลลัพธ์เข้าคลังประเมิน</p>
                    </div>
                    <!-- Live total scores float meter -->
                    <div class="bg-[#0A3370] text-amber-300 font-extrabold p-2 px-4 rounded-xl text-xs shadow-md border-b-2 border-amber-500 self-start sm:self-auto flex items-center gap-1">
                        <span>คะแนนประเมินรวม:</span>
                        <span id="live_score_box" class="text-white text-sm font-mono tracking-wider">100</span>
                        <span>/ 100</span>
                    </div>
                </div>

                <!-- Guidance explanations boxes -->
                <div class="grid grid-cols-5 gap-2 bg-[#FAF8F5] p-3 rounded-xl border border-dashed text-slate-500 text-[10px] leading-relaxed select-none">
                    <div class="text-center font-bold text-emerald-700"><strong>5 คะแนน</strong><br>ระดับระดับ ดีเยี่ยม (Excellent)</div>
                    <div class="text-center font-bold text-green-700"><strong>4 คะแนน</strong><br>ระดับระดับ ดีมาก (Very Good)</div>
                    <div class="text-center font-bold text-blue-700"><strong>3 คะแนน</strong><br>ระดับระดับ ดี (Good)</div>
                    <div class="text-center font-bold text-amber-700"><strong>2 คะแนน</strong><br>ระดับระดับ พอใช้ (Fair)</div>
                    <div class="text-center font-bold text-rose-700"><strong>1 คะแนน</strong><br>ระดับระดับ ควรปรับปรุง (Needs Work)</div>
                </div>

                <!-- 20 Evaluation categories rows loop -->
                <div class="space-y-4">
                    <?php
                    $last_cats = '';
                    foreach ($evaluation_items as $item):
                        $cats = $item['category'];
                        if ($cats !== $last_cats) {
                            $last_cats = $cats;
                            // Category Ribbon Heading
                            echo "<div class='bg-slate-50 border-l-4 border-[#0A3370] p-2 px-3 rounded-lg text-xs font-bold text-[#0A3370] shadow-2xs mt-4 uppercase tracking-wide flex justify-between items-center'><span>{$cats}</span><span class='text-[10px] font-medium text-slate-400'>(ประเด็นเกณฑ์ประเมินที่สำคัญ)</span></div>";
                        }
                        $id_num = $item['item_id'];
                        $item_val = $scores[(int)$id_num] ?? 5;
                    ?>
                        <div class="bg-white border-b border-dashed border-slate-100 py-3.5 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 hover:bg-slate-50/50 transition px-2 rounded-xl">
                            <!-- Criterion number & label name -->
                            <div class="flex gap-2.5 items-start">
                                <span class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center font-mono font-bold text-slate-550 text-[11px] shrink-0"><?php echo $id_num; ?></span>
                                <span class="text-xs text-slate-800 font-semibold leading-relaxed"><?php echo htmlspecialchars($item['item_name']); ?></span>
                            </div>

                            <!-- Interactive Score Selector circles -->
                            <div class="flex items-center gap-3 select-none">
                                <div class="flex items-center gap-1.5">
                                    <?php for ($val = 1; $val <= 5; $val++): ?>
                                        <label class="cursor-pointer">
                                            <input type="radio" 
                                                   name="score_<?php echo $id_num; ?>" 
                                                   value="<?php echo $val; ?>" 
                                                   class="score_input_radio hidden" 
                                                   onclick="calculateLiveTotal()" 
                                                   <?php if ($item_val == $val) echo 'checked'; ?>>
                                            <span class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center font-mono font-bold text-[11px] text-slate-500 hover:border-blue-300 transition radio_score_circle">
                                                <?php echo $val; ?>
                                            </span>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Portion 3: Qualitative Commentaries -->
            <div class="space-y-4">
                <div class="border-b pb-3">
                    <h2 class="text-[#0A3370] font-extrabold text-base">ส่วนที่ 3: ความคิดเห็นผู้นิเทศ รายงานจุดเด่นและคำแนะนำเชิงพัฒนาการ</h2>
                    <p class="text-[11px] text-slate-400 mt-0.5">ระบุรายละเอียดเชิงพรรณนาเพื่อจุดประกายและบันทึกประวัติการพัฒนาวิทยฐานะของคุณครู</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs font-semibold">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600 block">จุดเด่นและแนวปฏิบัติที่เป็นเลิศ (Best Practice)</label>
                        <textarea name="comments_strengths" placeholder="คุณครูวิเคราะห์แผนบทเรียน และกระตุ้นเด็กนักเรียนผ่าน Active Learning ครบครัน..." rows="4" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none text-slate-700"><?php echo htmlspecialchars($comments_strengths); ?></textarea>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600 block">ข้อเสนอแนะเพื่อการพัฒนาการจัดการเรียนรู้</label>
                        <textarea name="comments_suggestions" placeholder="ควรเพิ่มเทคโนโลยีอินเทอร์เน็ตประยุกต์มาสุ่มเด็กนักเรียนเพิ่มเติม..." rows="4" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none text-slate-700"><?php echo htmlspecialchars($comments_suggestions); ?></textarea>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600 block">แนวทางและแผนการพัฒนาร่วมกัน</label>
                        <textarea name="comments_development" placeholder="ตกลงร่วมกันพัฒนานวัตกรรมสื่อสื่อประกอบการสอนและการติดตามแผนรายเทอม..." rows="4" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none text-slate-700"><?php echo htmlspecialchars($comments_development); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Portion 4: Classroom Supervision Photos Upload (Unified Client-side pool) -->
            <div class="space-y-4 border-t pt-6">
                <div class="border-b pb-3">
                    <h2 class="text-[#0A3370] font-extrabold text-base">ส่วนที่ 4: อัปโหลดรูปภาพบรรยากาศการจัดการเรียนรู้ในชั้นเรียน</h2>
                    <p class="text-[11px] text-slate-400 mt-0.5">แนบหลักฐานภาพถ่ายกิจกรรมในชั้นเรียน แผนการเรียนรู้แบบ Active Learning เพื่อนำไปแสดงผลที่ท้ายรายงานนิเทศ (แสดงผลสูงสุด 4 ภาพในระบบรายงาน 2 คอลัมน์)</p>
                </div>

                <!-- Unified Live Photo Gallery Container -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-xs font-bold text-slate-700 block">📸 ภาพถ่ายบรรยากาศการจัดการเรียนรู้ในคลังขณะนี้ (สูงสุด 4 รูป):</label>
                        <div id="upload_processing_badge" class="hidden text-amber-600 bg-amber-50 border border-amber-200 rounded px-2 py-0.5 text-[10px] font-bold animate-pulse">
                            ⏳ กำลังย่อขนาดและประจุรูปภาพ...
                        </div>
                    </div>
                    
                    <div id="unified_photo_gallery" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <!-- Dynamic photo items will be injected by JavaScript -->
                    </div>
                </div>

                <!-- New Upload Field with auto compression and accumulation -->
                <div class="bg-teal-50/40 border border-dashed border-[#0D9488]/30 p-5 rounded-2xl space-y-3">
                    <label class="text-xs font-bold text-[#0D9488] block">📤 เลือกอัปโหลดรูปภาพใหม่เพิ่มเติม (คลิกเพิ่มรูปภาพทีละรูปหรือเลือกพร้อมกันได้ ระบบจะบันทึกสะสมรวมกันไม่เกิน 4 รูป):</label>
                    <div class="flex items-center justify-center w-full">
                        <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-[#0D9488]/20 border-dashed rounded-xl cursor-pointer bg-white hover:bg-teal-50/20 transition">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6 text-center px-4">
                                <span class="text-3xl mb-1">🖼️</span>
                                <p class="mb-1 text-xs text-[#0D9488] font-bold">คลิกที่นี่เพื่อเปิดแกลเลอรี่/เลือกไฟล์เพิ่มรูปภาพ</p>
                                <p class="text-[10px] text-slate-400">ขนาดความละเอียดสูงจะถูกย่อไฟล์อัตโนมัติบนอุปกรณ์ทันที เพื่อสุขภาวะอินเทอร์เน็ตที่รวดเร็ว</p>
                            </div>
                            <input id="supervision_photos_files" type="file" accept="image/*" class="hidden" multiple onchange="handleNewPhotoSelection()">
                        </label>
                    </div>
                    
                    <!-- Hidden field to carry compressed image JSON data -->
                    <input type="hidden" name="compressed_photos_json" id="compressed_photos_json" value="">
                </div>
            </div>

            <!-- Portion 5: Evaluator and Status signature configs metadata (Formerly portion 4) -->
            <div class="space-y-4 border-t pt-6">
                <div class="border-b pb-3">
                    <h2 class="text-[#0A3370] font-extrabold text-base">ส่วนที่ 5: ข้อมูลผู้ประเมินและการยืนยันสถานะบันทึก</h2>
                    <p class="text-[11px] text-slate-400 mt-0.5">ระบุชื่อและตำแหน่งวิทยฐานะของท่านให้ถูกต้องและเลือกสถานะนำส่ง</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs font-medium">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600 block">ชื่อผู้นิเทศ / คณะผู้ตรวจประเมิน *</label>
                        <input type="text" name="evaluator_name" required value="<?php echo htmlspecialchars($evaluator_name); ?>" placeholder="ดร. สมชาย สายธรรม" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none text-slate-700">
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600 block">ตำแหน่งวิทยฐานะผู้นิเทศของท่าน *</label>
                        <input type="text" name="evaluator_position" required value="<?php echo htmlspecialchars($evaluator_position); ?>" placeholder="ผู้อำนวยการโรงเรียนฝ่ายวางแผนงาน" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none text-slate-700">
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600 block">สถานะจัดส่งบันทึกเล่มสารบัญ *</label>
                        <div class="flex gap-4 pt-1 select-none">
                            <label class="cursor-pointer flex items-center gap-1.5 font-bold">
                                <input type="radio" name="status" value="draft" <?php if ($status === 'draft') echo 'checked'; ?> class="cursor-pointer text-blue-900 w-4 h-4">
                                <span class="text-amber-700 bg-amber-50 px-2 py-0.5 rounded text-[11px]">แบบร่างบันทึก (Draft)</span>
                            </label>
                            <label class="cursor-pointer flex items-center gap-1.5 font-bold">
                                <input type="radio" name="status" value="submitted" <?php if ($status === 'submitted') echo 'checked'; ?> class="cursor-pointer text-blue-900 w-4 h-4">
                                <span class="text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded text-[11px]">จัดส่งสารบบถาวร (Submitted)</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Form operations submit triggers button -->
            <div class="pt-6 border-t flex flex-col sm:flex-row justify-between items-center gap-3 text-xs leading-relaxed">
                <a href="dashboard.php" class="w-full sm:w-auto p-2.5 px-6 border text-slate-600 rounded-xl hover:bg-slate-50 font-bold block text-center">
                    ⏮️ ยกเลิกและย้อนกลับหน้าแดชบอร์ด
                </a>
                <button type="submit" class="w-full sm:w-auto p-3.5 px-10 bg-[#0A3370] hover:bg-[#07244F] border-b-2 border-slate-900 text-white font-extrabold rounded-xl text-xs shadow-md cursor-pointer text-center flex items-center justify-center gap-1 transition">
                    💾 บันทึกและซิงค์ข้อมูลกับคลัง (Save Supervision Record)
                </button>
            </div>

        </form>

    <?php endif; ?>

    </main>

    <!-- Clean Footer block -->
    <footer class="py-6 mt-12 border-t border-slate-200 bg-white text-center text-[11px] text-slate-400 select-none leading-relaxed">
        <p>ระบบเว็บแอพพลิเคชันเพื่อสุขภาวะและวิทยฐานะประกอบคุณครู สังกัดกระทรวงศึกษาธิการ ประเทศไทย</p>
        <p class="mt-1">พัฒนารหัสด้วยมาตรฐานสูงสุด <strong>PHP 8.2+</strong> & <strong>MySQL 8</strong> อัปเดตฐานข้อมูลไดนามิกอเนกประสงค์</p>
    </footer>

    <!-- Active Rating Calculations scripts -->
    <script>
        // ฟังก์ชันวาดสีขอบวงกลมคะแนน (1-5) ตามการเช็คเลือกและคำนวณคะแนนรวมสะสมเรียลไทม์
        function calculateLiveTotal() {
            let total = 0;
            const radios = document.querySelectorAll('.score_input_radio');
            
            // ดึงกลุ่มรายข้อ 1-20
            const scoreMap = {};
            radios.forEach(radio => {
                const name = radio.name;
                if (radio.checked) {
                    scoreMap[name] = parseInt(radio.value);
                }
            });

            // คำนวณร้อยรวมคะแนนเต็่มจาก 20 ข้อ (แต่ละข้อเต็ม 5 คะแนน -> รวม 100 คะแนนเต็ม)
            let keysCount = 0;
            for (let k in scoreMap) {
                total += scoreMap[k];
                keysCount++;
            }

            // แสดงผลลัพธ์ในกล่อง Live Score Box
            const liveScoreEl = document.getElementById('live_score_box');
            if (liveScoreEl) {
                liveScoreEl.innerText = total;
            }

            // จัดหน้าแรเงาสีวงกลมที่ตรวจเลือกถูกเพื่อให้สวยโดดเด่นสะกดใจผู้ตรวจประเมิน
            radios.forEach(radio => {
                const parent = radio.nextElementSibling;
                if (radio.checked) {
                    parent.classList.remove('bg-white', 'text-slate-550', 'border-slate-200');
                    parent.classList.add('bg-[#0A3370]', 'text-white', 'border-[#0A3370]', 'shadow-xs');
                } else {
                    parent.classList.add('bg-white', 'text-slate-550', 'border-slate-200');
                    parent.classList.remove('bg-[#0A3370]', 'text-white', 'border-[#0A3370]', 'shadow-xs');
                }
            });
        }

        // คลังประจุเก็บรูปภาพแบบสะสมสำหรับฟรอนต์เอนด์ (ป้องกันรูปภาพทับซ้อนเมื่อคลิกเลือกทีละรูป)
        let accumulatedCompressedPhotos = <?php echo json_encode($current_photos); ?>;

        // ฟังก์ชันวาดรูปภาพในคลังสะสมทั้งหมดขึ้นแสดงผลในหน้าเว็บ
        function renderUnifiedGallery() {
            const container = document.getElementById('unified_photo_gallery');
            const hiddenInput = document.getElementById('compressed_photos_json');
            
            if (!container || !hiddenInput) return;

            container.innerHTML = '';

            // ซิงค์สเตทของ JSON ไปเตรียมไว้ในอินพุตสำหรับส่งฟอร์ม PHP
            hiddenInput.value = JSON.stringify(accumulatedCompressedPhotos);

            if (accumulatedCompressedPhotos.length === 0) {
                container.innerHTML = `
                    <div class="col-span-full border border-dashed border-slate-200 rounded-3xl p-6 bg-slate-50/50 text-center text-slate-400 select-none">
                        <span class="text-2xl block mb-1">🖼️</span>
                        <p class="text-xs font-bold">ยังไม่มีการเพิ่มรูปภาพบรรยากาศในระบบขณะนี้</p>
                        <p class="text-[10px] text-slate-400 mt-0.5">(หากกดบันทึกโดยไม่มีรูป แอปพลิเคชันจะใช้รูปภาพจำลองตัวอย่าง 4 ภาพอัตโนมัติในการจัดชุดเพื่อความสมบูรณ์สวยงาม)</p>
                    </div>
                `;
                return;
            }

            accumulatedCompressedPhotos.forEach((photoUrl, idx) => {
                const previewItem = document.createElement('div');
                previewItem.className = 'bg-white border border-slate-200 p-2.5 rounded-2xl relative flex flex-col items-center shadow-2xs transition hover:scale-[1.01]';
                
                previewItem.innerHTML = `
                    <div class="w-full h-24 rounded-xl overflow-hidden shadow-inner bg-slate-50 relative">
                        <img src="${photoUrl}" class="w-full h-full object-cover">
                        <button type="button" onclick="deletePhotoFromPool(${idx})" class="absolute top-1 right-1 bg-rose-600 hover:bg-rose-700 text-white rounded-full w-6 h-6 flex items-center justify-center font-bold text-xs shadow-md transition cursor-pointer select-none border-none" title="ลบภาพนี้">
                            ✕
                        </button>
                    </div>
                    <span class="text-[9px] text-[#0A3370] mt-1.5 font-extrabold px-1 truncate w-full text-center">📌 รูปที่ ${idx + 1}</span>
                `;
                container.appendChild(previewItem);
            });
        }

        // ฟังก์ชันลบรูปภาพที่เลือกออกจากคลังสะสม
        function deletePhotoFromPool(idx) {
            accumulatedCompressedPhotos.splice(idx, 1);
            renderUnifiedGallery();
        }

        // ฟังก์ชันบีบอัดรูปภาพด้วยแคนวาสประสิทธิภาพสูงและประหยัดคืนหน่วยความจำโมบาย
        function handleNewPhotoSelection() {
            const input = document.getElementById('supervision_photos_files');
            if (!input || !input.files || input.files.length === 0) return;

            const selectedFiles = Array.from(input.files);
            const badge = document.getElementById('upload_processing_badge');
            
            if (badge) {
                badge.classList.remove('hidden');
            }

            let pendingCount = selectedFiles.length;

            selectedFiles.forEach(file => {
                // ตรวจเช็คหากรวมของเดิมแล้วเกิน 4 รูป
                if (accumulatedCompressedPhotos.length >= 4) {
                    alert('ขออภัยครับ! ระบบจำกัดให้เพิ่มภาพถ่ายเพื่อความสวยงามจัดเรียบลื่นในหน้าพิมพ์รายงานได้สูงสุด 4 ภาพต่อหนึ่งเล่มนิเทศครับ');
                    pendingCount--;
                    if (pendingCount === 0 && badge) {
                        badge.classList.add('hidden');
                    }
                    return;
                }

                // วิธีการประหยัดหน่วยความจำระดับก้าวหน้าบนมือถือ: เลี่ยงการใช้ FileReader โหลด Base64 ดิบโดยตรง
                // หันมาใช้ URL.createObjectURL กวาดสตรีมสดเข้าสู่ Image ซิกแนลความจำต่ำทันที
                const tempObjectUrl = URL.createObjectURL(file);
                const img = new Image();
                img.onload = function() {
                    const MAX_WIDTH = 800;   // ปรับสัดส่วนเป็น 800px ประหยัดเนื้อที่ใน SQL คอลัมน์อย่างยั่งยืน
                    const MAX_HEIGHT = 800;
                    let width = img.width;
                    let height = img.height;

                    if (width > height) {
                        if (width > MAX_WIDTH) {
                            height *= MAX_WIDTH / width;
                            width = MAX_WIDTH;
                        }
                    } else {
                        if (height > MAX_HEIGHT) {
                            width *= MAX_HEIGHT / height;
                            height = MAX_HEIGHT;
                        }
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;

                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, width, height);

                    // ปรับค่าคุณภาพการบีบอัดเป็น 0.55 (เล็กมากเพียง 20-30KB แต่ความคมชัดรูปภาพยังดีเยี่ยมบนสมาร์ทโฟน)
                    const compressedBase64 = canvas.toDataURL('image/jpeg', 0.55);
                    
                    if (accumulatedCompressedPhotos.length < 4) {
                        accumulatedCompressedPhotos.push(compressedBase64);
                        renderUnifiedGallery();
                    } else {
                        alert('ขออภัยครับ! ระบบจำกัดให้เพิ่มภาพประกอบรายงานได้สูงสุด 4 ภาพครับ');
                    }

                    // ลบล้าง Object URL ออกจากระบบหน่วยความจำเครื่องโทรศัพท์มือถือทันทีเมื่อใช้งานสำเร็จ
                    URL.revokeObjectURL(tempObjectUrl);

                    pendingCount--;
                    if (pendingCount === 0 && badge) {
                        badge.classList.add('hidden');
                    }
                };
                img.onerror = function() {
                    URL.revokeObjectURL(tempObjectUrl);
                    pendingCount--;
                    if (pendingCount === 0 && badge) {
                        badge.classList.add('hidden');
                    }
                };
                img.src = tempObjectUrl;
            });

            // ชะลอการล้างค่าอินพุตมาตรฐานเล็กน้อยเพื่อป้องกันเบราว์เซอร์มือถือหยุดกระบวนการอ่านไฟล์กลางคัน
            setTimeout(() => {
                input.value = "";
            }, 300);
        }

        // รันคำสั่งทันทีหลังเปิดเว็บเพจเสร็จสิ้นความสมบูรณ์
        window.addEventListener('DOMContentLoaded', () => {
            calculateLiveTotal();
            renderUnifiedGallery();
        });
    </script>

    <!-- Spacing at the bottom for mobile navigation bar -->
    <div class="h-20 md:hidden"></div>

    <!-- Beautiful Bottom Navigation Bar for Mobile App Feeling -->
    <div class="fixed bottom-0 inset-x-0 bg-white/95 backdrop-blur-md border-t border-slate-200/80 z-40 md:hidden flex justify-around items-center py-2 px-1 shadow-[0_-4px_24px_rgba(0,0,0,0.06)] rounded-t-[18px]">
        <a href="dashboard.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-slate-400 hover:text-slate-600">
            <span class="text-xl">🏠</span>
            <span class="text-[9px]">หน้าหลัก</span>
        </a>
        <a href="supervision.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-[#1565C0] font-bold">
            <span class="text-xl">📝</span>
            <span class="text-[9px]">นิเทศ</span>
        </a>
        <a href="comparison.php" class="flex flex-col items-center gap-1 flex-1 text-center py-1 transition-all text-slate-400 hover:text-slate-600">
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

</body>
</html>

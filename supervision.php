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

// ข้อมูลหลักฟอร์มเซ็ตมาตรฐาน
$teacher_id = '';
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

// โหลดทะเบียนคุณครู & ปีการศึกษามาทำ Dropdown
$dropdown_teachers = $pdo->query("SELECT * FROM teachers ORDER BY teacher_id ASC")->fetchAll();
$dropdown_years = $pdo->query("SELECT * FROM academic_years ORDER BY year DESC, semester DESC")->fetchAll();
$dropdown_classrooms = $pdo->query("SELECT * FROM classrooms ORDER BY class_name ASC")->fetchAll();

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

    // ประมวลผลรูปภาพนิเทศชั้นเรียน
    $uploaded_photos = [];
    if ($edit_id) {
        $stmt_photo = $pdo->prepare("SELECT photos_json FROM supervisions WHERE record_id = ?");
        $stmt_photo->execute([$edit_id]);
        $existing_photos_str = $stmt_photo->fetchColumn();
        $uploaded_photos = json_decode($existing_photos_str ?: '[]', true) ?: [];
    }

    // ล้างรูปภาพเดิมที่เคยอัพโหลดไว้ หากระบุ
    if (isset($_POST['clear_existing_photos']) && $_POST['clear_existing_photos'] === '1') {
        $uploaded_photos = [];
    }

    // ตรวจสอบรูปเดี่ยวที่จะเลือกลบ
    if (isset($_POST['remove_photo_index']) && is_array($_POST['remove_photo_index'])) {
        foreach ($_POST['remove_photo_index'] as $remove_idx) {
            $remove_idx = (int)$remove_idx;
            if (isset($uploaded_photos[$remove_idx])) {
                unset($uploaded_photos[$remove_idx]);
            }
        }
        $uploaded_photos = array_values($uploaded_photos); // จัดเรียง index การวนลูปใหม่
    }

    // เพิ่มรูปภาพอัปโหลดใหม่ (จากแบบคำขอย่อขนาดประสิทธิภาพสูงบนเบราว์เซอร์)
    if (!empty($_POST['compressed_photos_json'])) {
        $client_photos = json_decode($_POST['compressed_photos_json'], true);
        if (is_array($client_photos)) {
            foreach ($client_photos as $b64) {
                if (strpos($b64, 'data:image/') === 0) {
                    $uploaded_photos[] = $b64;
                }
            }
        }
    }

    // เพิ่มรูปภาพอัปโหลดใหม่ (กรณี fallback ของไฟล์อัปโหลดมาตรฐาน)
    if (isset($_FILES['supervision_photos_files'])) {
        $files = $_FILES['supervision_photos_files'];
        if (is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp_name = $files['tmp_name'][$i];
                    $type = $files['type'][$i];
                    $data = file_get_contents($tmp_name);
                    $base64 = 'data:' . $type . ';base64,' . base64_encode($data);
                    $uploaded_photos[] = $base64;
                }
            }
        }
    }

    // จำกัดจำนวนรูปภาพประกอบเล่มนิเทศไว้ไม่เกิน 4 รูปสูงสุด เพื่อความสวยงามในการจัดพิมพ์ 2 คอลัมน์
    $uploaded_photos = array_slice($uploaded_photos, 0, 4);

    // แปลงรูปภาพทั้งหมดเป็น JSON String
    if (empty($uploaded_photos) && !$edit_id && (!isset($_POST['clear_existing_photos']) || $_POST['clear_existing_photos'] !== '1')) {
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
            $get_y = $pdo->prepare("SELECT year FROM academic_years WHERE year_id = ?");
            $get_y->execute([$year_id]);
            $year_val = $get_y->fetchColumn() ?: '2569';

            // นับเลขลำดับเพื่อความสอดคล้อง
            $curr_max_num = 0;
            $all_recs = $pdo->query("SELECT record_id FROM supervisions")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($all_recs as $r_id) {
                // รูปแบบ REC-YYYY-XXX
                if (preg_match('/REC-\d+-(\d+)/', $r_id, $matches)) {
                    $num = (int)$matches[1];
                    if ($num > $curr_max_num) {
                        $curr_max_num = $num;
                    }
                }
            }
            $next_num = $curr_max_num + 1;
            $new_record_id = "REC-{$year_val}-" . str_pad($next_num, 3, '0', STR_PAD_LEFT);

            $query = "
                INSERT INTO supervisions (
                    record_id, teacher_id, year_id, class_name, subject_name, date_string,
                    scores_json, comments_strengths, comments_suggestions, comments_development,
                    evaluator_name, evaluator_position, status, photos_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $new_record_id, $teacher_id, $year_id, $class_name, $subject_name, $date_string,
                $scores_json_str, $comments_strengths, $comments_suggestions, $comments_development,
                $evaluator_name, $evaluator_position, $status, $photos_json_saved
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
    <title>บันทึกคะแนนและประเมินนิเทศคุณครู - โรงเรียนบ้านหนองหว้า</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', 'Inter', sans-serif; } </style>
</head>
<body class="bg-[#FAF8F5] min-h-screen text-slate-900 duration-200">

    <!-- Navbar Header -->
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
            <a href="supervision.php" class="px-4 py-2 bg-[#0A3370] text-white rounded-xl shadow-xs font-bold flex items-center gap-1.5">
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

        <!-- Evaluation Form container -->
        <form method="POST" enctype="multipart/form-data" class="bg-white border border-slate-200 p-6 sm:p-8 rounded-3xl shadow-sm space-y-8">
            <input type="hidden" name="action_save_supervision" value="1">

            <!-- Phase 1: General Header details metadata -->
            <div class="space-y-4">
                <div class="border-b pb-3">
                    <h2 class="text-[#0A3370] font-extrabold text-base">ส่วนที่ 1: ข้อมูลปฐมภูมิประกอบการนิเทศชั้นเรียน</h2>
                    <p class="text-[11px] text-slate-400 mt-0.5">กรุณาเลือกชื่อครูวิทยฐานะ และระบุรายละเอียดคาบวิชาวิชาการให้ถูกต้องเพื่อใช้สืบค้นจับคู่ในระยะยาว</p>
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
                        <label class="text-xs font-bold text-slate-600 block">จุดเด่น/พฤติกรรมดีที่สุดของคุณครู (Strengths)</label>
                        <textarea name="comments_strengths" placeholder="คุณครูวิเคราะห์แผนบทเรียน และกระตุ้นเด็กนักเรียนผ่าน Active Learning ครบครัน..." rows="4" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none text-slate-700"><?php echo htmlspecialchars($comments_strengths); ?></textarea>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600 block">ข้อเสนอแนะเพื่อการจัดเรียนรู้ในอนาคต (Suggestions)</label>
                        <textarea name="comments_suggestions" placeholder="ควรเพิ่มเทคโนโลยีอินเทอร์เน็ตประยุกต์มาสุ่มเด็กนักเรียนเพิ่มเติม..." rows="4" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none text-slate-700"><?php echo htmlspecialchars($comments_suggestions); ?></textarea>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-600 block">แนวทางการพัฒนาร่วมกัน / ผลลัพธ์คาดคะเน (Action Plan)</label>
                        <textarea name="comments_development" placeholder="ตกลงร่วมกันพัฒนานวัตกรรมสื่อสื่อประกอบการสอนและการติดตามแผนรายเทอม..." rows="4" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none text-slate-700"><?php echo htmlspecialchars($comments_development); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Portion 4: Classroom Supervision Photos Upload -->
            <div class="space-y-4 border-t pt-6">
                <div class="border-b pb-3">
                    <h2 class="text-[#0A3370] font-extrabold text-base">ส่วนที่ 4: อัปโหลดรูปภาพบรรยากาศการจัดการเรียนรู้ในชั้นเรียน</h2>
                    <p class="text-[11px] text-slate-400 mt-0.5">แนบหลักฐานภาพถ่ายกิจกรรมในชั้นเรียน แผนการเรียนรู้แบบ Active Learning เพื่อนำไปแสดงผลที่ท้ายรายงานนิเทศ (พิมพ์แยกหน้าได้)</p>
                </div>

                <!-- Existing Photos Manager -->
                <?php if (!empty($current_photos)): ?>
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-700 block">📸 ภาพถ่ายบรรยากาศในสารบบข้อมูลขณะนี้ (จำนวน <?php echo count($current_photos); ?> ภาพ):</label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <?php foreach ($current_photos as $idx => $photo_url): ?>
                                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-2 relative flex flex-col items-center">
                                    <div class="w-full h-24 rounded-xl overflow-hidden shadow-inner bg-slate-100">
                                        <img src="<?php echo htmlspecialchars($photo_url); ?>" class="w-full h-full object-cover">
                                    </div>
                                    <div class="mt-2 flex items-center gap-1.5 self-start select-none">
                                        <label class="inline-flex items-center gap-1 cursor-pointer text-red-650 hover:text-red-800">
                                            <input type="checkbox" name="remove_photo_index[]" value="<?php echo $idx; ?>" class="rounded text-red-600 cursor-pointer border-slate-300">
                                            <span class="text-[10px] font-bold">🗑️ ติ๊กเพื่อลบรูปนี้</span>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="pt-1.5 flex items-center gap-2">
                            <label class="inline-flex items-center gap-1.5 text-xs text-rose-750 font-bold cursor-pointer bg-rose-50 border border-rose-200 rounded-xl px-3 py-1.5 hover:bg-rose-100 transition shadow-2xs">
                                <input type="checkbox" name="clear_existing_photos" value="1" class="rounded text-rose-700 cursor-pointer border-slate-300">
                                <span>❌ ลบรูปภาพประวัติเดิมทั้งหมดและเริ่มใหม่</span>
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- New Upload Field -->
                <div class="bg-teal-50/40 border border-dashed border-[#0D9488]/30 p-5 rounded-2xl space-y-3">
                    <label class="text-xs font-bold text-[#0D9488] block">📤 เลือกอัปโหลดรูปภาพใหม่เพิ่มเติมประกอบเล่ม (เลือกได้สูงสุด 4 ภาพ, รองรับการย่อขนาดไฟล์อัตโนมัติ):</label>
                    <div class="flex items-center justify-center w-full">
                        <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-[#0D9488]/20 border-dashed rounded-xl cursor-pointer bg-white hover:bg-teal-50/20 transition">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6 text-center px-4">
                                <span class="text-3xl mb-1">🖼️</span>
                                <p class="mb-1 text-xs text-[#0D9488] font-bold">คลิกที่นี่เพื่อเปิดแกลเลอรี่/เลือกไฟล์รูปภาพ</p>
                                <p class="text-[10px] text-slate-400">ระบบจะทำการสเกลย่อยขนาดไฟล์ภาพที่มีความละเอียดสูงให้อัตโนมัติในเบราว์เซอร์เพื่อความรวดเร็วในการส่งข้อมูล</p>
                            </div>
                            <input id="supervision_photos_files" name="supervision_photos_files[]" type="file" accept="image/*" class="hidden" multiple onchange="previewAndCompressImages()">
                        </label>
                    </div>
                    
                    <!-- Hidden field to carry compressed image JSON data -->
                    <input type="hidden" name="compressed_photos_json" id="compressed_photos_json" value="">

                    <!-- Selected Files Preview Container -->
                    <div id="new_preview_container" class="grid grid-cols-2 sm:grid-cols-4 gap-4 hidden mt-3">
                        <!-- Dynamic file previews will be injected here -->
                    </div>
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

        // ฟังก์ชันพรีวิวและย่อขนาดภาพถ่ายอัตโนมัติบนเบราว์เซอร์เพื่อความรวดเร็วและประหยัดพื้นที่คลังส่งมอบ
        function previewAndCompressImages() {
            const input = document.getElementById('supervision_photos_files');
            const container = document.getElementById('new_preview_container');
            const hiddenInput = document.getElementById('compressed_photos_json');
            
            if (!input || !container || !hiddenInput) return;

            container.innerHTML = '';
            hiddenInput.value = '';

            const files = Array.from(input.files || []).slice(0, 4); // สูงสุด 4 ภาพ
            if (files.length === 0) {
                container.classList.add('hidden');
                return;
            }

            container.classList.remove('hidden');

            const compressedResults = [];
            let processedFilesCount = 0;

            // หัวข้อแถบแสดงสถานะ
            const titleDiv = document.createElement('div');
            titleDiv.className = 'col-span-full border-b pb-1 mb-1 flex items-center justify-between text-[11px]';
            titleDiv.innerHTML = `
                <span class="font-extrabold text-teal-800">📸 กำลังย่อขนาดบรรจุไฟล์รูปภาพด้วยเบราว์เซอร์...</span>
                <span id="compress_status_badge" class="bg-amber-100 text-amber-800 font-bold px-1.5 py-0.5 rounded text-[9px]">ประมวลผลสำเร็จ 0/${files.length}</span>
            `;
            container.appendChild(titleDiv);

            files.forEach((file, index) => {
                const reader = new FileReader();
                reader.readAsDataURL(file);
                reader.onload = function(event) {
                    const img = new Image();
                    img.src = event.target.result;
                    img.onload = function() {
                        // กำหนดขนาดสเกลสูงสุด 1024px
                        const MAX_WIDTH = 1024;
                        const MAX_HEIGHT = 1024;
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

                        // สร้าง Canvas มวลชนเพื่อสเกลไฟล์รูปภาพสัดส่วนเดิม
                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;

                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, width, height);

                        // ทำการบีบอัดลงค่า JPEG ที่ 0.70 คุณภาพสูงแต่น้ำหนักเบาประหยัดแรง
                        const compressedBase64 = canvas.toDataURL('image/jpeg', 0.70);
                        compressedResults[index] = compressedBase64;
                        processedFilesCount++;

                        // แขวนรูปภาพตัวอย่างที่บีบอัดลงเพจ
                        const previewItem = document.createElement('div');
                        previewItem.className = 'bg-white border border-[#0D9488]/30 p-2 rounded-2xl relative flex flex-col items-center shadow-2xs transition hover:scale-[1.01]';
                        previewItem.innerHTML = `
                            <div class="w-full h-24 rounded-xl overflow-hidden shadow-inner bg-slate-50 relative">
                                <img src="${compressedBase64}" class="w-full h-full object-cover">
                                <div class="absolute bottom-1 right-1 bg-teal-600/90 text-white font-bold p-0.5 px-1 pb-0.5 text-[8px] rounded-sm">ย่อเสร็จ</div>
                            </div>
                            <span class="text-[9px] text-slate-500 mt-1.5 truncate w-full text-center font-bold px-1">${file.name}</span>
                        `;
                        container.appendChild(previewItem);

                        // รันส่งมอบเมื่อเรียบร้อยครบหน่วย
                        if (processedFilesCount === files.length) {
                            const finalBase64Array = compressedResults.filter(Boolean);
                            hiddenInput.value = JSON.stringify(finalBase64Array);
                            
                            const badge = document.getElementById('compress_status_badge');
                            if (badge) {
                                badge.className = "bg-teal-100 text-teal-800 font-bold px-1.5 py-0.5 rounded text-[9px]";
                                badge.innerText = `ย่อขนาดลุล่วงครบถ้วน ${finalBase64Array.length} ภาพ`;
                            }
                        } else {
                            const badge = document.getElementById('compress_status_badge');
                            if (badge) {
                                badge.innerText = `บีบอัดสำเร็จแล้ว ${processedFilesCount}/${files.length} ภาพ`;
                            }
                        }
                    };
                };
            });
        }

        // รันคำสั่งทันทีหลังเปิดเว็บเพจเสร็จสิ้นความสมบูรณ์
        window.addEventListener('DOMContentLoaded', () => {
            calculateLiveTotal();
        });
    </script>

</body>
</html>

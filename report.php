<?php
// ==========================================
// report.php - รายงานนิเทศรายคาบสอนส่วนบุคคล (Optimized for A4 Printing)
// ==========================================

require_once 'config.php';

// ความปลอดภัยสิทธิ์บุคคลที่ประเมิน
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$user_role = $_SESSION['role'] ?? 'teacher';
$my_teacher_id = $_SESSION['teacher_id'] ?? '';

if ($user_role === 'teacher' && empty($my_teacher_id) && isset($_SESSION['username'])) {
    $stmt_tid = $pdo->prepare("SELECT teacher_id FROM teachers WHERE username = ?");
    $stmt_tid->execute([$_SESSION['username']]);
    $my_teacher_id = $stmt_tid->fetchColumn() ?: '';
    $_SESSION['teacher_id'] = $my_teacher_id;
}

$record_id = $_GET['id'] ?? null;

if (!$record_id) {
    echo "<h1>ไม่พบไอดีบันทึกเป้าหมายการนิเทศประเมินผล</h1>";
    exit;
}

// โหลดฐานข้อมูลหลักพร้อมรายละเอียดครูและปีการศึกษา
$stmt = $pdo->prepare("
    SELECT s.*, t.teacher_name, t.position, t.subject_group, t.photo_path, y.year, y.semester
    FROM supervisions s
    JOIN teachers t ON s.teacher_id = t.teacher_id
    JOIN academic_years y ON s.year_id = y.year_id
    WHERE s.record_id = ?
");
$stmt->execute([$record_id]);
$rec = $stmt->fetch();

if (!$rec) {
    echo "<h1>ไม่พบประวัตินิเทศที่ตรงกับกุญแจหลักในระบบเซิร์ฟเวอร์</h1>";
    exit;
}

// ตรวจสอบความปลอดภัยคุณครูมองเห็นเฉพาะรายงานตนเอง
if ($user_role === 'teacher' && $rec['teacher_id'] !== $my_teacher_id) {
    echo "<h1>ขออภัย สิทธิบัญชีคุณครูของท่านไม่อนุญาตให้เข้ามองรายงานประเมินภายนอกบุคคล</h1>";
    exit;
}

$scores = json_decode($rec['scores_json'], true) ?: [];
$sum = array_sum($scores);
$pct = ($sum / 100) * 100;

// เกณฑ์ขั้นคุณภาพ
$grade = 'ต้องปรับปรุงเร่งด่วน';
$grade_desc = 'ระดับคะแนนเฉลี่ยร้อยละต่ำกว่า 60 ควรได้รับการแนะนำปรึกษาเพื่อซ่อมปรับปรุงคุณภาพวิชาการเรียนการสอน';
if ($pct >= 90) {
    $grade = 'ดีเยี่ยม (Excellent)';
    $grade_desc = 'มีโครงสร้างการจัดการเรียนเชิงรุกอย่างเพียบพร้อม กระตุ้นความสนใจใฝ่รู้ ความพร้อมในการวัดผลประเมินครบนิทรรศการชั้นเรียน';
} else if ($pct >= 80) {
    $grade = 'ดีมาก (Very Good)';
    $grade_desc = 'แผนการจัดสภาพบรรยากาศในระดับยอดเยี่ยม มีเครื่องมือสอนและเทคโนโลยีรอบด้าน ควบคุมชั้นเรียนปลอดภัยไร้กังวล';
} else if ($pct >= 70) {
    $grade = 'ดี (Good)';
    $grade_desc = 'มีแผนงานเรียบร้อย บรรลุตามผลลัพธ์พึงประสงค์กระทรวงศึกษาธิการ';
} else if ($pct >= 60) {
    $grade = 'พอใช้ (Fair)';
    $grade_desc = 'พอเป็นไปตามเป้าหมายหลัก ควรเพิ่มนวัตกรรมทางเลือกและข้อตกลงเพื่อพัฒนาครูเพิ่มส่งกำลังเสริมกระบวนการสอน';
}

// โหลดข้อเกณฑ์ทั้ง 20 เกณฑ์
$evaluation_items = $pdo->query("SELECT * FROM evaluation_items ORDER BY CAST(item_id AS UNSIGNED) ASC")->fetchAll();
$photos = json_decode($rec['photos_json'], true) ?: [];

// โหลดข้อมูลตราโลโก้และชื่อโรงเรียน
$stmt_logo = $pdo->prepare("SELECT setting_value FROM school_settings WHERE setting_key = 'school_logo'");
$stmt_logo->execute();
$school_logo = $stmt_logo->fetchColumn() ?: '';

$stmt_sname = $pdo->prepare("SELECT setting_value FROM school_settings WHERE setting_key = 'school_name'");
$stmt_sname->execute();
$school_name = $stmt_sname->fetchColumn() ?: 'โรงเรียนบ้านหนองหว้า สังกัดสำนักงานเขตพื้นที่การศึกษาประถมศึกษา';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เอกสารประเมินนิเทศชั้นเรียน <?php echo htmlspecialchars($rec['record_id']); ?> - คลังวิทยฐานะโรงเรียน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', 'Inter', sans-serif; }
        
        @page {
            size: A4 portrait;
            margin: 15mm;
        }
        
        /* สไตล์ปรับขนาดเพื่อจัดหน้ากระดาษ A4 สำหรับพิมพ์ */
        @media print {
            .no-print { display: none !important; }
            body { background: white; color: black; font-size: 11px; padding: 0 !important; margin: 0 !important; }
            .a4-container { width: 100%; border: none; padding: 0 !important; margin: 0 !important; box-shadow: none !important; }
            .avoid-break { page-break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen py-8 text-xs leading-relaxed text-slate-800">

    <!-- Action Bar (Hidden during Print) -->
    <div class="no-print max-w-4xl mx-auto mb-6 bg-white border border-slate-200 p-5 rounded-2xl shadow-sm space-y-3">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-3">
                <span class="text-2xl">📝</span>
                <div>
                    <span class="font-extrabold block text-sm text-slate-800">เอกสารรายงานสรุปผลการประเมินนิเทศ (หน้าจัดเตรียมลายลักษณ์)</span>
                    <span class="text-[10px] text-slate-400 block mt-0.5">คุณครูสามารถพิมพ์หรือบันทึกเก็บเป็นเอกสาร PDF เพื่อใช้เข้าเล่มประเมินวิทยฐานะได้ทันที</span>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="dashboard.php" class="px-4 py-2 bg-slate-150 hover:bg-slate-200 text-slate-700 font-bold rounded-xl whitespace-nowrap text-center text-xs">
                    ⏮️ กลับสู่แดชบอร์ด
                </a>
                <button onclick="window.print()" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold rounded-xl shadow-md transition whitespace-nowrap flex items-center gap-1.5 cursor-pointer text-xs">
                    🖨️ สั่งพิมพ์/บันทึก PDF (A4)
                </button>
            </div>
        </div>
        <div class="bg-blue-50/70 border border-blue-200 p-3 rounded-xl text-[10.5px] text-blue-900 leading-relaxed font-semibold">
            💡 <strong>คำแนะนำเพิ่มเติมในการบันทึกเป็นไฟล์ PDF:</strong> เมื่อคลิกปุ่มสั่งพิมพ์ ให้เลือก <strong>"ปลายทาง" (Destination)</strong> เป็น <strong>"บันทึกเป็น PDF" (Save as PDF)</strong> จากนั้นในแถบการตั้งค่าเพิ่มเติม (More settings) ให้ติ๊กเลือกถูกที่ <strong>"กราฟิกพื้นหลัง" (Background graphics)</strong> เพื่อให้สีสันกรอบ ตาราง และลายเส้นความก้าวหน้าแสดงผลอย่างครบถ้วนสวยงามเสมือนจริง
        </div>
    </div>

    <!-- Official A4 Sheet Simulation Card -->
    <div class="a4-container max-w-4xl mx-auto bg-white border border-slate-300 p-8 sm:p-12 shadow-md rounded-lg space-y-8 select-none">
        
        <!-- Institutional crest ornament & header title -->
        <div class="text-center space-y-2 border-b-2 border-[#0A3370] pb-5 flex flex-col items-center justify-center">
            <?php if (!empty($school_logo)): ?>
                <div class="w-20 h-20 md:w-24 md:h-24 mb-1">
                    <img referrerPolicy="no-referrer" src="<?php echo $school_logo; ?>" alt="โลโก้โรงเรียน" class="w-full h-full object-contain mx-auto">
                </div>
            <?php else: ?>
                <div class="text-4xl select-none mb-1">🔱</div>
            <?php endif; ?>
            <h1 class="text-lg font-extrabold text-[#0A3370]">แบบรายงานสรุปผลการประเมินนิเทศการจัดการเรียนรู้</h1>
            <p class="text-[11px] font-bold text-amber-600 uppercase tracking-widest leading-relaxed"><?php echo htmlspecialchars($school_name); ?></p>
            <p class="text-[10px] font-mono text-slate-400">เลขอ้างอิงทำเนียบทะเบียน: <?php echo htmlspecialchars($rec['record_id']); ?> — วันที่พิมพ์ประเมิน: <?php echo htmlspecialchars($rec['date_string']); ?></p>
        </div>

        <!-- Section 1: Detailed Metadata Grid -->
        <div class="space-y-3">
            <h3 class="font-extrabold text-[#0A3370] text-[13px] border-b pb-1">ตอนที่ 1 ข้อมูลผู้รับการประเมิน</h3>
            <div class="flex flex-col sm:flex-row gap-5 items-start">
                <!-- ส่วนแสดงภาพระบุประจำตัวของคุณครูผู้รับประกันนิเทศ -->
                <div class="w-20 h-24 bg-slate-50 border border-slate-200 rounded-xl overflow-hidden flex-shrink-0 flex items-center justify-center shadow-inner relative">
                    <?php if (!empty($rec['photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($rec['photo_path']); ?>" alt="รูปคุณครูผู้รับนิเทศ" class="w-full h-full object-cover">
                    <?php else: ?>
                        <div class="text-center">
                            <span class="text-2xl block">👩‍🏫</span>
                            <span class="text-[8px] text-slate-400 block mt-1">ไม่มีรูปภาพ</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 leading-relaxed font-medium w-full">
                    <div>
                        <span class="text-slate-400 block">ครูผู้เข้ารับนิเทศ:</span>
                        <strong class="text-slate-800 text-[12.5px]"><?php echo htmlspecialchars($rec['teacher_name']); ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">ตำแหน่งงานวิทยฐานะ:</span>
                        <strong class="text-slate-800"><?php echo htmlspecialchars($rec['position']); ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">สังกัดสาระการเรียนรู้:</span>
                        <strong class="text-slate-800"><?php echo htmlspecialchars($rec['subject_group']); ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">รหัสภาคเรียนที่ใช้:</span>
                        <strong class="text-[#0A3370]">เทอม <?php echo htmlspecialchars($rec['semester']); ?> ปีการศึกษา <?php echo htmlspecialchars($rec['year']); ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">วิชาเรียนสังเกตการณ์:</span>
                        <strong class="text-slate-850"><?php echo htmlspecialchars($rec['subject_name']); ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">ชั้นเรียนประสงค์:</span>
                        <strong class="text-slate-850">ชั้น <?php echo htmlspecialchars($rec['class_name']); ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">ผู้นิเทศควบคุม:</span>
                        <strong class="text-slate-850"><?php echo htmlspecialchars($rec['evaluator_name']); ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">วิทยฐานะผู้นิเทศ:</span>
                        <strong class="text-slate-850"><?php echo htmlspecialchars($rec['evaluator_position']); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Core Matrix Table items list scores -->
        <div class="space-y-3 avoid-break">
            <h3 class="font-extrabold text-[#0A3370] text-[13px] border-b pb-1">ตอนที่ 2 ผลการบันทึกการประเมินการนิเทศ</h3>
            <table class="w-full text-left border-collapse border border-slate-200">
                <thead>
                    <tr class="bg-slate-50 text-[10px] text-slate-500 font-bold">
                        <th class="p-2 border border-slate-200 w-10 text-center">ข้อที่</th>
                        <th class="p-2 border border-slate-250">หัวข้อรายละเอียดการสังเกตและประเมินพฤติกรรมในคาบเรียน</th>
                        <th class="p-2 border border-slate-200 w-16 text-center">เต็ม</th>
                        <th class="p-2 border border-slate-200 w-20 text-center text-[#0A3370]">คะแนนที่ได้</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-150">
                    <?php
                    $last_c = '';
                    foreach ($evaluation_items as $itm):
                        $c = $itm['category'];
                        if ($c !== $last_c) {
                            $last_c = $c;
                            echo "<tr class='bg-slate-50/50 font-extrabold text-[#0A3370]'><td colspan='4' class='p-2 px-3 border border-slate-200'>• " . htmlspecialchars($c) . "</td></tr>";
                        }
                        $it_id = $itm['item_id'];
                        $item_score = $scores[(int)$it_id] ?? 5;
                    ?>
                        <tr class="hover:bg-slate-50/30">
                            <td class="p-2 border border-slate-200 text-center font-bold font-mono text-slate-500"><?php echo $it_id; ?></td>
                            <td class="p-2 border border-slate-200 font-medium"><?php echo htmlspecialchars($itm['item_name']); ?></td>
                            <td class="p-2 border border-slate-200 text-center font-mono text-slate-400">5</td>
                            <td class="p-2 border border-slate-200 text-center font-mono font-extrabold text-[12px] text-blue-950 bg-blue-50/10"><?php echo $item_score; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Section 3: Summary aggregates KPI calculations -->
        <div class="p-5 bg-slate-50/50 border border-slate-200 rounded-2xl grid grid-cols-1 md:grid-cols-10 gap-4 avoid-break">
            <div class="md:col-span-3 text-center flex flex-col items-center justify-center p-2 border-r border-dashed border-slate-300">
                <span class="text-slate-400 uppercase font-bold text-[9px] block tracking-wide">คะแนนรวมความสัมฤทธิ์</span>
                <div class="text-3xl font-extrabold text-[#0A3370] font-mono tracking-tight mt-1"><?php echo $sum; ?> / 100</div>
                <span class="text-[9px] text-[#F59E0B] font-bold block mt-1">คะแนนเฉลี่ยคิดเป็นร้อยละ <?php echo $pct; ?>%</span>
            </div>
            
            <div class="md:col-span-3 text-center flex flex-col items-center justify-center p-2 border-r border-dashed border-slate-300">
                <span class="text-slate-400 uppercase font-bold text-[9px] block tracking-wide">ระดับชั้นเกรดคุณภาพ</span>
                <div class="text-sm font-black text-emerald-700 bg-emerald-50 px-4 py-1.5 rounded-full border border-emerald-200 mt-2 font-bold"><?php echo $grade; ?></div>
            </div>

            <div class="md:col-span-4 flex flex-col justify-center p-2 leading-relaxed">
                <span class="text-slate-400 font-bold text-[9px] block uppercase tracking-wide">คำนิยามและการแปลค่าระเบียน:</span>
                <p class="text-[10px] text-slate-500 mt-1"><?php echo $grade_desc; ?></p>
            </div>
        </div>

        <!-- Section 4: Qualitative commentaries details avoid break during PDF print sheets -->
        <div class="space-y-4 avoid-break">
            <h3 class="font-extrabold text-[#0A3370] text-[13px] border-b pb-1">ตอนที่ 3 คำแนะนำเพิ่มเติมเพื่อการพัฒนา</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 leading-relaxed font-semibold">
                
                <div class="space-y-1 bg-slate-50/30 p-3 rounded-xl border">
                    <span class="text-emerald-805 font-bold block">🌟 จุดเด่นและแนวทางปฏิบัติสำคัญ (Strengths):</span>
                    <p class="text-[11.5px] font-medium text-slate-650 mt-1 whitespace-pre-wrap"><?php echo htmlspecialchars($rec['comments_strengths'] ?: 'ไม่ระบุรหัสบันทึกเชิงตัวอักษร'); ?></p>
                </div>

                <div class="space-y-1 bg-amber-50/10 p-3 rounded-xl border">
                    <span class="text-amber-700 font-bold block">💡 คำแนะนำเพื่อความเติบโตทางวิชาชีพ (Suggestions):</span>
                    <p class="text-[11.5px] font-medium text-slate-650 mt-1 whitespace-pre-wrap"><?php echo htmlspecialchars($rec['comments_suggestions'] ?: 'ไม่ระบุรหัสแนะนำความเห็นเพิ่ม'); ?></p>
                </div>

                <div class="space-y-1 bg-blue-50/10 p-3 rounded-xl border">
                    <span class="text-blue-900 font-bold block">🤝 แผนปฏิบัติงานพัฒนากิจกรรมประยุกต์ร่วมกัน (Action Plan):</span>
                    <p class="text-[11.5px] font-medium text-slate-650 mt-1 whitespace-pre-wrap"><?php echo htmlspecialchars($rec['comments_development'] ?: 'ไม่ระบุข้อตกลงตกลงใจร่วมกัน'); ?></p>
                </div>

            </div>
        </div>

        <!-- Section 5: Photo evidences simulation -->
        <?php if (!empty($photos)): ?>
            <div class="space-y-3 avoid-break">
                <h3 class="font-extrabold text-[#0A3370] text-[13px] border-b pb-1">ตอนที่ 4: ภาพบรรยากาศประกอบการนิเทศสังเกตการณ์ในชั้นเรียน</h3>
                <div class="grid grid-cols-2 gap-4">
                    <?php foreach ($photos as $url): ?>
                        <div class="rounded-xl overflow-hidden border border-slate-205 shadow-2xs h-40">
                            <img src="<?php echo htmlspecialchars($url); ?>" alt="นิเทศชั้นเรียนเรียนร่วมกันโรงเรียน" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Section 6: Institutional Signatures Fields (Dual sign lines block) -->
        <div class="pt-8 grid grid-cols-2 gap-8 avoid-break text-center font-semibold text-slate-700">
            <!-- Left evaluated Teacher column sign -->
            <div class="space-y-10">
                <p>ลงชื่อ................................................................... ผู้รับการประเมินนิเทศ<br>( <?php echo htmlspecialchars($rec['teacher_name']); ?> )</p>
                <p class="text-[10px] text-slate-400 font-medium">ตำแหน่ง: <?php echo htmlspecialchars($rec['position']); ?></p>
            </div>

            <!-- Right Evaluator column sign (Director / Supervisor) -->
            <div class="space-y-10">
                <p>ลงชื่อ................................................................... ผู้นิเทศ<br>( <?php echo htmlspecialchars($rec['evaluator_name']); ?> )</p>
                <p class="text-[10px] text-slate-400 font-medium">ตำแหน่ง: <?php echo htmlspecialchars($rec['evaluator_position']); ?></p>
            </div>
        </div>

    </div>

    <!-- Minimal Print Footer Block -->
    <footer class="py-12 mt-12 text-center text-[11px] text-slate-400 select-none no-print">
        <p>เอกสารถือเป็นตราสัญลักษณ์สถิติลายลักษณ์ของหน่วยประเมินนิเทศครูโรงเรียนบ้านหนองหว้า</p>
    </footer>

</body>
</html>

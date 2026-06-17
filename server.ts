import express from "express";
import path from "path";
import fs from "fs";
import { createServer as createViteServer } from "vite";
import { User, Teacher, AcademicYear, Observation, EvaluationItem, ObservationScore, ObservationPhoto } from "./src/types.js";

const app = express();
const PORT = 3000;

// Body parser
app.use(express.json({ limit: "50mb" }));
app.use(express.urlencoded({ extended: true, limit: "50mb" }));

// Establish directories
const DATA_DIR = path.join(process.cwd(), "data");
if (!fs.existsSync(DATA_DIR)) {
  fs.mkdirSync(DATA_DIR);
}
const DB_FILE = path.join(DATA_DIR, "database.json");

// Define Default Evaluation Items
const DEFAULT_EVAL_ITEMS: EvaluationItem[] = [
  // 1. สภาพห้องเรียน
  { item_id: 1, category: "1. สภาพห้องเรียน", item_name: "มีป้ายนิเทศเพื่อเผยแพร่ข่าวสารและความรู้ต่าง ๆ", max_score: 5 },
  { item_id: 2, category: "1. สภาพห้องเรียน", item_name: "มีป้ายแสดงข้อมูลสถิติของห้องเรียนที่เป็นปัจจุบัน", max_score: 5 },
  { item_id: 3, category: "1. สภาพห้องเรียน", item_name: "มีสัญลักษณ์ชาติ ศาสนา พระมหากษัตริย์", max_score: 5 },
  { item_id: 4, category: "1. สภาพห้องเรียน", item_name: "มีการแสดงผลงานนักเรียน", max_score: 5 },
  { item_id: 5, category: "1. สภาพห้องเรียน", item_name: "บรรยากาศในห้องเรียนเอื้อต่อการเรียนรู้", max_score: 5 },
  // 2. การบริหารจัดการห้องเรียน
  { item_id: 6, category: "2. การบริหารจัดการห้องเรียน", item_name: "ใช้การเสริมแรงเชิงบวกในการจัดการเรียนรู้ (Positive Reinforcement)", max_score: 5 },
  { item_id: 7, category: "2. การบริหารจัดการห้องเรียน", item_name: "ใช้วิธีการทำงานเป็นกลุ่ม (Working in Groups)", max_score: 5 },
  { item_id: 8, category: "2. การบริหารจัดการห้องเรียน", item_name: "นักเรียนทุกคนมีส่วนร่วมในการจัดการเรียนรู้ (Involve Everyone)", max_score: 5 },
  // 3. ครูผู้สอน
  { item_id: 9, category: "3. ครูผู้สอน", item_name: "มีการจัดทำแผนการจัดการเรียนรู้", max_score: 5 },
  { item_id: 10, category: "3. ครูผู้สอน", item_name: "จัดกิจกรรมการเรียนรู้เน้นผู้เรียนเป็นสำคัญ", max_score: 5 },
  { item_id: 11, category: "3. ครูผู้สอน", item_name: "ใช้สื่อเทคโนโลยีในการจัดการเรียนรู้", max_score: 5 },
  { item_id: 12, category: "3. ครูผู้สอน", item_name: "มีข้อมูลนักเรียนเป็นรายบุคคล", max_score: 5 },
  { item_id: 13, category: "3. ครูผู้สอน", item_name: "มีวิจัยในชั้นเรียนเพื่อการพัฒนาการเรียนรู้", max_score: 5 },
  { item_id: 14, category: "3. ครูผู้สอน", item_name: "ดูแลเอาใจใส่นักเรียนอย่างทั่วถึง", max_score: 5 },
  { item_id: 15, category: "3. ครูผู้สอน", item_name: "แต่งกายเหมาะสมกับความเป็นครู", max_score: 5 },
  // 4. นักเรียน
  { item_id: 16, category: "4. นักเรียน", item_name: "ตั้งใจปฏิบัติกิจกรรมการเรียนที่ได้รับมอบหมาย", max_score: 5 },
  { item_id: 17, category: "4. นักเรียน", item_name: "นักเรียนบรรลุจุดมุ่งหมาย", max_score: 5 },
  { item_id: 18, category: "4. นักเรียน", item_name: "นักเรียนกระตือรือร้นและกล้าซักถามครู", max_score: 5 },
  { item_id: 19, category: "4. นักเรียน", item_name: "นักเรียนมีระเบียบวินัย", max_score: 5 },
  { item_id: 20, category: "4. นักเรียน", item_name: "นักเรียนแต่งกายสะอาดถูกต้องตามระเบียบ", max_score: 5 }
];

// Helper to load database
function loadDB() {
  if (!fs.existsSync(DB_FILE)) {
    const initialData = {
      users: [
        { id: "U001", username: "admin", password: "admin1234", fullname: "ผู้ดูแลระบบ (แอดมิน)", role: "Admin", created_at: new Date().toISOString() },
        { id: "U002", username: "director", password: "director1234", fullname: "ครูณิชชาพัชญ์ สังข์สัจธรรม", role: "Director", created_at: new Date().toISOString() },
        { id: "U003", username: "teacher1", password: "teacher1234", fullname: "นางสุธาสินี มีโชคบรรเจิด", role: "Teacher", teacherId: "T001", created_at: new Date().toISOString() },
        { id: "U004", username: "teacher2", password: "teacher2234", fullname: "นายสมชาย เจริญสุข", role: "Teacher", teacherId: "T002", created_at: new Date().toISOString() },
        { id: "U005", username: "teacher3", password: "teacher3334", fullname: "นางสาวสมรดี บุญลือ", role: "Teacher", teacherId: "T003", created_at: new Date().toISOString() }
      ],
      teachers: [
        { teacher_id: "T001", teacher_name: "นางสุธาสินี มีโชคบรรเจิด", position: "ครูชำนาญการพิเศษ", subject_group: "กลุ่มสาระการเรียนรู้ภาษาไทย", phone: "081-2345678" },
        { teacher_id: "T002", teacher_name: "นายสมชาย เจริญสุข", position: "ครู คศ.1", subject_group: "กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี", phone: "089-8765432" },
        { teacher_id: "T003", teacher_name: "นางสาวสมรดี บุญลือ", position: "ครูผู้ช่วย", subject_group: "กลุ่มสาระการเรียนรู้คณิตศาสตร์", phone: "086-5555123" }
      ],
      academic_years: [
        { year_id: "AY001", year: "2568", semester: "2" },
        { year_id: "AY002", year: "2569", semester: "1" }
      ],
      evaluation_items: DEFAULT_EVAL_ITEMS,
      observations: [
        {
          observation_id: "OBS001",
          teacher_id: "T001",
          observer_name: "ครูณิชชาพัชญ์ สังข์สัจธรรม",
          observation_date: "2569-05-18",
          subject: "ภาษาไทยพื้นฐาน",
          grade_level: "ประถมศึกษาปีที่ 3",
          class_room: "ห้อง 1",
          student_count: 24,
          academic_year: "2569",
          semester: "1",
          strengths: "<p>ครูมีความพร้อม จัดเตรียมสื่อบัตรคำที่ดี มีการเล่นเกมทำให้เด็กกระตือรือร้นมาก</p>",
          suggestions: "<p>ควรให้เวลานักเรียนที่ทำกิจกรรมช้าเป็นรายบุคคลเพื่อไม่ให้หลงลืม</p>",
          development_plan: "<p>นำสื่อนวัตกรรมแบบฝึกหัดคำพ้องรูปคำพ้องเสียงมาพัฒนาเพิ่มเติม</p>",
          total_score: 84,
          average_score: 4.20,
          evaluation_level: "ดีมาก",
          created_at: "2569-05-18T10:30:00.000Z",
          photos: []
        },
        {
          observation_id: "OBS002",
          teacher_id: "T002",
          observer_name: "ครูณิชชาพัชญ์ สังข์สัจธรรม",
          observation_date: "2569-06-02",
          subject: "วิทยาการคำนวณ",
          grade_level: "ประถมศึกษาปีที่ 6",
          class_room: "ห้องคอมพิวเตอร์",
          student_count: 30,
          academic_year: "2569",
          semester: "1",
          strengths: "<p>นำเทคโนโลยีคอมพิวเตอร์และโปรแกรม Scratch มาใช้อย่างน่าสนใจ บรรยากาศในห้องเรียนสนุกสนาน</p>",
          suggestions: "<p>นักเรียนบางกลุ่มทำงานช้า ควรกำชับเวลาให้กระชับขึ้น</p>",
          development_plan: "<p>ส่งเสริมให้นักเรียนเรียนรู้ด้วยตนเองผ่านคลิปสั้นเพิ่มเติมภายนอกชั้นเรียน</p>",
          total_score: 92,
          average_score: 4.60,
          evaluation_level: "ดีเยี่ยม",
          created_at: "2569-06-02T14:15:00.000Z",
          photos: []
        },
        {
          observation_id: "OBS003",
          teacher_id: "T001",
          observer_name: "ครูณิชชาพัชญ์ สังข์สัจธรรม",
          observation_date: "2569-06-12",
          subject: "ภาษาไทยรอบตัว",
          grade_level: "ประถมศึกษาปีที่ 3",
          class_room: "ห้อง 1",
          student_count: 24,
          academic_year: "2569",
          semester: "1",
          strengths: "<p>นักเรียนตอบสนองได้ดีเยี่ยม ครูดูแลใส่ใจทั่วถึง ตกแต่งด้วยป้ายนิเทศแบบโต้ตอบ</p>",
          suggestions: "<p>เพิ่มการสรุปผลการประเมินลงในกระดานบอร์ด</p>",
          development_plan: "<p>อบรมผลิตสื่อร่วมกับการแต่งกายในบทบาทสมมติ</p>",
          total_score: 95,
          average_score: 4.75,
          evaluation_level: "ดีเยี่ยม",
          created_at: "2569-06-12T09:00:00.000Z",
          photos: []
        }
      ],
      observation_scores: [
        // Seed OBS001: 20 items with scores ranging around 4s
        { score_id: "S001_1", observation_id: "OBS001", item_id: 1, score: 4 },
        { score_id: "S001_2", observation_id: "OBS001", item_id: 2, score: 4 },
        { score_id: "S001_3", observation_id: "OBS001", item_id: 3, score: 5 },
        { score_id: "S001_4", observation_id: "OBS001", item_id: 4, score: 4 },
        { score_id: "S001_5", observation_id: "OBS001", item_id: 5, score: 4 },
        { score_id: "S001_6", observation_id: "OBS001", item_id: 6, score: 4 },
        { score_id: "S001_7", observation_id: "OBS001", item_id: 7, score: 4 },
        { score_id: "S001_8", observation_id: "OBS001", item_id: 8, score: 5 },
        { score_id: "S001_9", observation_id: "OBS001", item_id: 9, score: 4 },
        { score_id: "S001_10", observation_id: "OBS001", item_id: 10, score: 4 },
        { score_id: "S001_11", observation_id: "OBS001", item_id: 11, score: 4 },
        { score_id: "S001_12", observation_id: "OBS001", item_id: 12, score: 3 },
        { score_id: "S001_13", observation_id: "OBS001", item_id: 13, score: 4 },
        { score_id: "S001_14", observation_id: "OBS001", item_id: 14, score: 4 },
        { score_id: "S001_15", observation_id: "OBS001", item_id: 15, score: 5 },
        { score_id: "S001_16", observation_id: "OBS001", item_id: 16, score: 4 },
        { score_id: "S001_17", observation_id: "OBS001", item_id: 17, score: 4 },
        { score_id: "S001_18", observation_id: "OBS001", item_id: 18, score: 4 },
        { score_id: "S001_19", observation_id: "OBS001", item_id: 19, score: 5 },
        { score_id: "S001_20", observation_id: "OBS001", item_id: 20, score: 5 },

        // Seed OBS002: scores around 4-5
        { score_id: "S002_1", observation_id: "OBS002", item_id: 1, score: 5 },
        { score_id: "S002_2", observation_id: "OBS002", item_id: 2, score: 4 },
        { score_id: "S002_3", observation_id: "OBS002", item_id: 3, score: 5 },
        { score_id: "S002_4", observation_id: "OBS002", item_id: 4, score: 5 },
        { score_id: "S002_5", observation_id: "OBS002", item_id: 5, score: 5 },
        { score_id: "S002_6", observation_id: "OBS002", item_id: 6, score: 5 },
        { score_id: "S002_7", observation_id: "OBS002", item_id: 7, score: 4 },
        { score_id: "S002_8", observation_id: "OBS002", item_id: 8, score: 5 },
        { score_id: "S002_9", observation_id: "OBS002", item_id: 9, score: 4 },
        { score_id: "S002_10", observation_id: "OBS002", item_id: 10, score: 5 },
        { score_id: "S002_11", observation_id: "OBS002", item_id: 11, score: 5 },
        { score_id: "S002_12", observation_id: "OBS002", item_id: 12, score: 4 },
        { score_id: "S002_13", observation_id: "OBS002", item_id: 13, score: 4 },
        { score_id: "S002_14", observation_id: "OBS002", item_id: 14, score: 4 },
        { score_id: "S002_15", observation_id: "OBS002", item_id: 15, score: 5 },
        { score_id: "S002_16", observation_id: "OBS002", item_id: 16, score: 4 },
        { score_id: "S002_17", observation_id: "OBS002", item_id: 17, score: 4 },
        { score_id: "S002_18", observation_id: "OBS002", item_id: 18, score: 5 },
        { score_id: "S002_19", observation_id: "OBS002", item_id: 19, score: 5 },
        { score_id: "S002_20", observation_id: "OBS002", item_id: 20, score: 4 },

        // Seed OBS003: scores around 4-5
        { score_id: "S003_1", observation_id: "OBS003", item_id: 1, score: 5 },
        { score_id: "S003_2", observation_id: "OBS003", item_id: 2, score: 5 },
        { score_id: "S003_3", observation_id: "OBS003", item_id: 3, score: 5 },
        { score_id: "S003_4", observation_id: "OBS003", item_id: 4, score: 4 },
        { score_id: "S003_5", observation_id: "OBS003", item_id: 5, score: 5 },
        { score_id: "S003_6", observation_id: "OBS003", item_id: 6, score: 5 },
        { score_id: "S003_7", observation_id: "OBS003", item_id: 7, score: 5 },
        { score_id: "S003_8", observation_id: "OBS003", item_id: 8, score: 5 },
        { score_id: "S003_9", observation_id: "OBS003", item_id: 9, score: 4 },
        { score_id: "S003_10", observation_id: "OBS003", item_id: 10, score: 5 },
        { score_id: "S003_11", observation_id: "OBS003", item_id: 11, score: 4 },
        { score_id: "S003_12", observation_id: "OBS003", item_id: 12, score: 5 },
        { score_id: "S003_13", observation_id: "OBS003", item_id: 13, score: 4 },
        { score_id: "S003_14", observation_id: "OBS003", item_id: 14, score: 5 },
        { score_id: "S003_15", observation_id: "OBS003", item_id: 15, score: 5 },
        { score_id: "S003_16", observation_id: "OBS003", item_id: 16, score: 5 },
        { score_id: "S003_17", observation_id: "OBS003", item_id: 17, score: 4 },
        { score_id: "S003_18", observation_id: "OBS003", item_id: 18, score: 5 },
        { score_id: "S003_19", observation_id: "OBS003", item_id: 19, score: 5 },
        { score_id: "S003_20", observation_id: "OBS003", item_id: 20, score: 5 }
      ],
      observation_photos: [] as ObservationPhoto[]
    };
    fs.writeFileSync(DB_FILE, JSON.stringify(initialData, null, 2));
    return initialData;
  }
  return JSON.parse(fs.readFileSync(DB_FILE, "utf-8"));
}

function saveDB(data: any) {
  fs.writeFileSync(DB_FILE, JSON.stringify(data, null, 2));
}

// Initial DB load
let db = loadDB();

// API Endpoints

// 1. Auth Login (Session Simulated)
app.post("/api/auth/login", (req, res) => {
  const { username, password } = req.body;
  db = loadDB();
  const user = db.users.find(
    (u: User) => u.username === username && u.password === password
  );
  if (user) {
    // Return user details with mock session token
    const { password, ...safeUser } = user;
    res.json({ success: true, user: safeUser, token: `session_${user.id}_${Date.now()}` });
  } else {
    res.status(401).json({ success: false, message: "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง" });
  }
});

// 2. Teachers CRUD
app.get("/api/teachers", (req, res) => {
  db = loadDB();
  res.json(db.teachers);
});

app.post("/api/teachers", (req, res) => {
  const { teacher_name, position, subject_group, phone } = req.body;
  if (!teacher_name) {
    return res.status(400).json({ error: "โปรดใส่ชื่อครูผู้สอน" });
  }
  db = loadDB();
  const newId = `T${String(db.teachers.length + 1).padStart(3, "0")}`;
  const newTeacher: Teacher = {
    teacher_id: newId,
    teacher_name,
    position,
    subject_group,
    phone
  };
  db.teachers.push(newTeacher);

  // Also create a linked user for teacher login
  const teacherUsername = `teacher_${newId.toLowerCase()}`;
  db.users.push({
    id: `U${String(db.users.length + 1).padStart(3, "0")}`,
    username: teacherUsername,
    password: "123456", // default teacher password
    fullname: teacher_name,
    role: "Teacher",
    teacherId: newId,
    created_at: new Date().toISOString()
  });

  saveDB(db);
  res.status(251).json(newTeacher);
});

app.put("/api/teachers/:id", (req, res) => {
  const { id } = req.params;
  const { teacher_name, position, subject_group, phone } = req.body;
  db = loadDB();
  const index = db.teachers.findIndex((t: Teacher) => t.teacher_id === id);
  if (index !== -1) {
    db.teachers[index] = { ...db.teachers[index], teacher_name, position, subject_group, phone };
    // update counterpart user name
    const uIndex = db.users.findIndex((u: User) => u.teacherId === id);
    if (uIndex !== -1) {
      db.users[uIndex].fullname = teacher_name;
    }
    saveDB(db);
    res.json(db.teachers[index]);
  } else {
    res.status(404).json({ error: "ไม่พบข้อมูลครู" });
  }
});

app.delete("/api/teachers/:id", (req, res) => {
  const { id } = req.params;
  db = loadDB();
  db.teachers = db.teachers.filter((t: Teacher) => t.teacher_id !== id);
  // filter user
  db.users = db.users.filter((u: User) => u.teacherId !== id);
  saveDB(db);
  res.json({ success: true });
});

// 3. Academic Years CRUD
app.get("/api/academic-years", (req, res) => {
  db = loadDB();
  res.json(db.academic_years);
});

app.post("/api/academic-years", (req, res) => {
  const { year, semester } = req.body;
  if (!year || !semester) {
    return res.status(400).json({ error: "โปรดใส่ปีการศึกษาและภาคเรียน" });
  }
  db = loadDB();
  const newId = `AY${String(db.academic_years.length + 1).padStart(3, "0")}`;
  const newAY: AcademicYear = { year_id: newId, year, semester };
  db.academic_years.push(newAY);
  saveDB(db);
  res.status(251).json(newAY);
});

app.delete("/api/academic-years/:id", (req, res) => {
  const { id } = req.params;
  db = loadDB();
  db.academic_years = db.academic_years.filter((ay: AcademicYear) => ay.year_id !== id);
  saveDB(db);
  res.json({ success: true });
});

// 4. Evaluation Items CRUD / List
app.get("/api/evaluation-items", (req, res) => {
  db = loadDB();
  res.json(db.evaluation_items);
});

// 5. Observations API
app.get("/api/observations", (req, res) => {
  db = loadDB();
  res.json({
    observations: db.observations,
    scores: db.observation_scores
  });
});

app.post("/api/observations", (req, res) => {
  const {
    teacher_id,
    observer_name,
    observation_date,
    subject,
    grade_level,
    class_room,
    student_count,
    academic_year,
    semester,
    strengths,
    suggestions,
    development_plan,
    scores, // Array of { item_id: number, score: number, remark?: string }
    photos // Array of base64 data
  } = req.body;

  db = loadDB();
  const obsId = `OBS${String(db.observations.length + 1).padStart(3, "0")}`;

  // calculate totals
  let totalScore = 0;
  scores.forEach((s: any) => {
    totalScore += Number(s.score || 0);
  });
  const avgScore = totalScore / 20;

  let evalLevel = "ปรับปรุง";
  if (avgScore >= 4.51 && avgScore <= 5.00) evalLevel = "ดีเยี่ยม";
  else if (avgScore >= 3.51 && avgScore <= 4.50) evalLevel = "ดีมาก";
  else if (avgScore >= 2.51 && avgScore <= 3.50) evalLevel = "ดี";
  else if (avgScore >= 1.51 && avgScore <= 2.50) evalLevel = "พอใช้";

  const newObs: Observation = {
    observation_id: obsId,
    teacher_id,
    observer_name,
    observation_date,
    subject,
    grade_level,
    class_room,
    student_count: Number(student_count || 0),
    academic_year,
    semester,
    strengths,
    suggestions,
    development_plan,
    total_score: totalScore,
    average_score: Math.round(avgScore * 100) / 100,
    evaluation_level: evalLevel,
    created_at: new Date().toISOString(),
    photos: photos || []
  };

  db.observations.push(newObs);

  // insert scores
  scores.forEach((s: any, idx: number) => {
    const newScore: ObservationScore = {
      score_id: `S${obsId.replace("OBS", "")}_${s.item_id}`,
      observation_id: obsId,
      item_id: Number(s.item_id),
      score: Number(s.score || 1),
      remark: s.remark || ""
    };
    db.observation_scores.push(newScore);
  });

  saveDB(db);
  res.status(251).json(newObs);
});

// Update Observation
app.put("/api/observations/:id", (req, res) => {
  const { id } = req.params;
  const {
    teacher_id,
    observer_name,
    observation_date,
    subject,
    grade_level,
    class_room,
    student_count,
    academic_year,
    semester,
    strengths,
    suggestions,
    development_plan,
    scores,
    photos
  } = req.body;

  db = loadDB();
  const obsIndex = db.observations.findIndex((o: Observation) => o.observation_id === id);

  if (obsIndex !== -1) {
    let totalScore = 0;
    scores.forEach((s: any) => {
      totalScore += Number(s.score || 0);
    });
    const avgScore = totalScore / 20;

    let evalLevel = "ปรับปรุง";
    if (avgScore >= 4.51 && avgScore <= 5.00) evalLevel = "ดีเยี่ยม";
    else if (avgScore >= 3.51 && avgScore <= 4.50) evalLevel = "ดีมาก";
    else if (avgScore >= 2.51 && avgScore <= 3.50) evalLevel = "ดี";
    else if (avgScore >= 1.51 && avgScore <= 2.50) evalLevel = "พอใช้";

    db.observations[obsIndex] = {
      ...db.observations[obsIndex],
      teacher_id,
      observer_name,
      observation_date,
      subject,
      grade_level,
      class_room,
      student_count: Number(student_count || 0),
      academic_year,
      semester,
      strengths,
      suggestions,
      development_plan,
      total_score: totalScore,
      average_score: Math.round(avgScore * 100) / 100,
      evaluation_level: evalLevel,
      photos: photos || db.observations[obsIndex].photos || []
    };

    // Replace scores
    db.observation_scores = db.observation_scores.filter((sc: ObservationScore) => sc.observation_id !== id);
    scores.forEach((s: any) => {
      const newScore: ObservationScore = {
        score_id: `S${id.replace("OBS", "")}_${s.item_id}`,
        observation_id: id,
        item_id: Number(s.item_id),
        score: Number(s.score || 1),
        remark: s.remark || ""
      };
      db.observation_scores.push(newScore);
    });

    saveDB(db);
    res.json(db.observations[obsIndex]);
  } else {
    res.status(404).json({ error: "ไม่พบข้อมูลบันทึกการนิเทศ" });
  }
});

app.delete("/api/observations/:id", (req, res) => {
  const { id } = req.params;
  db = loadDB();
  db.observations = db.observations.filter((o: Observation) => o.observation_id !== id);
  db.observation_scores = db.observation_scores.filter((s: ObservationScore) => s.observation_id !== id);
  saveDB(db);
  res.json({ success: true });
});

// 6. DB Backup api/db/backup -> download file database.json
app.get("/api/db/backup", (req, res) => {
  res.setHeader("Content-disposition", "attachment; filename=database_backup.json");
  res.setHeader("Content-type", "application/json");
  res.sendFile(DB_FILE);
});

// 7. DB Restore
app.post("/api/db/restore", (req, res) => {
  try {
    const incomingData = req.body;
    if (!incomingData.users || !incomingData.teachers || !incomingData.observations) {
      return res.status(400).json({ error: "รูปแบบฐานข้อมูลไม่ถูกต้อง" });
    }
    saveDB(incomingData);
    db = loadDB();
    res.json({ success: true, message: "กู้คืนระบบฐานข้อมูลเรียบร้อยแล้ว" });
  } catch (err: any) {
    res.status(500).json({ error: "เกิดข้อผิดพลาด: " + err.message });
  }
});

// 8. Excel/CSV Export
app.get("/api/reports/excel", (req, res) => {
  db = loadDB();
  // Build a highly detailed CSV with UTF-8 BOM so Excel opens it with perfect columns and Thai text
  let csvContent = "\uFEFF"; // BOM
  csvContent += "ID,วันที่นิเทศ,ชื่อครูผู้สอน,ผู้ประเมิน,วิชาภาษาไทย/สาระ,ระดับชั้น,ห้อง,จำนวนนักเรียน,คะแนนรวม,ค่าเฉลี่ย,ระดับผลประเมิน,ปีการศึกษา,ภาคเรียน\n";

  db.observations.forEach((o: Observation) => {
    const teacher = db.teachers.find((t: Teacher) => t.teacher_id === o.teacher_id);
    const teacherName = teacher ? teacher.teacher_name : o.teacher_id;
    csvContent += `"${o.observation_id}","${o.observation_date}","${teacherName}","${o.observer_name}","${o.subject}","${o.grade_level}","${o.class_room}",${o.student_count},${o.total_score},${o.average_score},"${o.evaluation_level}","${o.academic_year}","${o.semester}"\n`;
  });

  res.setHeader("Content-disposition", "attachment; filename=classroom_supervision_report.csv");
  res.set("Content-Type", "text/csv; charset=utf-8");
  res.status(200).send(csvContent);
});

// Serve Vite frontend
async function startServer() {
  if (process.env.NODE_ENV !== "production") {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: "spa",
    });
    app.use(vite.middlewares);
  } else {
    const distPath = path.join(process.cwd(), "dist");
    app.use(express.static(distPath));
    app.get("*", (req, res) => {
      res.sendFile(path.join(distPath, "index.html"));
    });
  }

  app.listen(PORT, "0.0.0.0", () => {
    console.log(`Server running on http://localhost:${PORT}`);
  });
}

startServer();

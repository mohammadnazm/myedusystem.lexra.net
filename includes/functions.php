<?php
/** Small shared helpers used across admin/teacher/student pages. */

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/** Validates a submitted gender value against the 'male'/'female' ENUM,
 *  returning null (stored as NULL / "not set") for anything else. */
function normalize_gender(?string $gender): ?string
{
    $gender = strtolower(trim((string)$gender));
    return in_array($gender, ['male', 'female'], true) ? $gender : null;
}

/** Small display label for a gender value, safe to echo directly. */
function gender_label(?string $gender): string
{
    if ($gender === 'male') return 'Male';
    if ($gender === 'female') return 'Female';
    return '—';
}

/** Runs one "SELECT COUNT(*) c FROM ... WHERE institute_id = ?"-shaped query
 *  start-to-finish (prepare, execute, fetch, close) before returning —
 *  never leaves a result pending while another statement runs on the same
 *  connection, which mysqli's native protocol doesn't allow. */
function scalar_count(mysqli $conn, string $sql, int $instituteId): int
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count;
}

/** Returns ['male' => n, 'female' => n, 'unspecified' => n] for the given
 *  table (must be 'teachers' or 'students'), scoped to one institute.
 *  Students additionally only counts active ones, to match the dashboard's
 *  other student figures. */
function gender_breakdown(mysqli $conn, string $table, int $instituteId): array
{
    $table = $table === 'students' ? 'students' : 'teachers'; // whitelist, never interpolate user input
    $activeClause = $table === 'students' ? ' AND is_active = 1' : '';
    $stmt = $conn->prepare("SELECT gender, COUNT(*) c FROM $table WHERE institute_id = ?$activeClause GROUP BY gender");
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $counts = ['male' => 0, 'female' => 0, 'unspecified' => 0];
    foreach ($rows as $row) {
        $key = in_array($row['gender'], ['male', 'female'], true) ? $row['gender'] : 'unspecified';
        $counts[$key] += (int)$row['c'];
    }
    return $counts;
}

/** Active-student counts per department, broken down by gender. Each row:
 *  department_id, department_name, male, female, unspecified, total. */
function students_by_department(mysqli $conn, int $instituteId): array
{
    $sql = "SELECT d.id AS department_id, d.name AS department_name,
                   SUM(s.gender = 'male') AS male,
                   SUM(s.gender = 'female') AS female,
                   SUM(s.gender IS NULL) AS unspecified,
                   COUNT(*) AS total
            FROM students s
            JOIN class_sections cs ON cs.id = s.class_section_id
            JOIN departments d ON d.id = cs.department_id
            WHERE d.institute_id = ? AND s.is_active = 1
            GROUP BY d.id, d.name
            ORDER BY d.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Active-student counts per department + grade, broken down by gender.
 *  Each row: department_name, grade, male, female, unspecified, total. */
function students_by_grade(mysqli $conn, int $instituteId): array
{
    $sql = "SELECT d.name AS department_name, cs.grade AS grade,
                   SUM(s.gender = 'male') AS male,
                   SUM(s.gender = 'female') AS female,
                   SUM(s.gender IS NULL) AS unspecified,
                   COUNT(*) AS total
            FROM students s
            JOIN class_sections cs ON cs.id = s.class_section_id
            JOIN departments d ON d.id = cs.department_id
            WHERE d.institute_id = ? AND s.is_active = 1
            GROUP BY d.id, d.name, cs.grade
            ORDER BY d.name, cs.grade + 0, cs.grade";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/* ==================================================================
   Every function below that lists or looks up data for an admin,
   teacher, or student is scoped to one institute_id, so one institute
   can never see or affect another institute's data. Functions that
   take a teacher/student/class-section ID also get an institute_id
   check where the ID could plausibly be tampered with (e.g. arrives
   via POST), so a request naming another institute's record is
   rejected rather than silently trusted.
   ================================================================== */

function all_departments(mysqli $conn, int $instituteId): array
{
    $stmt = $conn->prepare("SELECT * FROM departments WHERE institute_id = ? ORDER BY name");
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function all_class_sections(mysqli $conn, int $instituteId): array
{
    $sql = "SELECT cs.*, d.name AS department_name
            FROM class_sections cs
            JOIN departments d ON d.id = cs.department_id
            WHERE d.institute_id = ?
            ORDER BY d.name, cs.grade, cs.class_group";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function class_section_label(array $section): string
{
    return $section['department_name'] . ' — Grade ' . $section['grade'] . ' — Group ' . $section['class_group'];
}

/** Returns [] if the department doesn't belong to this institute (tamper-safe). */
function subjects_for_department(mysqli $conn, int $departmentId, int $instituteId): array
{
    $stmt = $conn->prepare(
        "SELECT sub.* FROM subjects sub
         JOIN departments d ON d.id = sub.department_id
         WHERE sub.department_id = ? AND d.institute_id = ?
         ORDER BY sub.name"
    );
    $stmt->bind_param('ii', $departmentId, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* ---------------------- Ownership verification helpers ---------------------- */

function department_belongs_to_institute(mysqli $conn, int $departmentId, int $instituteId): bool
{
    $stmt = $conn->prepare("SELECT id FROM departments WHERE id = ? AND institute_id = ?");
    $stmt->bind_param('ii', $departmentId, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function class_section_belongs_to_institute(mysqli $conn, int $classSectionId, int $instituteId): bool
{
    $stmt = $conn->prepare(
        "SELECT cs.id FROM class_sections cs
         JOIN departments d ON d.id = cs.department_id
         WHERE cs.id = ? AND d.institute_id = ?"
    );
    $stmt->bind_param('ii', $classSectionId, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/** True when the subject belongs to the same department as the class section
 *  (e.g. an Accounting subject can't be attached to a Computer class). */
function subject_in_class_section(mysqli $conn, int $subjectId, int $classSectionId): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM subjects sub
         JOIN class_sections cs ON cs.department_id = sub.department_id
         WHERE sub.id = ? AND cs.id = ?"
    );
    $stmt->bind_param('ii', $subjectId, $classSectionId);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

function subject_in_department(mysqli $conn, int $subjectId, int $departmentId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM subjects WHERE id = ? AND department_id = ?");
    $stmt->bind_param('ii', $subjectId, $departmentId);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

/** The students currently in a class section, as a lookup set (id => true).
 *  Marks/attendance saves use it so a tampered form can never write records
 *  for a student outside the class the person is allowed to work on. */
function student_ids_in_section(mysqli $conn, int $classSectionId): array
{
    $stmt = $conn->prepare("SELECT id FROM students WHERE class_section_id = ?");
    $stmt->bind_param('i', $classSectionId);
    $stmt->execute();
    $set = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $set[(int)$row['id']] = true;
    }
    $stmt->close();
    return $set;
}

function subject_belongs_to_institute(mysqli $conn, int $subjectId, int $instituteId): bool
{
    $stmt = $conn->prepare(
        "SELECT sub.id FROM subjects sub
         JOIN departments d ON d.id = sub.department_id
         WHERE sub.id = ? AND d.institute_id = ?"
    );
    $stmt->bind_param('ii', $subjectId, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function teacher_belongs_to_institute(mysqli $conn, int $teacherId, int $instituteId): bool
{
    $stmt = $conn->prepare("SELECT id FROM teachers WHERE id = ? AND institute_id = ?");
    $stmt->bind_param('ii', $teacherId, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/** Fetches one teacher row scoped to an institute, or null. Used to capture
 *  "before" state for activity-log diffs. */
function get_teacher(mysqli $conn, int $teacherId, int $instituteId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE id = ? AND institute_id = ?");
    $stmt->bind_param('ii', $teacherId, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function student_belongs_to_institute(mysqli $conn, int $studentId, int $instituteId): bool
{
    $stmt = $conn->prepare("SELECT id FROM students WHERE id = ? AND institute_id = ?");
    $stmt->bind_param('ii', $studentId, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/** Fetches one student row (with class/department label) scoped to an
 *  institute, or null. Used to capture "before" state for activity-log diffs. */
function get_student(mysqli $conn, int $studentId, int $instituteId): ?array
{
    $stmt = $conn->prepare(
        "SELECT s.*, cs.grade, cs.class_group, d.id AS department_id, d.name AS department_name
         FROM students s
         JOIN class_sections cs ON cs.id = s.class_section_id
         JOIN departments d ON d.id = cs.department_id
         WHERE s.id = ? AND s.institute_id = ?"
    );
    $stmt->bind_param('ii', $studentId, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/* ---------------------- Teacher assignments / marks ---------------------- */

/** Class sections + subject a given teacher is allowed to touch. */
function teacher_assignments(mysqli $conn, int $teacherId): array
{
    $sql = "SELECT ta.id AS assignment_id, cs.id AS class_section_id, cs.grade, cs.class_group,
                   d.id AS department_id, d.name AS department_name,
                   sub.id AS subject_id, sub.name AS subject_name
            FROM teacher_assignments ta
            JOIN class_sections cs ON cs.id = ta.class_section_id
            JOIN departments d ON d.id = cs.department_id
            JOIN subjects sub ON sub.id = ta.subject_id
            WHERE ta.teacher_id = ?
            ORDER BY d.name, cs.grade, cs.class_group, sub.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Confirm a teacher is actually allowed to enter marks for this section+subject. Returns bool. */
function teacher_can_access(mysqli $conn, int $teacherId, int $classSectionId, int $subjectId): bool
{
    $stmt = $conn->prepare("SELECT id FROM teacher_assignments WHERE teacher_id = ? AND class_section_id = ? AND subject_id = ?");
    $stmt->bind_param('iii', $teacherId, $classSectionId, $subjectId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function students_in_section(mysqli $conn, int $classSectionId): array
{
    $stmt = $conn->prepare("SELECT * FROM students WHERE class_section_id = ? AND is_active = 1 ORDER BY first_name, second_name, last_name");
    $stmt->bind_param('i', $classSectionId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function student_full_name(array $student): string
{
    return implode(' ', array_filter([
        $student['first_name'] ?? '',
        $student['second_name'] ?? '',
        $student['last_name'] ?? '',
    ], fn($part) => trim((string)$part) !== ''));
}

/** Splits one "Full Name" input into the first_name/second_name/last_name
 *  columns the database still stores separately (used by search, sorting,
 *  and Excel export/import). One word -> first name only. Two words ->
 *  first + last. Three or more -> first + everything in the middle
 *  (second_name) + last, matching common Kurdish/Arabic 3-part naming. */
function split_full_name(string $fullName): array
{
    $words = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
    $count = count($words);

    if ($count === 0) {
        return ['first_name' => '', 'second_name' => '', 'last_name' => ''];
    }
    if ($count === 1) {
        return ['first_name' => $words[0], 'second_name' => '', 'last_name' => ''];
    }
    if ($count === 2) {
        return ['first_name' => $words[0], 'second_name' => '', 'last_name' => $words[1]];
    }
    return [
        'first_name' => $words[0],
        'second_name' => implode(' ', array_slice($words, 1, -1)),
        'last_name' => end($words),
    ];
}

/** Existing marks for every student in a section, for one subject+semester, keyed by student_id. */
function marks_for_section(mysqli $conn, int $classSectionId, int $subjectId, string $semester): array
{
    $sql = "SELECT m.* FROM marks m
            JOIN students s ON s.id = m.student_id
            WHERE s.class_section_id = ? AND m.subject_id = ? AND m.semester = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iis', $classSectionId, $subjectId, $semester);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $byStudent = [];
    foreach ($rows as $row) {
        $byStudent[$row['student_id']] = $row;
    }
    return $byStudent;
}

/** Insert or update the 5 mark fields for one student/subject/semester (upsert). */
function save_student_marks(mysqli $conn, int $studentId, int $subjectId, string $semester, array $marks, ?int $teacherId): void
{
    $sql = "INSERT INTO marks (student_id, subject_id, semester, quiz_assignment, participation, first_term, second_term, final, updated_by_teacher_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                quiz_assignment = VALUES(quiz_assignment),
                participation = VALUES(participation),
                first_term = VALUES(first_term),
                second_term = VALUES(second_term),
                final = VALUES(final),
                updated_by_teacher_id = VALUES(updated_by_teacher_id)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'iisdddddi',
        $studentId,
        $subjectId,
        $semester,
        $marks['quiz_assignment'],
        $marks['participation'],
        $marks['first_term'],
        $marks['second_term'],
        $marks['final'],
        $teacherId
    );
    $stmt->execute();
}

/** Max possible value for each mark column (used for input limits, header labels, and clamping). */
const MARK_FIELD_MAX = [
    'quiz_assignment' => 10,
    'participation'   => 10,
    'first_term'      => 20,
    'second_term'     => 20,
    'final'           => 60,
];

/** Parse a mark form field into a float|null, clamped to 0-$max. Blank input stays null (not yet graded). */
function parse_mark(?string $raw, float $max = 100): ?float
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    if (!is_numeric($raw)) {
        return null;
    }
    $val = (float)$raw;
    return max(0, min($max, $val));
}

/* ---------------------- Attendance ---------------------- */

const ATTENDANCE_STATUSES = ['present', 'absent', 'late', 'excused'];

/** Human label + Bootstrap "text-bg-*" class for one attendance status, safe to echo directly. */
function attendance_status_meta(string $status): array
{
    $map = [
        'present' => ['label' => 'Present', 'badge' => 'text-bg-success-subtle text-success-emphasis', 'icon' => 'bi-check-circle'],
        'absent'  => ['label' => 'Absent',  'badge' => 'text-bg-danger-subtle text-danger-emphasis',   'icon' => 'bi-x-circle'],
        'late'    => ['label' => 'Late',    'badge' => 'text-bg-warning-subtle text-warning-emphasis', 'icon' => 'bi-clock-history'],
        'excused' => ['label' => 'Excused', 'badge' => 'text-bg-info-subtle text-info-emphasis',       'icon' => 'bi-file-earmark-check'],
    ];
    return $map[$status] ?? $map['present'];
}

/** Existing attendance rows for every student in a section, for one
 *  subject + date, keyed by student_id. Access to (section, subject) is
 *  gated the same way as marks — see teacher_can_access() above. */
function attendance_for_section_subject_date(mysqli $conn, int $classSectionId, int $subjectId, string $date): array
{
    $sql = "SELECT a.* FROM attendance a
            JOIN students s ON s.id = a.student_id
            WHERE s.class_section_id = ? AND a.subject_id = ? AND a.attendance_date = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iis', $classSectionId, $subjectId, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $byStudent = [];
    foreach ($rows as $row) {
        $byStudent[$row['student_id']] = $row;
    }
    return $byStudent;
}

/** Insert or update one student's attendance status for one class + subject
 *  + day (upsert). $lesson is a required free-text label for the session
 *  (e.g. "1st", "Period 2") — the same value is stored on every student's
 *  row for that submission. */
function save_student_attendance(mysqli $conn, int $studentId, int $classSectionId, int $subjectId, string $date, string $lesson, string $status, ?string $remarks, ?int $teacherId): void
{
    if (!in_array($status, ATTENDANCE_STATUSES, true)) {
        $status = 'present';
    }
    $remarks = ($remarks !== null && trim($remarks) !== '') ? trim($remarks) : null;
    $sql = "INSERT INTO attendance (student_id, class_section_id, subject_id, teacher_id, attendance_date, lesson, status, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                class_section_id = VALUES(class_section_id),
                teacher_id = VALUES(teacher_id),
                lesson = VALUES(lesson),
                status = VALUES(status),
                remarks = VALUES(remarks)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiiissss', $studentId, $classSectionId, $subjectId, $teacherId, $date, $lesson, $status, $remarks);
    $stmt->execute();
}

/** The last N dates attendance was taken for one class + subject, each with
 *  a quick present/absent/late/excused breakdown — lets a teacher jump back
 *  to any earlier day to review or correct it. */
function attendance_recent_dates_for_section(mysqli $conn, int $classSectionId, int $subjectId, int $limit = 10): array
{
    $sql = "SELECT a.attendance_date, MAX(a.lesson) AS lesson,
                   SUM(a.status = 'present') AS present_count,
                   SUM(a.status = 'absent') AS absent_count,
                   SUM(a.status = 'late') AS late_count,
                   SUM(a.status = 'excused') AS excused_count,
                   COUNT(*) AS total_count
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            WHERE s.class_section_id = ? AND a.subject_id = ?
            GROUP BY a.attendance_date
            ORDER BY a.attendance_date DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $classSectionId, $subjectId, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Admin-facing attendance report for a date range: one row per active
 *  student with a per-status day count, total days recorded, and an
 *  attendance percentage (present+late counted as attended). Optionally
 *  narrowed to one department, one class section, and/or one subject —
 *  leaving subject unset aggregates across every subject the student was
 *  marked in during the range. Always scoped to one institute. */
function attendance_summary_range(mysqli $conn, int $instituteId, string $from, string $to, ?int $departmentId = null, ?int $classSectionId = null, ?int $subjectId = null): array
{
    $joinSql = "LEFT JOIN attendance a ON a.student_id = s.id AND a.attendance_date BETWEEN ? AND ?";
    $params = [$from, $to];
    $types = 'ss';
    if ($subjectId) {
        $joinSql .= " AND a.subject_id = ?";
        $params[] = $subjectId;
        $types .= 'i';
    }

    $sql = "SELECT s.id AS student_id,
                   s.first_name, s.second_name, s.last_name,
                   d.name AS department_name, cs.grade, cs.class_group,
                   SUM(a.status = 'present') AS present_count,
                   SUM(a.status = 'absent') AS absent_count,
                   SUM(a.status = 'late') AS late_count,
                   SUM(a.status = 'excused') AS excused_count,
                   COUNT(a.id) AS total_days
            FROM students s
            JOIN class_sections cs ON cs.id = s.class_section_id
            JOIN departments d ON d.id = cs.department_id
            $joinSql
            WHERE d.institute_id = ? AND s.is_active = 1";
    $params[] = $instituteId;
    $types .= 'i';
    if ($departmentId) {
        $sql .= " AND d.id = ?";
        $params[] = $departmentId;
        $types .= 'i';
    }
    if ($classSectionId) {
        $sql .= " AND cs.id = ?";
        $params[] = $classSectionId;
        $types .= 'i';
    }
    $sql .= " GROUP BY s.id, s.first_name, s.second_name, s.last_name, d.name, cs.grade, cs.class_group
              HAVING total_days > 0
              ORDER BY d.name, cs.grade, cs.class_group, s.first_name, s.second_name, s.last_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Every subject in an institute, with its department name — used to
 *  populate the admin attendance report's subject filter. */
function all_subjects_for_institute(mysqli $conn, int $instituteId): array
{
    $sql = "SELECT sub.*, d.name AS department_name
            FROM subjects sub
            JOIN departments d ON d.id = sub.department_id
            WHERE d.institute_id = ?
            ORDER BY d.name, sub.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Admin-facing attendance report: one row PER RECORD (not aggregated) —
 *  student name, department, grade, class group, subject, lesson, status
 *  and remarks — so an admin can see exactly which subject/lesson a
 *  student was marked absent (or late/excused) for, on which day.
 *  Optionally narrowed to one department, class section, subject, and/or
 *  status. Always scoped to one institute. Uses the class section the
 *  attendance was actually recorded against (not the student's current
 *  one), so the record stays accurate even after a student is promoted. */
function attendance_records_range(mysqli $conn, int $instituteId, string $from, string $to, ?int $departmentId = null, ?int $classSectionId = null, ?int $subjectId = null, ?string $status = null): array
{
    $sql = "SELECT a.id, a.attendance_date, a.lesson, a.status, a.remarks,
                   s.id AS student_id, s.first_name, s.second_name, s.last_name,
                   d.name AS department_name, cs.grade, cs.class_group,
                   sub.name AS subject_name
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            JOIN class_sections cs ON cs.id = a.class_section_id
            JOIN departments d ON d.id = cs.department_id
            JOIN subjects sub ON sub.id = a.subject_id
            WHERE d.institute_id = ? AND a.attendance_date BETWEEN ? AND ?";
    $params = [$instituteId, $from, $to];
    $types = 'iss';
    if ($departmentId) {
        $sql .= " AND d.id = ?";
        $params[] = $departmentId;
        $types .= 'i';
    }
    if ($classSectionId) {
        $sql .= " AND cs.id = ?";
        $params[] = $classSectionId;
        $types .= 'i';
    }
    if ($subjectId) {
        $sql .= " AND a.subject_id = ?";
        $params[] = $subjectId;
        $types .= 'i';
    }
    if ($status && in_array($status, ATTENDANCE_STATUSES, true)) {
        $sql .= " AND a.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    $sql .= " ORDER BY a.attendance_date DESC, s.first_name, s.second_name, s.last_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Groups the flat rows from attendance_records_range() into one entry per
 *  student per day — a student marked in several subjects on the same date
 *  (Math, English, Science...) becomes a single row with a list of
 *  subject/lesson/status entries, instead of one row per subject. Input
 *  must already be ordered by date then student (attendance_records_range
 *  does this) so matching rows are adjacent. Each returned group has the
 *  shared date/student/grade/class/department fields plus an 'entries' key
 *  holding [subject_name, lesson, status, remarks] for every subject that day. */
function group_attendance_records_by_student_day(array $records): array
{
    $groups = [];
    $order = [];
    foreach ($records as $r) {
        $key = $r['attendance_date'] . '|' . $r['student_id'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'attendance_date' => $r['attendance_date'],
                'student_id' => $r['student_id'],
                'first_name' => $r['first_name'],
                'second_name' => $r['second_name'],
                'last_name' => $r['last_name'],
                'department_name' => $r['department_name'],
                'grade' => $r['grade'],
                'class_group' => $r['class_group'],
                'entries' => [],
            ];
            $order[] = $key;
        }
        $groups[$key]['entries'][] = [
            'id' => $r['id'],
            'subject_name' => $r['subject_name'],
            'lesson' => $r['lesson'],
            'status' => $r['status'],
            'remarks' => $r['remarks'],
        ];
    }
    $result = [];
    foreach ($order as $key) {
        $result[] = $groups[$key];
    }
    return $result;
}

/** A student's own attendance, one row per subject, for their whole
 *  history to date (no date picker — a running total through the
 *  semester). Used by the student-facing Attendance page. */
function attendance_summary_by_subject_for_student(mysqli $conn, int $studentId): array
{
    $sql = "SELECT sub.name AS subject_name,
                   SUM(a.status = 'present') AS present_count,
                   SUM(a.status = 'absent') AS absent_count,
                   SUM(a.status = 'late') AS late_count,
                   SUM(a.status = 'excused') AS excused_count,
                   COUNT(a.id) AS total_days
            FROM attendance a
            JOIN subjects sub ON sub.id = a.subject_id
            WHERE a.student_id = ?
            GROUP BY sub.id, sub.name
            ORDER BY sub.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** One attendance row plus its student/class/subject context, scoped to
 *  the given institute so an admin can never load another institute's
 *  record by guessing an id. Returns null if not found / not theirs. */
function attendance_record_for_admin(mysqli $conn, int $id, int $instituteId): ?array
{
    $sql = "SELECT a.*, s.first_name, s.second_name, s.last_name,
                   d.name AS department_name, cs.grade, cs.class_group, sub.name AS subject_name
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            JOIN class_sections cs ON cs.id = a.class_section_id
            JOIN departments d ON d.id = cs.department_id
            JOIN subjects sub ON sub.id = a.subject_id
            WHERE a.id = ? AND d.institute_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $id, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/** Updates the editable fields of one attendance record (lesson, status, remarks). */
function update_attendance_record(mysqli $conn, int $id, string $lesson, string $status, ?string $remarks): void
{
    if (!in_array($status, ATTENDANCE_STATUSES, true)) {
        $status = 'present';
    }
    $remarks = ($remarks !== null && trim($remarks) !== '') ? trim($remarks) : null;
    $stmt = $conn->prepare("UPDATE attendance SET lesson = ?, status = ?, remarks = ? WHERE id = ?");
    $stmt->bind_param('sssi', $lesson, $status, $remarks, $id);
    $stmt->execute();
}

/** Deletes one attendance record, scoped to the institute (see attendance_record_for_admin). */
function delete_attendance_record(mysqli $conn, int $id, int $instituteId): bool
{
    $sql = "DELETE a FROM attendance a
            JOIN class_sections cs ON cs.id = a.class_section_id
            JOIN departments d ON d.id = cs.department_id
            WHERE a.id = ? AND d.institute_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $id, $instituteId);
    $stmt->execute();
    return $stmt->affected_rows > 0;
}

/** Bulk-deletes every attendance record matching the given filters, scoped
 *  to one institute. Same filter set as the admin report itself, so
 *  "delete all" always means "delete everything currently shown" — clear
 *  the filters first to wipe the whole institute's attendance history.
 *  Returns the number of rows deleted. */
function delete_attendance_records_matching(mysqli $conn, int $instituteId, string $from, string $to, ?int $departmentId = null, ?int $classSectionId = null, ?int $subjectId = null, ?string $status = null): int
{
    $sql = "DELETE a FROM attendance a
            JOIN class_sections cs ON cs.id = a.class_section_id
            JOIN departments d ON d.id = cs.department_id
            WHERE d.institute_id = ? AND a.attendance_date BETWEEN ? AND ?";
    $params = [$instituteId, $from, $to];
    $types = 'iss';
    if ($departmentId) {
        $sql .= " AND d.id = ?";
        $params[] = $departmentId;
        $types .= 'i';
    }
    if ($classSectionId) {
        $sql .= " AND cs.id = ?";
        $params[] = $classSectionId;
        $types .= 'i';
    }
    if ($subjectId) {
        $sql .= " AND a.subject_id = ?";
        $params[] = $subjectId;
        $types .= 'i';
    }
    if ($status && in_array($status, ATTENDANCE_STATUSES, true)) {
        $sql .= " AND a.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->affected_rows;
}


/** Records one audit-log entry. $actorName is captured at the time of the
 *  action (not looked up later) so the log still reads correctly even if
 *  that teacher/admin account is later renamed or deleted. */
function log_activity(mysqli $conn, int $instituteId, string $role, int $actorId, string $actorName, string $action): void
{
    $stmt = $conn->prepare("INSERT INTO activity_log (institute_id, actor_role, actor_id, actor_name, action) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('isiss', $instituteId, $role, $actorId, $actorName, $action);
    $stmt->execute();
}

/** Builds a "field: old → new" list for only the fields that actually
 *  changed between two associative arrays (e.g. a row before/after an
 *  update). $labels maps column name => human-readable label; only keys
 *  present in $labels are considered. Empty/null values render as "(empty)". */
function diff_summary(array $before, array $after, array $labels): string
{
    $parts = [];
    foreach ($labels as $key => $label) {
        $old = $before[$key] ?? null;
        $new = $after[$key] ?? null;
        if ((string)$old === (string)$new) continue;
        $oldDisplay = ($old === null || $old === '') ? '(empty)' : (string)$old;
        $newDisplay = ($new === null || $new === '') ? '(empty)' : (string)$new;
        $parts[] = "$label: $oldDisplay → $newDisplay";
    }
    return implode('; ', $parts);
}

/* ---------------------- Daily reports ---------------------- */

function save_daily_report(mysqli $conn, int $teacherId, int $classSectionId, int $subjectId, int $lessonNumber, string $reportDate, string $materials, string $methods, string $contents): void
{
    $stmt = $conn->prepare(
        "INSERT INTO daily_reports (teacher_id, class_section_id, subject_id, lesson_number, report_date, materials, methods, contents)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iiiissss', $teacherId, $classSectionId, $subjectId, $lessonNumber, $reportDate, $materials, $methods, $contents);
    $stmt->execute();
}

/** A teacher's own reports, optionally filtered by date range. Most recent first. */
function daily_reports_for_teacher(mysqli $conn, int $teacherId, ?string $from = null, ?string $to = null): array
{
    $sql = "SELECT dr.*, sub.name AS subject_name, cs.grade, cs.class_group, d.name AS department_name
            FROM daily_reports dr
            JOIN subjects sub ON sub.id = dr.subject_id
            JOIN class_sections cs ON cs.id = dr.class_section_id
            JOIN departments d ON d.id = cs.department_id
            WHERE dr.teacher_id = ?";
    $params = [$teacherId];
    $types = 'i';
    if ($from) { $sql .= " AND dr.report_date >= ?"; $params[] = $from; $types .= 's'; }
    if ($to)   { $sql .= " AND dr.report_date <= ?"; $params[] = $to; $types .= 's'; }
    $sql .= " ORDER BY dr.report_date DESC, dr.lesson_number ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Admin summary: one row per teacher+date, with how many lesson entries were logged that day. Scoped to one institute. */
function daily_reports_summary(mysqli $conn, int $instituteId, ?int $teacherId, string $from, string $to): array
{
    $sql = "SELECT dr.teacher_id, t.full_name AS teacher_name, dr.report_date, COUNT(*) AS entry_count
            FROM daily_reports dr
            JOIN teachers t ON t.id = dr.teacher_id
            WHERE t.institute_id = ? AND dr.report_date BETWEEN ? AND ?";
    $params = [$instituteId, $from, $to];
    $types = 'iss';
    if ($teacherId) { $sql .= " AND dr.teacher_id = ?"; $params[] = $teacherId; $types .= 'i'; }
    $sql .= " GROUP BY dr.teacher_id, dr.report_date ORDER BY dr.report_date DESC, t.full_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Every lesson entry a specific teacher logged on a specific date. */
function daily_reports_detail(mysqli $conn, int $teacherId, string $date): array
{
    $sql = "SELECT dr.*, sub.name AS subject_name, cs.grade, cs.class_group, d.name AS department_name
            FROM daily_reports dr
            JOIN subjects sub ON sub.id = dr.subject_id
            JOIN class_sections cs ON cs.id = dr.class_section_id
            JOIN departments d ON d.id = cs.department_id
            WHERE dr.teacher_id = ? AND dr.report_date = ?
            ORDER BY dr.lesson_number ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $teacherId, $date);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function delete_daily_report(mysqli $conn, int $id, ?int $restrictToTeacherId = null): bool
{
    if ($restrictToTeacherId !== null) {
        $stmt = $conn->prepare("DELETE FROM daily_reports WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param('ii', $id, $restrictToTeacherId);
    } else {
        $stmt = $conn->prepare("DELETE FROM daily_reports WHERE id = ?");
        $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    return $stmt->affected_rows > 0;
}

/* ---------------------- Resources (PDFs) ---------------------- */

/** Pass a teacherId (uploaded by a teacher) or an adminId (uploaded by an admin) — never both. */
function save_resource(mysqli $conn, ?int $teacherId, ?int $adminId, int $departmentId, string $grade, int $subjectId, string $title, string $originalFilename, string $storedFilename, int $fileSize): void
{
    $stmt = $conn->prepare(
        "INSERT INTO resources (teacher_id, admin_id, department_id, grade, subject_id, title, original_filename, stored_filename, file_size)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iiisisssi', $teacherId, $adminId, $departmentId, $grade, $subjectId, $title, $originalFilename, $storedFilename, $fileSize);
    $stmt->execute();
}

/** A teacher's own uploaded resources, most recent first. */
function resources_for_teacher(mysqli $conn, int $teacherId): array
{
    $sql = "SELECT r.*, sub.name AS subject_name, d.name AS department_name
            FROM resources r
            JOIN subjects sub ON sub.id = r.subject_id
            JOIN departments d ON d.id = r.department_id
            WHERE r.teacher_id = ?
            ORDER BY r.uploaded_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Every resource available to students of one department+grade (every group in that grade), most recent first. */
function resources_for_grade(mysqli $conn, int $departmentId, string $grade): array
{
    $sql = "SELECT r.*, sub.name AS subject_name,
                   COALESCE(t.full_name, a.full_name) AS uploader_name,
                   IF(r.teacher_id IS NOT NULL, 'Teacher', 'Admin') AS uploader_role
            FROM resources r
            JOIN subjects sub ON sub.id = r.subject_id
            LEFT JOIN teachers t ON t.id = r.teacher_id
            LEFT JOIN admins a ON a.id = r.admin_id
            WHERE r.department_id = ? AND r.grade = ?
            ORDER BY sub.name, r.uploaded_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $departmentId, $grade);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** All resources for one institute, for admin oversight. */
function resources_all(mysqli $conn, int $instituteId): array
{
    $sql = "SELECT r.*, sub.name AS subject_name, d.name AS department_name,
                   COALESCE(t.full_name, a.full_name) AS uploader_name,
                   IF(r.teacher_id IS NOT NULL, 'Teacher', 'Admin') AS uploader_role
            FROM resources r
            JOIN subjects sub ON sub.id = r.subject_id
            JOIN departments d ON d.id = r.department_id
            LEFT JOIN teachers t ON t.id = r.teacher_id
            LEFT JOIN admins a ON a.id = r.admin_id
            WHERE d.institute_id = ?
            ORDER BY r.uploaded_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_resource(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM resources WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function delete_resource(mysqli $conn, int $id, ?int $restrictToTeacherId = null): ?array
{
    $resource = get_resource($conn, $id);
    if (!$resource) {
        return null;
    }
    if ($restrictToTeacherId !== null && (int)$resource['teacher_id'] !== $restrictToTeacherId) {
        return null;
    }
    $stmt = $conn->prepare("DELETE FROM resources WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $resource;
}

/** Every (department, grade, subject) combo that exists in this institute —
 *  what an admin can upload a PDF for, institute-wide (not limited to any
 *  one teacher's assignments). */
function institute_grade_subject_options(mysqli $conn, int $instituteId): array
{
    $sql = "SELECT DISTINCT d.id AS department_id, d.name AS department_name, cs.grade, sub.id AS subject_id, sub.name AS subject_name
            FROM class_sections cs
            JOIN departments d ON d.id = cs.department_id
            JOIN subjects sub ON sub.department_id = d.id
            WHERE d.institute_id = ?
            ORDER BY d.name, LENGTH(cs.grade), cs.grade, sub.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Which department_id + grade a student belongs to (used to gate resource access - ignores group). */
function student_department_and_grade(mysqli $conn, int $studentId): ?array
{
    $sql = "SELECT cs.department_id, cs.grade
            FROM students s
            JOIN class_sections cs ON cs.id = s.class_section_id
            WHERE s.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? ['department_id' => (int)$row['department_id'], 'grade' => $row['grade']] : null;
}

/** Which class_section_id a student belongs to (still used by marks/other features). */
function student_class_section_id(mysqli $conn, int $studentId): ?int
{
    $stmt = $conn->prepare("SELECT class_section_id FROM students WHERE id = ?");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int)$row['class_section_id'] : null;
}

/** A teacher's assigned (department, grade, subject) combos, deduplicated across groups - what they're allowed to upload PDFs for. */
function teacher_grade_subject_options(mysqli $conn, int $teacherId): array
{
    $sql = "SELECT DISTINCT cs.department_id, d.name AS department_name, cs.grade, sub.id AS subject_id, sub.name AS subject_name
            FROM teacher_assignments ta
            JOIN class_sections cs ON cs.id = ta.class_section_id
            JOIN departments d ON d.id = cs.department_id
            JOIN subjects sub ON sub.id = ta.subject_id
            WHERE ta.teacher_id = ?
            ORDER BY d.name, cs.grade, sub.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Whether a teacher has ANY assignment (in any group) covering this department+grade+subject. */
function teacher_can_access_grade_subject(mysqli $conn, int $teacherId, int $departmentId, string $grade, int $subjectId): bool
{
    $sql = "SELECT ta.id
            FROM teacher_assignments ta
            JOIN class_sections cs ON cs.id = ta.class_section_id
            WHERE ta.teacher_id = ? AND cs.department_id = ? AND cs.grade = ? AND ta.subject_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iisi', $teacherId, $departmentId, $grade, $subjectId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function format_file_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return round($bytes / 1024, 1) . ' KB';
}

/* ---------------------- Institutes (owner-facing) ---------------------- */

function all_institutes(mysqli $conn): array
{
    $sql = "SELECT i.*,
                   (SELECT COUNT(*) FROM admins a WHERE a.institute_id = i.id) AS admin_count,
                   (SELECT COUNT(*) FROM teachers t WHERE t.institute_id = i.id) AS teacher_count,
                   (SELECT COUNT(*) FROM students s WHERE s.institute_id = i.id) AS student_count
            FROM institutes i
            ORDER BY i.name";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function get_institute(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM institutes WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function admins_for_institute(mysqli $conn, int $instituteId): array
{
    $stmt = $conn->prepare("SELECT * FROM admins WHERE institute_id = ? ORDER BY full_name");
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* ---------------------- Bulk grade promotion ---------------------- */

/** Every distinct grade that has at least one class section in this department, sorted sensibly (9, 10, 11 not 10, 11, 9). */
function grades_for_department(mysqli $conn, int $departmentId, int $instituteId): array
{
    $stmt = $conn->prepare(
        "SELECT DISTINCT cs.grade
         FROM class_sections cs
         JOIN departments d ON d.id = cs.department_id
         WHERE cs.department_id = ? AND d.institute_id = ?
         ORDER BY LENGTH(cs.grade), cs.grade"
    );
    $stmt->bind_param('ii', $departmentId, $instituteId);
    $stmt->execute();
    return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'grade');
}

/** Class sections (groups) in one department+grade, each with its current student count. */
function class_sections_with_counts(mysqli $conn, int $departmentId, string $grade, int $instituteId): array
{
    $stmt = $conn->prepare(
        "SELECT cs.*, d.name AS department_name,
                (SELECT COUNT(*) FROM students s WHERE s.class_section_id = cs.id AND s.is_active = 1) AS student_count
         FROM class_sections cs
         JOIN departments d ON d.id = cs.department_id
         WHERE cs.department_id = ? AND cs.grade = ? AND d.institute_id = ?
         ORDER BY cs.class_group"
    );
    $stmt->bind_param('isi', $departmentId, $grade, $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** For each source-grade group, find the target-grade section with the SAME
 *  group label (e.g. source "9-A" maps to target "10-A"). Groups with no
 *  matching target are still returned, with target_section_id = null, so
 *  the UI can flag them instead of silently skipping. */
function build_promotion_mapping(mysqli $conn, int $departmentId, string $fromGrade, string $toGrade, int $instituteId): array
{
    $fromSections = class_sections_with_counts($conn, $departmentId, $fromGrade, $instituteId);
    $toSections = class_sections_with_counts($conn, $departmentId, $toGrade, $instituteId);

    $toByGroup = [];
    foreach ($toSections as $t) {
        $toByGroup[$t['class_group']] = $t;
    }

    $mapping = [];
    foreach ($fromSections as $f) {
        $target = $toByGroup[$f['class_group']] ?? null;
        $mapping[] = [
            'from_section' => $f,
            'to_section' => $target,
        ];
    }
    return $mapping;
}

/** Bulk-move a set of students to a new class section, restricted to
 *  students who actually belong to this institute AND are currently in
 *  the expected source section (defense against tampering with the
 *  selected student IDs). Returns how many rows were actually moved. */
function promote_students(mysqli $conn, array $studentIds, int $fromSectionId, int $toSectionId, int $instituteId): int
{
    if (!$studentIds) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $sql = "UPDATE students SET class_section_id = ?
            WHERE class_section_id = ? AND institute_id = ? AND id IN ($placeholders)";
    $types = 'iii' . str_repeat('i', count($studentIds));
    $params = array_merge([$toSectionId, $fromSectionId, $instituteId], $studentIds);

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->affected_rows;
}

/* ---------------------- Per-student attached document (PDF) ---------------------- */

/** Validates an uploaded file is really a PDF within the size limit.
 *  Returns an error message string, or null if it's fine to use. */
function validate_uploaded_pdf(array $file, int $maxBytes): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Please choose a file to upload.';
    }
    if ($file['size'] > $maxBytes) {
        return 'That file is too large (max ' . round($maxBytes / 1048576) . ' MB).';
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($mime !== 'application/pdf' || $extension !== 'pdf') {
        return 'Only PDF files are allowed.';
    }
    return null;
}

/** Moves a validated PDF upload into place and returns its new random
 *  filename on disk, or null if the move itself failed. */
function store_uploaded_pdf(array $file, string $uploadDir): ?string
{
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $storedFilename = bin2hex(random_bytes(20)) . '.pdf';
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $storedFilename)) {
        return $storedFilename;
    }
    return null;
}

function save_student_form(mysqli $conn, int $studentId, string $originalFilename, string $storedFilename, int $fileSize): void
{
    $stmt = $conn->prepare(
        "UPDATE students SET form_original_filename = ?, form_stored_filename = ?, form_file_size = ?, form_uploaded_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param('ssii', $originalFilename, $storedFilename, $fileSize, $studentId);
    $stmt->execute();
}

/** Removes a student's attached document (both the DB reference and, by
 *  returning the old stored filename, lets the caller delete the file too). */
function clear_student_form(mysqli $conn, int $studentId): ?string
{
    $stmt = $conn->prepare("SELECT form_stored_filename FROM students WHERE id = ?");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $old = $row['form_stored_filename'] ?? null;

    $update = $conn->prepare(
        "UPDATE students SET form_original_filename = NULL, form_stored_filename = NULL, form_file_size = NULL, form_uploaded_at = NULL WHERE id = ?"
    );
    $update->bind_param('i', $studentId);
    $update->execute();

    return $old;
}

/* ---------------------- Institute logo (image) ---------------------- */

const LOGO_ALLOWED_MIME_EXT = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

/** Validates an uploaded file is really an image of an allowed type,
 *  within the size limit. Returns an error message, or null if it's fine. */
function validate_uploaded_image(array $file, int $maxBytes): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Please choose a file to upload.';
    }
    if ($file['size'] > $maxBytes) {
        return 'That image is too large (max ' . round($maxBytes / 1048576) . ' MB).';
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset(LOGO_ALLOWED_MIME_EXT[$mime])) {
        return 'Please upload a PNG, JPG, GIF, or WEBP image.';
    }
    return null;
}

/** Moves a validated image upload into place with a random filename (the
 *  extension is derived from the actual validated file content, never
 *  trusted from the uploader) and returns that filename, or null on failure. */
function store_uploaded_image(array $file, string $uploadDir): ?string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $extension = LOGO_ALLOWED_MIME_EXT[$mime] ?? null;
    if (!$extension) {
        return null;
    }
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $storedFilename = bin2hex(random_bytes(20)) . '.' . $extension;
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $storedFilename)) {
        return $storedFilename;
    }
    return null;
}

function save_institute_logo(mysqli $conn, int $instituteId, string $originalFilename, string $storedFilename): void
{
    $stmt = $conn->prepare("UPDATE institutes SET logo_original_filename = ?, logo_stored_filename = ? WHERE id = ?");
    $stmt->bind_param('ssi', $originalFilename, $storedFilename, $instituteId);
    $stmt->execute();
}

/** Removes an institute's logo reference and returns the old stored
 *  filename so the caller can delete the file too (or null if there wasn't one). */
function clear_institute_logo(mysqli $conn, int $instituteId): ?string
{
    $stmt = $conn->prepare("SELECT logo_stored_filename FROM institutes WHERE id = ?");
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $old = $row['logo_stored_filename'] ?? null;

    $update = $conn->prepare("UPDATE institutes SET logo_original_filename = NULL, logo_stored_filename = NULL WHERE id = ?");
    $update->bind_param('i', $instituteId);
    $update->execute();

    return $old;
}

/** Public URL for an institute's logo, or null if it doesn't have one.
 *  Looked up fresh (not session-cached) so a logo change shows immediately
 *  for everyone, without needing to log out and back in. */
function institute_logo_url(mysqli $conn, ?int $instituteId): ?string
{
    if (!$instituteId) {
        return null;
    }
    $stmt = $conn->prepare("SELECT logo_stored_filename FROM institutes WHERE id = ?");
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || !$row['logo_stored_filename']) {
        return null;
    }
    return base_url('uploads/logos/' . $row['logo_stored_filename']);
}

/* ---------------------- Institute Student-Profile PDF template ---------------------- */

function save_institute_profile_template(mysqli $conn, int $instituteId, string $originalFilename, string $storedFilename, int $fileSize): void
{
    $stmt = $conn->prepare(
        "UPDATE institutes SET profile_template_original_filename = ?, profile_template_stored_filename = ?, profile_template_file_size = ?, profile_template_uploaded_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param('ssii', $originalFilename, $storedFilename, $fileSize, $instituteId);
    $stmt->execute();
}

/** Removes an institute's profile-template reference and returns the old
 *  stored filename so the caller can delete the file too (or null). */
function clear_institute_profile_template(mysqli $conn, int $instituteId): ?string
{
    $stmt = $conn->prepare("SELECT profile_template_stored_filename FROM institutes WHERE id = ?");
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $old = $row['profile_template_stored_filename'] ?? null;

    $update = $conn->prepare(
        "UPDATE institutes SET profile_template_original_filename = NULL, profile_template_stored_filename = NULL, profile_template_file_size = NULL, profile_template_uploaded_at = NULL WHERE id = ?"
    );
    $update->bind_param('i', $instituteId);
    $update->execute();

    return $old;
}

/* ---------------------- Student photos (profile photo + 2 extra) ---------------------- */

/** Column names for each of the three photo "slots" a student profile can
 *  hold: the main profile photo, plus two extra photo boxes (matching the
 *  two extra photo boxes on a typical paper enrollment form). Slot keys
 *  are fixed here (never taken from user input), so interpolating the
 *  looked-up column names into SQL below is safe. */
const STUDENT_PHOTO_SLOTS = [
    'main'   => ['original' => 'photo_original_filename',        'stored' => 'photo_stored_filename'],
    'extra1' => ['original' => 'extra_photo1_original_filename', 'stored' => 'extra_photo1_stored_filename'],
    'extra2' => ['original' => 'extra_photo2_original_filename', 'stored' => 'extra_photo2_stored_filename'],
];

function save_student_photo(mysqli $conn, int $studentId, string $slot, string $originalFilename, string $storedFilename): void
{
    if (!isset(STUDENT_PHOTO_SLOTS[$slot])) return;
    $cols = STUDENT_PHOTO_SLOTS[$slot];
    $stmt = $conn->prepare("UPDATE students SET {$cols['original']} = ?, {$cols['stored']} = ? WHERE id = ?");
    $stmt->bind_param('ssi', $originalFilename, $storedFilename, $studentId);
    $stmt->execute();
}

/** Removes one photo slot's reference and returns the old stored filename
 *  so the caller can delete the file too (or null if there wasn't one). */
function clear_student_photo(mysqli $conn, int $studentId, string $slot): ?string
{
    if (!isset(STUDENT_PHOTO_SLOTS[$slot])) return null;
    $cols = STUDENT_PHOTO_SLOTS[$slot];
    $stmt = $conn->prepare("SELECT {$cols['stored']} AS stored FROM students WHERE id = ?");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $old = $row['stored'] ?? null;

    $update = $conn->prepare("UPDATE students SET {$cols['original']} = NULL, {$cols['stored']} = NULL WHERE id = ?");
    $update->bind_param('i', $studentId);
    $update->execute();

    return $old;
}

/** Returns an uploaded image as a self-contained "data:" URI (shrunk to at
 *  most $maxSide px so the page stays light) so it can be embedded straight
 *  into the profile page. Because the picture travels inside the page
 *  itself, it can't be lost to a blocked/altered separate image request.
 *  Returns ['src' => data-URI|null, 'error' => reason|null]; 'error' is
 *  only set when a photo IS recorded for the student but can't be shown. */
function image_data_uri_from_file(string $path, int $maxSide, string $folderLabel): array
{
    if (!is_file($path)) {
        return ['src' => null, 'error' => "the file is missing from $folderLabel on the server"];
    }
    if (!is_readable($path)) {
        return ['src' => null, 'error' => 'the file exists but the web server is not allowed to read it (folder/file permissions)'];
    }
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return ['src' => null, 'error' => 'the file could not be read (or is empty)'];
    }
    $info = @getimagesizefromstring($bytes);
    $mime = $info['mime'] ?? null;
    if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return ['src' => null, 'error' => 'the stored file is not a valid image'];
    }

    // Shrink big photos (e.g. 2560x1440) — a 108px avatar never needs them.
    [$w, $h] = $info;
    if (max($w, $h) > $maxSide && $w * $h <= 40000000 && function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring($bytes);
        if ($src) {
            // Respect phone-camera rotation stored in EXIF (GD drops it on resize).
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
                $angle = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
                if ($angle && function_exists('imagerotate')) {
                    $rot = @imagerotate($src, $angle, 0);
                    if ($rot) { $src = $rot; $w = imagesx($src); $h = imagesy($src); }
                }
            }
            $scale = $maxSide / max($w, $h);
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            $alpha = in_array($mime, ['image/png', 'image/gif', 'image/webp'], true) && function_exists('imagepng');
            if ($alpha) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            $ok = $alpha ? imagepng($dst, null, 6) : imagejpeg($dst, null, 82);
            $out = ob_get_clean();
            if ($ok && $out !== '' && $out !== false) {
                $bytes = $out;
                $mime = $alpha ? 'image/png' : 'image/jpeg';
            }
        }
    }
    return ['src' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'error' => null];
}

/** Student photo (main or extra slot) as a data URI — see image_data_uri_from_file(). */
function student_photo_data_uri(?string $stored, int $maxSide = 400): array
{
    if (!$stored) {
        return ['src' => null, 'error' => null];
    }
    return image_data_uri_from_file(__DIR__ . '/../uploads/student_photos/' . basename($stored), $maxSide, 'uploads/student_photos/');
}

/** The institute's logo as a data URI for printing (embedded in the page so
 *  it can never fail to load when the browser builds the print preview).
 *  Returns null when the institute has no logo or it can't be read. */
function institute_logo_data_uri(?array $institute, int $maxSide = 300): ?string
{
    if (!$institute || empty($institute['logo_stored_filename'])) {
        return null;
    }
    $result = image_data_uri_from_file(__DIR__ . '/../uploads/logos/' . basename($institute['logo_stored_filename']), $maxSide, 'uploads/logos/');
    return $result['src'];
}

/** Streams a stored PDF to the browser (inline) and ends the request. Handles
 *  the details that break downloads on real servers: releases the session lock
 *  so other tabs aren't blocked, discards stray buffered output, turns off
 *  compression (it clashes with Content-Length), and encodes the original
 *  filename safely so Kurdish/Arabic names can't produce an invalid header. */
function send_pdf_file(string $path, string $originalName): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');

    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName);
    if (!preg_match('/[A-Za-z0-9]/', pathinfo($ascii, PATHINFO_FILENAME))) {
        $ascii = 'document.pdf'; // a name with no Latin letters (e.g. Kurdish) — the real one goes in filename*
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    exit;
}

/** Removes every uploaded file (the PDF form and up to 3 photos) that belongs
 *  to one student row, so deleting a student really deletes their personal
 *  files from the server too. Safe if a file is already gone. */
function delete_student_files(array $student): void
{
    if (!empty($student['form_stored_filename'])) {
        @unlink(__DIR__ . '/../uploads/student_forms/' . basename($student['form_stored_filename']));
    }
    foreach (['photo_stored_filename', 'extra_photo1_stored_filename', 'extra_photo2_stored_filename'] as $col) {
        if (!empty($student[$col])) {
            @unlink(__DIR__ . '/../uploads/student_photos/' . basename($student[$col]));
        }
    }
}

/* ==================================================================
   Sign-in rate limiting (brute-force protection).
   Wrong passwords are logged in `login_attempts`. Too many for one
   username/ID, or from one network address, inside the window locks
   further attempts for the rest of the window. If the table hasn't been
   created yet (migration not run) sign-in keeps working, unprotected,
   and the problem is written to the server error log — never a crash.
   ================================================================== */
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_FAILS_PER_ACCOUNT = 8;
const LOGIN_MAX_FAILS_PER_IP = 40;

function login_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function login_attempt_key(string $identifier): string
{
    return substr(mb_strtolower(trim($identifier)), 0, 100);
}

/** ['blocked' => bool, 'minutes' => minutes until it lifts (>= 1)]. */
function login_throttle_status(mysqli $conn, string $identifier): array
{
    try {
        $key = login_attempt_key($identifier);
        $ip = login_client_ip();
        $window = LOGIN_WINDOW_MINUTES;
        $stmt = $conn->prepare(
            "SELECT
                SUM(identifier = ?) AS by_account,
                SUM(ip_address = ?) AS by_ip,
                MIN(CASE WHEN identifier = ? OR ip_address = ? THEN attempted_at END) AS oldest
             FROM login_attempts
             WHERE attempted_at > NOW() - INTERVAL $window MINUTE AND (identifier = ? OR ip_address = ?)"
        );
        $stmt->bind_param('ssssss', $key, $ip, $key, $ip, $key, $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $byAccount = (int)($row['by_account'] ?? 0);
        $byIp = (int)($row['by_ip'] ?? 0);
        if ($byAccount >= LOGIN_MAX_FAILS_PER_ACCOUNT || $byIp >= LOGIN_MAX_FAILS_PER_IP) {
            $oldest = $row['oldest'] ? strtotime($row['oldest']) : time();
            return ['blocked' => true, 'minutes' => max(1, (int)ceil(($oldest + $window * 60 - time()) / 60))];
        }
    } catch (Throwable $e) {
        error_log('Login throttle unavailable (run database/migrations/2026-09-21_login_attempts.sql): ' . $e->getMessage());
    }
    return ['blocked' => false, 'minutes' => 0];
}

function login_record_failure(mysqli $conn, string $identifier): void
{
    try {
        $key = login_attempt_key($identifier);
        $ip = login_client_ip();
        $stmt = $conn->prepare("INSERT INTO login_attempts (ip_address, identifier) VALUES (?, ?)");
        $stmt->bind_param('ss', $ip, $key);
        $stmt->execute();
        $stmt->close();
        if (random_int(1, 25) === 1) { // tidy old rows now and then, no cron job needed
            $conn->query("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
        }
    } catch (Throwable $e) {
        error_log('Login throttle could not record a failure: ' . $e->getMessage());
    }
}

/** A correct sign-in wipes that username/ID's failure count. */
function login_clear_failures(mysqli $conn, string $identifier): void
{
    try {
        $key = login_attempt_key($identifier);
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE identifier = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // table missing: nothing to clear
    }
}

/** Updates the extended Student Profile fields (everything the Student
 *  Profile page shows beyond the core fields already managed from the
 *  Students page). All values are optional/nullable. */
function save_student_extended_profile(mysqli $conn, int $studentId, array $data): void
{
    $stmt = $conn->prepare(
        "UPDATE students SET
            mother_name = ?, birth_date = ?, blood_type = ?, nationality = ?, citizenship = ?, religion = ?,
            clothing_size = ?, brothers_count = ?, sisters_count = ?, birth_order = ?,
            father_phone = ?, mother_phone = ?, previous_school = ?, years_in_current_grade = ?,
            id_type = ?, last_year_average = ?
         WHERE id = ?"
    );
    $stmt->bind_param(
        'sssssssiiisssissi',
        $data['mother_name'], $data['birth_date'], $data['blood_type'], $data['nationality'], $data['citizenship'], $data['religion'],
        $data['clothing_size'], $data['brothers_count'], $data['sisters_count'], $data['birth_order'],
        $data['father_phone'], $data['mother_phone'], $data['previous_school'], $data['years_in_current_grade'],
        $data['id_type'], $data['last_year_average'],
        $studentId
    );
    $stmt->execute();
}

/** Fixed dropdown option lists for the Add/Edit Student form. Values are
 *  stored exactly as listed (Kurdish text where the option itself is Kurdish),
 *  same as the existing Blood Type dropdown. */
const STUDENT_ID_TYPES = ['کارتی نیشتیمانی', 'پاسپۆرت', 'ناسنامەی باری شارستانی', 'ئیقامە', 'هیتر'];
const STUDENT_CLOTHING_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'XXXXL', 'XXXXXL'];
const STUDENT_RELIGIONS = ['ئیسلام', 'مسیحی', 'کاکەیی', 'زەردەشتی', 'ئیزیدی', 'هیتر'];
const STUDENT_NATIONALITIES = ['کورد', 'عرب', 'تورك', 'سوری', 'فارس', 'ئەرمەنی', 'کلدنی', 'سریانی', 'ئاشوری', 'تورکمانی', 'ئەلمانی', 'هیتر'];
const STUDENT_CITIZENSHIPS = ['عراق', 'سوریا', 'تورکیا', 'ئێران', 'هیتر'];

/* ==================================================================
   Students Excel (CSV) export / import + bulk delete.
   ================================================================== */

/** Every column of the students Excel file, in the order used by both the
 *  export and the import template — so a file exported from this system can
 *  be edited and re-imported unchanged. One column per input on the Add /
 *  Edit student forms. Required to import: Full Name, National ID Number,
 *  Department, Grade, Group. "ID" and "Status" are informational on export
 *  and ignored on import. */
const STUDENT_IMPORT_COLUMNS = [
    'ID', 'Full Name', 'National ID Number', 'Department', 'Grade', 'Group',
    'Gender', 'Birth Date', 'ID Type', 'Blood Type', 'Nationality', 'Citizenship', 'Religion', 'Clothing Size',
    'Mother Name', 'Father Phone', 'Mother Phone', 'Brothers', 'Sisters', 'Birth Order',
    'Previous School', 'Years in Current Grade', 'Last Year Average',
    'Phone', 'Phone 2', 'Home Address', 'Added On', 'Status',
];
const STUDENT_IMPORT_REQUIRED = ['Full Name', 'National ID Number', 'Department', 'Grade', 'Group'];

/** Optional student columns that map 1:1 onto the extended profile fields:
 *  header => [database column, kind, limit]. kind is text | phone | int | date;
 *  limit is the max characters (text/phone) or the max value (int). */
const STUDENT_IMPORT_EXTENDED = [
    'ID Type'                => ['id_type', 'text', 50],
    'Birth Date'             => ['birth_date', 'date', 10],
    'Blood Type'             => ['blood_type', 'text', 10],
    'Nationality'            => ['nationality', 'text', 50],
    'Citizenship'            => ['citizenship', 'text', 50],
    'Religion'               => ['religion', 'text', 50],
    'Clothing Size'          => ['clothing_size', 'text', 20],
    'Mother Name'            => ['mother_name', 'text', 150],
    'Father Phone'           => ['father_phone', 'phone', 30],
    'Mother Phone'           => ['mother_phone', 'phone', 30],
    'Brothers'               => ['brothers_count', 'int', 99],
    'Sisters'                => ['sisters_count', 'int', 99],
    'Birth Order'            => ['birth_order', 'int', 99],
    'Previous School'        => ['previous_school', 'text', 150],
    'Years in Current Grade' => ['years_in_current_grade', 'int', 99],
    'Last Year Average'      => ['last_year_average', 'text', 20],
];

/** Columns the exported/template file formats as Text, so Excel keeps a
 *  typed "07501234567" or "2010-05-21" exactly as typed. */
const STUDENT_TEXT_COLUMNS = [
    'Full Name', 'National ID Number', 'Department', 'Grade', 'Group', 'Gender', 'Birth Date', 'ID Type',
    'Blood Type', 'Nationality', 'Citizenship', 'Religion', 'Clothing Size', 'Mother Name', 'Father Phone', 'Mother Phone',
    'Previous School', 'Last Year Average', 'Phone', 'Phone 2', 'Home Address', 'Added On',
];

/** "Mother's Name", "mother name", "MOTHER  NAME" -> "mothersname"/"mothername"... */
function import_header_key(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
    return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($header))) ?? '';
}

/** normalised header text => the canonical column name above. Accepts each
 *  canonical name itself plus common alternative spellings, so a file made
 *  by hand ("Name", "Mother's Name", "DOB", "Mobile"...) still imports. */
function student_import_header_aliases(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $aliases = [
        'ID'                     => ['studentid'],
        'Full Name'              => ['name', 'studentname', 'fullnameofstudent'],
        'National ID Number'     => ['nationalid', 'idnumber', 'idno', 'nationalidno', 'nid', 'idcardnumber'],
        'Department'             => ['dept'],
        'Gender'                 => ['sex'],
        'Birth Date'             => ['birthdate', 'dateofbirth', 'dob', 'birthday'],
        'ID Type'                => ['idcardtype', 'typeofid'],
        'Blood Type'             => ['bloodgroup'],
        'Clothing Size'          => ['uniformsize', 'clothessize'],
        'Citizenship'            => ['citizen', 'countryofcitizenship'],
        'Mother Name'            => ['mothersname'],
        'Father Phone'           => ['fathersphone', 'fathermobile', 'fatherphonenumber'],
        'Mother Phone'           => ['mothersphone', 'mothermobile', 'motherphonenumber'],
        'Brothers'               => ['brotherscount', 'numberofbrothers', 'nobrothers'],
        'Sisters'                => ['sisterscount', 'numberofsisters', 'nosisters'],
        'Birth Order'            => ['orderinfamily', 'childnumber'],
        'Previous School'        => ['lastschool', 'formerschool'],
        'Years in Current Grade' => ['yearsingrade', 'yearsincurrentclass'],
        'Last Year Average'      => ['lastyearsaverage', 'previousaverage', 'lastaverage'],
        'Phone'                  => ['studentphone', 'phonenumber', 'mobile', 'studentmobile'],
        'Phone 2'                => ['studentphone2', 'secondphone', 'phone02'],
        'Home Address'           => ['address'],
        'Added On'               => ['addedat', 'dateadded', 'createdat', 'created', 'registeredon', 'registrationdate', 'dateregistered', 'enrolleddate', 'dateenrolled'],
    ];
    $map = [];
    foreach (STUDENT_IMPORT_COLUMNS as $canonical) {
        $map[import_header_key($canonical)] = $canonical;
    }
    foreach ($aliases as $canonical => $list) {
        foreach ($list as $alias) $map[$alias] = $canonical;
    }
    return $map;
}

/** Turns one parsed spreadsheet row (keyed by whatever headers the file
 *  has) into a row keyed by the canonical column names. Unknown headers are
 *  dropped and reported through $ignored. */
function student_import_canonical_row(array $row, ?array &$ignored = null): array
{
    $aliases = student_import_header_aliases();
    $out = [];
    $ignored = [];
    foreach ($row as $header => $value) {
        $header = (string)$header;
        $key = import_header_key($header);
        if ($key === '') continue;
        if (isset($aliases[$key])) {
            $canonical = $aliases[$key];
            // If a file somehow has two columns that mean the same thing, keep the first non-empty one.
            if (!isset($out[$canonical]) || $out[$canonical] === '') $out[$canonical] = (string)$value;
        } else {
            $ignored[] = trim($header);
        }
    }
    return $out;
}

/** Kurdish/Arabic (٠١٢٣) and Persian (۰۱۲۳) digits -> 0123... */
function normalize_import_digits(string $s): string
{
    return strtr($s, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
}

/** Trims a cell and removes the invisible junk Excel/Word/copy-paste adds
 *  (non-breaking spaces, zero-width spaces, left/right-to-left marks) and
 *  collapses runs of whitespace. Deliberately keeps the zero-width
 *  non-joiner, which is a real part of some Kurdish/Persian words. */
function import_clean_text(string $s): string
{
    $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8E", "\xE2\x80\x8F", "\xEF\xBB\xBF"], [' ', '', '', '', ''], $s);
    $s = preg_replace('/[\x{202A}-\x{202E}]/u', '', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

/** male/female (also m/f, and the Kurdish/Arabic words) -> [value, ok].
 *  Blank is fine (value null); anything unrecognised is ok=false. */
function import_parse_gender(string $raw): array
{
    $k = mb_strtolower(import_clean_text($raw));
    if ($k === '') return [null, true];
    if (in_array($k, ['male', 'm', 'man', 'boy', 'نێر', 'نير', 'ذكر', 'ذكور'], true)) return ['male', true];
    if (in_array($k, ['female', 'f', 'woman', 'girl', 'مێ', 'مي', 'انثى', 'أنثى', 'أنثي', 'مؤنث'], true)) return ['female', true];
    return [null, false];
}

/** A birth date typed any common way -> [YYYY-MM-DD|null, ok]. Accepts
 *  2010-05-21, 2010/05/21, 21/05/2010 (day first), 21-05-2010, 21.05.2010,
 *  a date with a time after it, and the raw serial number Excel stores
 *  behind a date cell (e.g. 40319). */
function import_parse_date(string $raw): array
{
    $s = normalize_import_digits(import_clean_text($raw));
    if ($s === '') return [null, true];
    $s = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?(\.\d+)?Z?$/', '', $s) ?? $s;

    if (preg_match('/^(\d{5})(\.\d+)?$/', $s, $m) && (int)$m[1] >= 10000 && (int)$m[1] <= 60000) {
        $d = new DateTime('1899-12-30');
        $d->modify('+' . (int)$m[1] . ' days');
        return [$d->format('Y-m-d'), true];
    }
    if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$#', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$#', $s, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return [null, false];
    }
    if (!checkdate($mo, $d, $y) || $y < 1900 || $y > (int)date('Y')) return [null, false];
    return [sprintf('%04d-%02d-%02d', $y, $mo, $d), true];
}

/** When a student was added, typed any common way -> ['YYYY-MM-DD HH:MM:SS'|null, ok].
 *  Accepts everything import_parse_date() does, optionally followed by a time
 *  (13:48, 13:48:05, 1:48 PM), plus Excel's own date-and-time cell value
 *  (e.g. 46285.5756). A date with no time means midnight. Blank is fine
 *  (null = "use the moment of import"); a time in the future is not. */
function import_parse_datetime(string $raw): array
{
    $s = normalize_import_digits(import_clean_text($raw));
    if ($s === '') return [null, true];

    $time = [0, 0, 0];
    if (preg_match('/^(\d{5})(\.\d+)?$/', $s, $m) && (int)$m[1] >= 10000 && (int)$m[1] <= 60000) {
        // Excel serial: whole number = the day, fraction = the time of day.
        $secs = min(86399, (int)round((float)('0' . ($m[2] ?? '')) * 86400));
        $date = (new DateTime('1899-12-30'))->modify('+' . (int)$m[1] . ' days')->format('Y-m-d');
        $time = [intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60];
    } else {
        if (!preg_match('/^(.+?)(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?\s*([ap]m)?)?Z?$/i', $s, $m)) {
            return [null, false];
        }
        [$date, $ok] = import_parse_date($m[1]);
        if (!$ok || $date === null) return [null, false];
        if (isset($m[2]) && $m[2] !== '') {
            $h = (int)$m[2];
            $ampm = strtolower($m[5] ?? '');
            if ($ampm === 'pm' && $h < 12) $h += 12;
            if ($ampm === 'am' && $h === 12) $h = 0;
            $time = [$h, (int)$m[3], (int)($m[4] ?? 0)];
            if ($time[0] > 23 || $time[1] > 59 || $time[2] > 59) return [null, false];
        }
    }
    $out = sprintf('%s %02d:%02d:%02d', $date, $time[0], $time[1], $time[2]);
    if (strtotime($out) > time() + 300) return [null, false]; // a student can't have been added in the future
    return [$out, true];
}

/** A stored timestamp as "2026-09-20 13:48" (in the app's time zone) for
 *  on-screen display; '' when there isn't one. */
function format_added_at(?string $timestamp, bool $withSeconds = false): string
{
    if ($timestamp === null || $timestamp === '' || str_starts_with($timestamp, '0000')) return '';
    $t = strtotime($timestamp);
    return $t ? date($withSeconds ? 'Y-m-d H:i:s' : 'Y-m-d H:i', $t) : '';
}

/** Is this National ID Number already used by a student in the institute?
 *  ($exceptStudentId lets the edit form ignore the student being edited.) */
function student_national_id_taken(mysqli $conn, int $instituteId, string $idNumber, int $exceptStudentId = 0): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM students WHERE institute_id = ? AND id_number = ? AND id <> ? LIMIT 1");
    $stmt->bind_param('isi', $instituteId, $idNumber, $exceptStudentId);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $taken;
}

/** Reads the optional profile inputs (birth date, blood type, mother's name,
 *  brothers, ...) from a submitted Add/Edit student form and checks them with
 *  the same rules as the Excel import — a real calendar date, whole numbers in
 *  range, and text no longer than its database column. Problems are appended
 *  to $errors as readable sentences; the return value is ready for
 *  save_student_extended_profile(). This stops bad input from ever reaching
 *  the database (which would otherwise fail halfway through saving). */
function student_extended_from_post(array $post, array &$errors): array
{
    $out = [];
    foreach (STUDENT_IMPORT_EXTENDED as $label => [$field, $kind, $limit]) {
        $raw = trim((string)($post[$field] ?? ''));
        $value = null;
        if ($kind === 'date') {
            [$value, $ok] = import_parse_date($raw);
            if (!$ok) $errors[] = "$label isn't a valid date (use YYYY-MM-DD, e.g. 2010-05-21).";
        } elseif ($kind === 'int') {
            [$value, $ok] = import_parse_int($raw, $limit);
            if (!$ok) $errors[] = "$label must be a whole number from 0 to $limit.";
        } else {
            $text = import_clean_text($raw);
            if (mb_strlen($text) > $limit) $errors[] = "$label is too long (max $limit characters).";
            $value = $text === '' ? null : $text;
        }
        $out[$field] = $value;
    }
    return $out;
}

/** Length checks for the core student fields (the ones stored on the main row). */
function student_core_input_errors(array $nameParts, string $idNumber, string $phone, string $phone2, string $homeAddress): array
{
    $errors = [];
    foreach ($nameParts as $part) {
        if (mb_strlen($part) > 50) {
            $errors[] = 'Each part of the name can be at most 50 characters.';
            break;
        }
    }
    if (mb_strlen($idNumber) > 50) $errors[] = 'National ID Number is too long (max 50 characters).';
    if (mb_strlen($phone) > 30) $errors[] = 'Phone is too long (max 30 characters).';
    if (mb_strlen($phone2) > 30) $errors[] = 'Phone 2 is too long (max 30 characters).';
    if (mb_strlen($homeAddress) > 255) $errors[] = 'Home address is too long (max 255 characters).';
    return $errors;
}

/** An amount typed by a person -> float, or null if it isn't a clean number.
 *  Accepts "1500000", "1,500,000", "1 500 000", "1500.50" and Kurdish/Arabic
 *  digits/separators. Never guesses: "abc" or "12abc" is null (a bare (float)
 *  cast would turn them into 0 and silently overwrite a real price). The upper
 *  limit is what the database's decimal(12,2) columns can hold. */
function parse_money_input(?string $raw): ?float
{
    $s = normalize_import_digits(trim((string)$raw));
    $s = str_replace(['٬', ',', ' ', "\xC2\xA0", '٫'], ['', '', '', '', '.'], $s);
    if ($s === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
        return null;
    }
    $value = (float)$s;
    return $value <= 9999999999.99 ? $value : null;
}

/** "4", "4.0", "٤" -> [4, true]; blank -> [null, true]; anything else or
 *  above $max -> ok=false. */
function import_parse_int(string $raw, int $max): array
{
    $s = normalize_import_digits(import_clean_text($raw));
    if ($s === '') return [null, true];
    if (!preg_match('/^\d+(\.0+)?$/', $s)) return [null, false];
    $n = (int)$s;
    return $n <= $max ? [$n, true] : [null, false];
}

/** Cleans a phone number. Excel turns a typed 07501234567 into the number
 *  7501234567 (dropping the leading 0); a bare 10-digit number starting with 7
 *  is an Iraqi/Kurdistan mobile number that lost it, so the 0 is put back. */
function import_normalize_phone(string $raw): string
{
    $s = normalize_import_digits(import_clean_text($raw));
    if (preg_match('/^7\d{9}$/', $s)) $s = '0' . $s;
    return $s;
}

const TEACHER_EXPORT_COLUMNS = ['ID', 'Full Name', 'Gender', 'Username', 'Email', 'Phone', 'Joined', 'Status'];

/** Streams a UTF-8 CSV (opens directly in Excel, no plugin needed) as a
 *  file download and ends the request. $rows is a list of associative
 *  arrays; only the keys in $columns are written, in that order. */
function send_csv_download(string $filename, array $columns, array $rows): void
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders non-Latin text (Kurdish/Arabic/etc.) correctly
    fputcsv($out, $columns);
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $col) {
            $line[] = $row[$col] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/** Parses an uploaded CSV file into an array of associative rows keyed by
 *  the header found in the file's own first row (so column order doesn't
 *  matter). Copes with the comma, semicolon or tab separators different
 *  Excel language settings produce, and with a UTF-8 BOM. Returns null (and
 *  sets $error) if the file can't be read — including when it isn't UTF-8,
 *  because guessing would silently import garbled Kurdish/Arabic names. */
function parse_csv_upload(string $tmpPath, ?string &$error = null): ?array
{
    $data = @file_get_contents($tmpPath);
    if ($data === false) {
        $error = 'That .csv file could not be read.';
        return null;
    }
    if (strncmp($data, "\xEF\xBB\xBF", 3) === 0) {
        $data = substr($data, 3);
    }
    if ($data !== '' && !mb_check_encoding($data, 'UTF-8')) {
        $error = 'That .csv file is not saved as UTF-8, so Kurdish/Arabic text would be garbled. In Excel choose "Save As" → "CSV UTF-8 (Comma delimited)", or just upload the .xlsx file instead.';
        return null;
    }

    // Pick the separator by looking at the header line.
    $firstLine = strtok($data, "\r\n") ?: '';
    $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $delimiter = key($counts);

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $data);
    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter);
    if ($header === false) {
        fclose($handle);
        return [];
    }
    $header = array_map(fn($h) => trim((string)$h), $header);
    $rows = [];
    while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($cells) === 1 && trim((string)$cells[0]) === '') {
            continue; // skip blank lines
        }
        $row = [];
        foreach ($header as $i => $colName) {
            if ($colName === '') continue;
            $row[$colName] = trim((string)($cells[$i] ?? ''));
        }
        if (implode('', $row) === '') continue;
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

/** Builds a lookup of every class section in an institute, keyed by
 *  "department|grade|group" (lowercased) for case/whitespace-insensitive
 *  matching against imported spreadsheet values. */
function class_section_lookup_by_label(mysqli $conn, int $instituteId): array
{
    $lookup = [];
    foreach (all_class_sections($conn, $instituteId) as $s) {
        $key = mb_strtolower(trim($s['department_name'])) . '|' . mb_strtolower(trim($s['grade'])) . '|' . mb_strtolower(trim($s['class_group']));
        $lookup[$key] = $s;
    }
    return $lookup;
}

/** A short random password for bulk-imported students who weren't given
 *  one in their spreadsheet row. Digits only, easy to read back/retype. */
function generate_random_password(): string
{
    return (string)random_int(100000, 999999);
}

/* ====================================================================
 * ACCOUNTING — department/grade pricing set by an admin, an optional
 * per-student discount, and the read-only worksheet the accountant role
 * sees. Accountants never touch marks, attendance, or anything else.
 * ==================================================================== */

function accountant_belongs_to_institute(mysqli $conn, int $accountantId, int $instituteId): bool
{
    $stmt = $conn->prepare("SELECT id FROM accountants WHERE id = ? AND institute_id = ?");
    $stmt->bind_param('ii', $accountantId, $instituteId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc() !== null;
    $stmt->close();
    return $found;
}

function get_accountant(mysqli $conn, int $accountantId, int $instituteId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM accountants WHERE id = ? AND institute_id = ?");
    $stmt->bind_param('ii', $accountantId, $instituteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function all_accountants(mysqli $conn, int $instituteId, string $search = ''): array
{
    $sql = "SELECT * FROM accountants WHERE institute_id = ?";
    $types = 'i';
    $params = [$instituteId];
    if ($search !== '') {
        $sql .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
        $like = "%$search%";
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }
    $sql .= " ORDER BY full_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Every priced (department, grade) row for one institute. */
function department_prices(mysqli $conn, int $instituteId): array
{
    $stmt = $conn->prepare(
        "SELECT dp.*, d.name AS department_name
         FROM department_prices dp
         JOIN departments d ON d.id = dp.department_id
         WHERE d.institute_id = ?
         ORDER BY d.name, LENGTH(dp.grade), dp.grade"
    );
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Every grade that has a price set for one department — this is deliberately independent
 *  of class_sections, since an admin often prices a grade (e.g. next year's grade 11) before
 *  any class section or student exists in it yet. Used to list every grade a student's
 *  discount can be set for, not just the one they currently sit in. */
function priced_grades_for_department(mysqli $conn, int $departmentId): array
{
    $stmt = $conn->prepare(
        "SELECT grade FROM department_prices WHERE department_id = ? ORDER BY LENGTH(grade), grade"
    );
    $stmt->bind_param('i', $departmentId);
    $stmt->execute();
    return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'grade');
}

/** Creates or overwrites the price for one department + grade. */
function set_department_price(mysqli $conn, int $departmentId, string $grade, float $price): void
{
    $stmt = $conn->prepare(
        "INSERT INTO department_prices (department_id, grade, price) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE price = VALUES(price)"
    );
    $stmt->bind_param('isd', $departmentId, $grade, $price);
    $stmt->execute();
}

function department_price_belongs_to_institute(mysqli $conn, int $id, int $instituteId): bool
{
    $stmt = $conn->prepare(
        "SELECT dp.id FROM department_prices dp
         JOIN departments d ON d.id = dp.department_id
         WHERE dp.id = ? AND d.institute_id = ?"
    );
    $stmt->bind_param('ii', $id, $instituteId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc() !== null;
    $stmt->close();
    return $found;
}

function delete_department_price(mysqli $conn, int $id, int $instituteId): bool
{
    if (!department_price_belongs_to_institute($conn, $id, $instituteId)) {
        return false;
    }
    $stmt = $conn->prepare("DELETE FROM department_prices WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->affected_rows > 0;
}

/** All discount rows a student has, one per grade, keyed by grade. */
function student_discounts_for_student(mysqli $conn, int $studentId): array
{
    $stmt = $conn->prepare("SELECT * FROM student_discounts WHERE student_id = ?");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $byGrade = [];
    foreach ($rows as $row) {
        $byGrade[$row['grade']] = $row;
    }
    return $byGrade;
}

/** Every student's discount rows in one query, as [student_id => [grade => row]]. Used to
 *  prefill the "set discounts for every grade" panel for a whole list of students at once. */
function student_discounts_map(mysqli $conn, array $studentIds): array
{
    $map = [];
    if (!$studentIds) {
        return $map;
    }
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $types = str_repeat('i', count($studentIds));
    $stmt = $conn->prepare("SELECT * FROM student_discounts WHERE student_id IN ($placeholders)");
    $stmt->bind_param($types, ...$studentIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $row) {
        $map[$row['student_id']][$row['grade']] = $row;
    }
    return $map;
}

/** Sets (or replaces) a student's discount for one specific grade. */
function set_student_discount(mysqli $conn, int $studentId, string $grade, string $type, float $value, ?string $reason, int $adminId): void
{
    $type = $type === 'percent' ? 'percent' : 'fixed';
    $stmt = $conn->prepare(
        "INSERT INTO student_discounts (student_id, grade, discount_type, discount_value, reason, created_by_admin_id)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE discount_type = VALUES(discount_type), discount_value = VALUES(discount_value),
             reason = VALUES(reason), created_by_admin_id = VALUES(created_by_admin_id)"
    );
    $stmt->bind_param('issdsi', $studentId, $grade, $type, $value, $reason, $adminId);
    $stmt->execute();
}

/** Saves a student's discounts for several grades in one go — e.g. from a form that lets an
 *  admin fill in a discount per grade for a student all at once. $discounts is
 *  [grade => ['type' => 'percent'|'fixed', 'value' => float, 'reason' => ?string]].
 *  A grade left out of $discounts, or with a blank/zero value, has its discount cleared. */
function set_student_discounts_bulk(mysqli $conn, int $studentId, array $grades, array $discounts, int $adminId): void
{
    foreach ($grades as $grade) {
        $entry = $discounts[$grade] ?? null;
        if (!$entry || $entry['value'] === null || $entry['value'] === '') {
            clear_student_discount($conn, $studentId, $grade);
            continue;
        }
        set_student_discount($conn, $studentId, $grade, $entry['type'], (float)$entry['value'], $entry['reason'] ?? null, $adminId);
    }
}

function clear_student_discount(mysqli $conn, int $studentId, ?string $grade = null): void
{
    if ($grade !== null) {
        $stmt = $conn->prepare("DELETE FROM student_discounts WHERE student_id = ? AND grade = ?");
        $stmt->bind_param('is', $studentId, $grade);
    } else {
        $stmt = $conn->prepare("DELETE FROM student_discounts WHERE student_id = ?");
        $stmt->bind_param('i', $studentId);
    }
    $stmt->execute();
}

/** Applies a discount row (percent or fixed) to a base price. Never goes below zero. */
function apply_discount(float $basePrice, ?array $discount): float
{
    if (!$discount) {
        return round($basePrice, 2);
    }
    if ($discount['discount_type'] === 'percent') {
        $final = $basePrice - ($basePrice * ((float)$discount['discount_value'] / 100));
    } else {
        $final = $basePrice - (float)$discount['discount_value'];
    }
    return max(0.0, round($final, 2));
}

/** Every active student in the institute with their department/grade,
 *  base price (0 if the admin hasn't priced that grade yet), discount, and
 *  final price. This is the one worksheet both admins and accountants see —
 *  it never touches marks, attendance, or anything else. */
function students_with_pricing(mysqli $conn, int $instituteId, ?int $departmentId = null, ?string $grade = null, string $search = '', bool $onlyDiscounted = false): array
{
    $sql = "SELECT s.id, s.first_name, s.second_name, s.last_name,
                   cs.id AS class_section_id, cs.grade, cs.class_group,
                   d.id AS department_id, d.name AS department_name,
                   dp.price AS base_price,
                   sd.discount_type, sd.discount_value, sd.reason AS discount_reason
            FROM students s
            JOIN class_sections cs ON cs.id = s.class_section_id
            JOIN departments d ON d.id = cs.department_id
            LEFT JOIN department_prices dp ON dp.department_id = d.id AND dp.grade = cs.grade
            LEFT JOIN student_discounts sd ON sd.student_id = s.id AND sd.grade = cs.grade
            WHERE s.institute_id = ? AND s.is_active = 1";
    $types = 'i';
    $params = [$instituteId];

    if ($onlyDiscounted) {
        $sql .= " AND sd.id IS NOT NULL";
    }
    if ($departmentId) {
        $sql .= " AND d.id = ?";
        $types .= 'i';
        $params[] = $departmentId;
    }
    if ($grade !== null && $grade !== '') {
        $sql .= " AND cs.grade = ?";
        $types .= 's';
        $params[] = $grade;
    }
    if ($search !== '') {
        $sql .= " AND CONCAT_WS(' ', s.first_name, s.second_name, s.last_name) LIKE ?";
        $types .= 's';
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY d.name, LENGTH(cs.grade), cs.grade, s.first_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $base = $row['base_price'] !== null ? (float)$row['base_price'] : 0.0;
        $discount = $row['discount_type']
            ? ['discount_type' => $row['discount_type'], 'discount_value' => $row['discount_value']]
            : null;
        $row['base_price'] = $base;
        $row['final_price'] = apply_discount($base, $discount);
        $row['discount_amount'] = round($base - $row['final_price'], 2);
    }
    unset($row);

    return $rows;
}

/** Totals for the accounting summary cards: how many priced students,
 *  total base fees, total discounts granted, and total expected revenue
 *  after discounts. */
function accounting_summary(mysqli $conn, int $instituteId): array
{
    $rows = students_with_pricing($conn, $instituteId);
    $summary = ['students' => count($rows), 'unpriced' => 0, 'total_base' => 0.0, 'total_discount' => 0.0, 'total_final' => 0.0];
    foreach ($rows as $row) {
        $summary['total_base'] += $row['base_price'];
        $summary['total_discount'] += $row['discount_amount'];
        $summary['total_final'] += $row['final_price'];
        if ($row['base_price'] <= 0) {
            $summary['unpriced']++;
        }
    }
    return $summary;
}

/** Plain thousands-separated number for a price/fee amount. Institutes use
 *  their own local currency, so this deliberately doesn't attach a symbol. */
function format_money(float $amount): string
{
    return number_format($amount, 2);
}


/* Student payments ledger (record payments, balances, statements, receipts). */
require_once __DIR__ . '/payments.php';

/* Languages: English / Kurdish / Arabic (see includes/i18n.php). */
require_once __DIR__ . '/i18n.php';

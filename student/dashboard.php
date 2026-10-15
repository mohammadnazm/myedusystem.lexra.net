<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('student');

$studentId = current_user_id();
$semester = $_GET['semester'] ?? 'first';
if (!in_array($semester, ['first', 'second'], true)) {
    $semester = 'first';
}

$stmt = $conn->prepare(
    "SELECT sub.name AS subject_name, m.quiz_assignment, m.participation, m.first_term, m.second_term, m.final
     FROM subjects sub
     JOIN students st ON st.class_section_id IN (SELECT class_section_id FROM class_sections WHERE department_id = sub.department_id)
     LEFT JOIN marks m ON m.subject_id = sub.id AND m.student_id = st.id AND m.semester = ?
     WHERE st.id = ?
     ORDER BY sub.name"
);
$stmt->bind_param('si', $semester, $studentId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Only show subjects that actually have at least one mark entered for this student.
$rows = array_values(array_filter($rows, function ($r) {
    foreach (['quiz_assignment', 'participation', 'first_term', 'second_term', 'final'] as $f) {
        if ($r[$f] !== null) return true;
    }
    return false;
}));

$pageTitle = 'My Marks';
$role = 'student';
$activePage = 'dashboard';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel content-fill">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="panel-title mb-0">Welcome, <?= h(current_user_name()) ?></div>
    <form method="get">
      <select name="semester" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="first" <?= $semester === 'first' ? 'selected' : '' ?>>First term</option>
        <option value="second" <?= $semester === 'second' ? 'selected' : '' ?>>Second term</option>
      </select>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state"><i class="bi bi-journal-bookmark"></i>No marks have been added for this term yet.</div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="table">
      <thead>
        <tr>
          <th>Subject</th>
          <th>Quiz (/5)</th>
          <th>Participation (/10)</th>
          <th>1st Term (/20)</th>
          <th>2nd Term (/20)</th>
          <th>Final (/60)</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $fields = [$r['quiz_assignment'], $r['participation'], $r['first_term'], $r['second_term'], $r['final']];
          $graded = array_filter($fields, fn($v) => $v !== null);
          $total = $graded ? array_sum($graded) : null;
        ?>
          <tr>
            <td class="fw-semibold"><?= h($r['subject_name']) ?></td>
            <td><?= $r['quiz_assignment'] !== null ? h((string)$r['quiz_assignment']) : '—' ?></td>
            <td><?= $r['participation'] !== null ? h((string)$r['participation']) : '—' ?></td>
            <td><?= $r['first_term'] !== null ? h((string)$r['first_term']) : '—' ?></td>
            <td><?= $r['second_term'] !== null ? h((string)$r['second_term']) : '—' ?></td>
            <td><?= $r['final'] !== null ? h((string)$r['final']) : '—' ?></td>
            <td class="fw-bold"><?= $total !== null ? h((string)$total) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('student');

$studentId = current_user_id();
$instituteId = current_institute_id();

$stmt = $conn->prepare(
    "SELECT s.*, cs.grade, cs.class_group, d.name AS department_name
     FROM students s
     LEFT JOIN class_sections cs ON cs.id = s.class_section_id
     LEFT JOIN departments d ON d.id = cs.department_id
     WHERE s.id = ? AND s.institute_id = ?"
);
$stmt->bind_param('ii', $studentId, $instituteId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    header('Location: ' . base_url('logout.php'));
    exit;
}

$photo = student_photo_data_uri($student['photo_stored_filename'] ?? null);

/** One read-only labelled value (same look as the admin profile page). */
function my_field(string $label, $value): void
{
    $value = trim((string)$value);
    echo '<div class="profile-field"><label>' . h($label) . '</label>';
    if ($value === '') {
        echo '<div class="value empty">—</div>';
    } else {
        echo '<div class="value kurdish-text">' . h($value) . '</div>';
    }
    echo '</div>';
}

$pageTitle = 'My Profile';
$role = 'student';
$activePage = 'profile';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="profile-hero mb-4">
  <div class="profile-photo-frame">
    <?php if ($photo['src']): ?>
      <img src="<?= $photo['src'] ?>" alt="">
    <?php else: ?>
      <i class="bi bi-person"></i>
    <?php endif; ?>
  </div>
  <div>
    <div class="profile-hero-name kurdish-text"><?= h(student_full_name($student)) ?></div>
    <div class="profile-hero-meta">
      <span class="badge">#<?= (int)$student['id'] ?></span>
      <?php if ($student['department_name']): ?>
        <span class="badge"><?= h($student['department_name']) ?> · G<?= h($student['grade']) ?> · <?= h($student['class_group']) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="profile-section">
  <div class="profile-section-title"><i class="bi bi-person-vcard"></i>My information</div>
  <div class="profile-field-grid">
    <?php
      my_field('Full name', student_full_name($student));
      my_field('Phone', $student['phone'] ?? '');
      my_field('Department', $student['department_name'] ?? '');
      my_field('Grade', $student['grade'] ?? '');
      my_field('Class', $student['class_group'] ?? '');
    ?>
  </div>
</div>

<div class="mt-3">
  <a href="<?= base_url('student/dashboard.php') ?>" class="btn btn-brand"><i class="bi bi-mortarboard me-1"></i>View my marks</a>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

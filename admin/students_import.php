<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/xlsx_reader.php';
require_role('admin');

$instituteId = current_institute_id();
$sessionKey = 'student_import_preview_' . $instituteId;

$step = 'upload'; // upload | preview | done
$preview = null;   // ['valid' => [...], 'errors' => [...], 'total' => n]
$importResults = null; // after confirm: list of ['id','name','id_number']
$importSkipped = null; // after confirm: list of names skipped due to a race-condition duplicate

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            flash_set('danger', 'Please choose a .xlsx or .csv file to upload.');
        } else {
            $originalName = $_FILES['import_file']['name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $tmpPath = $_FILES['import_file']['tmp_name'];

            $readError = null;
            $rows = null;
            if ($ext === 'xlsx') {
                if (!xlsx_support_available()) {
                    $readError = 'This server\'s PHP is missing the ' . implode(' and ', xlsx_missing_extensions()) . ' extension, so .xlsx files can\'t be read. Enable it in php.ini (e.g. extension=zip) and restart the web server, or save the sheet as "CSV UTF-8" and upload that instead.';
                } else {
                    $rawRows = read_xlsx_first_sheet($tmpPath);
                    $rows = $rawRows !== null ? xlsx_rows_to_assoc($rawRows) : null;
                    if ($rows === null) $readError = 'That .xlsx file could not be read — it may be corrupted, password-protected, or not a real Excel file. Try re-saving it from Excel and uploading again.';
                }
            } elseif ($ext === 'csv') {
                $csvError = null;
                $rows = parse_csv_upload($tmpPath, $csvError);
                if ($rows === null) $readError = $csvError ?: 'That .csv file could not be read.';
            } else {
                $readError = 'Please upload a .xlsx or .csv file (that file looked like a ".' . h($ext) . '").';
            }

            if ($readError) {
                flash_set('danger', $readError);
            } else {
                $preview = build_student_import_preview($conn, $instituteId, $rows);
                if (!empty($preview['fatal'])) {
                    flash_set('danger', $preview['fatal']);
                    $preview = null;
                } elseif (!$preview['valid'] && !$preview['errors']) {
                    flash_set('danger', 'That file has no data rows to import.');
                } else {
                    $_SESSION[$sessionKey] = ['valid' => $preview['valid'], 'ignored' => $preview['ignored'] ?? [], 'generated_at' => time()];
                    $step = 'preview';
                }
            }
        }
    } elseif ($action === 'confirm') {
        $stored = $_SESSION[$sessionKey] ?? null;
        if (!$stored || !$stored['valid']) {
            flash_set('danger', 'Nothing to import — please upload the file again.');
        } else {
            @set_time_limit(300); // each student's password is hashed, which takes a moment per row
            $created = [];
            $skipped = [];
            foreach ($stored['valid'] as $row) {
                $hash = password_hash($row['id_number'], PASSWORD_DEFAULT);
                try {
                    // The core row and the extra profile fields go in together, or not at all.
                    $conn->begin_transaction();
                    // "Added On" from the file is kept as the student's registration time; blank = right now.
                    $withDate = !empty($row['created_at']);
                    $stmt = $conn->prepare("INSERT INTO students (institute_id, first_name, second_name, last_name, id_number, gender, phone, phone2, home_address, password_hash, class_section_id" . ($withDate ? ', created_at' : '') . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($withDate ? ', ?' : '') . ")");
                    if ($withDate) {
                        $stmt->bind_param('isssssssssis', $instituteId, $row['first_name'], $row['second_name'], $row['last_name'], $row['id_number'], $row['gender'], $row['phone'], $row['phone2'], $row['home_address'], $hash, $row['class_section_id'], $row['created_at']);
                    } else {
                        $stmt->bind_param('isssssssssi', $instituteId, $row['first_name'], $row['second_name'], $row['last_name'], $row['id_number'], $row['gender'], $row['phone'], $row['phone2'], $row['home_address'], $hash, $row['class_section_id']);
                    }
                    $stmt->execute();
                    $newId = $conn->insert_id;
                    $stmt->close();
                    save_student_extended_profile($conn, $newId, $row);
                    $conn->commit();
                    $created[] = ['id' => $newId, 'name' => $row['full_name'], 'id_number' => $row['id_number']];
                } catch (mysqli_sql_exception $e) {
                    $conn->rollback();
                    if ((int)$e->getCode() === 1062) {
                        // Someone else added a student with the same National ID
                        // Number between the preview and confirm steps — skip
                        // rather than fail the whole batch.
                        $skipped[] = $row['full_name'] . ' (National ID "' . $row['id_number'] . '" already in use)';
                    } else {
                        $skipped[] = $row['full_name'] . ' (database error: ' . $e->getMessage() . ')';
                    }
                }
            }
            unset($_SESSION[$sessionKey]);
            if ($created) {
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), 'Imported ' . count($created) . ' student(s) from Excel');
            }
            $importResults = $created;
            $importSkipped = $skipped;
            $step = 'done';
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION[$sessionKey]);
        header('Location: ' . base_url('admin/students_import.php'));
        exit;
    }
}

// If we're re-rendering the preview step after a page reload (not a fresh
// upload), read it back from the session rather than losing it.
if ($step === 'upload' && !empty($_SESSION[$sessionKey]['valid']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Only offer to resume if it's recent (10 minutes) — otherwise start clean.
    if (time() - ($_SESSION[$sessionKey]['generated_at'] ?? 0) < 600) {
        $preview = ['valid' => $_SESSION[$sessionKey]['valid'], 'errors' => [], 'ignored' => $_SESSION[$sessionKey]['ignored'] ?? []];
        $step = 'preview';
    } else {
        unset($_SESSION[$sessionKey]);
    }
}

/** Validates every parsed row against this institute's real class sections
 *  and the field rules, splitting them into ready-to-insert rows and
 *  per-row error messages. Every optional column is checked here, so a bad
 *  value shows up as a clear message in the preview instead of a database
 *  error (or a silently wrong value) after Confirm. */
function build_student_import_preview(mysqli $conn, int $instituteId, array $rows): array
{
    // A file whose header row lacks a required column can never work — say so once.
    $probeIgnored = [];
    $probe = student_import_canonical_row($rows[0] ?? [], $probeIgnored);
    $missing = array_values(array_filter(STUDENT_IMPORT_REQUIRED, fn($c) => !array_key_exists($c, $probe)));
    if ($rows && $missing) {
        return ['valid' => [], 'errors' => [], 'total' => 0, 'fatal' =>
            'The first row of your file must contain the column headers. Missing required column(s): ' . implode(', ', $missing) .
            '. Download the starter template to see the exact headers.'];
    }

    $sectionLookup = [];
    foreach (class_section_lookup_by_label($conn, $instituteId) as $key => $section) {
        $sectionLookup[normalize_import_digits($key)] = $section;
    }

    // Existing National ID Numbers in this institute, so we can flag a
    // collision before hitting the database's own unique-key error.
    $existingIds = [];
    $res = $conn->prepare("SELECT id_number FROM students WHERE institute_id = ? AND id_number IS NOT NULL AND id_number != ''");
    $res->bind_param('i', $instituteId);
    $res->execute();
    foreach ($res->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $existingIds[mb_strtolower($r['id_number'])] = true;
    }
    $res->close();

    $valid = [];
    $errors = [];
    $seenInBatch = []; // lowercased National ID Number => line number first seen on

    foreach ($rows as $i => $rawRow) {
        $lineNum = $i + 2; // +1 for header row, +1 for 1-indexing
        $row = student_import_canonical_row($rawRow);
        $rowErrors = [];

        $fullName = import_clean_text($row['Full Name'] ?? '');
        $nameParts = split_full_name($fullName);
        if ($nameParts['first_name'] === '') {
            $rowErrors[] = 'Full Name is required';
        }
        foreach ($nameParts as $part) {
            if (mb_strlen($part) > 50) {
                $rowErrors[] = 'Full Name has a name part longer than 50 characters';
                break;
            }
        }

        $nationalId = normalize_import_digits(import_clean_text($row['National ID Number'] ?? ''));
        if ($nationalId === '') {
            $rowErrors[] = 'National ID Number is required — students log in with it as their password';
        } elseif (mb_strlen($nationalId) > 50) {
            $rowErrors[] = 'National ID Number is longer than 50 characters';
        } else {
            $key = mb_strtolower($nationalId);
            if (isset($existingIds[$key])) {
                $rowErrors[] = "National ID Number \"$nationalId\" is already used by another student in this institute";
            } elseif (isset($seenInBatch[$key])) {
                $rowErrors[] = "National ID Number \"$nationalId\" is duplicated in this file (also on line {$seenInBatch[$key]})";
            } else {
                $seenInBatch[$key] = $lineNum;
            }
        }

        $genderRaw = $row['Gender'] ?? '';
        [$gender, $genderOk] = import_parse_gender($genderRaw);
        if (!$genderOk) {
            $rowErrors[] = 'Gender "' . import_clean_text($genderRaw) . '" must be "male", "female", or left blank';
        }

        $department = import_clean_text($row['Department'] ?? '');
        $grade = normalize_import_digits(import_clean_text($row['Grade'] ?? ''));
        $group = normalize_import_digits(import_clean_text($row['Group'] ?? ''));
        $sectionKey = mb_strtolower($department) . '|' . mb_strtolower($grade) . '|' . mb_strtolower($group);
        $section = $sectionLookup[$sectionKey] ?? null;
        if ($department === '' || $grade === '' || $group === '') {
            $rowErrors[] = 'Department, Grade, and Group are all required';
        } elseif (!$section) {
            $rowErrors[] = "No matching class section for \"$department / Grade $grade / Group $group\" — check spelling, or create that section first";
        }

        // Core contact fields.
        $phone = import_normalize_phone($row['Phone'] ?? '');
        $phone2 = import_normalize_phone($row['Phone 2'] ?? '');
        $homeAddress = import_clean_text($row['Home Address'] ?? '');
        foreach (['Phone' => [$phone, 30], 'Phone 2' => [$phone2, 30], 'Home Address' => [$homeAddress, 255]] as $label => [$val, $max]) {
            if (mb_strlen($val) > $max) $rowErrors[] = "$label is too long (max $max characters)";
        }

        // Optional profile columns (birth date, blood type, mother name, brothers, ...).
        $extended = [];
        foreach (STUDENT_IMPORT_EXTENDED as $header => [$field, $kind, $limit]) {
            $raw = $row[$header] ?? '';
            $value = null;
            if ($kind === 'date') {
                [$value, $ok] = import_parse_date($raw);
                if (!$ok) $rowErrors[] = "$header \"" . import_clean_text($raw) . '" isn\'t a valid date — use YYYY-MM-DD (e.g. 2010-05-21) or DD/MM/YYYY';
            } elseif ($kind === 'int') {
                [$value, $ok] = import_parse_int($raw, $limit);
                if (!$ok) $rowErrors[] = "$header \"" . import_clean_text($raw) . "\" must be a whole number from 0 to $limit";
            } else {
                $text = $kind === 'phone' ? import_normalize_phone($raw) : import_clean_text($raw);
                if (mb_strlen($text) > $limit) {
                    $rowErrors[] = "$header is too long (max $limit characters)";
                }
                $value = $text === '' ? null : $text;
            }
            $extended[$field] = $value;
        }

        $addedRaw = $row['Added On'] ?? '';
        [$addedAt, $addedOk] = import_parse_datetime($addedRaw);
        if (!$addedOk) {
            $rowErrors[] = 'Added On "' . import_clean_text($addedRaw) . '" isn\'t a valid date/time (or is in the future) — use YYYY-MM-DD HH:MM (e.g. 2026-09-20 13:48) or DD/MM/YYYY';
        }

        if ($rowErrors) {
            $errors[] = ['line' => $lineNum, 'name' => $fullName ?: '(no name)', 'messages' => $rowErrors];
            continue;
        }

        $valid[] = [
            'line' => $lineNum,
            'full_name' => $fullName,
            'first_name' => $nameParts['first_name'],
            'second_name' => $nameParts['second_name'],
            'last_name' => $nameParts['last_name'],
            'id_number' => $nationalId,
            'gender' => $gender,
            'phone' => $phone,
            'phone2' => $phone2,
            'home_address' => $homeAddress,
            'class_section_id' => (int)$section['id'],
            'section_label' => class_section_label($section),
            'created_at' => $addedAt,
        ] + $extended;
    }

    // Columns in the file we don't recognise (typos, extra notes) — reported, never silently guessed at.
    $ignored = [];
    if ($rows) {
        student_import_canonical_row($rows[0], $ignored);
    }

    return ['valid' => $valid, 'errors' => $errors, 'total' => count($rows), 'ignored' => $ignored];
}

$pageTitle = 'Import Students';
$role = 'admin';
$activePage = 'students';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel content-fill">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="panel-title mb-0">Import students from Excel</div>
    <a href="<?= base_url('admin/students.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to students</a>
  </div>

  <?php if ($step === 'upload'): ?>
    <p class="text-muted small mb-2">
      Upload a <code>.xlsx</code> or <code>.csv</code> file with one student per row. The first row must be the
      column headers — the easiest way to get them right is to download the starter template below, or export
      your current students (the exported file can be edited and imported straight back).
    </p>
    <ul class="text-muted small mb-3">
      <li><strong>Required:</strong> <?= h(implode(', ', STUDENT_IMPORT_REQUIRED)) ?>.
        <strong>National ID Number</strong> must be unique per student — it is what each student logs in with
        (alongside their auto-generated login ID) as their password.
        <strong>Department / Grade / Group</strong> must exactly match a class section that already exists (case doesn't matter).</li>
      <li><strong>Optional:</strong> <?= h(implode(', ', array_filter(STUDENT_IMPORT_COLUMNS, fn($c) => !in_array($c, STUDENT_IMPORT_REQUIRED, true) && !in_array($c, ['ID', 'Status'], true)))) ?>.
        Leave a cell empty to leave that detail blank.</li>
      <li><strong>Formats:</strong> Gender = male or female. Birth Date = <code>2010-05-21</code> or <code>21/05/2010</code>
        (day first). Brothers, Sisters, Birth Order and Years in Current Grade = whole numbers.
        Kurdish/Arabic digits (٠١٢٣) are accepted everywhere.</li>
      <li><strong>Added On</strong> (date and time the student was registered, e.g. <code>2026-09-20 13:48</code>) is optional — leave it empty to use the moment of import; fill it to keep a student's original registration date when moving them from another system.</li>
      <li><strong>ID</strong> and <strong>Status</strong> are ignored on import. Students whose National ID Number
        already exists are skipped (this page adds new students; it doesn't update existing ones).</li>
    </ul>
    <a href="<?= base_url('admin/students_export.php') ?>?template=1" class="btn btn-sm btn-outline-secondary mb-3 w-auto d-inline-flex align-items-center"><i class="bi bi-download me-1"></i>Download a starter template</a>

    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="preview">
      <div class="col-sm-6">
        <label class="form-label">File</label>
        <input type="file" name="import_file" accept=".xlsx,.csv" class="form-control" required>
      </div>
      <div class="col-sm-3">
        <button type="submit" class="btn btn-brand"><i class="bi bi-upload me-1"></i>Upload &amp; preview</button>
      </div>
    </form>

  <?php elseif ($step === 'preview'): ?>
    <?php $valid = $preview['valid']; $errors = $preview['errors'] ?? []; $ignoredCols = $preview['ignored'] ?? []; ?>
    <div class="alert alert-<?= $errors ? 'warning' : 'success' ?> py-2 mb-3">
      <?= count($valid) ?> row(s) ready to import.
      <?php if ($errors): ?><?= count($errors) ?> row(s) have problems and will be skipped.<?php endif; ?>
    </div>

    <?php if ($ignoredCols): ?>
      <div class="alert alert-info py-2 small mb-3">
        These columns weren't recognised and will be ignored: <strong><?= h(implode(', ', $ignoredCols)) ?></strong>.
        Check the spelling against the starter template if you expected them to be imported.
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="mb-3">
        <div class="fw-semibold small mb-1 text-warning-emphasis">Rows that will be skipped</div>
        <div class="table-scroll table-scroll-sm">
          <table class="table table-sm">
            <thead><tr><th>Line</th><th>Name</th><th>Problem</th></tr></thead>
            <tbody>
              <?php foreach ($errors as $e): ?>
                <tr><td><?= (int)$e['line'] ?></td><td><?= h($e['name']) ?></td><td class="text-danger small"><?= h(implode('; ', $e['messages'])) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($valid): ?>
      <div class="fw-semibold small mb-1">Rows ready to import</div>
      <div class="table-scroll mb-3">
        <table class="table table-sm">
          <thead><tr><th>Name</th><th>National ID</th><th>Gender</th><th>Birth date</th><th>Class</th><th>Phone</th><th>Added on</th><th>Other details</th></tr></thead>
          <tbody>
            <?php foreach ($valid as $r): ?>
              <tr>
                <td><?= h($r['full_name']) ?></td>
                <td class="small"><?= h($r['id_number']) ?></td>
                <td class="text-muted small"><?= gender_label($r['gender']) ?></td>
                <td class="text-muted small"><?= h($r['birth_date'] ?? '') ?: '—' ?></td>
                <td class="small"><?= h($r['section_label']) ?></td>
                <td class="text-muted small"><?= h($r['phone']) ?: '—' ?></td>
                <td class="text-muted small"><?= !empty($r['created_at']) ? h(format_added_at($r['created_at'])) : 'on import' ?></td>
                <td class="text-muted small"><?php
                  $filled = 0;
                  foreach (STUDENT_IMPORT_EXTENDED as [$field]) { if ($field !== 'birth_date' && ($r[$field] ?? null) !== null && $r[$field] !== '') $filled++; }
                  if ($r['phone2'] !== '') $filled++;
                  if ($r['home_address'] !== '') $filled++;
                  echo $filled ? $filled . ' field' . ($filled === 1 ? '' : 's') . ' filled' : '—';
                ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <form method="post" class="d-flex gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="confirm">
        <button type="submit" class="btn btn-brand"><i class="bi bi-check2-circle me-1"></i>Confirm — import <?= count($valid) ?> student(s)</button>
        <button type="submit" name="action" value="cancel" class="btn btn-outline-secondary" formnovalidate>Cancel</button>
      </form>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <button type="submit" name="action" value="cancel" class="btn btn-outline-secondary">Start over</button>
      </form>
    <?php endif; ?>

  <?php elseif ($step === 'done'): ?>
    <div class="alert alert-success py-2 mb-3">
      <?= count($importResults) ?> student(s) imported.
    </div>
    <?php if ($importSkipped): ?>
      <div class="alert alert-warning py-2 mb-3">
        <?= count($importSkipped) ?> row(s) could not be imported: <?= h(implode('; ', $importSkipped)) ?>.
        (A National ID Number can be "already in use" if another admin added the same one between the preview and the import.)
      </div>
    <?php endif; ?>
    <?php if ($importResults): ?>
      <p class="text-muted small">Save or print this list now — students log in with their ID below and their National ID Number as the password.</p>
      <div class="table-scroll mb-3">
        <table class="table table-sm">
          <thead><tr><th>ID</th><th>Name</th><th>National ID Number</th></tr></thead>
          <tbody>
            <?php foreach ($importResults as $r): ?>
              <tr><td>#<?= (int)$r['id'] ?></td><td><?= h($r['name']) ?></td><td><?= h($r['id_number']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <a href="<?= base_url('admin/students.php') ?>" class="btn btn-brand">Go to students list</a>
    <a href="<?= base_url('admin/students_import.php') ?>" class="btn btn-outline-secondary">Import another file</a>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

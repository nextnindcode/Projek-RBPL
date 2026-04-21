<?php
// ============================================================
// therapist/index.php — Therapist Schedule Management
// Sprint 5: PBI-022, PBI-023, PBI-024
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

guardRole('manager');

$db = getDB();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    verifyCsrf();
    $db->prepare('DELETE FROM therapist_schedules WHERE id = ?')->execute([(int)post('id')]);
    flash('success','Jadwal berhasil dihapus.','success');
    redirect('therapist/index.php');
}

// Handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'add') {
    verifyCsrf();
    $therapistId  = (int)post('therapist_id');
    $schedDate    = post('schedule_date');
    $startTime    = post('start_time');
    $endTime      = post('end_time');
    $notes        = trim(post('notes'));
    $errors       = [];

    if (!$therapistId) $errors[] = 'Pilih terapis.';
    if (!$schedDate)   $errors[] = 'Tanggal wajib diisi.';
    if (!$startTime)   $errors[] = 'Waktu mulai wajib diisi.';
    if (!$endTime)     $errors[] = 'Waktu selesai wajib diisi.';
    if ($startTime >= $endTime) $errors[] = 'Waktu selesai harus setelah waktu mulai.';

    if (!$errors) {
        // Check conflict
        $conflict = $db->prepare("SELECT id FROM therapist_schedules
            WHERE therapist_id = ? AND schedule_date = ?
            AND NOT (end_time <= ? OR start_time >= ?)");
        $conflict->execute([$therapistId, $schedDate, $startTime, $endTime]);
        if ($conflict->fetch()) {
            $errors[] = 'Terapis sudah memiliki jadwal pada waktu tersebut.';
        }
    }

    if (!$errors) {
        $db->prepare("INSERT INTO therapist_schedules(therapist_id,schedule_date,start_time,end_time,notes)
            VALUES(?,?,?,?,?)")->execute([$therapistId,$schedDate,$startTime,$endTime,$notes]);
        flash('success','Jadwal berhasil ditambahkan.','success');
        redirect('therapist/index.php');
    }
    foreach ($errors as $e) flash('error',$e,'error');
    redirect('therapist/index.php');
}

// List — show ALL schedules by default, filter is optional
$filterDate      = get('date', '');
$filterTherapist = (int)get('therapist_id', 0);

$where  = ['1=1'];
$params = [];

if ($filterDate !== '') {
    $where[]  = 'ts.schedule_date = ?';
    $params[] = $filterDate;
}
if ($filterTherapist > 0) {
    $where[]  = 'ts.therapist_id = ?';
    $params[] = $filterTherapist;
}

$whereStr  = implode(' AND ', $where);
$schedStmt = $db->prepare("SELECT ts.*, u.name as therapist_name
    FROM therapist_schedules ts
    JOIN users u ON u.id = ts.therapist_id
    WHERE $whereStr
    ORDER BY ts.schedule_date ASC, ts.start_time ASC");
$schedStmt->execute($params);
$schedules = $schedStmt->fetchAll();

$therapists = $db->query("SELECT * FROM users WHERE role = 'therapist' AND is_active = 1 ORDER BY name")->fetchAll();

$pageTitle = 'Jadwal Terapis';
include __DIR__ . '/../views/layouts/header.php';
?>

<div class="row g-3">
    <!-- Add Form -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-plus-circle me-2"></i>Tambah Jadwal</h5></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Terapis *</label>
                        <select name="therapist_id" class="form-select" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach ($therapists as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= sanitize($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal *</label>
                        <input type="date" name="schedule_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mulai *</label>
                        <input type="time" name="start_time" class="form-control" value="08:00" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Selesai *</label>
                        <input type="time" name="end_time" class="form-control" value="17:00" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn-tea w-100"><i class="bi bi-check-lg"></i> Simpan Jadwal</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Schedule List -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-people me-2"></i>Jadwal Terapis</h5>
                <span style="font-size:.8rem;color:var(--muted)"><?= count($schedules) ?> jadwal</span>
            </div>
            <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--border)">
                <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                    <div>
                        <label class="form-label" style="margin-bottom:.2rem">Filter Terapis</label>
                        <select name="therapist_id" class="form-select" style="width:auto">
                            <option value="">Semua Terapis</option>
                            <?php foreach ($therapists as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $filterTherapist == $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" style="margin-bottom:.2rem">Filter Tanggal</label>
                        <input type="date" name="date" class="form-control" style="width:auto" value="<?= sanitize($filterDate) ?>">
                    </div>
                    <div style="align-self:flex-end;display:flex;gap:.4rem">
                        <button type="submit" class="btn-tea" style="padding:.5rem .9rem"><i class="bi bi-search"></i> Filter</button>
                        <a href="<?= url('therapist/index.php') ?>" class="btn-outline-tea" style="padding:.5rem .9rem">Reset</a>
                    </div>
                </form>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Terapis</th>
                            <th>Tanggal</th>
                            <th>Mulai</th>
                            <th>Selesai</th>
                            <th>Catatan</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$schedules): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-calendar-x"></i><p>Belum ada jadwal<?= $filterDate || $filterTherapist ? ' untuk filter ini' : '' ?></p></div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($schedules as $s): ?>
                        <tr>
                            <td style="font-weight:500"><?= sanitize($s['therapist_name']) ?></td>
                            <td><?= formatDate($s['schedule_date']) ?></td>
                            <td><?= formatTime($s['start_time']) ?></td>
                            <td><?= formatTime($s['end_time']) ?></td>
                            <td style="font-size:.8rem;color:var(--muted)"><?= sanitize($s['notes'] ?: '-') ?></td>
                            <td><?= $s['is_available'] ? '<span class="badge bg-success">Tersedia</span>' : '<span class="badge bg-secondary">Tidak Tersedia</span>' ?></td>
                            <td>
                                <form method="POST" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" data-confirm="Hapus jadwal ini?"
                                        style="background:#fef2f2;color:#ef4444;border:1.5px solid #fecaca;border-radius:6px;padding:.25rem .65rem;cursor:pointer;font-size:.78rem">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../views/layouts/footer.php'; ?>
<?php
// ============================================================
// monitoring/index.php — Reservation Monitoring Dashboard
// Sprint 3: PBI-013, PBI-014
// FR-009, FR-010, FR-011
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

guardRole('manager','therapist');

$db   = getDB();
$role = currentRole();

$filterDate   = get('date', date('Y-m-d'));
$filterStatus = get('status','');

$where  = ['r.reservation_date = ?'];
$params = [$filterDate];

// Therapist: only see their own reservations
// if ($role === 'therapist') {
//     $where[]  = 'r.therapist_id = ?';
//     $params[] = auth()['id'];
// }

if ($role === 'therapist') {
    // Terapis bisa melihat reservasinya sendiri ATAU yang belum di-assign (menunggu)
    $where[]  = '(r.therapist_id = ? OR r.therapist_id IS NULL)';
    $params[] = auth()['id'];
}

if ($filterStatus) {
    $where[]  = 'r.status = ?';
    $params[] = $filterStatus;
}

$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("SELECT r.*, s.name as service_name, s.duration as service_duration,
        s.price as service_price, u.name as therapist_name
    FROM reservations r
    JOIN services s ON s.id = r.service_id
    LEFT JOIN users u ON u.id = r.therapist_id
    WHERE $whereStr
    ORDER BY r.reservation_time ASC");
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// Status summary for selected date
$summaryStmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM reservations
    WHERE reservation_date = ? GROUP BY status");
$summaryStmt->execute([$filterDate]);
$summary = array_column($summaryStmt->fetchAll(), 'cnt', 'status');

$pageTitle = 'Monitoring Reservasi';
include __DIR__ . '/../views/layouts/header.php';
?>

<!-- Summary bar -->
<div class="row g-2 mb-4">
    <?php $statColors = ['Menunggu'=>'gold','Proses'=>'blue','Selesai'=>'green']; ?>
    <?php foreach (['Menunggu','Proses','Selesai'] as $st): ?>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-icon <?= $statColors[$st] ?>">
                <i class="bi bi-<?= $st==='Menunggu'?'hourglass':($st==='Proses'?'play-circle':'check-circle') ?>"></i>
            </div>
            <div>
                <div class="stat-label"><?= $st ?></div>
                <div class="stat-value"><?= $summary[$st] ?? 0 ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <h5><i class="bi bi-activity me-2"></i>Jadwal Harian</h5>
    </div>

    <!-- Filter -->
    <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
            <input type="date" name="date" class="form-control" style="width:auto" value="<?= sanitize($filterDate) ?>">
            <select name="status" class="form-select" style="width:auto">
                <option value="">Semua Status</option>
                <?php foreach (['Menunggu','Proses','Selesai'] as $st): ?>
                <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-tea" style="padding:.5rem .9rem"><i class="bi bi-search"></i> Tampilkan</button>
        </form>
    </div>

    <!-- Kanban-style cards -->
    <div style="padding:1.5rem">
        <?php if (!$reservations): ?>
        <div class="empty-state"><i class="bi bi-calendar-x"></i><p>Tidak ada reservasi untuk tanggal ini</p></div>
        <?php endif; ?>

        <div style="display:grid;gap:1rem">
        <?php foreach ($reservations as $r): ?>
        <div class="res-card" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.1rem 1.25rem;display:flex;flex-wrap:wrap;align-items:center;gap:1rem;box-shadow:0 1px 4px rgba(0,0,0,.04)">

            <!-- Time block -->
            <div style="text-align:center;min-width:70px;flex-shrink:0">
                <div style="font-size:1.15rem;font-weight:600;color:var(--tea-dark)"><?= formatTime($r['reservation_time']) ?></div>
                <div style="font-size:.72rem;color:var(--muted)">s/d <?= formatTime($r['end_time']) ?></div>
            </div>

            <!-- Room badge -->
            <div style="flex-shrink:0">
                <span style="display:flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:10px;background:var(--tea-light);color:var(--tea-dark);font-weight:700;font-size:.88rem">R<?= sanitize($r['room_number']) ?></span>
            </div>

            <!-- Info -->
            <div style="flex:1;min-width:180px">
                <div style="font-weight:600;font-size:.95rem"><?= sanitize($r['customer_name']) ?></div>
                <div style="font-size:.82rem;color:var(--muted)"><?= sanitize($r['service_name']) ?> &bull; <?= $r['service_duration'] ?> mnt</div>
                <div style="font-size:.8rem;color:var(--muted)">Terapis: <?= sanitize($r['therapist_name'] ?? '—') ?></div>
            </div>

            <!-- Status & Actions -->
            <div style="display:flex;align-items:center;gap:.6rem;flex-shrink:0;flex-wrap:wrap">
                <?= statusBadge($r['status']) ?>

                <?php if ($r['status'] === 'Menunggu' && in_array($role,['manager','therapist'])): ?>
                <form method="POST" action="<?= url('monitoring/update_status.php') ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <input type="hidden" name="status" value="Proses">
                    <input type="hidden" name="redirect" value="monitoring">
                    <button type="submit" class="btn-tea" style="font-size:.78rem;padding:.3rem .75rem">
                        <i class="bi bi-play-fill"></i> Mulai
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($r['status'] === 'Proses' && in_array($role,['manager','therapist'])): ?>
                <form method="POST" action="<?= url('monitoring/update_status.php') ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <input type="hidden" name="status" value="Selesai">
                    <input type="hidden" name="redirect" value="monitoring">
                    <button type="submit" class="btn-tea" style="font-size:.78rem;padding:.3rem .75rem;background:var(--gold)">
                        <i class="bi bi-check-lg"></i> Selesai
                    </button>
                </form>
                <a href="<?= url('inventory/usage.php?reservation_id='.$r['id']) ?>" class="btn-outline-tea" style="font-size:.78rem;padding:.3rem .75rem">
                    <i class="bi bi-box-seam"></i> Pemakaian
                </a>
                <?php endif; ?>

                <a href="<?= url('reservations/detail.php?id='.$r['id']) ?>" class="btn-outline-tea" style="font-size:.78rem;padding:.3rem .65rem">
                    <i class="bi bi-eye"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../views/layouts/footer.php'; ?>

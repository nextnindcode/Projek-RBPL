<?php
// ============================================================
// dashboard.php — Role-Specific Dashboard
// Each role sees only data relevant to their function
// ============================================================
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

guardAuth();

$db    = getDB();
$role  = currentRole();
$uid   = auth()['id'];
$today = date('Y-m-d');

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// MANAGER
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($role === 'manager') {
    $resStmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM reservations WHERE reservation_date=? GROUP BY status");
    $resStmt->execute([$today]);
    $resByStatus = array_column($resStmt->fetchAll(), 'cnt', 'status');
    $totalToday  = array_sum($resByStatus);

    $todayRevStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Lunas' AND DATE(paid_at)=?");
    $todayRevStmt->execute([$today]);
    $todayRev = (float)$todayRevStmt->fetchColumn();

    $monthRev = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Lunas' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
    $pendPay  = (int)$db->query("SELECT COUNT(*) FROM reservations r LEFT JOIN payments p ON p.reservation_id=r.id WHERE r.status='Selesai' AND (p.payment_status IS NULL OR p.payment_status='Belum Bayar')")->fetchColumn();
    $pendStock= (int)$db->query("SELECT COUNT(*) FROM stock_requests WHERE status='Pending'")->fetchColumn();
    $lowStock = $db->query('SELECT * FROM products WHERE stock<=min_stock AND is_active=1 ORDER BY stock ASC LIMIT 5')->fetchAll();

    $upcomingStmt = $db->prepare("SELECT r.*,s.name as service_name,u.name as therapist_name FROM reservations r JOIN services s ON s.id=r.service_id LEFT JOIN users u ON u.id=r.therapist_id WHERE r.reservation_date>=CURDATE() ORDER BY r.reservation_date ASC,r.reservation_time ASC LIMIT 8");
    $upcomingStmt->execute();
    $upcoming = $upcomingStmt->fetchAll();

    $popular = $db->query("SELECT s.name,COUNT(*) as cnt FROM reservations r JOIN services s ON s.id=r.service_id WHERE MONTH(r.reservation_date)=MONTH(NOW()) AND YEAR(r.reservation_date)=YEAR(NOW()) GROUP BY s.id,s.name ORDER BY cnt DESC LIMIT 5")->fetchAll();
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// THERAPIST
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
elseif ($role === 'therapist') {
    $myTodayStmt = $db->prepare("SELECT r.*,s.name as service_name FROM reservations r JOIN services s ON s.id=r.service_id WHERE r.therapist_id=? AND r.reservation_date=? ORDER BY r.reservation_time ASC");
    $myTodayStmt->execute([$uid,$today]);
    $myToday = $myTodayStmt->fetchAll();

    $myUpcomingStmt = $db->prepare("SELECT r.*,s.name as service_name FROM reservations r JOIN services s ON s.id=r.service_id WHERE r.therapist_id=? AND r.reservation_date>? AND r.reservation_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY r.reservation_date ASC,r.reservation_time ASC");
    $myUpcomingStmt->execute([$uid,$today]);
    $myUpcoming = $myUpcomingStmt->fetchAll();

    $actionNeeded = array_values(array_filter($myToday, fn($r)=>in_array($r['status'],['Menunggu','Proses'])));
    $myStats = [
        'Menunggu' => count(array_filter($myToday, fn($r)=>$r['status']==='Menunggu')),
        'Proses'   => count(array_filter($myToday, fn($r)=>$r['status']==='Proses')),
        'Selesai'  => count(array_filter($myToday, fn($r)=>$r['status']==='Selesai')),
    ];
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// CASHIER
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
elseif ($role === 'cashier') {
    $queue = $db->query("SELECT r.*,s.name as service_name,s.price as service_price,p.payment_status FROM reservations r JOIN services s ON s.id=r.service_id LEFT JOIN payments p ON p.reservation_id=r.id WHERE r.status='Selesai' AND (p.payment_status IS NULL OR p.payment_status='Belum Bayar') ORDER BY r.reservation_date ASC,r.reservation_time ASC")->fetchAll();

    $paidToday = $db->query("SELECT p.*,r.customer_name,s.name as service_name FROM payments p JOIN reservations r ON r.id=p.reservation_id JOIN services s ON s.id=r.service_id WHERE p.payment_status='Lunas' AND DATE(p.paid_at)=CURDATE() ORDER BY p.paid_at DESC LIMIT 8")->fetchAll();

    $cashStats = [
        'queue'      => count($queue),
        'paid_today' => (int)$db->query("SELECT COUNT(*) FROM payments WHERE payment_status='Lunas' AND DATE(paid_at)=CURDATE()")->fetchColumn(),
        'rev_today'  => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Lunas' AND DATE(paid_at)=CURDATE()")->fetchColumn(),
        'rev_month'  => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Lunas' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn(),
    ];
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// PURCHASING
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
elseif ($role === 'purchasing') {
    $allProducts  = $db->query('SELECT * FROM products WHERE is_active=1 ORDER BY stock ASC')->fetchAll();
    $lowProducts  = array_values(array_filter($allProducts, fn($p)=>$p['stock']<=$p['min_stock']));
    $safeProducts = array_values(array_filter($allProducts, fn($p)=>$p['stock']>$p['min_stock']));

    $pendingReqs  = $db->query("SELECT sr.*,p.name as product_name,p.unit,u.name as requester_name FROM stock_requests sr JOIN products p ON p.id=sr.product_id LEFT JOIN users u ON u.id=sr.requested_by WHERE sr.status='Pending' ORDER BY sr.requested_at ASC")->fetchAll();

    $recentApproved = $db->query("SELECT sr.*,p.name as product_name,p.unit FROM stock_requests sr JOIN products p ON p.id=sr.product_id WHERE sr.status='Disetujui' AND DATE(sr.approved_at)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) ORDER BY sr.approved_at DESC LIMIT 5")->fetchAll();
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// ACCOUNTING
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
elseif ($role === 'accounting') {
    $accStats = [
        'today'    => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Lunas' AND DATE(paid_at)=CURDATE()")->fetchColumn(),
        'month'    => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Lunas' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn(),
        'tx_today' => (int)$db->query("SELECT COUNT(*) FROM payments WHERE payment_status='Lunas' AND DATE(paid_at)=CURDATE()")->fetchColumn(),
        'tx_month' => (int)$db->query("SELECT COUNT(*) FROM payments WHERE payment_status='Lunas' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn(),
    ];
    $trend = $db->query("SELECT DATE_FORMAT(paid_at,'%Y-%m') as month_key, DATE_FORMAT(paid_at,'%b %Y') as month_label, COALESCE(SUM(amount),0) as revenue, COUNT(*) as transactions FROM payments WHERE payment_status='Lunas' AND paid_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY month_key,month_label ORDER BY month_key ASC")->fetchAll();
    $byService = $db->query("SELECT s.name as service_name,COUNT(*) as cnt,SUM(p.amount) as revenue FROM payments p JOIN reservations r ON r.id=p.reservation_id JOIN services s ON s.id=r.service_id WHERE p.payment_status='Lunas' AND MONTH(p.paid_at)=MONTH(NOW()) AND YEAR(p.paid_at)=YEAR(NOW()) GROUP BY s.id,s.name ORDER BY revenue DESC LIMIT 6")->fetchAll();
    $recentTx  = $db->query("SELECT p.*,r.customer_name,s.name as service_name FROM payments p JOIN reservations r ON r.id=p.reservation_id JOIN services s ON s.id=r.service_id WHERE p.payment_status='Lunas' ORDER BY p.paid_at DESC LIMIT 8")->fetchAll();
}

$pageTitle = 'Dashboard';
include __DIR__ . '/views/layouts/header.php';
?>

<?php if ($role === 'manager'): ?>
<!-- ══════════════════════ MANAGER ══════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-calendar-check"></i></div>
            <div><div class="stat-label">Reservasi Hari Ini</div><div class="stat-value"><?= $totalToday ?></div><div class="stat-sub"><?= formatDate($today) ?></div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon gold"><i class="bi bi-cash-coin"></i></div>
            <div><div class="stat-label">Pendapatan Hari Ini</div><div class="stat-value" style="font-size:1rem"><?= formatRupiah($todayRev) ?></div><div class="stat-sub">Bulan: <?= formatRupiah($monthRev) ?></div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon <?= $pendPay>0?'red':'green' ?>"><i class="bi bi-hourglass-split"></i></div>
            <div><div class="stat-label">Menunggu Bayar</div><div class="stat-value"><?= $pendPay ?></div><div class="stat-sub">Antrian kasir</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon <?= (count($lowStock)||$pendStock)?'red':'green' ?>"><i class="bi bi-box-seam"></i></div>
            <div><div class="stat-label">Stok Rendah / Req Pending</div><div class="stat-value"><?= count($lowStock) ?> / <?= $pendStock ?></div></div>
        </div>
    </div>
</div>
<div class="d-flex gap-2 mb-4 flex-wrap">
    <?php foreach (['Menunggu'=>'warning','Proses'=>'info','Selesai'=>'success'] as $st=>$cl): ?>
    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:.6rem 1.1rem;display:flex;align-items:center;gap:.6rem;box-shadow:0 1px 4px rgba(0,0,0,.04)">
        <span class="badge bg-<?= $cl ?>"><?= $st ?></span>
        <span style="font-size:1.1rem;font-weight:700"><?= $resByStatus[$st]??0 ?></span>
        <span style="font-size:.78rem;color:var(--muted)">hari ini</span>
    </div>
    <?php endforeach; ?>
</div>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">
                <h5><i class="bi bi-calendar3 me-2"></i>Reservasi Mendatang</h5>
                <a href="<?= url('reservations/index.php') ?>" class="btn-outline-tea" style="font-size:.78rem;padding:.3rem .75rem">Lihat Semua</a>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Pelanggan</th><th>Layanan</th><th>Tanggal</th><th>Waktu</th><th>Ruang</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (!$upcoming): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-calendar-x"></i><p>Belum ada reservasi</p></div></td></tr><?php endif; ?>
                        <?php foreach ($upcoming as $r): ?>
                        <tr>
                            <td><div style="font-weight:500"><?= sanitize($r['customer_name']) ?></div><div style="font-size:.75rem;color:var(--muted)"><?= sanitize($r['phone_number']) ?></div></td>
                            <td style="font-size:.84rem"><?= sanitize($r['service_name']) ?></td>
                            <td style="font-size:.84rem"><?= formatDate($r['reservation_date']) ?></td>
                            <td><?= formatTime($r['reservation_time']) ?></td>
                            <td><span class="badge bg-secondary">R<?= $r['room_number'] ?></span></td>
                            <td><?= statusBadge($r['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($popular): ?>
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-bar-chart me-2"></i>Popularitas Layanan Bulan Ini</h5></div>
            <div class="card-body" style="padding:1rem 1.5rem">
                <?php $maxPop=max(array_column($popular,'cnt')); ?>
                <?php foreach ($popular as $sv): ?>
                <div style="margin-bottom:.7rem">
                    <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.2rem"><span><?= sanitize($sv['name']) ?></span><span style="font-weight:600;color:var(--tea-dark)"><?= $sv['cnt'] ?> reservasi</span></div>
                    <div style="height:7px;background:#f0f0f0;border-radius:4px"><div style="width:<?= round($sv['cnt']/$maxPop*100) ?>%;height:100%;background:var(--tea);border-radius:4px"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h5><i class="bi bi-exclamation-triangle text-warning me-2"></i>Stok Rendah</h5><a href="<?= url('inventory/index.php') ?>" class="btn-outline-tea" style="font-size:.75rem;padding:.25rem .6rem">Kelola</a></div>
            <?php if (!$lowStock): ?><div class="empty-state"><i class="bi bi-check-circle text-success"></i><p>Semua stok aman</p></div>
            <?php endif; ?>
            <?php foreach ($lowStock as $item): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-bottom:1px solid var(--border)">
                <div><div style="font-size:.85rem;font-weight:500"><?= sanitize($item['name']) ?></div><div style="font-size:.72rem;color:var(--muted)">Min: <?= $item['min_stock'] ?> <?= $item['unit'] ?></div></div>
                <span class="badge bg-danger"><?= $item['stock'] ?> <?= $item['unit'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <div class="card-header"><h5>Aksi Cepat</h5></div>
            <div class="card-body d-flex flex-column gap-2">
                <a href="<?= url('reservations/create.php') ?>" class="btn-tea" style="justify-content:center"><i class="bi bi-plus-circle"></i> Buat Reservasi</a>
                <a href="<?= url('services/index.php') ?>" class="btn-outline-tea" style="justify-content:center"><i class="bi bi-stars"></i> Kelola Layanan</a>
                <a href="<?= url('therapist/index.php') ?>" class="btn-outline-tea" style="justify-content:center"><i class="bi bi-people"></i> Jadwal Terapis</a>
                <a href="<?= url('reports/index.php') ?>" class="btn-outline-tea" style="justify-content:center"><i class="bi bi-bar-chart-line"></i> Laporan Keuangan</a>
            </div>
        </div>
    </div>
</div>

<?php elseif ($role === 'therapist'): ?>
<!-- ══════════════════════ THERAPIST ══════════════════════ -->
<div class="row g-3 mb-4">
    <?php foreach (['Menunggu'=>['gold','hourglass'],'Proses'=>['blue','play-circle'],'Selesai'=>['green','check-circle']] as $st=>[$cl,$ic]): ?>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-icon <?= $cl ?>"><i class="bi bi-<?= $ic ?>"></i></div>
            <div><div class="stat-label"><?= $st ?></div><div class="stat-value"><?= $myStats[$st] ?></div><div class="stat-sub">Hari ini</div></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><h5><i class="bi bi-calendar-day me-2"></i>Jadwal Saya Hari Ini</h5><span style="font-size:.8rem;color:var(--muted)"><?= formatDate($today) ?></span></div>
            <div style="padding:1.1rem;display:flex;flex-direction:column;gap:.65rem">
                <?php if (!$myToday): ?><div class="empty-state"><i class="bi bi-calendar-check"></i><p>Tidak ada jadwal hari ini</p></div><?php endif; ?>
                <?php foreach ($myToday as $r): ?>
                <div style="border:1px solid var(--border);border-radius:10px;padding:.9rem 1rem;display:flex;flex-wrap:wrap;align-items:center;gap:.65rem;background:<?= $r['status']==='Proses'?'#f0fdf4':($r['status']==='Selesai'?'#f9f9f9':'#fff') ?>">
                    <div style="text-align:center;min-width:58px"><div style="font-size:1.05rem;font-weight:700;color:var(--tea-dark)"><?= formatTime($r['reservation_time']) ?></div><div style="font-size:.66rem;color:var(--muted)">s/d <?= formatTime($r['end_time']) ?></div></div>
                    <div style="width:38px;height:38px;background:var(--tea-light);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:var(--tea-dark);flex-shrink:0">R<?= $r['room_number'] ?></div>
                    <div style="flex:1;min-width:130px"><div style="font-weight:600"><?= sanitize($r['customer_name']) ?></div><div style="font-size:.8rem;color:var(--muted)"><?= sanitize($r['service_name']) ?></div></div>
                    <div style="display:flex;align-items:center;gap:.45rem;flex-shrink:0;flex-wrap:wrap">
                        <?= statusBadge($r['status']) ?>
                        <?php if ($r['status']==='Menunggu'): ?>
                        <form method="POST" action="<?= url('monitoring/update_status.php') ?>"><?= csrfField() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="status" value="Proses"><input type="hidden" name="redirect" value="monitoring"><button type="submit" class="btn-tea" style="font-size:.73rem;padding:.26rem .65rem"><i class="bi bi-play-fill"></i> Mulai</button></form>
                        <?php elseif ($r['status']==='Proses'): ?>
                        <form method="POST" action="<?= url('monitoring/update_status.php') ?>"><?= csrfField() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="status" value="Selesai"><input type="hidden" name="redirect" value="monitoring"><button type="submit" class="btn-tea" style="font-size:.73rem;padding:.26rem .65rem;background:var(--gold)"><i class="bi bi-check-lg"></i> Selesai</button></form>
                        <a href="<?= url('inventory/usage.php?reservation_id='.$r['id']) ?>" class="btn-outline-tea" style="font-size:.73rem;padding:.26rem .65rem"><i class="bi bi-box-seam"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($myUpcoming): ?>
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-calendar-week me-2"></i>Jadwal 7 Hari ke Depan</h5></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Pelanggan</th><th>Layanan</th><th>Tanggal</th><th>Waktu</th><th>Ruang</th></tr></thead>
                    <tbody>
                        <?php foreach ($myUpcoming as $r): ?>
                        <tr>
                            <td style="font-weight:500"><?= sanitize($r['customer_name']) ?></td>
                            <td style="font-size:.84rem"><?= sanitize($r['service_name']) ?></td>
                            <td><?= formatDate($r['reservation_date']) ?></td>
                            <td><?= formatTime($r['reservation_time']) ?></td>
                            <td><span class="badge bg-secondary">R<?= $r['room_number'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h5>Aksi Cepat</h5></div>
            <div class="card-body d-flex flex-column gap-2">
                <a href="<?= url('monitoring/index.php') ?>" class="btn-tea" style="justify-content:center"><i class="bi bi-activity"></i> Monitoring Jadwal</a>
                <a href="<?= url('inventory/usage.php') ?>" class="btn-outline-tea" style="justify-content:center"><i class="bi bi-box-seam"></i> Catat Pemakaian</a>
            </div>
        </div>
        <?php if (!empty($actionNeeded)): ?>
        <div class="card" style="border-color:#fde047">
            <div class="card-body" style="padding:1rem 1.25rem">
                <div style="font-size:.82rem;font-weight:600;color:#713f12;margin-bottom:.5rem"><i class="bi bi-bell-fill me-1"></i>Perlu Tindakan (<?= count($actionNeeded) ?>)</div>
                <?php foreach ($actionNeeded as $r): ?>
                <div style="font-size:.82rem;margin-bottom:.3rem;padding:.4rem .6rem;background:#fefce8;border-radius:6px"><b><?= formatTime($r['reservation_time']) ?></b> — <?= sanitize($r['customer_name']) ?> <span style="float:right"><?= statusBadge($r['status']) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($role === 'cashier'): ?>
<!-- ══════════════════════ CASHIER ══════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card"><div class="stat-icon red"><i class="bi bi-hourglass-split"></i></div><div><div class="stat-label">Antrian Pembayaran</div><div class="stat-value"><?= $cashStats['queue'] ?></div><div class="stat-sub">Menunggu konfirmasi</div></div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle"></i></div><div><div class="stat-label">Lunas Hari Ini</div><div class="stat-value"><?= $cashStats['paid_today'] ?></div></div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card"><div class="stat-icon gold"><i class="bi bi-cash-coin"></i></div><div><div class="stat-label">Total Bayar Hari Ini</div><div class="stat-value" style="font-size:1rem"><?= formatRupiah($cashStats['rev_today']) ?></div></div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card"><div class="stat-icon blue"><i class="bi bi-graph-up"></i></div><div><div class="stat-label">Total Bulan Ini</div><div class="stat-value" style="font-size:1rem"><?= formatRupiah($cashStats['rev_month']) ?></div></div></div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h5><i class="bi bi-hourglass-split me-2"></i>Antrian Pembayaran</h5><a href="<?= url('payments/index.php') ?>" class="btn-outline-tea" style="font-size:.78rem;padding:.3rem .75rem">Lihat Semua</a></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Pelanggan</th><th>Layanan</th><th>Ruang</th><th>Tagihan</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <?php if (!$queue): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-check-circle text-success"></i><p>Tidak ada antrian</p></div></td></tr><?php endif; ?>
                        <?php foreach (array_slice($queue,0,6) as $q): ?>
                        <tr>
                            <td><div style="font-weight:500"><?= sanitize($q['customer_name']) ?></div><div style="font-size:.75rem;color:var(--muted)"><?= sanitize($q['phone_number']) ?></div></td>
                            <td style="font-size:.84rem"><?= sanitize($q['service_name']) ?></td>
                            <td><span class="badge bg-secondary">R<?= $q['room_number'] ?></span></td>
                            <td style="font-weight:600;color:var(--tea-dark)"><?= formatRupiah($q['service_price']) ?></td>
                            <td><a href="<?= url('payments/confirm.php?reservation_id='.$q['id']) ?>" class="btn-tea" style="font-size:.75rem;padding:.28rem .7rem"><i class="bi bi-cash-coin"></i> Bayar</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-receipt me-2"></i>Transaksi Lunas Hari Ini</h5></div>
            <div style="max-height:380px;overflow-y:auto">
                <?php if (!$paidToday): ?><div class="empty-state"><i class="bi bi-receipt"></i><p>Belum ada transaksi</p></div><?php endif; ?>
                <?php foreach ($paidToday as $t): ?>
                <div style="padding:.7rem 1.1rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
                    <div><div style="font-size:.85rem;font-weight:500"><?= sanitize($t['customer_name']) ?></div><div style="font-size:.75rem;color:var(--muted)"><?= sanitize($t['service_name']) ?> &bull; <?= ucfirst($t['payment_method']) ?></div></div>
                    <div style="text-align:right"><div style="font-size:.88rem;font-weight:600;color:var(--tea-dark)"><?= formatRupiah($t['amount']) ?></div><div style="font-size:.7rem;color:var(--muted)"><?= $t['paid_at'] ? date('H:i',strtotime($t['paid_at'])) : '' ?></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($role === 'purchasing'): ?>
<!-- ══════════════════════ PURCHASING ══════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-card"><div class="stat-icon green"><i class="bi bi-boxes"></i></div><div><div class="stat-label">Total Produk</div><div class="stat-value"><?= count($allProducts) ?></div></div></div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card"><div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div><div><div class="stat-label">Stok Rendah</div><div class="stat-value"><?= count($lowProducts) ?></div></div></div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card"><div class="stat-icon <?= count($pendingReqs)>0?'gold':'green' ?>"><i class="bi bi-clipboard-check"></i></div><div><div class="stat-label">Permintaan Pending</div><div class="stat-value"><?= count($pendingReqs) ?></div></div></div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header"><h5><i class="bi bi-clipboard-check me-2"></i>Permintaan Stok Masuk</h5><a href="<?= url('inventory/requests.php') ?>" class="btn-outline-tea" style="font-size:.78rem;padding:.3rem .75rem">Kelola Semua</a></div>
            <div style="padding:1rem;display:flex;flex-direction:column;gap:.6rem">
                <?php if (!$pendingReqs): ?><div class="empty-state"><i class="bi bi-clipboard-check"></i><p>Tidak ada permintaan pending</p></div><?php endif; ?>
                <?php foreach (array_slice($pendingReqs,0,5) as $req): ?>
                <div style="border:1px solid #fde047;border-radius:10px;padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;background:#fefce8">
                    <div><div style="font-weight:600;font-size:.87rem"><?= sanitize($req['product_name']) ?></div><div style="font-size:.74rem;color:var(--muted)">Diminta: <?= $req['requested_qty'] ?> <?= $req['unit'] ?> &bull; <?= sanitize($req['requester_name']??'—') ?></div></div>
                    <a href="<?= url('inventory/requests.php') ?>" class="btn-tea" style="font-size:.75rem;padding:.28rem .65rem"><i class="bi bi-check-lg"></i> Proses</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($recentApproved): ?>
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-check-circle me-2"></i>Baru Disetujui (7 Hari)</h5></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Produk</th><th>Qty Disetujui</th><th>Tanggal</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentApproved as $ra): ?>
                        <tr><td style="font-size:.85rem;font-weight:500"><?= sanitize($ra['product_name']) ?></td><td style="font-size:.85rem"><?= $ra['approved_qty'] ?> <?= $ra['unit'] ?></td><td style="font-size:.8rem;color:var(--muted)"><?= $ra['approved_at'] ? date('d M',strtotime($ra['approved_at'])) : '-' ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-boxes me-2"></i>Status Stok Semua Produk</h5><a href="<?= url('inventory/index.php') ?>" class="btn-outline-tea" style="font-size:.78rem;padding:.3rem .75rem">Tambah Stok</a></div>
            <div style="max-height:480px;overflow-y:auto">
                <?php foreach ($lowProducts as $p): ?>
                <div style="padding:.65rem 1.1rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:#fff5f5">
                    <div><div style="font-size:.84rem;font-weight:600;color:#991b1b"><?= sanitize($p['name']) ?></div><div style="font-size:.7rem;color:#ef4444">Min: <?= $p['min_stock'] ?> <?= $p['unit'] ?></div></div>
                    <span class="badge bg-danger"><?= $p['stock'] ?> <?= $p['unit'] ?></span>
                </div>
                <?php endforeach; ?>
                <?php foreach ($safeProducts as $p): ?>
                <div style="padding:.65rem 1.1rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
                    <div style="font-size:.84rem"><?= sanitize($p['name']) ?></div>
                    <div><span style="font-weight:600;color:var(--tea-dark)"><?= $p['stock'] ?></span> <span style="font-size:.75rem;color:var(--muted)"><?= $p['unit'] ?></span></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($role === 'accounting'): ?>
<!-- ══════════════════════ ACCOUNTING ══════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card"><div class="stat-icon gold"><i class="bi bi-cash-coin"></i></div><div><div class="stat-label">Pendapatan Hari Ini</div><div class="stat-value" style="font-size:1rem"><?= formatRupiah($accStats['today']) ?></div><div class="stat-sub"><?= $accStats['tx_today'] ?> transaksi</div></div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card"><div class="stat-icon green"><i class="bi bi-graph-up"></i></div><div><div class="stat-label">Pendapatan Bulan Ini</div><div class="stat-value" style="font-size:1rem"><?= formatRupiah($accStats['month']) ?></div><div class="stat-sub"><?= $accStats['tx_month'] ?> transaksi</div></div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card"><div class="stat-icon blue"><i class="bi bi-receipt"></i></div><div><div class="stat-label">Avg / Transaksi</div><div class="stat-value" style="font-size:1rem"><?= $accStats['tx_month']>0 ? formatRupiah($accStats['month']/$accStats['tx_month']) : 'Rp 0' ?></div></div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card"><div class="stat-icon purple"><i class="bi bi-calendar-month"></i></div><div><div class="stat-label">Periode Aktif</div><div class="stat-value" style="font-size:.95rem"><?= date('F Y') ?></div></div></div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-8">
        <?php if ($trend): ?>
        <div class="card mb-3">
            <div class="card-header"><h5><i class="bi bi-bar-chart me-2"></i>Tren Pendapatan 6 Bulan Terakhir</h5><a href="<?= url('reports/index.php') ?>" class="btn-outline-tea" style="font-size:.78rem;padding:.3rem .75rem">Laporan Lengkap</a></div>
            <div class="card-body">
                <?php $maxTrend=max(array_column($trend,'revenue')?:[1]); ?>
                <div style="display:flex;align-items:flex-end;gap:8px;height:150px;padding-bottom:.75rem">
                    <?php foreach ($trend as $t): ?>
                    <?php $h=max(6,round($t['revenue']/$maxTrend*130)); ?>
                    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px">
                        <div style="font-size:.62rem;color:var(--muted);writing-mode:vertical-rl;transform:rotate(180deg)"><?= formatRupiah($t['revenue']) ?></div>
                        <div style="width:100%;height:<?= $h ?>px;background:var(--tea);border-radius:5px 5px 0 0" onmouseover="this.style.background='var(--tea-dark)'" onmouseout="this.style.background='var(--tea)'"></div>
                        <div style="font-size:.68rem;color:var(--muted);white-space:nowrap"><?= $t['month_label'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($byService): ?>
        <div class="card mb-3">
            <div class="card-header"><h5><i class="bi bi-pie-chart me-2"></i>Pendapatan per Layanan (Bulan Ini)</h5></div>
            <div class="card-body" style="padding:1rem 1.5rem">
                <?php $maxSvc=max(array_column($byService,'revenue')?:[1]); ?>
                <?php foreach ($byService as $sv): ?>
                <div style="margin-bottom:.7rem">
                    <div style="display:flex;justify-content:space-between;font-size:.84rem;margin-bottom:.2rem"><span><?= sanitize($sv['service_name']) ?></span><span style="font-weight:600;color:var(--tea-dark)"><?= formatRupiah($sv['revenue']) ?> <span style="color:var(--muted);font-weight:400">(<?= $sv['cnt'] ?>x)</span></span></div>
                    <div style="height:7px;background:#f0f0f0;border-radius:3px"><div style="width:<?= round($sv['revenue']/$maxSvc*100) ?>%;height:100%;background:var(--gold);border-radius:3px"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-list-ul me-2"></i>Transaksi Terbaru</h5></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Pelanggan</th><th>Layanan</th><th>Waktu Bayar</th><th>Metode</th><th>Jumlah</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentTx as $t): ?>
                        <tr>
                            <td style="font-weight:500"><?= sanitize($t['customer_name']) ?></td>
                            <td style="font-size:.84rem"><?= sanitize($t['service_name']) ?></td>
                            <td style="font-size:.82rem"><?= $t['paid_at'] ? date('d M Y H:i',strtotime($t['paid_at'])) : '-' ?></td>
                            <td><span class="badge bg-<?= $t['payment_method']==='tunai'?'info':'success' ?>"><?= ucfirst($t['payment_method']) ?></span></td>
                            <td style="font-weight:600;color:var(--tea-dark)"><?= formatRupiah($t['amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h5>Aksi Cepat</h5></div>
            <div class="card-body d-flex flex-column gap-2">
                <a href="<?= url('reports/index.php') ?>" class="btn-tea" style="justify-content:center"><i class="bi bi-bar-chart-line"></i> Laporan Keuangan</a>
                <a href="<?= url('reports/index.php?type=daily') ?>" class="btn-outline-tea" style="justify-content:center"><i class="bi bi-calendar-day"></i> Laporan Harian</a>
                <a href="<?= url('reports/export.php?type=monthly&month=').date('Y-m') ?>" target="_blank" class="btn-outline-tea" style="justify-content:center"><i class="bi bi-file-earmark-pdf"></i> Export PDF Bulan Ini</a>
            </div>
        </div>
        <div class="card" style="border-color:var(--gold)">
            <div class="card-header" style="background:#fef9ec"><h5 style="color:#92400e"><i class="bi bi-trophy me-2"></i>Ringkasan <?= date('F Y') ?></h5></div>
            <div class="card-body">
                <div style="margin-bottom:1rem"><div style="font-size:.75rem;color:var(--muted);margin-bottom:.2rem">Total Pendapatan</div><div style="font-size:1.5rem;font-weight:700;color:var(--tea-dark)"><?= formatRupiah($accStats['month']) ?></div></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div style="background:var(--cream);padding:.6rem .8rem;border-radius:8px;text-align:center"><div style="font-size:1.1rem;font-weight:700"><?= $accStats['tx_month'] ?></div><div style="font-size:.72rem;color:var(--muted)">Transaksi</div></div>
                    <div style="background:var(--cream);padding:.6rem .8rem;border-radius:8px;text-align:center"><div style="font-size:.88rem;font-weight:700"><?= $accStats['tx_month']>0 ? formatRupiah($accStats['month']/$accStats['tx_month']) : 'Rp 0' ?></div><div style="font-size:.72rem;color:var(--muted)">Rata-rata</div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
<?php
// ============================================================
// inventory/usage.php — Record Product Usage (Therapist)
// Sprint 6: PBI-027, PBI-028
// FR-014, FR-015
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

guardRole('therapist','manager');

$db  = getDB();
$rid = (int)get('reservation_id');

// Load reservation (must be Selesai or Proses)
$resStmt = $db->prepare("SELECT r.*, s.name as service_name FROM reservations r
    JOIN services s ON s.id = r.service_id
    WHERE r.id = ? AND r.status IN ('Proses','Selesai')");
$resStmt->execute([$rid]);
$res = $resStmt->fetch();

// If no specific reservation, show general form
$specificMode = $res !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $reservationId = (int)post('reservation_id');
    $items         = post('items', []);

    // Validate reservation
    $resCheck = $db->prepare("SELECT id FROM reservations WHERE id = ? AND status IN ('Proses','Selesai')");
    $resCheck->execute([$reservationId]);
    if (!$resCheck->fetch()) {
        flash('error','Reservasi tidak valid.','error');
        redirect('inventory/usage.php');
    }

    $recorded = 0;
    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty       = (int)($item['quantity'] ?? 0);
        if (!$productId || $qty <= 0) continue;

        // Check stock
        $stockStmt = $db->prepare('SELECT stock FROM products WHERE id = ?');
        $stockStmt->execute([$productId]);
        $stock = (int)$stockStmt->fetchColumn();

        if ($qty > $stock) {
            flash('error',"Stok tidak mencukupi untuk produk ID $productId (stok: $stock).",'error');
            redirect('inventory/usage.php?reservation_id='.$reservationId);
        }

        // Insert usage record
        $db->prepare("INSERT INTO product_usage(reservation_id,product_id,quantity_used,recorded_by) VALUES(?,?,?,?)")
           ->execute([$reservationId, $productId, $qty, auth()['id']]);

        // Reduce stock automatically (FR-015)
        $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$qty, $productId]);
        $recorded++;
    }

    if ($recorded > 0) {
        flash('success',"$recorded pemakaian berhasil dicatat. Stok otomatis berkurang.",'success');
    } else {
        flash('info','Tidak ada pemakaian yang dicatat.','info');
    }

    redirect('reservations/detail.php?id='.$reservationId);
}

// Load finished/in-progress reservations for dropdown
$finishedRes = $db->query("SELECT r.id, r.customer_name, r.reservation_date, r.reservation_time, s.name as service_name
    FROM reservations r
    JOIN services s ON s.id = r.service_id
    WHERE r.status IN ('Proses','Selesai')
    ORDER BY r.reservation_date DESC, r.reservation_time DESC
    LIMIT 30")->fetchAll();

$products = $db->query('SELECT * FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();

// Existing usage for this reservation
$existingUsage = [];
if ($rid) {
    $uStmt = $db->prepare("SELECT pu.*, p.name as product_name, p.unit FROM product_usage pu
        JOIN products p ON p.id = pu.product_id WHERE pu.reservation_id = ?");
    $uStmt->execute([$rid]);
    $existingUsage = $uStmt->fetchAll();
}

$pageTitle = 'Catat Pemakaian Bahan';
include __DIR__ . '/../views/layouts/header.php';
?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-box-seam me-2"></i>Input Pemakaian Bahan</h5>
                <a href="javascript:history.back()" class="btn-outline-tea" style="font-size:.8rem">← Kembali</a>
            </div>
            <div class="card-body">
                <?php if ($existingUsage): ?>
                <div style="background:var(--tea-light);border-radius:8px;padding:.75rem 1rem;margin-bottom:1.25rem">
                    <div style="font-size:.8rem;font-weight:600;color:var(--tea-dark);margin-bottom:.5rem">Pemakaian yang sudah dicatat:</div>
                    <?php foreach ($existingUsage as $u): ?>
                    <div style="font-size:.83rem;color:var(--tea-dark)">• <?= sanitize($u['product_name']) ?>: <?= $u['quantity_used'] ?> <?= $u['unit'] ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="usageForm">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Reservasi *</label>
                        <select name="reservation_id" class="form-select" required>
                            <option value="">-- Pilih Reservasi --</option>
                            <?php foreach ($finishedRes as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= $rid == $r['id'] ? 'selected' : '' ?>>
                                #<?= $r['id'] ?> — <?= sanitize($r['customer_name']) ?> (<?= sanitize($r['service_name']) ?>) — <?= formatDate($r['reservation_date']) ?> <?= formatTime($r['reservation_time']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="itemsContainer">
                        <div class="usage-item" style="display:grid;grid-template-columns:1fr 120px 36px;gap:.5rem;align-items:end;margin-bottom:.6rem">
                            <div>
                                <label class="form-label">Produk</label>
                                <select name="items[0][product_id]" class="form-select">
                                    <option value="">-- Pilih --</option>
                                    <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?> (<?= $p['stock'] ?> <?= $p['unit'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Jumlah</label>
                                <input type="number" name="items[0][quantity]" class="form-control" min="1" step="1" placeholder="0">
                            </div>
                            <div style="padding-bottom:.1rem">
                                <button type="button" onclick="this.closest('.usage-item').remove()"
                                    style="width:36px;height:38px;background:#fef2f2;border:1.5px solid #fecaca;color:#ef4444;border-radius:7px;cursor:pointer;font-size:.9rem">
                                    <i class="bi bi-dash"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <button type="button" onclick="addItem()" class="btn-outline-tea mb-3" style="font-size:.82rem">
                        <i class="bi bi-plus"></i> Tambah Bahan
                    </button>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" class="btn-tea"><i class="bi bi-check-lg"></i> Simpan Pemakaian</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Stock sidebar -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-boxes me-2"></i>Stok Saat Ini</h5></div>
            <div style="max-height:400px;overflow-y:auto">
                <?php foreach ($products as $p): ?>
                <?php $isLow = $p['stock'] <= $p['min_stock']; ?>
                <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
                    <div style="font-size:.83rem"><?= sanitize($p['name']) ?></div>
                    <span style="font-size:.83rem;font-weight:600;color:<?= $isLow?'#ef4444':'var(--tea-dark)' ?>">
                        <?= $p['stock'] ?> <?= $p['unit'] ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
let itemIndex = 1;
const products = <?= json_encode(array_map(fn($p) => ['id'=>$p['id'],'name'=>$p['name'],'stock'=>$p['stock'],'unit'=>$p['unit']], $products)) ?>;

function addItem() {
    const container = document.getElementById('itemsContainer');
    const opts = products.map(p => `<option value="${p.id}">${p.name} (${p.stock} ${p.unit})</option>`).join('');
    const html = `<div class="usage-item" style="display:grid;grid-template-columns:1fr 120px 36px;gap:.5rem;align-items:end;margin-bottom:.6rem">
        <div>
            <label class="form-label">Produk</label>
            <select name="items[${itemIndex}][product_id]" class="form-select">
                <option value="">-- Pilih --</option>${opts}
            </select>
        </div>
        <div>
            <label class="form-label">Jumlah</label>
            <input type="number" name="items[${itemIndex}][quantity]" class="form-control" min="1" step="1" placeholder="0">
        </div>
        <div style="padding-bottom:.1rem">
            <button type="button" onclick="this.closest('.usage-item').remove()"
                style="width:36px;height:38px;background:#fef2f2;border:1.5px solid #fecaca;color:#ef4444;border-radius:7px;cursor:pointer;font-size:.9rem">
                <i class="bi bi-dash"></i>
            </button>
        </div>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
    itemIndex++;
}
</script>

<?php include __DIR__ . '/../views/layouts/footer.php'; ?>

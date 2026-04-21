<?php
// ============================================================
// inventory/requests.php — Stock Requests (PBI-029, PBI-030)
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

guardRole('purchasing','manager','therapist');

$db   = getDB();
$role = currentRole();

// Handle new request (therapist or manager)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'request') {
    verifyCsrf();
    $productId = (int)post('product_id');
    $qty       = (int)post('requested_qty');
    $notes     = trim(post('notes'));

    if ($productId && $qty > 0) {
        $db->prepare("INSERT INTO stock_requests(product_id,requested_qty,requested_by,request_notes) VALUES(?,?,?,?)")
           ->execute([$productId,$qty,auth()['id'],$notes]);
        flash('success','Permintaan stok berhasil diajukan.','success');
    } else {
        flash('error','Pilih produk dan masukkan jumlah.','error');
    }
    redirect('inventory/requests.php');
}

// Handle approve/reject (purchasing/manager)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['approve','reject'])) {
    verifyCsrf();
    guardRole('purchasing','manager');
    $reqId    = (int)post('request_id');
    $action   = post('action');
    $appQty   = (int)post('approved_qty');
    $appNotes = trim(post('approval_notes'));
    $status   = $action === 'approve' ? 'Disetujui' : 'Ditolak';

    if ($action === 'approve' && $appQty > 0) {
        // Add stock
        $reqStmt = $db->prepare('SELECT product_id FROM stock_requests WHERE id = ?');
        $reqStmt->execute([$reqId]);
        $req = $reqStmt->fetch();
        if ($req) {
            $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$appQty, $req['product_id']]);
        }
        $db->prepare("UPDATE stock_requests SET status=?,approved_qty=?,approved_by=?,approval_notes=?,approved_at=NOW() WHERE id=?")
           ->execute([$status,$appQty,auth()['id'],$appNotes,$reqId]);
        flash('success','Permintaan disetujui dan stok ditambahkan.','success');
    } else {
        $db->prepare("UPDATE stock_requests SET status='Ditolak',approved_by=?,approval_notes=?,approved_at=NOW() WHERE id=?")
           ->execute([auth()['id'],$appNotes,$reqId]);
        flash('success','Permintaan ditolak.','success');
    }
    redirect('inventory/requests.php');
}

$requests = $db->query("SELECT sr.*, p.name as product_name, p.unit,
        r.name as requester_name, a.name as approver_name
    FROM stock_requests sr
    JOIN products p ON p.id = sr.product_id
    LEFT JOIN users r ON r.id = sr.requested_by
    LEFT JOIN users a ON a.id = sr.approved_by
    ORDER BY sr.requested_at DESC")->fetchAll();

$products = $db->query('SELECT * FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();

$pageTitle = 'Permintaan Stok';
include __DIR__ . '/../views/layouts/header.php';
?>

<div class="row g-3">
    <?php if (in_array($role, ['therapist','manager'])): ?>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-clipboard-plus me-2"></i>Ajukan Permintaan</h5></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="request">
                    <div class="mb-3">
                        <label class="form-label">Produk *</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?> (<?= $p['stock'] ?> <?= $p['unit'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jumlah Diminta *</label>
                        <input type="number" name="requested_qty" class="form-control" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn-tea w-100"><i class="bi bi-send"></i> Ajukan</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
    <?php else: ?>
    <div class="col-12">
    <?php endif; ?>
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-clipboard-check me-2"></i>Daftar Permintaan</h5></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Produk</th>
                            <th>Diminta</th>
                            <th>Oleh</th>
                            <th>Tanggal</th>
                            <th>Status</th>
                            <?php if ($role === 'purchasing'): ?><th>Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$requests): ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="bi bi-clipboard-x"></i><p>Belum ada permintaan</p></div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($requests as $r): ?>
                        <tr>
                            <td style="font-weight:500"><?= sanitize($r['product_name']) ?></td>
                            <td><?= $r['requested_qty'] ?> <?= $r['unit'] ?></td>
                            <td style="font-size:.83rem"><?= sanitize($r['requester_name'] ?? '-') ?></td>
                            <td style="font-size:.83rem"><?= date('d M Y', strtotime($r['requested_at'])) ?></td>
                            <td><?= statusBadge($r['status']) ?></td>
                            <?php if ($role === 'purchasing' && $r['status'] === 'Pending'): ?>
                            <td>
                                <button onclick="showApprove(<?= $r['id'] ?>, '<?= sanitize($r['product_name']) ?>', <?= $r['requested_qty'] ?>)"
                                    class="btn-tea" style="font-size:.78rem;padding:.25rem .65rem">
                                    <i class="bi bi-check-lg"></i> Proses
                                </button>
                            </td>
                            <?php elseif ($role === 'purchasing'): ?>
                            <td><span style="font-size:.78rem;color:var(--muted)">Selesai</span></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<?php if (in_array($role,['purchasing','manager'])): ?>
<div id="approveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:16px;padding:2rem;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2)">
        <h4 style="font-family:'Cormorant Garamond',serif;color:var(--tea-dark);margin-bottom:1rem">Konfirmasi Permintaan</h4>
        <form method="POST" id="approveForm">
            <?= csrfField() ?>
            <input type="hidden" name="request_id" id="modalRequestId">
            <div class="mb-3">
                <label class="form-label">Produk</label>
                <div id="modalProductName" style="font-weight:600;padding:.5rem 0"></div>
            </div>
            <div class="mb-3">
                <label class="form-label">Jumlah Disetujui</label>
                <input type="number" name="approved_qty" id="modalQty" class="form-control" min="0.01" step="0.01">
            </div>
            <div class="mb-3">
                <label class="form-label">Catatan</label>
                <textarea name="approval_notes" class="form-control" rows="2"></textarea>
            </div>
            <div style="display:flex;gap:.75rem;justify-content:flex-end">
                <button type="button" onclick="hideApprove()" class="btn-outline-tea">Batal</button>
                <button type="submit" name="action" value="reject"
                    style="background:#fef2f2;color:#ef4444;border:1.5px solid #fecaca;border-radius:8px;padding:.5rem 1rem;cursor:pointer;font-size:.88rem">
                    <i class="bi bi-x-lg"></i> Tolak
                </button>
                <button type="submit" name="action" value="approve" class="btn-tea" style="background:#16a34a">
                    <i class="bi bi-check-lg"></i> Setujui
                </button>
            </div>
        </form>
    </div>
</div>
<script>
function showApprove(id, name, qty) {
    document.getElementById('modalRequestId').value = id;
    document.getElementById('modalProductName').textContent = name;
    document.getElementById('modalQty').value = qty;
    document.getElementById('approveModal').style.display = 'flex';
}
function hideApprove() { document.getElementById('approveModal').style.display = 'none'; }
</script>
<?php endif; ?>

<?php include __DIR__ . '/../views/layouts/footer.php'; ?>

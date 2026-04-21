<?php
// ============================================================
// inventory/index.php — Stock Management (Purchasing/Manager)
// Sprint 6: PBI-026, PBI-028, PBI-029, PBI-030
// FR-015, FR-016
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

guardRole('purchasing','manager');

$db = getDB();

// Handle add stock (Purchasing receives new items)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'add_stock') {
    verifyCsrf();
    $productId = (int)post('product_id');
    $qty       = (int)post('quantity');
    $notes     = trim(post('notes'));

    if ($productId > 0 && $qty > 0) {
        $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$qty, $productId]);
        flash('success', "Stok berhasil ditambahkan sebesar $qty.", 'success');
    } else {
        flash('error','Pilih produk dan masukkan jumlah yang valid.','error');
    }
    redirect('inventory/index.php');
}

// Handle delete product (soft delete — mark inactive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete_product') {
    verifyCsrf();
    guardRole('manager');
    $delId = (int)post('product_id');
    if ($delId > 0) {
        $db->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$delId]);
        flash('success','Produk berhasil dihapus dari daftar aktif.','success');
    }
    redirect('inventory/index.php');
}

// Handle restore product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'restore_product') {
    verifyCsrf();
    guardRole('manager');
    $restId = (int)post('product_id');
    if ($restId > 0) {
        $db->prepare('UPDATE products SET is_active = 1 WHERE id = ?')->execute([$restId]);
        flash('success','Produk berhasil diaktifkan kembali.','success');
    }
    redirect('inventory/index.php');

    verifyCsrf();
    $name      = trim(post('name'));
    $unit      = trim(post('unit'));
    $stock     = (int)post('stock');
    $minStock  = (int)post('min_stock');

    if ($name && $unit) {
        $db->prepare('INSERT INTO products(name,unit,stock,min_stock) VALUES(?,?,?,?)')
           ->execute([$name,$unit,$stock,$minStock]);
        flash('success','Produk baru berhasil ditambahkan.','success');
    } else {
        flash('error','Nama dan satuan wajib diisi.','error');
    }
    redirect('inventory/index.php');
}

$showInactive = get('show_inactive') === '1';
// Manager sees all products; purchasing sees only active
if (currentRole() === 'manager' && $showInactive) {
    $products = $db->query('SELECT * FROM products ORDER BY is_active DESC, name ASC')->fetchAll();
} else {
    $products = $db->query('SELECT * FROM products WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
}
$activeProducts = array_filter($products, fn($p) => $p['is_active']);
$inactiveCount  = $db->query('SELECT COUNT(*) FROM products WHERE is_active = 0')->fetchColumn();

$pageTitle = 'Manajemen Stok';
include __DIR__ . '/../views/layouts/header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-boxes"></i></div>
            <div>
                <div class="stat-label">Total Produk Aktif</div>
                <div class="stat-value"><?= count($activeProducts) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="stat-label">Stok Rendah</div>
                <div class="stat-value"><?= count(array_filter($activeProducts, fn($p) => $p['stock'] <= $p['min_stock'])) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="stat-label">Stok Aman</div>
                <div class="stat-value"><?= count(array_filter($activeProducts, fn($p) => $p['stock'] > $p['min_stock'])) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Left: Add Stock form + Add Product -->
    <div class="col-lg-4">
        <!-- Add Stock -->
        <div class="card mb-3">
            <div class="card-header"><h5><i class="bi bi-plus-circle me-2"></i>Tambah Stok</h5></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_stock">
                    <div class="mb-3">
                        <label class="form-label">Produk *</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Pilih Produk --</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?> (<?= $p['stock'] ?> <?= $p['unit'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jumlah Masuk *</label>
                        <input type="number" name="quantity" class="form-control" min="0" step="1" required>
                    </div>
                    <button type="submit" class="btn-tea w-100"><i class="bi bi-plus-lg"></i> Tambah Stok</button>
                </form>
            </div>
        </div>

        <!-- Add Product -->
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-box-seam me-2"></i>Produk Baru</h5></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_product">
                    <div class="mb-3">
                        <label class="form-label">Nama Produk *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Satuan *</label>
                        <input type="text" name="unit" class="form-control" placeholder="ml / gram / pcs" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Stok Awal</label>
                            <input type="number" name="stock" class="form-control" value="0" min="0" step="1">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Stok Min.</label>
                            <input type="number" name="min_stock" class="form-control" value="10" min="0" step="1">
                        </div>
                    </div>
                    <button type="submit" class="btn-outline-tea w-100"><i class="bi bi-plus-lg"></i> Tambah Produk</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: Product list -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-boxes me-2"></i>Daftar Produk</h5>
                <div style="display:flex;gap:.5rem;align-items:center">
                    <?php if (currentRole() === 'manager' && $inactiveCount > 0): ?>
                    <a href="<?= url('inventory/index.php?show_inactive='.($showInactive?'0':'1')) ?>"
                       class="btn-outline-tea" style="font-size:.78rem;padding:.3rem .75rem">
                        <?= $showInactive ? 'Sembunyikan Nonaktif' : "Tampilkan Nonaktif ($inactiveCount)" ?>
                    </a>
                    <?php endif; ?>
                    <a href="<?= url('inventory/requests.php') ?>" class="btn-outline-tea" style="font-size:.8rem">
                        <i class="bi bi-clipboard-check"></i> Permintaan Stok
                    </a>
                </div>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nama Produk</th>
                            <th>Stok Saat Ini</th>
                            <th>Stok Minimum</th>
                            <th>Status</th>
                            <?php if (currentRole() === 'manager'): ?><th>Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$products): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="bi bi-boxes"></i><p>Belum ada produk</p></div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($products as $p): ?>
                        <?php $isLow = $p['stock'] <= $p['min_stock'] && $p['is_active']; ?>
                        <tr <?= !$p['is_active'] ? 'style="opacity:.5;background:#f9f9f9"' : ($isLow ? 'style="background:#fff5f5"' : '') ?>>
                            <td style="font-weight:500">
                                <?= sanitize($p['name']) ?>
                                <?php if (!$p['is_active']): ?><span class="badge bg-secondary ms-1" style="font-size:.65rem">Nonaktif</span><?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size:1.05rem;font-weight:600;color:<?= $isLow ? '#ef4444' : 'var(--tea-dark)' ?>">
                                    <?= $p['stock'] ?>
                                </span>
                                <span style="color:var(--muted);font-size:.82rem"> <?= $p['unit'] ?></span>
                            </td>
                            <td style="font-size:.85rem;color:var(--muted)"><?= $p['min_stock'] ?> <?= $p['unit'] ?></td>
                            <td>
                                <?php if (!$p['is_active']): ?>
                                <span class="badge bg-secondary">Nonaktif</span>
                                <?php elseif ($isLow): ?>
                                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Rendah</span>
                                <?php else: ?>
                                <span class="badge bg-success">Aman</span>
                                <?php endif; ?>
                            </td>
                            <?php if (currentRole() === 'manager'): ?>
                            <td>
                                <?php if ($p['is_active']): ?>
                                <form method="POST" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_product">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <button type="submit"
                                        data-confirm="Hapus produk '<?= sanitize($p['name']) ?>'? Produk akan dinonaktifkan."
                                        style="background:#fef2f2;color:#ef4444;border:1.5px solid #fecaca;border-radius:6px;padding:.25rem .65rem;cursor:pointer;font-size:.78rem">
                                        <i class="bi bi-trash"></i> Hapus
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="restore_product">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <button type="submit"
                                        style="background:var(--tea-light);color:var(--tea-dark);border:1.5px solid var(--tea);border-radius:6px;padding:.25rem .65rem;cursor:pointer;font-size:.78rem">
                                        <i class="bi bi-arrow-counterclockwise"></i> Aktifkan
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../views/layouts/footer.php'; ?>

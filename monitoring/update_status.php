<?php
// ============================================================
// monitoring/update_status.php — Update Reservation Status
// Sprint 3: PBI-013
// FR-010, FR-011
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

guardRole('manager','therapist');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('monitoring/index.php');
}

verifyCsrf();

$db        = getDB();
$id        = (int)post('id');
$newStatus = post('status');
$back      = post('redirect','monitoring');

// Validate status transition
$allowed = ['Proses','Selesai'];
if (!in_array($newStatus, $allowed, true)) {
    flash('error','Status tidak valid.','error');
    redirect($back === 'monitoring' ? 'monitoring/index.php' : 'reservations/detail.php?id='.$id);
}

// Get current reservation
$stmt = $db->prepare('SELECT * FROM reservations WHERE id = ?');
$stmt->execute([$id]);
$res = $stmt->fetch();

if (!$res) {
    flash('error','Reservasi tidak ditemukan.','error');
    redirect('monitoring/index.php');
}

// Assign otomatis jika terapis menekan "Proses" dan reservasi belum ada terapisnya
if (currentRole() === 'therapist' && $res['therapist_id'] === null) {
    $db->prepare('UPDATE reservations SET therapist_id = ? WHERE id = ?')
       ->execute([auth()['id'], $id]);
       
    $res['therapist_id'] = auth()['id']; // Update state variable untuk bypass pengecekan di bawahnya
}

// Therapist can only update their own reservations
if (currentRole() === 'therapist' && $res['therapist_id'] != auth()['id']) {
    // Allow if no therapist assigned yet
    if ($res['therapist_id'] !== null) {
        flash('error','Anda tidak dapat mengubah reservasi terapis lain.','error');
        redirect('monitoring/index.php');
    }
}

// Check valid transition
$validTransitions = ['Menunggu' => 'Proses', 'Proses' => 'Selesai'];
if (($validTransitions[$res['status']] ?? '') !== $newStatus) {
    flash('error','Transisi status tidak valid.','error');
    redirect('monitoring/index.php');
}

// If moving to Proses, assign therapist if not set
$updateSql = 'UPDATE reservations SET status = ?';
$params    = [$newStatus];

if ($newStatus === 'Proses' && !$res['therapist_id'] && currentRole() === 'therapist') {
    $updateSql .= ', therapist_id = ?';
    $params[]   = auth()['id'];
}

// If moving to Selesai, auto-create payment record
if ($newStatus === 'Selesai') {
    // Get service price
    $priceStmt = $db->prepare('SELECT price FROM services WHERE id = ?');
    $priceStmt->execute([$res['service_id']]);
    $price = (float)$priceStmt->fetchColumn();

    // Create payment entry if not exists
    $existPay = $db->prepare('SELECT id FROM payments WHERE reservation_id = ?');
    $existPay->execute([$id]);
    if (!$existPay->fetch()) {
        $db->prepare("INSERT INTO payments(reservation_id, amount, payment_status) VALUES(?,?,'Belum Bayar')")
           ->execute([$id, $price]);
    }
}

$updateSql .= ', updated_at = NOW() WHERE id = ?';
$params[]   = $id;

$db->prepare($updateSql)->execute($params);

flash('success', "Status reservasi diubah ke \"$newStatus\".", 'success');

if ($back === 'detail') {
    redirect('reservations/detail.php?id='.$id);
} else {
    redirect('monitoring/index.php?date='.$res['reservation_date']);
}

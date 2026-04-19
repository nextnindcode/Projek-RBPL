<?php
// ============================================================
// reserve.php — Public Reservation Form (No Login Required)
// FR-006, FR-007, FR-008
// ============================================================
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

sessionStart();

$db     = getDB();
$errors = [];
$success = false;

// Load services
$services   = $db->query('SELECT * FROM services WHERE is_active = 1 ORDER BY name')->fetchAll();
$therapists = $db->query("SELECT id, name FROM users WHERE role = 'therapist' AND is_active = 1 ORDER BY name")->fetchAll();
$rooms      = array_map(fn($n) => str_pad($n, 2, '0', STR_PAD_LEFT), range(1,8));

// Handle AJAX conflict check
if (isset($_GET['check_conflict'])) {
    header('Content-Type: application/json');
    $date       = $_GET['date']        ?? '';
    $time       = $_GET['time']        ?? '';
    $serviceId  = (int)($_GET['service_id'] ?? 0);
    $therapistId= (int)($_GET['therapist_id'] ?? 0);
    $room       = $_GET['room']        ?? '';

    $svc = $db->prepare('SELECT duration FROM services WHERE id = ?');
    $svc->execute([$serviceId]);
    $dur = (int)($svc->fetchColumn() ?: 60);

    [$h,$m] = explode(':', $time ?: '00:00');
    $endMin  = (int)$h * 60 + (int)$m + $dur;
    $endTime = sprintf('%02d:%02d', floor($endMin/60)%24, $endMin%60);

    $conflicts = [];
    if ($room) {
        $roomStmt = $db->prepare("SELECT customer_name FROM reservations
            WHERE reservation_date=? AND room_number=? AND status!='Selesai'
            AND NOT(end_time<=? OR reservation_time>=?)");
        $roomStmt->execute([$date,$room,$time,$endTime]);
        if ($rc = $roomStmt->fetch()) $conflicts[] = "Ruangan $room tidak tersedia pada waktu tersebut.";
    }
    if ($therapistId) {
        $thStmt = $db->prepare("SELECT customer_name FROM reservations
            WHERE reservation_date=? AND therapist_id=? AND status!='Selesai'
            AND NOT(end_time<=? OR reservation_time>=?)");
        $thStmt->execute([$date,$therapistId,$time,$endTime]);
        if ($thStmt->fetch()) $conflicts[] = 'Terapis pilihan tidak tersedia pada waktu tersebut.';
    }
    echo json_encode(['conflicts' => $conflicts, 'end_time' => $endTime]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerName  = trim(post('customer_name'));
    $phoneNumber   = trim(post('phone_number'));
    $serviceId     = (int)post('service_id');
    // $therapistId   = (int)post('therapist_id') ?: null;
    $therapistId = null;
    $resDate       = post('reservation_date');
    $resTime       = post('reservation_time');
    // $room          = post('room_number');
    $room = null;
    $notes         = trim(post('notes'));

    if (!$customerName) $errors['customer_name'] = 'Nama wajib diisi.';
    if (!$phoneNumber)  $errors['phone_number']  = 'No. telepon wajib diisi.';
    if (!$serviceId)    $errors['service_id']    = 'Pilih layanan.';
    if (!$resDate)      $errors['reservation_date'] = 'Tanggal wajib diisi.';
    if (!$resTime)      $errors['reservation_time'] = 'Waktu wajib diisi.';
    // if (!$room)         $errors['room_number']   = 'Pilih nomor ruangan.';
    if ($resDate && $resDate < date('Y-m-d')) $errors['reservation_date'] = 'Tidak dapat memilih tanggal yang sudah lewat.';

    if (!$errors) {
        $svcStmt = $db->prepare('SELECT duration FROM services WHERE id = ?');
        $svcStmt->execute([$serviceId]);
        $duration = (int)$svcStmt->fetchColumn();

        [$h,$m] = explode(':', $resTime);
        $endMin  = (int)$h * 60 + (int)$m + $duration;
        $endTime = sprintf('%02d:%02d', floor($endMin/60)%24, $endMin%60);

        // Cari ruangan kosong secara otomatis (1 sampai 8)
        $availableRoom = null;
        foreach (range(1, 8) as $n) {
            $r = str_pad($n, 2, '0', STR_PAD_LEFT);
            $roomChk = $db->prepare("SELECT id FROM reservations WHERE reservation_date=? AND room_number=? AND status!='Selesai' AND NOT(end_time<=? OR reservation_time>=?)");
            $roomChk->execute([$resDate, $r, $resTime, $endTime]);
            
            // Jika tidak ada hasil fetch, berarti ruangan ini KOSONG di jam tersebut
            if (!$roomChk->fetch()) {
                $availableRoom = $r;
                break; // Berhenti mencari jika sudah dapat 1 ruangan kosong
            }
        }

        if (!$availableRoom) {
            // Jika setelah dilooping 8 ruangan penuh semua, berikan error
            $errors['reservation_time'] = 'Mohon maaf, semua ruangan telah penuh pada waktu tersebut. Silakan pilih waktu atau tanggal lain.';
        } else {
            // Jika ada yang kosong, assign ruangan tersebut
            $room = $availableRoom;
        }
    }

    if (!$errors) {
        $db->prepare("INSERT INTO reservations(customer_name,phone_number,service_id,therapist_id,reservation_date,reservation_time,end_time,room_number,notes)
            VALUES(?,?,?,?,?,?,?,?,?)")
           ->execute([$customerName,$phoneNumber,$serviceId,$therapistId,$resDate,$resTime,$endTime,$room,$notes]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservasi — Tea Spa</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,600;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --tea:#5a7c5a; --tea-dark:#3d5c3d; --cream:#faf8f3; --gold:#c9a96e; }
        *{margin:0;padding:0;box-sizing:border-box}
        body{min-height:100vh;background:var(--cream);font-family:'Jost',sans-serif;padding:2rem 1rem}
        .container{max-width:640px;margin:0 auto}
        .brand{text-align:center;margin-bottom:2rem}
        .brand h1{font-family:'Cormorant Garamond',serif;font-size:2rem;color:var(--tea-dark)}
        .brand p{font-size:.8rem;color:#888;letter-spacing:.12em;text-transform:uppercase}
        .card{background:#fff;border-radius:16px;padding:2rem;box-shadow:0 4px 30px rgba(0,0,0,.06);border:1px solid #eee}
        .form-label{display:block;font-size:.72rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#888;margin-bottom:.4rem}
        .form-control,.form-select{width:100%;padding:.65rem .9rem;border:1.5px solid #e0e0e0;border-radius:8px;font-family:'Jost',sans-serif;font-size:.9rem;background:var(--cream);outline:none;transition:border-color .2s}
        .form-control:focus,.form-select:focus{border-color:var(--tea);background:#fff}
        .form-control.is-invalid{border-color:#ef4444}
        .invalid-feedback{font-size:.75rem;color:#ef4444;margin-top:.25rem}
        .mb-3{margin-bottom:1.1rem}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
        .btn{width:100%;padding:.85rem;background:var(--tea);color:#fff;border:none;border-radius:8px;font-family:'Jost',sans-serif;font-size:.95rem;font-weight:500;cursor:pointer;transition:background .2s;margin-top:.5rem}
        .btn:hover{background:var(--tea-dark)}
        .conflict-alert{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:.75rem 1rem;font-size:.85rem;margin-bottom:1rem;display:none}
        .success-box{text-align:center;padding:2rem}
        .success-box i{font-size:3rem;color:var(--tea);display:block;margin-bottom:1rem}
        .success-box h3{font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--tea-dark);margin-bottom:.5rem}
        .room-grid{display:flex;flex-wrap:wrap;gap:.5rem}
        .room-btn{display:inline-flex;align-items:center;justify-content:center;width:52px;height:42px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:.85rem;font-weight:500;color:#666;cursor:pointer;transition:all .15s}
        input[type=radio]:checked+.room-btn{background:var(--tea);color:#fff;border-color:var(--tea)}
    </style>
</head>
<body>
<div class="container">
    <div class="brand">
        <i class="bi bi-flower1" style="font-size:2.5rem;color:var(--tea)"></i>
        <h1>Tea Spa</h1>
        <p>Form Reservasi Layanan</p>
    </div>

    <div class="card">
        <?php if ($success): ?>
        <div class="success-box">
            <i class="bi bi-check-circle-fill"></i>
            <h3>Reservasi Berhasil!</h3>
            <p style="color:#888;font-size:.9rem">Reservasi Anda telah kami terima. Kami akan mempersiapkan layanan terbaik untuk Anda.</p>
            <a href="<?= url('reserve.php') ?>" style="display:inline-block;margin-top:1.5rem;padding:.65rem 1.5rem;background:var(--tea);color:#fff;border-radius:8px;text-decoration:none;font-size:.88rem">+ Buat Reservasi Lain</a>
        </div>
        <?php else: ?>
        <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.25rem;color:var(--tea-dark);margin-bottom:1.5rem">Isi Form Reservasi</h3>
        <div id="conflictAlert" class="conflict-alert"></div>
        <form method="POST">
            <div class="row">
                <div class="mb-3">
                    <label class="form-label">Nama Lengkap *</label>
                    <input type="text" name="customer_name" class="form-control <?= isset($errors['customer_name'])?'is-invalid':'' ?>"
                           value="<?= sanitize(post('customer_name')) ?>" required>
                    <?php if (isset($errors['customer_name'])): ?><div class="invalid-feedback"><?= $errors['customer_name'] ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label">No. Telepon / WhatsApp *</label>
                    <input type="tel" name="phone_number" class="form-control <?= isset($errors['phone_number'])?'is-invalid':'' ?>"
                           value="<?= sanitize(post('phone_number')) ?>" required>
                    <?php if (isset($errors['phone_number'])): ?><div class="invalid-feedback"><?= $errors['phone_number'] ?></div><?php endif; ?>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Pilih Layanan *</label>
                <select name="service_id" id="serviceId" class="form-select" onchange="checkConflict()" required>
                    <option value="">-- Pilih Layanan --</option>
                    <?php foreach ($services as $s): ?>
                    <option value="<?= $s['id'] ?>" data-duration="<?= $s['duration'] ?>" <?= post('service_id')==$s['id']?'selected':'' ?>>
                        <?= sanitize($s['name']) ?> — <?= $s['duration'] ?> mnt — Rp <?= number_format($s['price'],0,',','.') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- <div class="mb-3">
                <label class="form-label">Terapis (Opsional)</label>
                <select name="therapist_id" id="therapistId" class="form-select" onchange="checkConflict()">
                    <option value="">-- Pilih Terapis --</option>
                    <?php foreach ($therapists as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= post('therapist_id')==$t['id']?'selected':'' ?>><?= sanitize($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div> -->
            <div class="row">
                <div class="mb-3">
                    <label class="form-label">Tanggal *</label>
                    <input type="date" name="reservation_date" id="resDate" class="form-control <?= isset($errors['reservation_date'])?'is-invalid':'' ?>"
                           value="<?= sanitize(post('reservation_date', date('Y-m-d'))) ?>"
                           min="<?= date('Y-m-d') ?>" onchange="checkConflict()" required>
                    <?php if (isset($errors['reservation_date'])): ?><div class="invalid-feedback"><?= $errors['reservation_date'] ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label">Waktu Mulai *</label>
                    <input type="time" name="reservation_time" id="resTime" class="form-control <?= isset($errors['reservation_time'])?'is-invalid':'' ?>"
                           value="<?= sanitize(post('reservation_time','09:00')) ?>" onchange="checkConflict()" required>
                    <?php if (isset($errors['reservation_time'])): ?><div class="invalid-feedback"><?= $errors['reservation_time'] ?></div><?php endif; ?>
                </div>
            </div>
            <!-- <div class="mb-3">
                <label class="form-label">Nomor Ruangan *</label>
                <div class="room-grid">
                    <?php foreach ($rooms as $r): ?>
                    <label>
                        <input type="radio" name="room_number" value="<?= $r ?>" style="display:none"
                               <?= post('room_number')===$r?'checked':'' ?> onchange="checkConflict()">
                        <span class="room-btn">R<?= $r ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($errors['room_number'])): ?><div class="invalid-feedback d-block"><?= $errors['room_number'] ?></div><?php endif; ?>
            </div> -->
            <div class="mb-3">
                <label class="form-label">Catatan Khusus</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Alergi, preferensi, dll..."><?= sanitize(post('notes')) ?></textarea>
            </div>
            <button type="submit" class="btn"><i class="bi bi-calendar-check"></i> Buat Reservasi</button>
        </form>
        <?php endif; ?>
    </div>
    <p style="text-align:center;margin-top:1.5rem;font-size:.75rem;color:#aaa">© <?= date('Y') ?> Tea Spa — Greenhost Boutique Hotel, Yogyakarta</p>
</div>

<script>
function checkConflict() {
    const date=document.getElementById('resDate')?.value,
          time=document.getElementById('resTime')?.value,
          serviceId=document.getElementById('serviceId')?.value,
          therapistId=document.getElementById('therapistId')?.value,
          room=document.querySelector('input[name="room_number"]:checked')?.value,
          alertEl=document.getElementById('conflictAlert');
    if(!date||!time||!serviceId) return;
    const p=new URLSearchParams({check_conflict:1,date,time,service_id:serviceId,therapist_id:therapistId||0,room:room||''});
    fetch('<?= url('reserve.php') ?>?'+p).then(r=>r.json()).then(data=>{
        if(data.conflicts&&data.conflicts.length){
            alertEl.innerHTML='⚠️ <strong>Konflik:</strong> '+data.conflicts.join(' ');
            alertEl.style.display='block';
        } else alertEl.style.display='none';
    }).catch(()=>{});
}
document.addEventListener('DOMContentLoaded',checkConflict);
</script>
</body>
</html>

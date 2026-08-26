<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES ADDENDUM
// Addendum merupakan perubahan clinical documentation yang sensitif.
// Permission update visit dipakai sementara mengikuti workflow SOAP.
// ============================================================
require_permission('visits.update');
Session::start();

if (!Session::has('csrf_addendum')) {
    Session::set('csrf_addendum', bin2hex(random_bytes(32)));
}
$csrfToken = (string) Session::get('csrf_addendum');

$visitId = filter_input(INPUT_GET, 'visit_id', FILTER_VALIDATE_INT);
if (!$visitId || $visitId < 1) {
    http_response_code(400);
    exit('ID kunjungan tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL SOAP FINALIZED
// Addendum hanya boleh dibuat untuk SOAP yang sudah dikunci.
// ============================================================
$query = $pdo->prepare(
    'SELECT
        v.id AS visit_id,
        v.patient_id,
        v.visit_number,
        v.visit_date,
        p.full_name,
        p.medical_record_number,
        p.status AS patient_status,
        mr.record_status,
        s.id AS soap_id,
        s.status AS soap_status,
        s.finalized_at
     FROM patient_visits AS v
     INNER JOIN patients AS p ON p.id = v.patient_id
     INNER JOIN patient_medical_records AS mr ON mr.id = v.medical_record_id
     INNER JOIN patient_visit_soap_notes AS s ON s.visit_id = v.id
     WHERE v.id = :visit_id
     LIMIT 1'
);
$query->execute(['visit_id' => $visitId]);
$record = $query->fetch();

if (!$record) {
    http_response_code(404);
    exit('Visit atau SOAP tidak ditemukan.');
}

if ((string) $record['patient_status'] !== 'active') {
    http_response_code(409);
    exit('Pasien tidak aktif.');
}

if ((string) $record['record_status'] !== 'active') {
    http_response_code(409);
    exit('Master rekam medis pasien sudah ditutup.');
}

if ((string) $record['soap_status'] !== 'finalized') {
    http_response_code(409);
    exit('Addendum hanya dapat dibuat setelah SOAP FINALIZED.');
}

$reason = '';
$content = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ========================================================
    // VALIDASI CSRF DAN INPUT
    // Reason dan content wajib diisi agar koreksi memiliki konteks.
    // ========================================================
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));

    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    }

    if ($reason === '') {
        $errors[] = 'Alasan addendum wajib diisi.';
    } elseif (mb_strlen($reason) > 255) {
        $errors[] = 'Alasan addendum maksimal 255 karakter.';
    }

    if ($content === '') {
        $errors[] = 'Isi addendum wajib diisi.';
    }

    if (!$errors) {
        try {
            // ====================================================
            // INSERT IMMUTABLE ADDENDUM
            // Tidak ada UPDATE atau DELETE dari workflow aplikasi.
            // SOAP finalized asli tidak disentuh sama sekali.
            // ====================================================
            $statement = $pdo->prepare(
                'INSERT INTO patient_visit_clinical_addendums
                    (visit_id, soap_id, reason, content, created_by)
                 VALUES
                    (:visit_id, :soap_id, :reason, :content, :created_by)'
            );

            $statement->execute([
                'visit_id' => $visitId,
                'soap_id' => (int) $record['soap_id'],
                'reason' => $reason,
                'content' => $content,
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);

            header('Location: /dashboard/patients/soap.php?visit_id=' . $visitId . '&addendum=1');
            exit;
        } catch (Throwable $exception) {
            $errors[] = 'Addendum gagal disimpan. Silakan coba lagi.';
        }
    }
}

$e = static function (?string $value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Addendum SOAP — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(850px, calc(100% - 32px)); margin: 32px auto 50px; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        h1 { margin: 0 0 7px; }
        .subtitle, .meta { color: #667085; line-height: 1.6; }
        .patient { padding: 15px; border: 1px solid #d9e2ec; border-radius: 12px; background: #f8fafc; margin: 20px 0; }
        .locked { padding: 14px 16px; border-radius: 10px; background: #ecfdf3; color: #146c43; margin-bottom: 18px; }
        .error { padding: 13px 15px; border-radius: 10px; background: #fff1f0; color: #b42318; margin-bottom: 16px; }
        .error ul { margin: 0; padding-left: 20px; }
        label { display: block; margin: 16px 0 7px; font-weight: 800; font-size: 14px; }
        input, textarea { width: 100%; border: 1px solid #d0d5dd; border-radius: 10px; padding: 12px; font: inherit; }
        textarea { min-height: 180px; resize: vertical; line-height: 1.5; }
        input:focus, textarea:focus { outline: 3px solid rgba(20,108,67,.12); border-color: #146c43; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
        a, button { border: 0; border-radius: 10px; padding: 11px 16px; font: inherit; font-weight: 800; text-decoration: none; cursor: pointer; }
        .cancel { background: #f2f4f7; color: #344054; }
        .save { background: #146c43; color: #fff; }
        @media (max-width: 650px) { .actions { flex-direction: column; } a, button { text-align: center; width: 100%; } }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- Header menjelaskan bahwa ini bukan edit terhadap SOAP asli. -->
        <h1>📝 Clinical Addendum</h1>
        <p class="subtitle">Tambahkan koreksi atau informasi klinis tanpa mengubah SOAP yang sudah FINALIZED.</p>

        <div class="patient">
            <strong><?= $e($record['full_name']) ?></strong>
            <div class="meta">RM: <?= $e($record['medical_record_number']) ?></div>
            <div class="meta">Kunjungan: <?= $e($record['visit_number']) ?> · <?= $e($record['visit_date']) ?></div>
            <div class="meta">SOAP finalized: <?= $e($record['finalized_at']) ?></div>
        </div>

        <div class="locked">🔒 SOAP FINALIZED — Catatan asli tidak akan diubah oleh addendum ini.</div>

        <?php if ($errors): ?>
            <div class="error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <!-- Token CSRF wajib untuk mencegah POST dari sumber tidak sah. -->
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

            <label for="reason">Alasan Addendum *</label>
            <input id="reason" name="reason" maxlength="255" required value="<?= $e($reason) ?>" placeholder="Contoh: Koreksi dokumentasi dosis obat">

            <label for="content">Isi Addendum *</label>
            <textarea id="content" name="content" required placeholder="Tuliskan koreksi atau informasi klinis tambahan secara jelas."><?= $e($content) ?></textarea>

            <div class="actions">
                <a class="cancel" href="/dashboard/patients/soap.php?visit_id=<?= (int) $visitId ?>">Batal</a>
                <button class="save" type="submit">📝 Simpan Addendum</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>

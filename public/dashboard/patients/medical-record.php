<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES REKAM MEDIS
// Halaman rekam medis mengikuti permission melihat data pasien.
// ============================================================
require_permission('patients.view');

Session::start();

// ============================================================
// VALIDASI ID PASIEN
// Rekam medis selalu dibuka berdasarkan pasien yang dipilih.
// ============================================================
$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$patientId || $patientId < 1) {
    http_response_code(400);
    exit('ID pasien tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL IDENTITAS PASIEN + MASTER REKAM MEDIS
// Medical record menjadi clinical container permanen pasien.
// ============================================================
$patientQuery = $pdo->prepare(
    'SELECT
        p.id,
        p.medical_record_number,
        p.full_name,
        p.nik,
        p.gender,
        p.birth_date,
        p.status,
        mr.id AS medical_record_id,
        mr.record_status,
        mr.opened_at,
        mr.closed_at,
        mr.updated_at AS medical_record_updated_at
     FROM patients AS p
     LEFT JOIN patient_medical_records AS mr
        ON mr.patient_id = p.id
     WHERE p.id = :patient_id
     LIMIT 1'
);
$patientQuery->execute(['patient_id' => $patientId]);
$patient = $patientQuery->fetch();

if (!$patient) {
    http_response_code(404);
    exit('Pasien tidak ditemukan.');
}

// ============================================================
// VALIDASI MASTER REKAM MEDIS
// Jika migration 022 belum dijalankan, halaman memberikan pesan
// yang jelas daripada menghasilkan data klinis yang menyesatkan.
// ============================================================
if (empty($patient['medical_record_id'])) {
    http_response_code(409);
    exit('Master rekam medis pasien belum tersedia. Jalankan migration 022 terlebih dahulu.');
}

$medicalRecordId = (int) $patient['medical_record_id'];

// ============================================================
// AMBIL TIMELINE KLINIS
// Semua kunjungan diambil melalui medical_record_id sebagai
// clinical container, bukan hanya melalui patient_id.
// ============================================================
$visitQuery = $pdo->prepare(
    'SELECT
        v.id,
        v.visit_number,
        v.visit_date,
        v.complaint,
        v.examination,
        v.diagnosis,
        v.treatment,
        v.notes,
        v.status,
        v.voided_at,
        v.void_reason,
        creator.name AS created_by_name,
        updater.name AS updated_by_name
     FROM patient_visits AS v
     LEFT JOIN users AS creator
        ON creator.id = v.created_by
     LEFT JOIN users AS updater
        ON updater.id = v.updated_by
     WHERE v.medical_record_id = :medical_record_id
     ORDER BY v.visit_date DESC, v.id DESC'
);
$visitQuery->execute(['medical_record_id' => $medicalRecordId]);
$visits = $visitQuery->fetchAll();

// ============================================================
// HELPER OUTPUT AMAN
// Semua data dari database di-escape sebelum dirender ke HTML.
// ============================================================
$e = static function (?string $value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};

$genderLabel = [
    'male' => 'Laki-laki',
    'female' => 'Perempuan',
][$patient['gender']] ?? (string) $patient['gender'];

$recordStatusLabel = (string) $patient['record_status'] === 'active' ? 'AKTIF' : 'DITUTUP';
$patientStatusLabel = (string) $patient['status'] === 'active' ? 'ACTIVE' : 'INACTIVE';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medical Record — <?= $e($patient['full_name']) ?> — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1150px, calc(100% - 32px)); margin: 30px auto 50px; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .topbar { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 22px; }
        h1 { margin: 0 0 6px; font-size: 28px; }
        .subtitle { margin: 0; color: #667085; }
        .actions { display: flex; gap: 9px; flex-wrap: wrap; justify-content: flex-end; }
        .button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 14px; }
        .button.primary { background: #146c43; color: #fff; }
        .button.secondary { background: #f2f4f7; color: #344054; }
        .patient-card { display: flex; justify-content: space-between; gap: 18px; align-items: center; padding: 20px; border: 1px solid #dfe5e9; border-radius: 15px; background: #f8fafc; }
        .identity { display: flex; align-items: center; gap: 15px; min-width: 0; }
        .avatar { width: 58px; height: 58px; flex: 0 0 auto; display: grid; place-items: center; border-radius: 50%; background: #d1fadf; font-size: 27px; }
        .name { margin: 0 0 5px; font-size: 22px; }
        .meta { color: #667085; font-size: 13px; line-height: 1.6; }
        .meta strong { color: #146c43; }
        .badges { display: flex; gap: 7px; flex-wrap: wrap; justify-content: flex-end; }
        .badge { display: inline-flex; padding: 6px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; }
        .badge.record { background: #ecfdf3; color: #146c43; }
        .badge.patient { background: #eff6ff; color: #175cd3; }
        .section-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 28px 0 15px; }
        .section-head h2 { margin: 0; color: #146c43; font-size: 19px; }
        .count { color: #667085; font-size: 13px; font-weight: 700; }
        .record-info { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .info-item { padding: 13px; border: 1px solid #e3e7eb; border-radius: 11px; }
        .label { display: block; margin-bottom: 5px; color: #667085; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
        .value { font-weight: 700; line-height: 1.45; }
        .timeline { position: relative; display: grid; gap: 16px; }
        .timeline::before { content: ''; position: absolute; left: 14px; top: 16px; bottom: 16px; width: 2px; background: #dfe5e9; }
        .event { position: relative; margin-left: 42px; padding: 18px; border: 1px solid #e3e7eb; border-radius: 14px; background: #fff; }
        .event::before { content: ''; position: absolute; left: -36px; top: 18px; width: 12px; height: 12px; border-radius: 50%; background: #146c43; border: 4px solid #d1fadf; }
        .event.cancelled { opacity: .78; border-style: dashed; }
        .event-head { display: flex; justify-content: space-between; gap: 15px; align-items: flex-start; margin-bottom: 14px; }
        .date { color: #146c43; font-weight: 800; }
        .visit-number { margin-top: 4px; color: #667085; font-size: 12px; }
        .status { display: inline-flex; padding: 5px 9px; border-radius: 999px; background: #f2f4f7; color: #344054; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .status.completed { background: #ecfdf3; color: #146c43; }
        .status.cancelled { background: #fef3f2; color: #b42318; }
        .clinical-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .clinical-item { padding: 13px; border-radius: 10px; background: #f8fafc; min-width: 0; }
        .clinical-item.full { grid-column: 1 / -1; }
        .clinical-value { white-space: pre-wrap; line-height: 1.55; word-break: break-word; }
        .void-box { margin-top: 12px; padding: 12px; border-radius: 10px; background: #fff1f0; color: #912018; font-size: 13px; }
        .audit { margin-top: 12px; color: #667085; font-size: 11px; line-height: 1.6; }
        .empty { padding: 32px 20px; border: 1px dashed #cfd6dc; border-radius: 14px; text-align: center; color: #667085; }
        @media (max-width: 850px) {
            .topbar, .patient-card { flex-direction: column; align-items: stretch; }
            .actions, .badges { justify-content: flex-start; }
            .record-info { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 600px) {
            main { width: min(100% - 20px, 1150px); margin-top: 18px; }
            .panel { padding: 18px; }
            h1 { font-size: 23px; }
            .record-info, .clinical-grid { grid-template-columns: 1fr; }
            .clinical-item.full { grid-column: auto; }
            .event { margin-left: 32px; padding: 15px; }
            .timeline::before { left: 10px; }
            .event::before { left: -28px; }
            .button { width: 100%; }
            .actions { flex-direction: column; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- =====================================================
             HEADER MEDICAL RECORD
             Menempatkan pasien dan master rekam medis sebagai
             identitas utama clinical container.
             ===================================================== -->
        <div class="topbar">
            <div>
                <h1>🩺 Medical Record</h1>
                <p class="subtitle">Timeline rekam medis dan perjalanan klinis pasien.</p>
            </div>
            <div class="actions">
                <a class="button secondary" href="/dashboard/patients/profile.php?id=<?= (int) $patientId ?>">← Profil Pasien</a>
                <?php if (Auth::can('visits.create') && (string) $patient['status'] === 'active'): ?>
                    <a class="button primary" href="/dashboard/patients/visit.php?id=<?= (int) $patientId ?>">🩺 Kunjungan Baru</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="patient-card">
            <div class="identity">
                <div class="avatar">👤</div>
                <div>
                    <h2 class="name"><?= $e($patient['full_name']) ?></h2>
                    <div class="meta">
                        No. RM: <strong><?= $e($patient['medical_record_number']) ?></strong>
                        · NIK: <?= $e($patient['nik']) ?>
                        · <?= $e($genderLabel) ?>
                    </div>
                </div>
            </div>
            <div class="badges">
                <span class="badge record">MR <?= $e($recordStatusLabel) ?></span>
                <span class="badge patient"><?= $e($patientStatusLabel) ?></span>
            </div>
        </div>

        <!-- =====================================================
             RINGKASAN MASTER REKAM MEDIS
             Data ini berasal dari patient_medical_records.
             ===================================================== -->
        <section>
            <div class="section-head">
                <h2>📁 Master Rekam Medis</h2>
            </div>
            <div class="record-info">
                <div class="info-item"><span class="label">Medical Record ID</span><div class="value">#<?= $medicalRecordId ?></div></div>
                <div class="info-item"><span class="label">Status</span><div class="value"><?= $e($recordStatusLabel) ?></div></div>
                <div class="info-item"><span class="label">Dibuka</span><div class="value"><?= $e($patient['opened_at']) ?></div></div>
                <div class="info-item"><span class="label">Update Terakhir</span><div class="value"><?= $e($patient['medical_record_updated_at']) ?></div></div>
            </div>
        </section>

        <!-- =====================================================
             CLINICAL TIMELINE
             Satu event mewakili satu encounter/visit yang terikat
             langsung ke master medical record pasien.
             ===================================================== -->
        <section>
            <div class="section-head">
                <h2>📋 Clinical Timeline</h2>
                <span class="count"><?= count($visits) ?> kunjungan</span>
            </div>

            <?php if (!$visits): ?>
                <div class="empty">
                    <strong>Belum ada kunjungan klinis.</strong><br>
                    Riwayat pelayanan akan muncul di sini setelah kunjungan pertama dibuat.
                </div>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($visits as $visit): ?>
                        <?php $isCancelled = (string) $visit['status'] === 'cancelled'; ?>
                        <article class="event <?= $isCancelled ? 'cancelled' : '' ?>">
                            <div class="event-head">
                                <div>
                                    <div class="date"><?= $e($visit['visit_date']) ?></div>
                                    <div class="visit-number"><?= $e($visit['visit_number']) ?></div>
                                </div>
                                <span class="status <?= $e($visit['status']) ?>"><?= $e($visit['status']) ?></span>
                            </div>

                            <div class="clinical-grid">
                                <div class="clinical-item">
                                    <span class="label">Keluhan</span>
                                    <div class="clinical-value"><?= $e($visit['complaint'] ?: 'Belum dicatat.') ?></div>
                                </div>
                                <div class="clinical-item">
                                    <span class="label">Pemeriksaan</span>
                                    <div class="clinical-value"><?= $e($visit['examination'] ?: 'Belum dicatat.') ?></div>
                                </div>
                                <div class="clinical-item">
                                    <span class="label">Diagnosis</span>
                                    <div class="clinical-value"><?= $e($visit['diagnosis'] ?: 'Belum dicatat.') ?></div>
                                </div>
                                <div class="clinical-item">
                                    <span class="label">Tindakan / Terapi</span>
                                    <div class="clinical-value"><?= $e($visit['treatment'] ?: 'Belum dicatat.') ?></div>
                                </div>
                                <div class="clinical-item full">
                                    <span class="label">Catatan</span>
                                    <div class="clinical-value"><?= $e($visit['notes'] ?: 'Tidak ada catatan tambahan.') ?></div>
                                </div>
                            </div>

                            <?php if ($isCancelled): ?>
                                <!-- =================================
                                     VOID / PEMBATALAN
                                     Histori tetap ditampilkan dan tidak
                                     dihapus agar jejak klinis terjaga.
                                     ================================= -->
                                <div class="void-box">
                                    <strong>⚠️ Kunjungan dibatalkan.</strong><br>
                                    Alasan: <?= $e($visit['void_reason'] ?: 'Tidak dicatat.') ?><br>
                                    Waktu void: <?= $e($visit['voided_at'] ?: '—') ?>
                                </div>
                            <?php endif; ?>

                            <div class="audit">
                                Dibuat oleh: <?= $e($visit['created_by_name'] ?: '—') ?>
                                · Diperbarui oleh: <?= $e($visit['updated_by_name'] ?: '—') ?>
                            </div>

                            <div class="actions" style="margin-top:12px; justify-content:flex-start;">
                                <?php if (Auth::can('visits.view')): ?>
                                    <a class="button secondary" href="/dashboard/patients/visits.php?id=<?= (int) $patientId ?>">📋 Riwayat Kunjungan</a>
                                <?php endif; ?>
                                <?php if (Auth::can('visits.update') && !$isCancelled): ?>
                                    <a class="button secondary" href="/dashboard/patients/visit-edit.php?id=<?= (int) $visit['id'] ?>">✏️ Edit Kunjungan</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>
</body>
</html>

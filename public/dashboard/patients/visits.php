<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';
require_once __DIR__ . '/../../../app/helpers/csrf.php';

// ============================================================
// AKSES RIWAYAT KUNJUNGAN
// Hanya pengguna dengan permission visits.view yang dapat melihat
// seluruh riwayat kunjungan pasien.
// ============================================================
require_permission('visits.view');
Session::start();

$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$patientId || $patientId < 1) {
    http_response_code(400);
    exit('ID pasien tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL IDENTITAS PASIEN
// ============================================================
$patientQuery = $pdo->prepare(
    'SELECT id, medical_record_number, full_name, status
     FROM patients
     WHERE id = :id
     LIMIT 1'
);
$patientQuery->execute(['id' => $patientId]);
$patient = $patientQuery->fetch();

if (!$patient) {
    http_response_code(404);
    exit('Pasien tidak ditemukan.');
}

// ============================================================
// TOKEN CSRF VOID
// Token yang sama digunakan oleh form dan endpoint void.
// ============================================================
$voidCsrfToken = csrf_token();

// ============================================================
// AMBIL RIWAYAT KUNJUNGAN
// Metadata void ikut ditampilkan untuk menjaga audit trail.
// ============================================================
$visitQuery = $pdo->prepare(
    'SELECT pv.id, pv.visit_number, pv.visit_date, pv.complaint,
            pv.examination, pv.diagnosis, pv.treatment, pv.notes,
            pv.status, pv.created_at, pv.voided_at, pv.void_reason,
            vu.name AS voided_by_name
     FROM patient_visits pv
     LEFT JOIN users vu ON vu.id = pv.voided_by
     WHERE pv.patient_id = :patient_id
     ORDER BY pv.visit_date DESC, pv.id DESC'
);
$visitQuery->execute(['patient_id' => $patientId]);
$visits = $visitQuery->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Riwayat Kunjungan — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1050px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .titlebar { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 24px; }
        h1 { margin: 0 0 6px; }
        .subtitle { margin: 0; color: #667085; }
        .back { color: #475467; text-decoration: none; font-weight: 700; }
        .patient-box { padding: 15px 17px; margin-bottom: 25px; border: 1px solid #abefc6; border-radius: 12px; background: #ecfdf3; }
        .patient-box strong { display: block; color: #146c43; margin-bottom: 5px; }
        .patient-meta { color: #475467; font-size: 14px; }
        .empty { padding: 34px 20px; border: 1px dashed #d0d5dd; border-radius: 14px; text-align: center; color: #667085; }
        .timeline { position: relative; display: grid; gap: 18px; }
        .visit { position: relative; padding: 20px; border: 1px solid #e3e7eb; border-radius: 15px; background: #fff; }
        .visit-head { display: flex; justify-content: space-between; gap: 15px; margin-bottom: 16px; }
        .visit-date { font-weight: 800; color: #146c43; }
        .visit-number { font-size: 12px; color: #667085; margin-top: 4px; }
        .badge { display: inline-flex; align-items: center; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 800; }
        .badge.completed { background: #ecfdf3; color: #146c43; }
        .badge.open { background: #fffaeb; color: #b54708; }
        .badge.cancelled { background: #fef3f2; color: #b42318; }
        .clinical-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .clinical-item { padding: 13px 14px; border-radius: 11px; background: #f8fafc; }
        .clinical-item.full { grid-column: 1 / -1; }
        .clinical-label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 800; color: #667085; text-transform: uppercase; letter-spacing: .04em; }
        .clinical-value { white-space: pre-wrap; line-height: 1.55; }
        .void-box { margin-top: 14px; padding: 14px; border: 1px solid #fecdca; border-radius: 11px; background: #fef3f2; }
        .void-meta { margin: 0 0 6px; font-size: 13px; font-weight: 800; color: #b42318; }
        .void-reason { margin: 0; white-space: pre-wrap; line-height: 1.5; color: #7a271a; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
        .action { display: inline-block; border: 0; cursor: pointer; padding: 8px 11px; border-radius: 8px; font: inherit; font-weight: 700; text-decoration: none; }
        .action.edit { background: #f2f4f7; color: #344054; }
        .action.void { background: #fff1f0; color: #b42318; }
        .void-form { display: inline; margin: 0; }
        .modal-backdrop { display: none; position: fixed; inset: 0; z-index: 1000; align-items: center; justify-content: center; padding: 20px; background: rgba(16,24,40,.55); }
        .modal-backdrop.is-open { display: flex; }
        .modal { width: min(520px, 100%); padding: 24px; border-radius: 16px; background: #fff; box-shadow: 0 24px 60px rgba(0,0,0,.2); }
        .modal h2 { margin: 0 0 8px; }
        .modal p { color: #667085; line-height: 1.5; }
        .reason-label { display: block; margin: 18px 0 7px; font-weight: 800; }
        .reason-input { width: 100%; min-height: 120px; resize: vertical; padding: 12px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; }
        .reason-input:focus { outline: 2px solid #98a2b3; outline-offset: 1px; }
        .reason-error { display: none; margin-top: 7px; color: #b42318; font-size: 13px; font-weight: 700; }
        .reason-counter { margin-top: 6px; text-align: right; color: #667085; font-size: 12px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
        .modal-button { padding: 9px 13px; border: 0; border-radius: 9px; cursor: pointer; font: inherit; font-weight: 800; }
        .modal-button.cancel { background: #f2f4f7; color: #344054; }
        .modal-button.submit { background: #b42318; color: #fff; }
        @media (max-width: 700px) {
            main { margin-top: 22px; }
            .titlebar, .visit-head { flex-direction: column; }
            .clinical-grid { grid-template-columns: 1fr; }
            .clinical-item.full { grid-column: auto; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- =====================================================
             HEADER RIWAYAT
             ===================================================== -->
        <div class="titlebar">
            <div>
                <h1>🩺 Riwayat Kunjungan</h1>
                <p class="subtitle">Timeline perjalanan klinis pasien.</p>
            </div>
            <a class="back" href="/dashboard/patients/profile.php?id=<?= (int) $patientId ?>">← Profil Pasien</a>
        </div>

        <div class="patient-box">
            <strong><?= htmlspecialchars((string) $patient['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
            <div class="patient-meta">
                RM: <?= htmlspecialchars((string) $patient['medical_record_number'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>

        <?php if (!$visits): ?>
            <!-- Kondisi kosong ketika pasien belum mempunyai kunjungan. -->
            <div class="empty">Belum ada riwayat kunjungan klinis untuk pasien ini.</div>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($visits as $visit): ?>
                    <article class="visit">
                        <div class="visit-head">
                            <div>
                                <div class="visit-date">
                                    <?= htmlspecialchars(date('d-m-Y', strtotime((string) $visit['visit_date'])), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="visit-number">
                                    <?= htmlspecialchars((string) $visit['visit_number'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                            <span class="badge <?= htmlspecialchars((string) $visit['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(ucfirst((string) $visit['status']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>

                        <div class="clinical-grid">
                            <div class="clinical-item">
                                <span class="clinical-label">Keluhan Utama</span>
                                <div class="clinical-value"><?= htmlspecialchars((string) ($visit['complaint'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="clinical-item">
                                <span class="clinical-label">Diagnosis</span>
                                <div class="clinical-value"><?= htmlspecialchars((string) ($visit['diagnosis'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="clinical-item">
                                <span class="clinical-label">Pemeriksaan</span>
                                <div class="clinical-value"><?= htmlspecialchars((string) ($visit['examination'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="clinical-item">
                                <span class="clinical-label">Tindakan / Terapi</span>
                                <div class="clinical-value"><?= htmlspecialchars((string) ($visit['treatment'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="clinical-item full">
                                <span class="clinical-label">Catatan Klinis</span>
                                <div class="clinical-value"><?= htmlspecialchars((string) ($visit['notes'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>

                        <?php if ($visit['status'] === 'cancelled'): ?>
                            <!-- Detail pembatalan tetap tampil untuk audit trail. -->
                            <div class="void-box">
                                <p class="void-meta">
                                    🚫 Dibatalkan
                                    <?php if ($visit['voided_by_name']): ?>
                                        oleh <?= htmlspecialchars((string) $visit['voided_by_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                    <?php if ($visit['voided_at']): ?>
                                        pada <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $visit['voided_at'])), ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </p>
                                <p class="void-reason">
                                    Alasan: <?= htmlspecialchars((string) ($visit['void_reason'] ?: 'Tidak dicantumkan.'), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <div class="actions">
                            <?php if ($visit['status'] !== 'cancelled' && Auth::can('visits.update')): ?>
                                <a class="action edit" href="/dashboard/patients/visit-edit.php?id=<?= (int) $visit['id'] ?>">Edit</a>
                            <?php endif; ?>

                            <?php if ($visit['status'] !== 'cancelled' && Auth::can('visits.void')): ?>
                                <!-- Tombol membuka form alasan; proses tetap memakai POST + CSRF. -->
                                <button
                                    class="action void js-open-void"
                                    type="button"
                                    data-visit-id="<?= (int) $visit['id'] ?>"
                                    data-visit-number="<?= htmlspecialchars((string) $visit['visit_number'], ENT_QUOTES, 'UTF-8') ?>"
                                >Void</button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<!-- ==========================================================
     MODAL VOID
     Alasan wajib diisi agar setiap pembatalan memiliki konteks
     audit yang jelas. Form dikirim sebagai POST ke endpoint void.
     ========================================================== -->
<div class="modal-backdrop" id="voidModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="voidModalTitle">
        <h2 id="voidModalTitle">🚫 Batalkan Kunjungan</h2>
        <p id="voidVisitInfo">Kunjungan akan dibatalkan tanpa menghapus record klinis.</p>

        <form id="voidForm" method="post" action="/dashboard/patients/visit-void.php">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($voidCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" id="voidVisitId" value="">

            <label class="reason-label" for="voidReason">Alasan pembatalan <span aria-hidden="true">*</span></label>
            <textarea
                class="reason-input"
                id="voidReason"
                name="void_reason"
                maxlength="500"
                required
                placeholder="Tuliskan alasan pembatalan kunjungan..."
            ></textarea>
            <div class="reason-error" id="voidReasonError">Alasan pembatalan wajib diisi.</div>
            <div class="reason-counter"><span id="voidReasonCount">0</span>/500</div>

            <div class="modal-actions">
                <button class="modal-button cancel" type="button" id="voidCancel">Batal</button>
                <button class="modal-button submit" type="submit">Void Kunjungan</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================================
// INTERAKSI FORM VOID
// JavaScript hanya mengatur UX; validasi final tetap dilakukan
// oleh endpoint PHP agar keamanan tidak bergantung pada browser.
// ============================================================
(function () {
    const modal = document.getElementById('voidModal');
    const form = document.getElementById('voidForm');
    const visitIdInput = document.getElementById('voidVisitId');
    const visitInfo = document.getElementById('voidVisitInfo');
    const reasonInput = document.getElementById('voidReason');
    const reasonError = document.getElementById('voidReasonError');
    const reasonCount = document.getElementById('voidReasonCount');
    const cancelButton = document.getElementById('voidCancel');

    function openModal(button) {
        visitIdInput.value = button.dataset.visitId || '';
        visitInfo.textContent = 'Kunjungan ' + (button.dataset.visitNumber || '') + ' akan dibatalkan tanpa menghapus record klinis.';
        reasonInput.value = '';
        reasonError.style.display = 'none';
        reasonCount.textContent = '0';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        reasonInput.focus();
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.js-open-void').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(button);
        });
    });

    cancelButton.addEventListener('click', closeModal);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    reasonInput.addEventListener('input', function () {
        reasonCount.textContent = String(reasonInput.value.length);
        reasonError.style.display = 'none';
    });

    form.addEventListener('submit', function (event) {
        const reason = reasonInput.value.trim();

        if (reason === '') {
            event.preventDefault();
            reasonError.textContent = 'Alasan pembatalan wajib diisi.';
            reasonError.style.display = 'block';
            reasonInput.focus();
            return;
        }

        if (reason.length > 500) {
            event.preventDefault();
            reasonError.textContent = 'Alasan pembatalan maksimal 500 karakter.';
            reasonError.style.display = 'block';
            reasonInput.focus();
            return;
        }

        reasonInput.value = reason;
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
})();
</script>
</body>
</html>

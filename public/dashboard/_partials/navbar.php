<?php

declare(strict_types=1);

// ============================================================
// NAVBAR + SIDEBAR GLOBAL KLINIK TUBAGUS
// Menampilkan identitas aplikasi, navigasi modul, username,
// role pengguna, serta dropdown logout pada seluruh halaman.
// ============================================================
$currentUser = current_user();

$navbarName = (string) ($currentUser['name'] ?? $currentUser['username'] ?? 'Pengguna');
$navbarUsername = (string) ($currentUser['username'] ?? '');
$navbarRole = (string) ($currentUser['role_name'] ?? '');
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

// ============================================================
// HELPER NAVIGASI AKTIF
// Memberi penanda visual pada menu yang sedang dibuka.
// ============================================================
$isActive = static function (string $path) use ($currentPath): bool {
    return $currentPath === $path || str_starts_with($currentPath, rtrim($path, '/') . '/');
};
?>

<style>
    /* ========================================================
       LAYOUT SIDEBAR GLOBAL
       Sidebar dibuat tetap di sisi kiri agar smoke test semua
       modul dapat dilakukan tanpa kembali ke dashboard.
       ======================================================== */
    body {
        padding-left: 250px;
    }

    .global-sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        width: 250px;
        background: #17202a;
        color: #fff;
        z-index: 1100;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
    }

    .global-sidebar__brand {
        display: block;
        padding: 22px 20px;
        color: #fff;
        text-decoration: none;
        font-size: 19px;
        font-weight: 800;
        border-bottom: 1px solid rgba(255,255,255,.10);
    }

    .global-sidebar__section {
        padding: 18px 12px 8px;
        color: #98a2b3;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .global-sidebar__nav {
        padding: 4px 10px 18px;
    }

    .global-sidebar__link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 12px;
        margin: 3px 0;
        border-radius: 10px;
        color: #d0d5dd;
        text-decoration: none;
        font-weight: 700;
        transition: background .18s ease, color .18s ease;
    }

    .global-sidebar__link:hover,
    .global-sidebar__link:focus-visible {
        background: rgba(255,255,255,.08);
        color: #fff;
        outline: none;
    }

    .global-sidebar__link.is-active {
        background: #146c43;
        color: #fff;
    }

    .global-sidebar__footer {
        margin-top: auto;
        padding: 12px 14px 18px;
        color: #98a2b3;
        font-size: 12px;
    }

    /* ========================================================
       NAVBAR GLOBAL
       Navbar mengikuti area konten di sebelah kanan sidebar.
       ======================================================== */
    .global-navbar {
        padding: 14px 28px;
        background: #fff;
        border-bottom: 1px solid #e3e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        position: relative;
        z-index: 1000;
    }

    .global-navbar__brand {
        color: #17202a;
        font-size: 20px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }

    .global-navbar__right {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .global-navbar__user {
        position: relative;
    }

    .global-navbar__trigger {
        border: 1px solid #d0d5dd;
        background: #fff;
        color: #344054;
        padding: 9px 12px;
        border-radius: 10px;
        cursor: pointer;
        font: inherit;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .global-navbar__trigger:hover,
    .global-navbar__trigger:focus-visible {
        background: #f8fafc;
        border-color: #98a2b3;
        outline: none;
    }

    .global-navbar__caret {
        font-size: 11px;
        transition: transform .18s ease;
    }

    .global-navbar__user.is-open .global-navbar__caret {
        transform: rotate(180deg);
    }

    .global-navbar__menu {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        width: 230px;
        padding: 8px;
        background: #fff;
        border: 1px solid #e3e7eb;
        border-radius: 14px;
        box-shadow: 0 14px 35px rgba(0,0,0,.12);
        display: none;
    }

    .global-navbar__user.is-open .global-navbar__menu {
        display: block;
    }

    .global-navbar__profile {
        padding: 10px 11px 12px;
        border-bottom: 1px solid #edf0f2;
        margin-bottom: 6px;
    }

    .global-navbar__name {
        font-weight: 700;
        color: #17202a;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .global-navbar__meta {
        margin-top: 4px;
        color: #667085;
        font-size: 12px;
    }

    .global-navbar__logout {
        display: block;
        width: 100%;
        padding: 10px 11px;
        border-radius: 9px;
        color: #b42318;
        text-decoration: none;
        font-weight: 700;
    }

    .global-navbar__logout:hover,
    .global-navbar__logout:focus-visible {
        background: #fff1f0;
        outline: none;
    }

    @media (max-width: 700px) {
        body { padding-left: 220px; }
        .global-sidebar { width: 220px; }
        .global-sidebar__brand { padding: 18px 16px; font-size: 17px; }
        .global-navbar { padding: 12px 16px; }
        .global-navbar__brand { font-size: 18px; }
        .global-navbar__trigger { padding: 8px 10px; }
        .global-navbar__menu { width: 220px; }
    }
</style>

<!-- ==========================================================
     SIDEBAR GLOBAL
     Navigasi ditampilkan berdasarkan permission pengguna agar
     menu smoke test tetap mengikuti aturan RBAC.
     ========================================================== -->
<aside class="global-sidebar" aria-label="Navigasi utama">
    <a class="global-sidebar__brand" href="/dashboard/">🏥 Klinik Tubagus</a>

    <div class="global-sidebar__section">Utama</div>
    <nav class="global-sidebar__nav">
        <a class="global-sidebar__link <?= $isActive('/dashboard/') && !$isActive('/dashboard/patients') && !$isActive('/dashboard/doctors') && !$isActive('/dashboard/users') ? 'is-active' : '' ?>" href="/dashboard/">
            🏠 <span>Dashboard</span>
        </a>
    </nav>

    <div class="global-sidebar__section">Klinik</div>
    <nav class="global-sidebar__nav">
        <?php if (Auth::can('patients.view')): ?>
            <a class="global-sidebar__link <?= $isActive('/dashboard/patients') ? 'is-active' : '' ?>" href="/dashboard/patients/">
                👥 <span>Pasien</span>
            </a>
        <?php endif; ?>

        <?php if (Auth::can('doctors.view')): ?>
            <a class="global-sidebar__link <?= $isActive('/dashboard/doctors') ? 'is-active' : '' ?>" href="/dashboard/doctors/">
                🩺 <span>Dokter</span>
            </a>
        <?php endif; ?>
    </nav>

    <div class="global-sidebar__section">Administrasi</div>
    <nav class="global-sidebar__nav">
        <?php if (Auth::can('users.view')): ?>
            <a class="global-sidebar__link <?= $isActive('/dashboard/users') ? 'is-active' : '' ?>" href="/dashboard/users/">
                👤 <span>Users</span>
            </a>
        <?php endif; ?>

        <a class="global-sidebar__link <?= $isActive('/dashboard/rbac-test.php') ? 'is-active' : '' ?>" href="/dashboard/rbac-test.php">
            🔐 <span>RBAC Smoke Test</span>
        </a>
    </nav>

    <div class="global-sidebar__footer">
        Klinik Tubagus · Admin Panel
    </div>
</aside>

<header class="global-navbar">
    <a class="global-navbar__brand" href="/dashboard/">🏥 Klinik Tubagus</a>

    <div class="global-navbar__right">
        <!-- ====================================================
             PROFIL PENGGUNA AKTIF
             Username menjadi tombol utama untuk smoke test agar
             identitas akun yang sedang login selalu terlihat.
             ==================================================== -->
        <div class="global-navbar__user" data-navbar-user>
            <button
                class="global-navbar__trigger"
                type="button"
                aria-expanded="false"
                aria-haspopup="true"
                data-navbar-trigger
            >
                👤 <?= htmlspecialchars($navbarUsername !== '' ? $navbarUsername : $navbarName, ENT_QUOTES, 'UTF-8') ?>
                <span class="global-navbar__caret">▼</span>
            </button>

            <div class="global-navbar__menu" data-navbar-menu>
                <div class="global-navbar__profile">
                    <div class="global-navbar__name"><?= htmlspecialchars($navbarName, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="global-navbar__meta">
                        @<?= htmlspecialchars($navbarUsername, ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($navbarRole !== ''): ?>
                            · <?= htmlspecialchars($navbarRole, ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- =================================================
                     LOGOUT
                     Logout tetap tersedia melalui dropdown profil.
                     ================================================= -->
                <a class="global-navbar__logout" href="/logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

<script>
// ============================================================
// DROPDOWN PROFIL PENGGUNA
// Membuka/menutup menu username dan menutupnya ketika pengguna
// mengklik area di luar dropdown.
// ============================================================
(function () {
    const userMenu = document.querySelector('[data-navbar-user]');
    const trigger = document.querySelector('[data-navbar-trigger]');

    if (!userMenu || !trigger) return;

    trigger.addEventListener('click', function () {
        const isOpen = userMenu.classList.toggle('is-open');
        trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!userMenu.contains(event.target)) {
            userMenu.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        }
    });
})();
</script>

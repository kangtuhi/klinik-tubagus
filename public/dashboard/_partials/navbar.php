<?php

declare(strict_types=1);

// ============================================================
// NAVBAR GLOBAL KLINIK TUBAGUS
// Menampilkan identitas aplikasi, username pengguna aktif,
// role pengguna, serta dropdown untuk akses logout.
// File ini dipakai bersama oleh halaman dashboard dan modul.
// ============================================================
$currentUser = current_user();

$navbarName = (string) ($currentUser['name'] ?? $currentUser['username'] ?? 'Pengguna');
$navbarUsername = (string) ($currentUser['username'] ?? '');
$navbarRole = (string) ($currentUser['role_name'] ?? '');
?>

<style>
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
        .global-navbar { padding: 12px 16px; }
        .global-navbar__brand { font-size: 18px; }
        .global-navbar__trigger { padding: 8px 10px; }
        .global-navbar__menu { width: 220px; }
    }
</style>

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

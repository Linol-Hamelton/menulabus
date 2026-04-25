<?php
/**
 * partials/account_security_section.php — 2FA setup wizard (Phase 9.3).
 *
 * Renders on /account.php for any logged-in user. Shows current 2FA status
 * and a 3-step wizard:
 *   1. "Включить" → POST setup, JS shows the secret + QR otpauth URI.
 *   2. User scans with their app, enters a 6-digit code → POST enable.
 *   3. App returns 10 backup codes; UI shows them once.
 *
 * Disabled state is the default; the wizard collapses until the user clicks "Включить".
 *
 * Requires in scope: $user, $db.
 */

if (!isset($user['id'])) return;

$twofa = $db->getUser2FA((int)$user['id']);
$enabled = $twofa && (int)$twofa['enabled'] === 1;
?>
<section class="account-section account-security">
    <div class="account-section-head">
        <div class="account-section-heading">
            <p class="account-section-kicker">Security</p>
            <h2>Двухфакторная аутентификация</h2>
            <p class="account-section-copy">
                <?php if ($enabled): ?>
                    Включена. При следующем входе нужно будет ввести 6-значный код из приложения.
                    <?php if (!empty($twofa['last_used_at'])): ?>
                        Последний вход с кодом — <?= htmlspecialchars((string)$twofa['last_used_at']) ?>.
                    <?php endif; ?>
                <?php else: ?>
                    Защитите аккаунт одноразовыми кодами из приложения-аутентификатора (Google Authenticator, Authy, 1Password, Bitwarden).
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="account-2fa-block" data-2fa-enabled="<?= $enabled ? '1' : '0' ?>">
        <?php if ($enabled): ?>
            <div class="account-2fa-actions">
                <input type="text" id="twofaDisableCode" placeholder="Код из приложения или резервный" inputmode="numeric" pattern="[0-9A-Z\-]{6,14}">
                <button type="button" class="admin-checkout-btn cancel" id="twofaDisableBtn">Отключить</button>
                <button type="button" class="admin-checkout-btn" id="twofaRegenerateBtn">Сгенерировать новые резервные</button>
            </div>
            <div id="twofaResult" class="account-2fa-result" hidden></div>
        <?php else: ?>
            <button type="button" class="checkout-btn" id="twofaSetupBtn">Включить 2FA</button>
            <div id="twofaSetupBox" class="account-2fa-setup" hidden>
                <p>1. Откройте приложение-аутентификатор и отсканируйте QR-код или введите ключ вручную.</p>
                <div class="account-2fa-secret">
                    <code id="twofaSecret">—</code>
                    <small id="twofaUriHint">otpauth-ссылка для копирования</small>
                </div>
                <p>2. Введите 6-значный код, который покажет приложение:</p>
                <div class="account-2fa-actions">
                    <input type="text" id="twofaCode" placeholder="123456" inputmode="numeric" pattern="\d{6}" maxlength="6">
                    <button type="button" class="checkout-btn" id="twofaEnableBtn">Подтвердить</button>
                </div>
                <div id="twofaBackupCodes" class="account-2fa-backup" hidden></div>
            </div>
        <?php endif; ?>
    </div>
</section>

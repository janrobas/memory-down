<?php
/**
 * Users: the accounts on this instance, and what can be done to them.
 *
 * Admin-only, and it touches records rather than memory: creating, disabling,
 * resetting a password, changing a role, handing out an API token, and deleting
 * (which moves that account's whole directory into data/users/.trash). The one
 * thing an admin can do with somebody else's *memory* is download it.
 *
 * Some actions are locked in the UI as well as in the store — you cannot demote,
 * disable or delete yourself, and the last enabled admin cannot be removed —
 * because an instance with no admin has no way back except editing
 * data/auth/users.json by hand over FTP.
 *
 * @var string $csrf
 * @var string $currentId
 * @var array<string, \MemoryDown\Users\User> $users
 * @var int $enabledAdmins
 * @var array<string, array<string, array{token: string, enabled: bool}>> $tokens
 * @var string $notices
 * @var string $errors
 * @var callable $te
 * @var callable $t
 */
use MemoryDown\Users\UserStore;
use MemoryDown\Web\WebApp;

$h = static fn (string $v): string => WebApp::h($v);
?>
<main class="page-stack">

  <section class="page-card">
    <h1><?= $te('users.title') ?></h1>
    <p class="muted"><?= $te('users.intro') ?></p>

    <?php if ('' !== $notices): ?>
      <p class="alert ok" role="status"><?= $h($notices) ?></p>
    <?php endif; ?>
    <?php if ('' !== $errors): ?>
      <p class="alert" role="alert"><?= $h($errors) ?></p>
    <?php endif; ?>

    <p class="trash-back">
      <a href="/ui"><?= $te('users.back') ?></a> &middot; <a href="/ui/settings"><?= $te('users.your_settings') ?></a>
    </p>
  </section>

  <section class="page-card">
    <h2><?= $te('users.new_account') ?></h2>
    <p class="muted"><?= $te('users.new_account_intro', ['min' => UserStore::MIN_PASSWORD_LENGTH]) ?></p>
    <form method="post" action="/ui/users/create" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <label for="new-id"><?= $te('field.username') ?></label>
      <input type="text" id="new-id" name="id" required pattern="[a-z0-9][a-z0-9_-]{2,31}" placeholder="alice">
      <label for="new-role"><?= $te('users.role') ?></label>
      <select id="new-role" name="role">
        <option value="user"><?= $te('users.role_user_option') ?></option>
        <option value="admin"><?= $te('users.role_admin_option') ?></option>
      </select>
      <label for="new-password"><?= $te('field.password') ?></label>
      <input type="password" id="new-password" name="password" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>">
      <label for="new-confirm"><?= $te('field.confirm') ?></label>
      <input type="password" id="new-confirm" name="confirm" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>">
      <button type="submit" class="primary block"><?= $te('users.create') ?></button>
    </form>
  </section>

  <section class="page-card">
    <h2><?= $te('users.accounts') ?></h2>

    <?php foreach ($users as $id => $account): ?>
      <?php
        $locked = $id === $currentId
            || ($account->isAdmin() && $account->enabled && $enabledAdmins <= 1);
        $own = $id === $currentId;
      ?>
      <div class="account-row">
        <div class="account-who">
          <span class="account-name"><?= $h($account->id) ?></span>
          <span class="account-tags">
            <span class="pill<?= $account->isAdmin() ? ' pill-accent' : '' ?>"><?= $account->isAdmin() ? $te('users.role_admin') : $te('users.role_user') ?></span>
            <?php if (!$account->enabled): ?>
              <span class="pill pill-danger"><?= $te('users.disabled') ?></span>
            <?php endif; ?>
            <?php if ($own): ?>
              <span class="pill"><?= $te('users.you') ?></span>
            <?php endif; ?>
            <?php if ($account->isAdmin() && $enabledAdmins <= 1 && $account->enabled): ?>
              <span class="pill"><?= $te('users.only_enabled_admin') ?></span>
            <?php endif; ?>
          </span>
          <span class="muted"><?= $te('users.created', ['date' => gmdate('Y-m-d', $account->createdAt)]) ?></span>
        </div>

        <div class="account-actions">
          <?php if (!$locked): ?>
            <form class="inline-form" method="post" action="/ui/users/role">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="id" value="<?= $h($id) ?>">
              <select name="role" aria-label="<?= $te('users.role_aria', ['id' => $id]) ?>">
                <option value="user"<?= $account->isAdmin() ? '' : ' selected' ?>><?= $te('users.role_user') ?></option>
                <option value="admin"<?= $account->isAdmin() ? ' selected' : '' ?>><?= $te('users.role_admin') ?></option>
              </select>
              <button type="submit"><?= $te('users.set_role') ?></button>
            </form>

            <form class="inline-form" method="post" action="/ui/users/enabled">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="id" value="<?= $h($id) ?>">
              <input type="hidden" name="enabled" value="<?= $account->enabled ? '0' : '1' ?>">
              <button type="submit"><?= $account->enabled ? $te('users.disable') : $te('users.enable') ?></button>
            </form>
          <?php endif; ?>

          <details class="account-more">
            <summary><?= $te('users.more') ?></summary>

            <form class="inline-form" method="post" action="/ui/users/password" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="id" value="<?= $h($id) ?>">
              <input type="password" name="password" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>" placeholder="<?= $te('users.new_password_ph') ?>" aria-label="<?= $te('users.new_password_ph') ?>">
              <input type="password" name="confirm" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>" placeholder="<?= $te('users.confirm_ph') ?>" aria-label="<?= $te('users.confirm_ph') ?>">
              <button type="submit"><?= $te('users.reset_password') ?></button>
            </form>

            <?php if ($own): ?>
              <p class="muted"><?= $t('users.own_tokens') ?></p>
            <?php else: ?>
              <?php foreach ([UserStore::TOKEN_WRITINGS, UserStore::TOKEN_WORKFLOWS] as $kind): ?>
                <?php $token = $tokens[$id][$kind] ?? ['token' => '', 'enabled' => false]; ?>
                <?php $kindName = $t('settings.' . $kind . '_name'); ?>
                <div class="inline-form">
                  <span class="muted"><?= $te('users.token_line', ['kind' => $kindName]) ?>
                    <?= '' === $token['token'] ? $te('users.token_none') : '<span class="mono-inline">' . $h($token['token']) . '</span>' ?>
                  </span>
                  <form class="inline-form" method="post" action="/ui/users/token">
                    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= $h($id) ?>">
                    <input type="hidden" name="kind" value="<?= $h($kind) ?>">
                    <input type="hidden" name="action" value="rotate">
                    <button type="submit"><?= $te('users.rotate') ?></button>
                  </form>
                  <?php if ('' !== $token['token']): ?>
                    <form class="inline-form" method="post" action="/ui/users/token"
                          data-confirm="<?= $te('users.clear_confirm', ['kind' => $kindName, 'id' => $id]) ?>">
                      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                      <input type="hidden" name="id" value="<?= $h($id) ?>">
                      <input type="hidden" name="kind" value="<?= $h($kind) ?>">
                      <input type="hidden" name="action" value="clear">
                      <button type="submit" class="danger"><?= $te('settings.clear') ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <p>
              <a href="/ui/backup?user=<?= rawurlencode($id) ?>" download><?= $te('users.download') ?></a>
            </p>

            <?php if (!$locked): ?>
              <form method="post" action="/ui/users/delete"
                    data-confirm="<?= $te('users.delete_confirm', ['id' => $id]) ?>">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="id" value="<?= $h($id) ?>">
                <button type="submit" class="danger"><?= $te('users.delete_account') ?></button>
              </form>
              <p class="muted"><?= $t('users.delete_note') ?></p>
            <?php else: ?>
              <p class="muted">
                <?= $own ? $te('users.locked_self') : $te('users.locked_last_admin') ?>
              </p>
            <?php endif; ?>
          </details>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

</main>

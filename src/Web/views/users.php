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
 */
use MemoryDown\Users\UserStore;
use MemoryDown\Web\WebApp;

$h = static fn (string $v): string => WebApp::h($v);
?>
<main class="page-stack">

  <section class="page-card">
    <h1>Users</h1>
    <p class="muted">
      Every account has its own memory, its own password and its own API tokens. An account
      signs in here with the same username and password it uses on the MCP consent page, and
      it can only ever reach its own memory.
    </p>

    <?php if ('' !== $notices): ?>
      <p class="alert ok" role="status"><?= $h($notices) ?></p>
    <?php endif; ?>
    <?php if ('' !== $errors): ?>
      <p class="alert" role="alert"><?= $h($errors) ?></p>
    <?php endif; ?>

    <p class="trash-back">
      <a href="/ui">&larr; Back to memories</a> &middot; <a href="/ui/settings">Your settings</a>
    </p>
  </section>

  <section class="page-card">
    <h2>New account</h2>
    <p class="muted">
      The username cannot be changed later, and it becomes the name of that person's data
      directory. Passwords must be at least <?= UserStore::MIN_PASSWORD_LENGTH ?> characters.
    </p>
    <form method="post" action="/ui/users/create" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <label for="new-id">Username</label>
      <input type="text" id="new-id" name="id" required pattern="[a-z0-9][a-z0-9_-]{2,31}" placeholder="alice">
      <label for="new-role">Role</label>
      <select id="new-role" name="role">
        <option value="user">user — own memory only</option>
        <option value="admin">admin — also manages accounts</option>
      </select>
      <label for="new-password">Password</label>
      <input type="password" id="new-password" name="password" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>">
      <label for="new-confirm">Confirm password</label>
      <input type="password" id="new-confirm" name="confirm" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>">
      <button type="submit" class="primary block">Create account</button>
    </form>
  </section>

  <section class="page-card">
    <h2>Accounts</h2>

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
            <span class="pill<?= $account->isAdmin() ? ' pill-accent' : '' ?>"><?= $h($account->role) ?></span>
            <?php if (!$account->enabled): ?>
              <span class="pill pill-danger">disabled</span>
            <?php endif; ?>
            <?php if ($own): ?>
              <span class="pill">you</span>
            <?php endif; ?>
            <?php if ($account->isAdmin() && $enabledAdmins <= 1 && $account->enabled): ?>
              <span class="pill">only enabled admin</span>
            <?php endif; ?>
          </span>
          <span class="muted">created <?= $h(gmdate('Y-m-d', $account->createdAt)) ?></span>
        </div>

        <div class="account-actions">
          <?php if (!$locked): ?>
            <form class="inline-form" method="post" action="/ui/users/role">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="id" value="<?= $h($id) ?>">
              <select name="role" aria-label="Role for <?= $h($id) ?>">
                <option value="user"<?= $account->isAdmin() ? '' : ' selected' ?>>user</option>
                <option value="admin"<?= $account->isAdmin() ? ' selected' : '' ?>>admin</option>
              </select>
              <button type="submit">Set role</button>
            </form>

            <form class="inline-form" method="post" action="/ui/users/enabled">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="id" value="<?= $h($id) ?>">
              <input type="hidden" name="enabled" value="<?= $account->enabled ? '0' : '1' ?>">
              <button type="submit"><?= $account->enabled ? 'Disable' : 'Enable' ?></button>
            </form>
          <?php endif; ?>

          <details class="account-more">
            <summary>Password, tokens &amp; delete</summary>

            <form class="inline-form" method="post" action="/ui/users/password" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="id" value="<?= $h($id) ?>">
              <input type="password" name="password" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>" placeholder="New password" aria-label="New password for <?= $h($id) ?>">
              <input type="password" name="confirm" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>" placeholder="Confirm" aria-label="Confirm new password for <?= $h($id) ?>">
              <button type="submit">Reset password</button>
            </form>

            <?php if ($own): ?>
              <p class="muted">Your own tokens are on <a href="/ui/settings">Settings</a>.</p>
            <?php else: ?>
              <?php foreach ([UserStore::TOKEN_WRITINGS, UserStore::TOKEN_WORKFLOWS] as $kind): ?>
                <?php $token = $tokens[$id][$kind] ?? ['token' => '', 'enabled' => false]; ?>
                <div class="inline-form">
                  <span class="muted"><?= $h($kind) ?> token:
                    <?= '' === $token['token'] ? 'none' : '<span class="mono-inline">' . $h($token['token']) . '</span>' ?>
                  </span>
                  <form class="inline-form" method="post" action="/ui/users/token">
                    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= $h($id) ?>">
                    <input type="hidden" name="kind" value="<?= $h($kind) ?>">
                    <input type="hidden" name="action" value="rotate">
                    <button type="submit">Rotate</button>
                  </form>
                  <?php if ('' !== $token['token']): ?>
                    <form class="inline-form" method="post" action="/ui/users/token">
                      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                      <input type="hidden" name="id" value="<?= $h($id) ?>">
                      <input type="hidden" name="kind" value="<?= $h($kind) ?>">
                      <input type="hidden" name="action" value="clear">
                      <button type="submit" class="danger">Clear</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <p>
              <a href="/ui/backup?user=<?= rawurlencode($id) ?>" download>Download this account's memory (zip)</a>
            </p>

            <?php if (!$locked): ?>
              <form method="post" action="/ui/users/delete">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="id" value="<?= $h($id) ?>">
                <button type="submit" class="danger">Delete account</button>
              </form>
              <p class="muted">
                Deleting moves that account's directory to <code>data/users/.trash</code>. Its
                memory is not erased, but nothing in the app can see it again.
              </p>
            <?php else: ?>
              <p class="muted">
                <?= $own
                    ? 'You cannot change your own role, disable or delete yourself.'
                    : 'The last enabled admin cannot be demoted, disabled or deleted.' ?>
              </p>
            <?php endif; ?>
          </details>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

</main>

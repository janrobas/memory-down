<?php
/**
 * Settings: the account you are signed in as, and the two things only you can
 * change about it — the password (which is also the OAuth consent password, so
 * changing it signs out every other session and MCP connection) and the API
 * tokens of the read-only publishing APIs.
 *
 * @var string $csrf
 * @var \MemoryDown\Users\User $user
 * @var array<string, array{token: string, enabled: bool}> $tokens
 * @var array<string, bool> $apiEnabled
 * @var string $notices
 * @var string $errors
 */
use MemoryDown\Users\UserStore;
use MemoryDown\Web\WebApp;

$h = static fn (string $v): string => WebApp::h($v);
$labels = [
    UserStore::TOKEN_WRITINGS => [
        'name' => 'Writings API',
        'what' => 'read-only access to your published writings (entries in the writings category that are marked public), at /public/writings',
    ],
    UserStore::TOKEN_WORKFLOWS => [
        'name' => 'Workflows API',
        'what' => 'read-only access to your workflow definitions, at /api/workflows',
    ],
];
?>
<main class="page-stack">

  <section class="page-card">
    <h1>Settings</h1>
    <p class="muted">
      Signed in as <strong><?= $h($user->id) ?></strong>
      <span class="pill<?= $user->isAdmin() ? ' pill-accent' : '' ?>"><?= $h($user->role) ?></span>
    </p>

    <?php if ('' !== $notices): ?>
      <p class="alert ok" role="status"><?= $h($notices) ?></p>
    <?php endif; ?>
    <?php if ('' !== $errors): ?>
      <p class="alert" role="alert"><?= $h($errors) ?></p>
    <?php endif; ?>

    <p class="trash-back">
      <a href="/ui">&larr; Back to memories</a>
      <?php if ($user->isAdmin()): ?> &middot; <a href="/ui/users">Users</a><?php endif; ?>
    </p>
  </section>

  <section class="page-card">
    <h2>Password</h2>
    <p class="muted">
      This is the same password MCP clients ask for on the consent page, and the one you
      sign in here with. Changing it signs out your other sessions and invalidates every
      MCP connection of this account; you stay signed in here.
    </p>
    <form method="post" action="/ui/settings/password" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <label for="current">Current password</label>
      <input type="password" id="current" name="current" required autocomplete="current-password">
      <label for="password">New password</label>
      <input type="password" id="password" name="password" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>">
      <label for="confirm">Confirm new password</label>
      <input type="password" id="confirm" name="confirm" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>">
      <button type="submit" class="primary block">Change password</button>
    </form>
  </section>

  <section class="page-card">
    <h2>API tokens</h2>
    <p class="muted">
      These are your keys to the read-only publishing APIs. Each one is shown in full so
      you can copy it; treat it like a password, because it does not need a login.
      Rotating replaces it immediately, and whatever was using the old one stops working.
    </p>

    <?php foreach ($labels as $kind => $label): ?>
      <?php $entry = $tokens[$kind] ?? ['token' => '', 'enabled' => false]; ?>
      <div class="token-block">
        <div class="account-name"><?= $h($label['name']) ?></div>
        <p class="muted">
          <?= $h($label['what']) ?>.
          <?php if (!$entry['enabled']): ?>
            <strong>This API is switched off for the whole instance</strong> — a token will not
            answer until it is enabled (its own <code>_API_ENABLED</code> setting).
          <?php endif; ?>
        </p>

        <?php if ('' === $entry['token']): ?>
          <p class="muted">No token yet: nothing can read that API for your account.</p>
        <?php else: ?>
          <details class="account-more">
            <summary>Show token</summary>
            <div class="token-value"><?= $h($entry['token']) ?></div>
          </details>
        <?php endif; ?>

        <div class="inline-form">
          <form class="inline-form" method="post" action="/ui/settings/token">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="kind" value="<?= $h($kind) ?>">
            <input type="hidden" name="action" value="rotate">
            <button type="submit"><?= '' === $entry['token'] ? 'Generate token' : 'Rotate token' ?></button>
          </form>
          <?php if ('' !== $entry['token']): ?>
            <form class="inline-form" method="post" action="/ui/settings/token"
                  data-confirm="Clear your <?= $h($label['name']) ?> token? Whatever is using it stops working immediately, and it cannot be shown again.">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="kind" value="<?= $h($kind) ?>">
              <input type="hidden" name="action" value="clear">
              <button type="submit" class="danger">Clear</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <p class="trash-back"><a href="/ui/backup" download>Download all my memories (zip)</a></p>
  </section>

</main>

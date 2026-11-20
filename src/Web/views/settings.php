<?php
/**
 * Settings: the account you are signed in as, and the two things only you can
 * change about it — the password (which is also the OAuth consent password, so
 * changing it signs out every other session and MCP connection) and the API
 * tokens of the read-only publishing APIs.
 *
 * `$te` escapes what it renders; `$t` is used for the two or three notes whose
 * catalogue value carries its own <code> markup.
 *
 * @var string $csrf
 * @var \MemoryDown\Users\User $user
 * @var array<string, array{token: string, enabled: bool}> $tokens
 * @var array<string, bool> $apiEnabled
 * @var array{account: string, all: string} $mcp
 * @var string $notices
 * @var string $errors
 * @var callable $te
 * @var callable $t
 */
use MemoryDown\Users\UserStore;
use MemoryDown\Web\WebApp;

$h = static fn (string $v): string => WebApp::h($v);
$labels = [
    UserStore::TOKEN_WRITINGS => [
        'name' => $te('settings.writings_name'),
        'name_raw' => $t('settings.writings_name'),
        'what' => $te('settings.writings_what'),
    ],
    UserStore::TOKEN_WORKFLOWS => [
        'name' => $te('settings.workflows_name'),
        'name_raw' => $t('settings.workflows_name'),
        'what' => $te('settings.workflows_what'),
    ],
];
?>
<main class="page-stack">

  <section class="page-card">
    <h1><?= $te('settings.title') ?></h1>
    <p class="muted">
      <?= $te('settings.signed_in_as') ?> <strong><?= $h($user->id) ?></strong>
      <span class="pill<?= $user->isAdmin() ? ' pill-accent' : '' ?>"><?= $h($user->role) ?></span>
    </p>

    <?php if ('' !== $notices): ?>
      <p class="alert ok" role="status"><?= $h($notices) ?></p>
    <?php endif; ?>
    <?php if ('' !== $errors): ?>
      <p class="alert" role="alert"><?= $h($errors) ?></p>
    <?php endif; ?>

    <p class="trash-back">
      <a href="/ui"><?= $te('settings.back') ?></a>
      <?php if ($user->isAdmin()): ?> &middot; <a href="/ui/users"><?= $te('settings.users') ?></a><?php endif; ?>
    </p>
  </section>

  <section class="page-card">
    <h2><?= $te('settings.password_head') ?></h2>
    <p class="muted"><?= $te('settings.password_intro') ?></p>
    <form method="post" action="/ui/settings/password" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <label for="current"><?= $te('field.current_password') ?></label>
      <input type="password" id="current" name="current" required autocomplete="current-password">
      <label for="password"><?= $te('field.new_password') ?></label>
      <input type="password" id="password" name="password" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>">
      <label for="confirm"><?= $te('field.confirm_new') ?></label>
      <input type="password" id="confirm" name="confirm" required autocomplete="new-password" minlength="<?= UserStore::MIN_PASSWORD_LENGTH ?>">
      <button type="submit" class="primary block"><?= $te('settings.change_password') ?></button>
    </form>
  </section>

  <section class="page-card">
    <h2><?= $te('settings.mcp_head') ?></h2>
    <p class="muted"><?= $te('settings.mcp_intro') ?></p>

    <div class="token-block">
      <div class="account-name"><?= $te('settings.mcp_account') ?></div>
      <div class="token-value"><?= $h($mcp['account']) ?></div>
      <p class="muted"><?= $te('settings.mcp_account_note') ?></p>
    </div>

    <?php if ('' !== $mcp['all']): ?>
      <div class="token-block">
        <div class="account-name"><?= $te('settings.mcp_all') ?></div>
        <div class="token-value"><?= $h($mcp['all']) ?></div>
        <p class="muted"><?= $t('settings.mcp_all_note') ?></p>
      </div>
    <?php endif; ?>
  </section>

  <section class="page-card">
    <h2><?= $te('settings.api_head') ?></h2>
    <p class="muted"><?= $te('settings.api_intro') ?></p>

    <?php foreach ($labels as $kind => $label): ?>
      <?php $entry = $tokens[$kind] ?? ['token' => '', 'enabled' => false]; ?>
      <div class="token-block">
        <div class="account-name"><?= $label['name'] ?></div>
        <p class="muted">
          <?= $label['what'] ?>.
          <?php if (!$entry['enabled']): ?>
            <strong><?= $t('settings.api_disabled') ?></strong>
          <?php endif; ?>
        </p>

        <?php if ('' === $entry['token']): ?>
          <p class="muted"><?= $te('settings.no_token') ?></p>
        <?php else: ?>
          <details class="account-more">
            <summary><?= $te('settings.show_token') ?></summary>
            <div class="token-value"><?= $h($entry['token']) ?></div>
          </details>
        <?php endif; ?>

        <div class="inline-form">
          <form class="inline-form" method="post" action="/ui/settings/token">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="kind" value="<?= $h($kind) ?>">
            <input type="hidden" name="action" value="rotate">
            <button type="submit"><?= '' === $entry['token'] ? $te('settings.generate_token') : $te('settings.rotate_token') ?></button>
          </form>
          <?php if ('' !== $entry['token']): ?>
            <form class="inline-form" method="post" action="/ui/settings/token"
                  data-confirm="<?= $te('settings.clear_confirm', ['name' => $label['name_raw']]) ?>">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="kind" value="<?= $h($kind) ?>">
              <input type="hidden" name="action" value="clear">
              <button type="submit" class="danger"><?= $te('settings.clear') ?></button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <p class="trash-back"><a href="/ui/backup" download><?= $te('settings.backup_link') ?></a></p>
  </section>

</main>

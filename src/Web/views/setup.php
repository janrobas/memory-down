<?php
/**
 * First-run setup: the one place a password is typed in the clear, and the only
 * way the first account comes into existence on a fresh install. An instance
 * upgraded from single-user mode already has one (the migration seeded it from
 * the environment), so it lands on the login page instead.
 *
 * @var string $csrf
 * @var string $error
 * @var bool   $needsToken
 * @var string $username
 * @var string $languageLinks
 * @var callable $te
 */
?>
<main class="auth-card">
  <h1><?= $te('setup.title') ?></h1>
  <p class="muted"><?= $te('setup.intro') ?></p>
  <?php if ('' !== $error): ?>
    <p class="alert" role="alert"><?= \MemoryDown\Web\WebApp::h($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/ui/setup" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= \MemoryDown\Web\WebApp::h($csrf) ?>">
    <?php if ($needsToken): ?>
      <label for="setup_token"><?= $te('setup.token') ?></label>
      <input type="password" id="setup_token" name="setup_token" required>
    <?php endif; ?>
    <label for="username"><?= $te('field.username') ?></label>
    <input type="text" id="username" name="username" value="<?= \MemoryDown\Web\WebApp::h($username) ?>" required autocomplete="username" pattern="[a-z0-9][a-z0-9_-]{2,31}">
    <label for="password"><?= $te('field.password') ?></label>
    <input type="password" id="password" name="password" required autocomplete="new-password">
    <label for="confirm"><?= $te('field.confirm') ?></label>
    <input type="password" id="confirm" name="confirm" required autocomplete="new-password">
    <button type="submit" class="primary block"><?= $te('setup.submit') ?></button>
  </form>
  <?= $languageLinks ?>
</main>

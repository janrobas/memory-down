<?php
/**
 * @var string $csrf
 * @var string $error
 * @var bool   $needsToken
 */
?>
<main class="auth-card">
  <h1>Set up MemoryDown</h1>
  <p class="muted">Choose the password that protects the admin UI. It is stored as a one-way hash.</p>
  <?php if ('' !== $error): ?>
    <p class="alert" role="alert"><?= \MemoryDown\Web\WebApp::h($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/ui/setup" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= \MemoryDown\Web\WebApp::h($csrf) ?>">
    <?php if ($needsToken): ?>
      <label for="setup_token">Setup token</label>
      <input type="password" id="setup_token" name="setup_token" required>
    <?php endif; ?>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="new-password">
    <label for="confirm">Confirm password</label>
    <input type="password" id="confirm" name="confirm" required autocomplete="new-password">
    <button type="submit" class="primary block">Save password</button>
  </form>
</main>

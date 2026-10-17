<?php
/**
 * @var string $csrf
 * @var string $username
 * @var string $error
 */
?>
<main class="auth-card">
  <h1>MemoryDown</h1>
  <p class="muted">Sign in to browse and edit your memories.</p>
  <?php if ('' !== $error): ?>
    <p class="alert" role="alert"><?= \MemoryDown\Web\WebApp::h($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/ui/login" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= \MemoryDown\Web\WebApp::h($csrf) ?>">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= \MemoryDown\Web\WebApp::h($username) ?>" autofocus required autocomplete="username">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
    <button type="submit" class="primary block">Sign in</button>
  </form>
</main>

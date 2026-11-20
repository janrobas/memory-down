<?php
/**
 * @var string $csrf
 * @var string $username
 * @var string $error
 * @var string $languageLinks
 * @var callable $te
 */
?>
<main class="auth-card">
  <h1>MemoryDown</h1>
  <p class="muted"><?= $te('login.tagline') ?></p>
  <?php if ('' !== $error): ?>
    <p class="alert" role="alert"><?= \MemoryDown\Web\WebApp::h($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/ui/login" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= \MemoryDown\Web\WebApp::h($csrf) ?>">
    <label for="username"><?= $te('field.username') ?></label>
    <input type="text" id="username" name="username" value="<?= \MemoryDown\Web\WebApp::h($username) ?>" autofocus required autocomplete="username">
    <label for="password"><?= $te('field.password') ?></label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
    <button type="submit" class="primary block"><?= $te('login.submit') ?></button>
  </form>
  <?php /* The chooser sits on the sign-in page too: somebody who cannot read the
           current language has to be able to change it before signing in. */ ?>
  <?= $languageLinks ?>
  <p class="auth-foot"><a href="/about"><?= $te('login.about') ?></a></p>
</main>

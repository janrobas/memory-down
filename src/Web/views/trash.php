<?php
/**
 * The trash: entries deleted from the admin UI or by the MCP forget_memory
 * tool, restorable until they are purged. Server-rendered so it works without
 * JavaScript, like the login and setup pages.
 *
 * @var string $csrf
 * @var list<array{name: string, category: string, id: string, deleted_at: string, bytes: int}> $entries
 * @var int    $bytes
 * @var string $notice
 * @var string $error
 */
$h = static fn (string $v): string => \MemoryDown\Web\WebApp::h($v);
$size = static function (int $bytes): string {
    return $bytes < 1024 ? $bytes . ' B' : round($bytes / 1024, 1) . ' KB';
};
?>
<main class="trash-card">
  <h1>Trash</h1>
  <p class="muted">
    Deleted memories stay here until you purge them.
    <?php if ([] !== $entries): ?>
      <?= count($entries) ?> entr<?= 1 === count($entries) ? 'y' : 'ies' ?>, <?= $h($size($bytes)) ?>.
    <?php endif; ?>
  </p>

  <?php if ('' !== $notice): ?>
    <p class="alert ok" role="status"><?= $h($notice) ?></p>
  <?php endif; ?>
  <?php if ('' !== $error): ?>
    <p class="alert" role="alert"><?= $h($error) ?></p>
  <?php endif; ?>

  <?php if ([] === $entries): ?>
    <p class="muted">Nothing has been deleted since the trash was last emptied.</p>
  <?php else: ?>
    <ul class="trash-list">
      <?php foreach ($entries as $entry): ?>
        <li>
          <div class="trash-meta">
            <span class="trash-title"><?= $h($entry['id']) ?></span>
            <span class="muted">
              <?= $h($entry['category']) ?> &middot; deleted <?= $h($entry['deleted_at']) ?> &middot; <?= $h($size($entry['bytes'])) ?>
            </span>
          </div>
          <div class="trash-actions">
            <form method="post" action="/ui/trash/restore">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="name" value="<?= $h($entry['name']) ?>">
              <button type="submit">Restore</button>
            </form>
            <form method="post" action="/ui/trash/purge"
                  data-confirm="Delete <?= $h($entry['id']) ?> forever? This cannot be undone.">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="name" value="<?= $h($entry['name']) ?>">
              <button type="submit" class="danger">Delete forever</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <form method="post" action="/ui/trash/empty" class="trash-empty"
          data-confirm="Empty the trash? All <?= count($entries) ?> entr<?= 1 === count($entries) ? 'y' : 'ies' ?> are deleted forever, and this cannot be undone.">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <button type="submit" class="danger block">Empty the trash (delete all <?= count($entries) ?> forever)</button>
    </form>
  <?php endif; ?>

  <p class="trash-back"><a href="/ui">&larr; Back to memories</a></p>
</main>

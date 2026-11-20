<?php
/**
 * The trash: entries deleted from the admin UI or by the MCP forget_memory
 * tool, restorable until they are purged. Server-rendered so it works without
 * JavaScript, like the login and setup pages.
 *
 * The counts are the one place the UI needs real plurals: Slovenian has four
 * forms, English and Italian two — so `$te('trash.summary', ['n' => …])` picks
 * the form, and the sentence itself lives in the catalogue.
 *
 * @var string $csrf
 * @var list<array{name: string, category: string, id: string, deleted_at: string, bytes: int}> $entries
 * @var int    $bytes
 * @var string $notice
 * @var string $error
 * @var callable $te
 * @var callable $t
 * @var callable $categoryLabel  slug => display name
 */
$h = static fn (string $v): string => \MemoryDown\Web\WebApp::h($v);
$size = static function (int $bytes): string {
    return $bytes < 1024 ? $bytes . ' B' : round($bytes / 1024, 1) . ' KB';
};
$count = count($entries);
?>
<main class="trash-card">
  <h1><?= $te('trash.title') ?></h1>
  <p class="muted">
    <?= $te('trash.intro') ?>
    <?php if ([] !== $entries): ?>
      <?= $te('trash.summary', ['n' => $count, 'size' => $size($bytes)]) ?>
    <?php endif; ?>
  </p>

  <?php if ('' !== $notice): ?>
    <p class="alert ok" role="status"><?= $h($notice) ?></p>
  <?php endif; ?>
  <?php if ('' !== $error): ?>
    <p class="alert" role="alert"><?= $h($error) ?></p>
  <?php endif; ?>

  <?php if ([] === $entries): ?>
    <p class="muted"><?= $te('trash.empty_list') ?></p>
  <?php else: ?>
    <ul class="trash-list">
      <?php foreach ($entries as $entry): ?>
        <li>
          <div class="trash-meta">
            <span class="trash-title"><?= $h($entry['id']) ?></span>
            <span class="muted">
              <?= $te('trash.meta', [
                  'category' => $categoryLabel((string) $entry['category']),
                  'date' => $entry['deleted_at'],
                  'size' => $size($entry['bytes']),
              ]) ?>
            </span>
          </div>
          <div class="trash-actions">
            <form method="post" action="/ui/trash/restore">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="name" value="<?= $h($entry['name']) ?>">
              <button type="submit"><?= $te('trash.restore') ?></button>
            </form>
            <form method="post" action="/ui/trash/purge"
                  data-confirm="<?= $te('trash.purge_confirm', ['id' => $entry['id']]) ?>">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="name" value="<?= $h($entry['name']) ?>">
              <button type="submit" class="danger"><?= $te('trash.purge') ?></button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <form method="post" action="/ui/trash/empty" class="trash-empty"
          data-confirm="<?= $te('trash.empty_confirm', ['n' => $count]) ?>">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <button type="submit" class="danger block"><?= $te('trash.empty_button', ['n' => $count]) ?></button>
    </form>
  <?php endif; ?>

  <p class="trash-back"><a href="/ui"><?= $te('trash.back') ?></a></p>
</main>

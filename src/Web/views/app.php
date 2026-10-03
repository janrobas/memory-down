<?php
/**
 * The two-pane admin UI: browse/search on the left, editor on the right.
 *
 * Server-rendered so it works without JS; app.js progressively enhances it
 * with live search, in-place load/save and a Markdown preview.
 *
 * @var string                          $csrf
 * @var list<string>                    $categories
 * @var list<array<string, mixed>>      $tree
 * @var array<string, mixed>|null       $selected
 * @var array<string, mixed>            $engine
 */
use MemoryDown\Web\WebApp;

$selected = $selected ?? null;
$selCategory = $selected['category'] ?? 'facts';
$selId = $selected['id'] ?? '';
$selTitle = $selected['title'] ?? '';
$selTags = $selected ? implode(', ', $selected['tags'] ?? []) : '';
$selBody = $selected['body'] ?? '';
if (!in_array($selCategory, $categories, true)) {
    $categories[] = $selCategory;
    sort($categories);
}
?>
<div class="app-shell"
     data-csrf="<?= WebApp::h($csrf) ?>"
     data-engine="<?= WebApp::h($engine['engine'] ?? 'direct') ?>">

  <aside class="sidebar">
    <div class="sidebar-top">
      <div class="brand">MemoryDown</div>
      <input id="search" type="search" class="search" placeholder="Search memories…" autocomplete="off" aria-label="Search memories">
      <button id="new-memory" type="button" class="primary block">+ New memory</button>
    </div>
    <nav id="list" class="list" aria-label="Memories">
      <?php foreach ($tree as $group): ?>
        <section class="cat" data-category="<?= WebApp::h($group['name']) ?>">
          <div class="cat-name">
            <span><?= WebApp::h($group['name']) ?></span>
            <span class="count"><?= count($group['memories']) ?></span>
          </div>
          <ul>
            <?php foreach ($group['memories'] as $mem): ?>
              <li>
                <a class="mem<?= ($mem['id'] === $selId && $group['name'] === $selCategory) ? ' active' : '' ?>"
                   href="/ui?category=<?= rawurlencode((string) $group['name']) ?>&amp;id=<?= rawurlencode((string) $mem['id']) ?>"
                   data-category="<?= WebApp::h($group['name']) ?>"
                   data-id="<?= WebApp::h($mem['id']) ?>"><?= WebApp::h($mem['title'] !== '' ? $mem['title'] : $mem['id']) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
      <p class="empty muted" id="list-empty" <?= [] !== $tree ? 'hidden' : '' ?>>No memories yet.</p>
    </nav>
  </aside>

  <main class="editor">
    <form id="editor-form" method="post" action="/ui/api/memory">
      <input type="hidden" name="csrf" value="<?= WebApp::h($csrf) ?>">
      <input type="hidden" name="id" id="f-id" value="<?= WebApp::h($selId) ?>">

      <div class="editor-head">
        <div class="editor-topbar">
          <span class="breadcrumb" id="breadcrumb"><?= '' !== $selId ? WebApp::h($selCategory) . '<span class="sep">/</span>' . WebApp::h($selId) : 'New memory' ?></span>
          <span class="spacer"></span>

          <span class="index-status muted" id="index-status"
                data-engine="<?= WebApp::h($engine['engine'] ?? 'direct') ?>"
                data-indexed="<?= (int) ($engine['indexed'] ?? 0) ?>"
                title="Search index"><?= WebApp::h(($engine['engine'] ?? 'direct') === 'sqlite-fts5' ? 'Indexed ' . (int) ($engine['indexed'] ?? 0) : 'Direct search') ?></span>
          <button type="button" id="reindex" title="Rebuild the search index">⟳ Reindex</button>

          <div class="theme-picker">
            <button type="button" id="theme-toggle" aria-haspopup="true" aria-expanded="false" title="Theme">Theme ▾</button>
            <div class="theme-menu" id="theme-menu" hidden role="menu">
              <button type="button" role="menuitem" data-theme-value="light"><span class="swatch light"></span>White</button>
              <button type="button" role="menuitem" data-theme-value="dark"><span class="swatch dark"></span>Dark</button>
              <button type="button" role="menuitem" data-theme-value="retro"><span class="swatch retro"></span>Retro orange</button>
              <button type="button" role="menuitem" data-theme-value="green"><span class="swatch green"></span>Green</button>
              <button type="button" role="menuitem" data-theme-value="blue"><span class="swatch blue"></span>Blue</button>
            </div>
          </div>

          <button type="button" id="logout" title="Sign out">Logout</button>
        </div>

        <div class="title-row">
          <input id="f-title" name="title" class="title-input" placeholder="Title" value="<?= WebApp::h($selTitle) ?>">
          <button type="button" class="danger" id="delete" title="Delete this memory"<?= '' === $selId ? ' disabled hidden' : '' ?>>Delete</button>
        </div>

        <div class="new-cats" id="new-cats"<?= '' !== $selId ? ' hidden' : '' ?>>
          <span class="new-label">Category</span>
          <?php foreach ($categories as $category): ?>
            <button type="button" class="chip<?= $category === $selCategory ? ' active' : '' ?>" data-cat="<?= WebApp::h($category) ?>"><?= WebApp::h($category) ?></button>
          <?php endforeach; ?>
          <input type="hidden" name="category" id="f-category" value="<?= WebApp::h($selCategory) ?>">
        </div>

        <div class="editor-meta">
          <label class="field" id="move-field"<?= '' === $selId ? ' hidden' : '' ?>>
            <span class="field-label">Move to</span>
            <select id="move-category">
              <?php foreach ($categories as $category): ?>
                <option value="<?= WebApp::h($category) ?>"<?= $category === $selCategory ? ' selected' : '' ?>><?= WebApp::h($category) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field grow">
            <span class="field-label">Tags</span>
            <input id="f-tags" name="tags" placeholder="comma, separated" value="<?= WebApp::h($selTags) ?>">
          </label>
        </div>
      </div>

      <div class="editor-body">
        <div class="tabs">
          <button type="button" class="tab active" data-tab="write">Write</button>
          <button type="button" class="tab" data-tab="preview">Preview</button>
          <span class="status" id="status"></span>
        </div>

        <textarea id="f-content" name="content" class="content" spellcheck="false" placeholder="Write Markdown…"><?= WebApp::h($selBody) ?></textarea>
        <div id="preview" class="preview markdown" hidden></div>
      </div>
    </form>
  </main>
</div>

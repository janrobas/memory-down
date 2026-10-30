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
 * @var list<string>|null               $definition  what a runner would refuse, for an activated workflow
 * @var array<string, mixed>            $engine
 * @var int                             $trashCount
 * @var string                          $appVersion
 */
use MemoryDown\Memory\WorkflowDefinition;
use MemoryDown\Web\WebApp;

$selected = $selected ?? null;
$definition = $definition ?? null;
$selCategory = $selected['category'] ?? 'facts';
$selId = $selected['id'] ?? '';
$selTitle = $selected['title'] ?? '';
$selTags = $selected ? implode(', ', $selected['tags'] ?? []) : '';
$selArchived = (bool) ($selected['archived'] ?? false);
$selPublic = (bool) ($selected['public'] ?? false);
$selBody = $selected['body'] ?? '';
if (!in_array($selCategory, $categories, true)) {
    $categories[] = $selCategory;
    sort($categories);
}

// The definition verdict: null means the entry is not an activated workflow and
// the verdict would be meaningless, so the row is not shown at all.
$definitionState = null === $definition ? 'is-hidden' : ([] === $definition ? 'is-ok' : 'is-problem');
$definitionHead = null === $definition
    ? ''
    : ([] === $definition ? 'A runner may execute this definition' : 'A runner would refuse this definition');
?>
<div class="app-shell<?= '' === $selId ? ' is-empty' : '' ?>"
     data-csrf="<?= WebApp::h($csrf) ?>"
     data-engine="<?= WebApp::h($engine['engine'] ?? 'direct') ?>"
     data-workflow-category="<?= WebApp::h(WorkflowDefinition::CATEGORY) ?>"
     data-workflow-template="<?= WebApp::h(WorkflowDefinition::TEMPLATE_BODY) ?>">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
      <div class="brand-row">
        <a class="brand" href="/ui" id="brand-home">MemoryDown</a><span class="brand-version">v<?= WebApp::h($appVersion) ?></span>
        <button id="new-memory" type="button" class="icon-btn brand-new" title="New memory" aria-label="New memory">+</button>
      </div>
      <div class="search-wrap">
        <input id="search" type="search" class="search" placeholder="Search memories… (tag:foo)" autocomplete="off" aria-label="Search memories">
        <button type="button" id="search-clear" class="search-clear" hidden aria-label="Clear search" title="Clear search">×</button>
      </div>
      <div class="filter" id="filter" role="group" aria-label="Show memories">
        <button type="button" class="filter-btn active" data-filter="active" aria-pressed="true">Active</button>
        <button type="button" class="filter-btn" data-filter="all" aria-pressed="false">All</button>
        <button type="button" class="filter-btn" data-filter="archived" aria-pressed="false">Archived</button>
      </div>
      <div class="categories-head" id="categories-head">
        <span class="categories-label">Categories</span>
        <button type="button" id="collapse-toggle" class="icon-btn" title="Collapse all categories" aria-label="Collapse all categories">⊟</button>
      </div>
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
              <?php $memKind = !empty($mem['archived']) ? 'archived' : (!empty($mem['public']) ? 'public' : (!empty($mem['new']) ? 'new' : '')); ?>
              <li>
                <a class="mem<?= ($mem['id'] === $selId && $group['name'] === $selCategory) ? ' active' : '' ?><?= !empty($mem['archived']) ? ' archived' : '' ?><?= 'new' === $memKind ? ' new' : '' ?>"
                   href="/ui?category=<?= rawurlencode((string) $group['name']) ?>&amp;id=<?= rawurlencode((string) $mem['id']) ?>"
                   data-category="<?= WebApp::h($group['name']) ?>"
                   data-id="<?= WebApp::h($mem['id']) ?>"><span class="mem-title"><?= WebApp::h($mem['title'] !== '' ? $mem['title'] : $mem['id']) ?></span><?php if ('' !== $memKind): ?><span class="badge-<?= $memKind ?>"><?= $memKind ?></span><?php endif; ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
      <p class="empty muted" id="list-empty" <?= [] !== $tree ? 'hidden' : '' ?>>No memories yet.</p>
    </nav>
    <div class="sidebar-resizer" id="sidebar-resizer" role="separator" aria-orientation="vertical" aria-label="Resize sidebar" tabindex="0"></div>
  </aside>

  <main class="editor">
    <form id="editor-form" method="post" action="/ui/api/memory">
      <input type="hidden" name="csrf" value="<?= WebApp::h($csrf) ?>">
      <input type="hidden" name="id" id="f-id" value="<?= WebApp::h($selId) ?>">

      <div class="editor-head">
        <div class="editor-topbar">
          <button type="button" id="open-memories" class="icon-btn" aria-controls="sidebar" aria-expanded="false" title="Memories">☰</button>
          <span class="breadcrumb" id="breadcrumb"><?= '' !== $selId ? WebApp::h($selCategory) . '<span class="sep">/</span>' . WebApp::h($selId) : 'New memory' ?></span>
          <span class="spacer"></span>

          <button type="button" id="open-menu" class="icon-btn" aria-controls="command-drawer" aria-expanded="false" title="Menu">⋯</button>
        </div>

        <div class="title-row">
          <input id="f-title" name="title" class="title-input" placeholder="Title" value="<?= WebApp::h($selTitle) ?>">
          <button type="button" class="danger" id="delete" title="Move this memory to the trash"<?= '' === $selId ? ' disabled hidden' : '' ?>>Delete</button>
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
          <div class="field grow tag-suggest-wrap">
            <span class="field-label">Suggested</span>
            <div class="tag-suggest" id="tag-suggest" role="group" aria-label="Suggested tags">
              <?php foreach (\MemoryDown\Memory\RecommendedTags::LIST as $tag): ?>
                <button type="button" class="tag-chip" data-tag="<?= WebApp::h($tag) ?>"><?= WebApp::h($tag) ?></button>
              <?php endforeach; ?>
              <?php /* The reserved tag is offered exactly where it is legal — on an
                       entry in `workflows` — and the admin API refuses it anywhere
                       else. app.js keeps it in step when the category changes. */ ?>
              <button type="button" class="tag-chip tag-chip-reserved"
                      data-tag="<?= WebApp::h(WorkflowDefinition::TAG) ?>"
                      data-only-category="<?= WebApp::h(WorkflowDefinition::CATEGORY) ?>"
                      title="Reserved tag: marks this entry as a workflow definition a runner may execute, so agents can only read it. Only allowed in the workflows category."
                      <?= WorkflowDefinition::CATEGORY === $selCategory ? '' : 'hidden' ?>><?= WebApp::h(WorkflowDefinition::TAG) ?></button>
            </div>
          </div>
          <div class="field check">
            <span class="field-label">Archived</span>
            <label class="check-label">
              <input type="checkbox" id="f-archived" name="archived" value="1"<?= $selArchived ? ' checked' : '' ?>>
              <span>Archived</span>
            </label>
          </div>
          <div class="field check" id="public-field"<?= 'writings' !== $selCategory ? ' hidden' : '' ?>>
            <span class="field-label">Public</span>
            <label class="check-label">
              <input type="checkbox" id="f-public" name="public" value="1"<?= $selPublic ? ' checked' : '' ?>>
              <span>Public</span>
            </label>
          </div>
        </div>
      </div>

      <?php /* The verdict of a runner's pre-flight checks, shown only for an
               activated definition: that is when the question has an answer.
               app.js keeps it current after every save (see renderDefinition). */ ?>
      <div class="definition <?= $definitionState ?>" id="definition"<?= null === $definition ? ' hidden' : '' ?>>
        <span class="definition-head" id="definition-head"><?= WebApp::h($definitionHead) ?></span>
        <ul class="definition-list" id="definition-list">
          <?php foreach ($definition ?? [] as $problem): ?>
            <li><?= WebApp::h($problem) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="editor-body">
        <div class="tabs" role="tablist" aria-label="Editor view">
          <button type="button" class="tab" data-tab="preview" role="tab" aria-selected="false" aria-controls="preview" id="tab-preview">Read</button>
          <button type="button" class="tab active" data-tab="write" role="tab" aria-selected="true" aria-controls="f-content" id="tab-write">Write</button>
          <span class="status" id="status"></span>
        </div>

        <textarea id="f-content" name="content" class="content" spellcheck="false" placeholder="Write Markdown…" role="tabpanel" aria-labelledby="tab-write"><?= WebApp::h($selBody) ?></textarea>
        <div id="preview" class="preview markdown" hidden role="tabpanel" aria-labelledby="tab-preview"></div>
      </div>
    </form>

    <div class="editor-empty" id="editor-empty"<?= '' !== $selId ? ' hidden' : '' ?>>
      <p class="muted">Select a memory from the list, or create a new one.</p>
      <button type="button" class="primary" id="editor-empty-new">+ New memory</button>
    </div>
  </main>

  <aside class="command-drawer" id="command-drawer" aria-label="Menu">
    <div class="command-head">
      <strong>Menu</strong>
      <button type="button" id="close-menu" class="icon-btn" title="Close">✕</button>
    </div>

    <div class="command-group">
      <span class="command-group-label">Memory</span>
      <button type="button" id="reindex" title="Rebuild the search index" aria-live="polite">⟳ Reindex</button>

      <!-- Plain links: no JavaScript, and the backup is a normal file download. -->
      <a class="drawer-link" href="/ui/backup" download title="Download every memory as a zip">⭳ Backup</a>
      <!-- The count sits in a fixed-width slot so its label can change without
           nudging the neighbouring buttons; app.js keeps it current after a delete.
           Spacing comes from CSS: an inline-block strips a leading space. -->
      <a class="drawer-link" href="/ui/trash" title="Deleted memories, restorable">🗑 Trash<span class="trash-badge" id="trash-count"><?= (int) ($trashCount ?? 0) > 0 ? '(' . (int) $trashCount . ')' : '' ?></span></a>
    </div>

    <div class="command-group">
      <span class="command-group-label">Account</span>
      <a class="drawer-link" href="/ui/settings" title="Your password and API tokens">⚙ Settings</a>
      <?php if (($user ?? null) instanceof \MemoryDown\Users\User && $user->isAdmin()): ?>
        <a class="drawer-link" href="/ui/users" title="Manage accounts">👥 Users</a>
      <?php endif; ?>
      <button type="button" id="logout" title="Sign out">Logout</button>
    </div>

    <div class="command-group">
      <span class="command-group-label">Theme</span>
      <div class="theme-picker">
        <button type="button" id="theme-toggle" aria-haspopup="true" aria-expanded="false" title="Theme">Theme ▾</button>
        <div class="theme-menu" id="theme-menu" hidden role="menu">
          <button type="button" role="menuitem" data-theme-value="light"><span class="swatch light"></span>White</button>
          <button type="button" role="menuitem" data-theme-value="dark"><span class="swatch dark"></span>Dark</button>
          <button type="button" role="menuitem" data-theme-value="retro"><span class="swatch retro"></span>Retro orange</button>
          <button type="button" role="menuitem" data-theme-value="blue"><span class="swatch blue"></span>Blue</button>
          <div class="theme-group" role="presentation">Spring</div>
          <button type="button" role="menuitem" data-theme-value="spring-light"><span class="swatch spring-light"></span>Spring — light</button>
          <button type="button" role="menuitem" data-theme-value="spring-dark"><span class="swatch spring-dark"></span>Spring — dark</button>
          <div class="theme-group" role="presentation">Summer</div>
          <button type="button" role="menuitem" data-theme-value="summer-light"><span class="swatch summer-light"></span>Summer — light</button>
          <button type="button" role="menuitem" data-theme-value="summer-dark"><span class="swatch summer-dark"></span>Summer — dark</button>
          <div class="theme-group" role="presentation">Autumn</div>
          <button type="button" role="menuitem" data-theme-value="autumn-light"><span class="swatch autumn-light"></span>Autumn — light</button>
          <button type="button" role="menuitem" data-theme-value="autumn-dark"><span class="swatch autumn-dark"></span>Autumn — dark</button>
          <div class="theme-group" role="presentation">Winter</div>
          <button type="button" role="menuitem" data-theme-value="winter-light"><span class="swatch winter-light"></span>Winter — light</button>
          <button type="button" role="menuitem" data-theme-value="winter-dark"><span class="swatch winter-dark"></span>Winter — dark</button>
        </div>
      </div>
    </div>
  </aside>

  <div class="backdrop" id="backdrop" hidden></div>
</div>

<?php
/**
 * The two-pane admin UI: browse/search on the left, editor on the right.
 *
 * Server-rendered so it works without JS; app.js progressively enhances it
 * with live search, in-place load/save and a Markdown preview.
 *
 * Every visible string comes from the catalogue through `$te()` (escaped) —
 * a literal here would be a string that never changes language. `$t()` is used
 * only where the catalogue value carries markup of its own.
 *
 * @var string                          $csrf
 * @var list<string>                    $categories
 * @var list<array<string, mixed>>      $tree
 * @var array<string, mixed>|null       $selected
 * @var list<string>|null               $definition  what a runner would refuse, for an activated workflow
 * @var array<string, mixed>            $engine
 * @var int                             $trashCount
 * @var string                          $appVersion
 * @var string                          $languageLinks pre-rendered chooser
 * @var string                          $languageBlurb
 * @var callable                        $te
 * @var callable                        $categoryLabel  slug => display name
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
$selPrivate = (bool) ($selected['private'] ?? false);
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
    : ([] === $definition ? $te('definition.ok') : $te('definition.refuse'));

// slug => display name, for the pieces app.js draws itself (group headers, the
// move picker, the "moved to" status). The slugs stay the values everywhere; this
// map only ever supplies text.
$categoryLabels = [];
foreach ($categories as $category) {
    $categoryLabels[$category] = $categoryLabel($category);
}
?>
<div class="app-shell<?= '' === $selId ? ' is-empty' : '' ?>"
     data-csrf="<?= WebApp::h($csrf) ?>"
     data-engine="<?= WebApp::h($engine['engine'] ?? 'direct') ?>"
     data-category-labels="<?= WebApp::h((string) json_encode($categoryLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
     data-workflow-category="<?= WebApp::h(WorkflowDefinition::CATEGORY) ?>"
     data-workflow-template="<?= WebApp::h(WorkflowDefinition::TEMPLATE_BODY) ?>">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
      <div class="brand-row">
        <a class="brand" href="/ui" id="brand-home">MemoryDown</a><span class="brand-version">v<?= WebApp::h($appVersion) ?></span>
        <button id="new-memory" type="button" class="icon-btn brand-new" title="<?= $te('app.new_memory') ?>" aria-label="<?= $te('app.new_memory') ?>">+</button>
      </div>
      <div class="search-wrap">
        <input id="search" type="search" class="search" placeholder="<?= $te('app.search_placeholder') ?>" autocomplete="off" aria-label="<?= $te('app.search_aria') ?>">
        <button type="button" id="search-clear" class="search-clear" hidden aria-label="<?= $te('app.clear_search') ?>" title="<?= $te('app.clear_search') ?>">×</button>
      </div>
      <div class="filter" id="filter" role="group" aria-label="<?= $te('app.filter_group_aria') ?>">
        <button type="button" class="filter-btn active" data-filter="active" aria-pressed="true"><?= $te('app.filter_active') ?></button>
        <button type="button" class="filter-btn" data-filter="all" aria-pressed="false"><?= $te('app.filter_all') ?></button>
        <button type="button" class="filter-btn" data-filter="archived" aria-pressed="false"><?= $te('app.filter_archived') ?></button>
      </div>
      <div class="categories-head" id="categories-head">
        <span class="categories-label"><?= $te('app.categories') ?></span>
        <button type="button" id="collapse-toggle" class="icon-btn" title="<?= $te('app.collapse_all') ?>" aria-label="<?= $te('app.collapse_all') ?>">⊟</button>
      </div>
    </div>
    <nav id="list" class="list" aria-label="<?= $te('app.memories_aria') ?>">
      <?php foreach ($tree as $group): ?>
        <section class="cat" data-category="<?= WebApp::h($group['name']) ?>">
          <div class="cat-name">
            <span><?= WebApp::h($categoryLabel((string) $group['name'])) ?></span>
            <span class="count"><?= count($group['memories']) ?></span>
          </div>
          <ul>
            <?php foreach ($group['memories'] as $mem): ?>
              <?php $memKind = !empty($mem['private']) ? 'private' : (!empty($mem['archived']) ? 'archived' : (!empty($mem['public']) ? 'public' : (!empty($mem['new']) ? 'new' : ''))); ?>
              <li>
                <a class="mem<?= ($mem['id'] === $selId && $group['name'] === $selCategory) ? ' active' : '' ?><?= !empty($mem['archived']) ? ' archived' : '' ?><?= !empty($mem['private']) ? ' private-entry' : '' ?><?= 'new' === $memKind ? ' new' : '' ?>"
                   href="/ui?category=<?= rawurlencode((string) $group['name']) ?>&amp;id=<?= rawurlencode((string) $mem['id']) ?>"
                   data-category="<?= WebApp::h($group['name']) ?>"
                   data-id="<?= WebApp::h($mem['id']) ?>"><span class="mem-title"><?= WebApp::h($mem['title'] !== '' ? $mem['title'] : $mem['id']) ?></span><?php if ('' !== $memKind): ?><span class="badge-<?= $memKind ?>"><?= $te('badge.' . $memKind) ?></span><?php endif; ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
      <p class="empty muted" id="list-empty" <?= [] !== $tree ? 'hidden' : '' ?>><?= $te('app.no_memories') ?></p>
    </nav>
    <div class="sidebar-resizer" id="sidebar-resizer" role="separator" aria-orientation="vertical" aria-label="<?= $te('app.resize_sidebar') ?>" tabindex="0"></div>
  </aside>

  <main class="editor">
    <form id="editor-form" method="post" action="/ui/api/memory">
      <input type="hidden" name="csrf" value="<?= WebApp::h($csrf) ?>">
      <input type="hidden" name="id" id="f-id" value="<?= WebApp::h($selId) ?>">

      <div class="editor-head">
        <div class="editor-topbar">
          <button type="button" id="open-memories" class="icon-btn" aria-controls="sidebar" aria-expanded="false" title="<?= $te('app.memories') ?>">☰</button>
          <span class="breadcrumb" id="breadcrumb"><?= '' !== $selId ? WebApp::h($categoryLabel((string) $selCategory)) . '<span class="sep">/</span>' . WebApp::h($selId) : $te('app.new_memory') ?></span>
          <span class="spacer"></span>

          <?php /* Theme and Logout live in the drawer markup (so the no-JS fallback
                   keeps them in the inline toolbar) and app.js moves them out here
                   on desktop, where the menu should only hold navigation. */ ?>
          <span class="topbar-tools" id="topbar-tools"></span>

          <button type="button" id="open-menu" class="icon-btn" aria-controls="command-drawer" aria-expanded="false" title="<?= $te('app.menu') ?>">⋯</button>
        </div>

        <div class="title-row">
          <input id="f-title" name="title" class="title-input" placeholder="<?= $te('app.title_placeholder') ?>" value="<?= WebApp::h($selTitle) ?>">
          <button type="button" class="danger" id="delete" title="<?= $te('app.delete_title') ?>"<?= '' === $selId ? ' disabled hidden' : '' ?>><?= $te('app.delete') ?></button>
        </div>

        <div class="new-cats" id="new-cats"<?= '' !== $selId ? ' hidden' : '' ?>>
          <span class="new-label"><?= $te('app.category') ?></span>
          <?php foreach ($categories as $category): ?>
            <button type="button" class="chip<?= $category === $selCategory ? ' active' : '' ?>" data-cat="<?= WebApp::h($category) ?>"><?= WebApp::h($categoryLabel($category)) ?></button>
          <?php endforeach; ?>
          <input type="hidden" name="category" id="f-category" value="<?= WebApp::h($selCategory) ?>">
        </div>

        <div class="editor-meta">
          <label class="field" id="move-field"<?= '' === $selId ? ' hidden' : '' ?>>
            <span class="field-label"><?= $te('app.move_to') ?></span>
            <select id="move-category">
              <?php foreach ($categories as $category): ?>
                <option value="<?= WebApp::h($category) ?>"<?= $category === $selCategory ? ' selected' : '' ?>><?= WebApp::h($categoryLabel($category)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field grow">
            <span class="field-label"><?= $te('app.tags') ?></span>
            <input id="f-tags" name="tags" placeholder="<?= $te('app.tags_placeholder') ?>" value="<?= WebApp::h($selTags) ?>">
          </label>
          <div class="field grow tag-suggest-wrap">
            <span class="field-label"><?= $te('app.suggested') ?></span>
            <div class="tag-suggest" id="tag-suggest" role="group" aria-label="<?= $te('app.suggested_aria') ?>">
              <?php foreach (\MemoryDown\Memory\RecommendedTags::LIST as $tag): ?>
                <button type="button" class="tag-chip" data-tag="<?= WebApp::h($tag) ?>"><?= WebApp::h($tag) ?></button>
              <?php endforeach; ?>
              <?php /* The reserved tag is offered exactly where it is legal — on an
                       entry in `workflows` — and the admin API refuses it anywhere
                       else. app.js keeps it in step when the category changes. */ ?>
              <button type="button" class="tag-chip tag-chip-reserved"
                      data-tag="<?= WebApp::h(WorkflowDefinition::TAG) ?>"
                      data-only-category="<?= WebApp::h(WorkflowDefinition::CATEGORY) ?>"
                      title="<?= $te('app.reserved_tag_title') ?>"
                      <?= WorkflowDefinition::CATEGORY === $selCategory ? '' : 'hidden' ?>><?= WebApp::h(WorkflowDefinition::TAG) ?></button>
            </div>
          </div>
          <div class="field check">
            <span class="field-label"><?= $te('app.archived') ?></span>
            <label class="check-label">
              <input type="checkbox" id="f-archived" name="archived" value="1"<?= $selArchived ? ' checked' : '' ?>>
              <span><?= $te('app.archived') ?></span>
            </label>
          </div>
          <div class="field check" id="public-field"<?= 'writings' !== $selCategory ? ' hidden' : '' ?>>
            <span class="field-label"><?= $te('app.public') ?></span>
            <label class="check-label">
              <input type="checkbox" id="f-public" name="public" value="1"<?= $selPublic ? ' checked' : '' ?>>
              <span><?= $te('app.public') ?></span>
            </label>
          </div>
          <?php /* Private is an entry's own state, not a category's; it is offered
                   on every entry, including a writing (a draft kept away from AI).
                   It is human-only: MCP can neither set nor lift it. */ ?>
          <div class="field check" id="private-field">
            <span class="field-label"><?= $te('app.private') ?></span>
            <label class="check-label" title="<?= $te('app.private_title') ?>">
              <input type="checkbox" id="f-private" name="private" value="1"<?= $selPrivate ? ' checked' : '' ?>>
              <span><?= $te('app.private') ?></span>
            </label>
          </div>
        </div>
      </div>

      <?php /* The verdict of a runner's pre-flight checks, shown only for an
               activated definition: that is when the question has an answer.
               app.js keeps it current after every save (see renderDefinition). */ ?>
      <div class="definition <?= $definitionState ?>" id="definition"<?= null === $definition ? ' hidden' : '' ?>>
        <span class="definition-head" id="definition-head"><?= $definitionHead ?></span>
        <ul class="definition-list" id="definition-list">
          <?php foreach ($definition ?? [] as $problem): ?>
            <li><?= WebApp::h($problem) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="editor-body">
        <div class="tabs" role="tablist" aria-label="<?= $te('app.editor_view_aria') ?>">
          <button type="button" class="tab" data-tab="preview" role="tab" aria-selected="false" aria-controls="preview" id="tab-preview"><?= $te('app.read') ?></button>
          <button type="button" class="tab active" data-tab="write" role="tab" aria-selected="true" aria-controls="f-content" id="tab-write"><?= $te('app.write') ?></button>
          <span class="status" id="status"></span>
        </div>

        <textarea id="f-content" name="content" class="content" spellcheck="false" placeholder="<?= $te('app.content_placeholder') ?>" role="tabpanel" aria-labelledby="tab-write"><?= WebApp::h($selBody) ?></textarea>
        <div id="preview" class="preview markdown" hidden role="tabpanel" aria-labelledby="tab-preview"></div>
      </div>
    </form>

    <div class="editor-empty" id="editor-empty"<?= '' !== $selId ? ' hidden' : '' ?>>
      <p class="muted"><?= $te('app.empty_hint') ?></p>
      <button type="button" class="primary" id="editor-empty-new"><?= $te('app.new_memory_button') ?></button>
    </div>
  </main>

  <aside class="command-drawer" id="command-drawer" aria-label="<?= $te('app.menu') ?>">
    <div class="command-head">
      <strong><?= $te('app.menu') ?></strong>
      <button type="button" id="close-menu" class="icon-btn" title="<?= $te('app.close') ?>">✕</button>
    </div>

    <div class="command-group">
      <span class="command-group-label"><?= $te('app.command_memory') ?></span>
      <button type="button" id="reindex" title="<?= $te('app.reindex_title') ?>" aria-live="polite"><?= $te('app.reindex') ?></button>

      <!-- Plain links: no JavaScript, and the backup is a normal file download. -->
      <a class="drawer-link" href="/ui/backup" download title="<?= $te('app.backup_title') ?>"><?= $te('app.backup') ?></a>
      <!-- The count sits in a fixed-width slot so its label can change without
           nudging the neighbouring buttons; app.js keeps it current after a delete.
           Spacing comes from CSS: an inline-block strips a leading space. -->
      <a class="drawer-link" href="/ui/trash" title="<?= $te('app.trash_title') ?>"><?= $te('app.trash') ?><span class="trash-badge" id="trash-count"><?= (int) ($trashCount ?? 0) > 0 ? '(' . (int) $trashCount . ')' : '' ?></span></a>
    </div>

    <div class="command-group" id="group-account">
      <span class="command-group-label"><?= $te('app.command_account') ?></span>
      <a class="drawer-link" href="/ui/settings" title="<?= $te('app.settings_title') ?>"><?= $te('app.settings') ?></a>
      <?php if (($user ?? null) instanceof \MemoryDown\Users\User && $user->isAdmin()): ?>
        <a class="drawer-link" href="/ui/users" title="<?= $te('app.users_title') ?>"><?= $te('app.users') ?></a>
      <?php endif; ?>
      <button type="button" id="logout" title="<?= $te('app.logout_title') ?>"><?= $te('app.logout') ?></button>
    </div>

    <?php /* The language chooser is its own group and stays in the menu on every
             screen size: a preference chosen once, not a control worth a top-bar
             slot. app.js moves Theme and Logout up there and leaves this alone. */ ?>
    <div class="command-group" id="group-language">
      <span class="command-group-label"><?= $te('app.command_language') ?></span>
      <?= $languageLinks ?>
      <p class="command-note"><?= $languageBlurb ?></p>
    </div>

    <div class="command-group" id="group-theme">
      <span class="command-group-label"><?= $te('app.command_theme') ?></span>
      <div class="theme-picker" id="theme-picker">
        <button type="button" id="theme-toggle" aria-haspopup="true" aria-expanded="false" title="<?= $te('app.theme_title') ?>"><?= $te('app.theme_pick') ?></button>
        <div class="theme-menu" id="theme-menu" hidden role="menu">
          <button type="button" role="menuitem" data-theme-value="light"><span class="swatch light"></span><?= $te('theme.light') ?></button>
          <button type="button" role="menuitem" data-theme-value="dark"><span class="swatch dark"></span><?= $te('theme.dark') ?></button>
          <button type="button" role="menuitem" data-theme-value="retro"><span class="swatch retro"></span><?= $te('theme.retro') ?></button>
          <button type="button" role="menuitem" data-theme-value="blue"><span class="swatch blue"></span><?= $te('theme.blue') ?></button>
          <div class="theme-group" role="presentation"><?= $te('theme.spring') ?></div>
          <button type="button" role="menuitem" data-theme-value="spring-light"><span class="swatch spring-light"></span><?= $te('theme.spring_light') ?></button>
          <button type="button" role="menuitem" data-theme-value="spring-dark"><span class="swatch spring-dark"></span><?= $te('theme.spring_dark') ?></button>
          <div class="theme-group" role="presentation"><?= $te('theme.summer') ?></div>
          <button type="button" role="menuitem" data-theme-value="summer-light"><span class="swatch summer-light"></span><?= $te('theme.summer_light') ?></button>
          <button type="button" role="menuitem" data-theme-value="summer-dark"><span class="swatch summer-dark"></span><?= $te('theme.summer_dark') ?></button>
          <div class="theme-group" role="presentation"><?= $te('theme.autumn') ?></div>
          <button type="button" role="menuitem" data-theme-value="autumn-light"><span class="swatch autumn-light"></span><?= $te('theme.autumn_light') ?></button>
          <button type="button" role="menuitem" data-theme-value="autumn-dark"><span class="swatch autumn-dark"></span><?= $te('theme.autumn_dark') ?></button>
          <div class="theme-group" role="presentation"><?= $te('theme.winter') ?></div>
          <button type="button" role="menuitem" data-theme-value="winter-light"><span class="swatch winter-light"></span><?= $te('theme.winter_light') ?></button>
          <button type="button" role="menuitem" data-theme-value="winter-dark"><span class="swatch winter-dark"></span><?= $te('theme.winter_dark') ?></button>
        </div>
      </div>
    </div>
  </aside>

  <div class="backdrop" id="backdrop" hidden></div>
</div>

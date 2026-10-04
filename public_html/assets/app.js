/*
 * MemoryDown admin UI.
 *
 * Progressive enhancement over the server-rendered shell:
 *   - live search (left pane)
 *   - in-place load/edit with autosave (save on switch + 20s idle)
 *   - drag a memory onto a category header to move it
 *   - server-rendered Markdown preview
 *   - theme selection persisted in localStorage
 *
 * No framework, no build step — served from /assets/app.js under the strict
 * admin Content-Security-Policy (script-src 'self').
 */
(function () {
  'use strict';

  var shell = document.querySelector('.app-shell');
  if (!shell) {
    // Login / setup pages have no shell; still apply the saved theme.
    applyStoredTheme();
    return;
  }

  applyStoredTheme();

  var csrf = shell.getAttribute('data-csrf') || '';
  var list = document.getElementById('list');
  var search = document.getElementById('search');
  var searchClear = document.getElementById('search-clear');
  var form = document.getElementById('editor-form');
  var fId = document.getElementById('f-id');
  var fTitle = document.getElementById('f-title');
  var fCategory = document.getElementById('f-category');
  var fTags = document.getElementById('f-tags');
  var fArchived = document.getElementById('f-archived');
  var fContent = document.getElementById('f-content');
  var deleteBtn = document.getElementById('delete');
  var newBtn = document.getElementById('new-memory');
  var statusEl = document.getElementById('status');
  var previewEl = document.getElementById('preview');
  var tabs = document.querySelectorAll('.tab');
  var breadcrumb = document.getElementById('breadcrumb');
  var newCats = document.getElementById('new-cats');
  var moveField = document.getElementById('move-field');
  var moveCategory = document.getElementById('move-category');
  var filterBar = document.getElementById('filter');
  var backdrop = document.getElementById('backdrop');
  var openMemoriesBtn = document.getElementById('open-memories');
  var openMenuBtn = document.getElementById('open-menu');

  var AUTOSAVE_MS = 20000;
  var PREVIEW_MS = 200;

  var FILTER_KEY = 'memorydown.filter';
  var filterMode = loadFilter();

  function loadFilter() {
    var value = 'all';
    try { value = localStorage.getItem(FILTER_KEY) || 'all'; } catch (e) { value = 'all'; }
    return ['all', 'active', 'archived'].indexOf(value) >= 0 ? value : 'all';
  }

  function passesFilter(archived) {
    if (filterMode === 'active') { return !archived; }
    if (filterMode === 'archived') { return !!archived; }
    return true;
  }

  var endpoint = {
    tree: '/ui/api/tree',
    search: '/ui/api/search',
    memory: '/ui/api/memory',
    preview: '/ui/api/preview',
    reindex: '/ui/reindex'
  };

  /* ------------------------------------------------------------------ api */

  function api(path, options) {
    options = options || {};
    var headers = { 'Accept': 'application/json' };
    if (options.body) {
      headers['Content-Type'] = 'application/json';
    }
    if (options.method && options.method !== 'GET') {
      headers['X-CSRF-Token'] = csrf;
    }
    return fetch(path, {
      method: options.method || 'GET',
      headers: headers,
      body: options.body ? JSON.stringify(options.body) : undefined,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { ok: res.ok, status: res.status, data: data };
      });
    });
  }

  /* --------------------------------------------------------------- themes */

  function applyStoredTheme() {
    var theme = '';
    try { theme = localStorage.getItem('memorydown.theme') || ''; } catch (e) { theme = ''; }
    if (theme) {
      document.documentElement.setAttribute('data-theme', theme);
    }
    markActiveTheme();
  }

  function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('memorydown.theme', theme); } catch (e) { /* ignore */ }
    markActiveTheme();
  }

  function markActiveTheme() {
    var current = document.documentElement.getAttribute('data-theme') || 'light';
    Array.prototype.forEach.call(document.querySelectorAll('[data-theme-value]'), function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-theme-value') === current);
    });
  }

  function themeSwitcher() {
    var toggle = document.getElementById('theme-toggle');
    var menu = document.getElementById('theme-menu');
    if (!toggle || !menu) { return; }

    toggle.addEventListener('click', function (event) {
      event.stopPropagation();
      var open = menu.hidden;
      menu.hidden = !open;
      toggle.setAttribute('aria-expanded', String(open));
    });

    menu.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-theme-value]');
      if (!btn) { return; }
      setTheme(btn.getAttribute('data-theme-value'));
      menu.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    });

    document.addEventListener('click', function () {
      if (!menu.hidden) {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ---------------------------------------------------------- index status */

  function renderIndexStatus(status) {
    var el = document.getElementById('index-status');
    if (!el || !status) { return; }
    el.setAttribute('data-engine', status.engine || 'direct');
    el.setAttribute('data-indexed', String(status.indexed || 0));
    el.textContent = status.engine === 'sqlite-fts5'
      ? 'Indexed ' + (status.indexed || 0)
      : 'Direct search';
  }

  function wireLogout() {
    var btn = document.getElementById('logout');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
      if (!window.confirm('Sign out?')) { return; }
      btn.disabled = true;
      api('/ui/logout', { method: 'POST' }).then(function () {
        window.location.href = '/ui/login';
      }).catch(function () {
        window.location.href = '/ui/login';
      });
    });
  }

  function wireReindex() {
    var btn = document.getElementById('reindex');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
      btn.disabled = true;
      setStatus('Reindexing…');
      api(endpoint.reindex, { method: 'POST' }).then(function (res) {
        btn.disabled = false;
        if (res.ok && res.data.status) {
          renderIndexStatus(res.data.status);
          if (res.data.status.engine === 'sqlite-fts5') {
            setStatus('Indexed ' + (res.data.status.indexed || 0) + ' entries.', 'ok');
          } else {
            setStatus('Index unavailable — using direct search.', 'err');
          }
        } else {
          setStatus(res.data.error || 'Reindex failed.', 'err');
        }
      }).catch(function () {
        btn.disabled = false;
        setStatus('Network error.', 'err');
      });
    });
  }

  /* --------------------------------------------------------------- drawers */

  var MOBILE = '(max-width: 820px)';

  function isMobile() { return window.matchMedia(MOBILE).matches; }

  function drawerState() {
    return shell.classList.contains('drawer-left') ? 'left'
      : shell.classList.contains('drawer-right') ? 'right'
      : null;
  }

  function openDrawer(side) {
    shell.classList.remove('drawer-left', 'drawer-right');
    shell.classList.add(side === 'left' ? 'drawer-left' : 'drawer-right');
    backdrop.hidden = false;
    openMemoriesBtn.setAttribute('aria-expanded', String(side === 'left'));
    openMenuBtn.setAttribute('aria-expanded', String(side === 'right'));
    document.body.classList.add('drawer-open');
    if (side === 'left' && search) { search.focus(); }
  }

  function closeDrawer() {
    if (!drawerState()) { return; }
    shell.classList.remove('drawer-left', 'drawer-right');
    backdrop.hidden = true;
    openMemoriesBtn.setAttribute('aria-expanded', 'false');
    openMenuBtn.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('drawer-open');
  }

  function toggleDrawer(side) {
    if (drawerState() === side) { closeDrawer(); } else { openDrawer(side); }
  }

  function wireDrawers() {
    if (!openMemoriesBtn || !openMenuBtn) { return; }

    openMemoriesBtn.addEventListener('click', function () { toggleDrawer('left'); });
    openMenuBtn.addEventListener('click', function () { toggleDrawer('right'); });
    var closeMenu = document.getElementById('close-menu');
    if (closeMenu) { closeMenu.addEventListener('click', closeDrawer); }
    backdrop.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { closeDrawer(); }
    });

    // Opening the drawer must not stay open when the viewport grows to desktop.
    var mq = window.matchMedia(MOBILE);
    var onChange = function () { if (!mq.matches) { closeDrawer(); } };
    if (mq.addEventListener) { mq.addEventListener('change', onChange); }
    else if (mq.addListener) { mq.addListener(onChange); }

    wireEdgeSwipe();
  }

  // Swipe from the left edge to open the memories drawer; swipe across the open
  // drawer to close it (left) or open commands (right→ then left). Simple
  // threshold-based gesture, no library.
  function wireEdgeSwipe() {
    var startX = 0, startY = 0, tracking = false, fromEdge = false;

    var onStart = function (event) {
      if (!isMobile() || !event.touches || event.touches.length !== 1) { return; }
      var t = event.touches[0];
      startX = t.clientX; startY = t.clientY; tracking = true;
      // An edge-swipe begins within 24px of the left border.
      fromEdge = startX <= 24;
    };

    var onEnd = function (event) {
      if (!tracking) { return; }
      tracking = false;
      var t = (event.changedTouches && event.changedTouches[0]) || null;
      if (!t) { return; }
      var dx = t.clientX - startX;
      var dy = t.clientY - startY;
      if (Math.abs(dx) < 45 || Math.abs(dx) < Math.abs(dy) * 1.5) { return; } // horizontal only

      var state = drawerState();
      if (dx > 0 && !state && fromEdge) { openDrawer('left'); }
      else if (dx < 0 && state === 'left') { closeDrawer(); }
      else if (dx > 0 && state === 'right') { closeDrawer(); }
    };

    document.addEventListener('touchstart', onStart, { passive: true });
    document.addEventListener('touchend', onEnd, { passive: true });
  }

  /* ------------------------------------------------------------ utilities */

  function setStatus(message, kind) {
    statusEl.textContent = message || '';
    statusEl.className = 'status' + (kind ? ' ' + kind : '');
  }

  function debounce(fn, ms) {
    var handle;
    var wrapped = function () {
      var args = arguments, self = this;
      clearTimeout(handle);
      handle = setTimeout(function () { fn.apply(self, args); }, ms);
    };
    wrapped.cancel = function () { clearTimeout(handle); };
    return wrapped;
  }

  /* ------------------------------------------------------- editor state */

  // Snapshot of what's currently persisted, used to detect unsaved changes.
  var saved = { id: '', title: '', category: '', tags: '', archived: false, content: '' };
  var saving = false;
  var queuedSave = null;

  function currentPayload() {
    return {
      id: fId.value,
      title: fTitle.value,
      category: fCategory.value,
      tags: fTags.value,
      archived: !!fArchived.checked,
      content: fContent.value
    };
  }

  function isDirty() {
    var p = currentPayload();
    return p.id !== saved.id
      || p.title !== saved.title
      || p.category !== saved.category
      || p.tags !== saved.tags
      || p.archived !== saved.archived
      || p.content !== saved.content;
  }

  function snapshot(payload) {
    saved = {
      id: payload.id,
      title: payload.title,
      category: payload.category,
      tags: payload.tags,
      archived: !!payload.archived,
      content: payload.content
    };
  }

  function markSaved(payload) {
    snapshot(payload);
    setStatus('Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }), 'ok');
  }

  /* --------------------------------------------------------------- render */

  function memLink(item) {
    var li = document.createElement('li');
    var a = document.createElement('a');
    a.className = 'mem'
      + (item.archived ? ' archived' : '')
      + (item.new && !item.archived ? ' new' : '');
    a.href = '/ui?category=' + encodeURIComponent(item.category) + '&id=' + encodeURIComponent(item.id);
    a.setAttribute('data-category', item.category);
    a.setAttribute('data-id', item.id);
    a.setAttribute('draggable', 'true');
    a.textContent = item.title || item.id;
    if (item.new && !item.archived) {
      var newBadge = document.createElement('span');
      newBadge.className = 'badge-new';
      newBadge.textContent = 'new';
      a.appendChild(newBadge);
    }
    if (item.archived) {
      var badge = document.createElement('span');
      badge.className = 'badge-archived';
      badge.textContent = 'archived';
      a.appendChild(badge);
    }
    li.appendChild(a);
    return li;
  }

  function sectionTitle(name, count) {
    var div = document.createElement('div');
    div.className = 'cat-name';
    var label = document.createElement('span');
    label.textContent = name;
    var badge = document.createElement('span');
    badge.className = 'count';
    badge.textContent = count;
    div.appendChild(label);
    div.appendChild(badge);
    return div;
  }

  function renderTree(categories) {
    list.textContent = '';
    var total = 0;
    categories.forEach(function (group) {
      var visible = group.memories.filter(function (mem) { return passesFilter(!!mem.archived); });
      total += visible.length;
      if (filterMode !== 'all' && visible.length === 0) { return; }
      var section = document.createElement('section');
      section.className = 'cat';
      section.setAttribute('data-category', group.name);
      section.appendChild(sectionTitle(group.name, visible.length));
      var ul = document.createElement('ul');
      visible.forEach(function (mem) {
        ul.appendChild(memLink({ id: mem.id, category: group.name, title: mem.title, archived: !!mem.archived, new: !!mem.new }));
      });
      section.appendChild(ul);
      list.appendChild(section);
    });
    var empty = document.getElementById('list-empty');
    if (empty) {
      empty.hidden = total > 0;
      empty.textContent = filterMode === 'archived' ? 'No archived memories.' : 'No memories yet.';
    }
    markActive(fId.value ? fCategory.value : '', fId.value);
    applyCollapsed();
  }

  function renderResults(results) {
    list.textContent = '';
    if (!results.length) {
      var p = document.createElement('p');
      p.className = 'empty muted';
      p.textContent = 'No matches.';
      list.appendChild(p);
      return;
    }
    var section = document.createElement('section');
    section.className = 'cat';
    section.appendChild(sectionTitle('Results', results.length));
    var ul = document.createElement('ul');
    results.forEach(function (item) {
      var li = memLink(item);
      if (item.snippet) {
        var span = document.createElement('span');
        span.className = 'snippet';
        span.textContent = item.snippet;
        li.firstChild.appendChild(span);
      }
      ul.appendChild(li);
    });
    section.appendChild(ul);
    list.appendChild(section);
  }

  function refreshTree() {
    return api(endpoint.tree).then(function (res) {
      if (res.ok && res.data.categories) {
        renderTree(res.data.categories);
      }
    });
  }

  function markActive(category, id) {
    Array.prototype.forEach.call(list.querySelectorAll('.mem'), function (a) {
      var on = a.getAttribute('data-category') === category && a.getAttribute('data-id') === id;
      a.classList.toggle('active', on);
    });
  }

  function ensureCategoryOption(category) {
    var exists = false;
    Array.prototype.forEach.call(moveCategory.options, function (opt) {
      if (opt.value === category) { exists = true; }
    });
    if (!exists) {
      var opt = document.createElement('option');
      opt.value = category;
      opt.textContent = category;
      moveCategory.appendChild(opt);
    }
  }

  function syncCategoryUI(category) {
    fCategory.value = category;
    ensureCategoryOption(category);
    moveCategory.value = category;
    Array.prototype.forEach.call(newCats.querySelectorAll('.chip'), function (chip) {
      chip.classList.toggle('active', chip.getAttribute('data-cat') === category);
    });
  }

  function updateBreadcrumb(category, id) {
    if (id) {
      breadcrumb.innerHTML = '';
      breadcrumb.appendChild(document.createTextNode(category));
      var sep = document.createElement('span');
      sep.className = 'sep';
      sep.textContent = '/';
      breadcrumb.appendChild(sep);
      breadcrumb.appendChild(document.createTextNode(id));
    } else {
      breadcrumb.textContent = 'New memory';
    }
  }

  function resetTabs() {
    Array.prototype.forEach.call(tabs, function (t) {
      t.classList.toggle('active', t.getAttribute('data-tab') === 'write');
    });
    hidePreview();
  }

  function clearPreview() {
    renderPreview.cancel();
    previewEl.innerHTML = '';
  }

  function fillEditor(doc) {
    // Never let a previous document's preview linger: clear first, always.
    var wasPreview = previewVisible();
    clearPreview();

    fId.value = doc.id;
    fTitle.value = doc.title || '';
    fTags.value = (doc.tags || []).join(', ');
    fArchived.checked = !!doc.archived;
    fContent.value = doc.body || '';
    syncCategoryUI(doc.category);
    deleteBtn.disabled = false;
    deleteBtn.hidden = false;
    newCats.hidden = true;
    moveField.hidden = false;
    updateBreadcrumb(doc.category, doc.id);
    markActive(doc.category, doc.id);
    history.replaceState(null, '', '/ui?category=' + encodeURIComponent(doc.category) + '&id=' + encodeURIComponent(doc.id));

    snapshot({
      id: doc.id,
      title: fTitle.value,
      category: doc.category,
      tags: fTags.value,
      archived: fArchived.checked,
      content: fContent.value
    });
    setStatus('');

    // If the user was reading in Preview, immediately render the new document
    // instead of leaving the pane empty.
    if (wasPreview) { renderPreview(); }
  }

  function loadMemory(category, id) {
    return api(endpoint.memory + '?category=' + encodeURIComponent(category) + '&id=' + encodeURIComponent(id))
      .then(function (res) {
        if (res.ok && res.data.memory) {
          fillEditor(res.data.memory);
        } else {
          setStatus('Could not load that memory.', 'err');
        }
      });
  }

  function startNew(defaultCategory) {
    clearPreview();

    fId.value = '';
    fTitle.value = '';
    fTags.value = '';
    fArchived.checked = false;
    fContent.value = '';
    deleteBtn.disabled = true;
    deleteBtn.hidden = true;
    newCats.hidden = false;
    moveField.hidden = true;
    syncCategoryUI(defaultCategory || 'facts');
    updateBreadcrumb('', '');
    markActive('', '');
    history.replaceState(null, '', '/ui');
    resetTabs();
    snapshot({ id: '', title: '', category: fCategory.value, tags: '', archived: false, content: '' });
    setStatus('');
    fTitle.focus();
  }

  function hideEditor() {
    clearPreview();
    markActive('', '');
    history.replaceState(null, '', '/ui');
    setStatus('');
  }

  /* --------------------------------------------------------------- saving */

  function save() {
    if (saving) {
      queuedSave = true;
      return Promise.resolve(true);
    }

    var payload = currentPayload();
    if (!isDirty() && '' !== payload.id) {
      return Promise.resolve(true);
    }
    if ('' === payload.content.trim()) {
      // Autosave safety: don't create/overwrite with an empty body.
      if (manualIntent) {
        setStatus('Content must not be empty.', 'err');
      }
      return Promise.resolve(false);
    }

    saving = true;
    setStatus('Saving…');
    return api(endpoint.memory, { method: 'POST', body: payload }).then(function (res) {
      if (res.ok && res.data.memory) {
        fillEditor(res.data.memory);
        markSaved(currentPayload());
        refreshTree();
        return true;
      }
      setStatus(res.data.error || 'Save failed.', 'err');
      return false;
    }).catch(function () {
      setStatus('Network error.', 'err');
      return false;
    }).then(function (ok) {
      saving = false;
      if (queuedSave) {
        queuedSave = false;
        save();
      }
      return ok;
    });
  }

  var saveIdle = debounce(function () { save(); }, AUTOSAVE_MS);

  // Whether the current save() call was triggered by the user (affects empty
  // content handling/messaging). Autosaves stay silent.
  var manualIntent = false;
  function manualSave() {
    manualIntent = true;
    save().then(function () { manualIntent = false; });
  }

  // Save before leaving the current memory (e.g. clicking another one).
  function saveBeforeSwitch() {
    if (!isDirty()) { return Promise.resolve(true); }
    return save();
  }

  /* -------------------------------------------------------------- preview */

  function previewVisible() { return !previewEl.hidden; }
  function hidePreview() { previewEl.hidden = true; fContent.hidden = false; }
  function showPreview() { previewEl.hidden = false; fContent.hidden = true; renderPreview(); }

  var renderPreview = debounce(function () {
    var body = fContent.value;
    api(endpoint.preview, { method: 'POST', body: { markdown: body } }).then(function (res) {
      // Only paint if the editor still holds the same text we sent.
      if (fContent.value !== body) { return; }
      previewEl.innerHTML = res.ok ? (res.data.html || '') : '<p class="muted">Preview unavailable.</p>';
    });
  }, PREVIEW_MS);

  /* ---------------------------------------------------------------- move */

  function moveMemory(fromCategory, id, toCategory) {
    // Load the doc, then re-save under the new category (the store relocates
    // the .md file). Uses the current editor content when it's the open doc.
    return api(endpoint.memory + '?category=' + encodeURIComponent(fromCategory) + '&id=' + encodeURIComponent(id))
      .then(function (res) {
        if (!res.ok || !res.data.memory) {
          setStatus('Could not move memory.', 'err');
          return;
        }
        var doc = res.data.memory;
        var payload = {
          id: doc.id,
          title: doc.title || '',
          category: toCategory,
          tags: (doc.tags || []).join(', '),
          archived: !!doc.archived,
          content: doc.body || ''
        };
        return api(endpoint.memory, { method: 'POST', body: payload }).then(function (saved) {
          if (saved.ok && saved.data.memory) {
            if (fId.value === id) {
              fillEditor(saved.data.memory);
              markSaved(currentPayload());
            }
            setStatus('Moved to ' + toCategory + '.', 'ok');
            refreshTree();
          } else {
            setStatus(saved.data.error || 'Move failed.', 'err');
          }
        });
      });
  }

  /* --------------------------------------------------------------- events */

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    manualSave();
  });

  deleteBtn.addEventListener('click', function () {
    var category = fCategory.value;
    var id = fId.value;
    if (!id || !window.confirm('Delete this memory? This cannot be undone.')) { return; }
    api(endpoint.memory + '?category=' + encodeURIComponent(category) + '&id=' + encodeURIComponent(id), { method: 'DELETE' })
      .then(function (res) {
        if (res.ok) {
          hideEditor();
          setStatus('Deleted.', 'ok');
          refreshTree();
        } else {
          setStatus(res.data.error || 'Delete failed.', 'err');
        }
      });
  });

  newBtn.addEventListener('click', function () {
    saveBeforeSwitch().then(function () {
      startNew('facts');
      if (isMobile()) { closeDrawer(); }
    });
  });

  list.addEventListener('click', function (event) {
    var link = event.target.closest('.mem');
    if (!link) { return; }
    event.preventDefault();
    var category = link.getAttribute('data-category');
    var id = link.getAttribute('data-id');
    if (id === fId.value && category === fCategory.value) { closeDrawer(); return; }
    saveBeforeSwitch().then(function (ok) {
      if (!ok) { return; }
      loadMemory(category, id);
      if (isMobile()) { closeDrawer(); }
    });
  });

  newCats.addEventListener('click', function (event) {
    var chip = event.target.closest('.chip');
    if (!chip) { return; }
    syncCategoryUI(chip.getAttribute('data-cat'));
  });

  moveCategory.addEventListener('change', function () {
    if ('' === fId.value) { return; }
    var to = moveCategory.value;
    if (to === saved.category) { return; }
    moveMemory(saved.category, fId.value, to);
  });

  /* Collapsible category groups (state remembered). */
  list.addEventListener('click', function (event) {
    var header = event.target.closest('.cat-name');
    if (!header) { return; }
    var section = header.closest('.cat');
    if (!section) { return; }
    section.classList.toggle('collapsed');
    rememberCollapsed();
  });

  function collapsedState() {
    try { return JSON.parse(localStorage.getItem('memorydown.collapsed') || '{}') || {}; } catch (e) { return {}; }
  }

  function rememberCollapsed() {
    var state = {};
    Array.prototype.forEach.call(list.querySelectorAll('.cat'), function (section) {
      if (section.classList.contains('collapsed')) {
        state[section.getAttribute('data-category')] = 1;
      }
    });
    try { localStorage.setItem('memorydown.collapsed', JSON.stringify(state)); } catch (e) { /* ignore */ }
  }

  function applyCollapsed() {
    var state = collapsedState();
    Array.prototype.forEach.call(list.querySelectorAll('.cat'), function (section) {
      if (state[section.getAttribute('data-category')]) {
        section.classList.add('collapsed');
      }
    });
  }

  /* Drag a memory onto a category header to move it. */
  var dragSource = null;

  document.addEventListener('dragstart', function (event) {
    var link = event.target.closest('.mem');
    if (!link) { return; }
    dragSource = { id: link.getAttribute('data-id'), category: link.getAttribute('data-category') };
    link.classList.add('dragging');
    document.body.classList.add('dragging');
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', dragSource.id);
    }
  });

  document.addEventListener('dragend', function () {
    // Clear on the next tick: some browsers fire dragend before drop, and the
    // drop handler still needs the source.
    setTimeout(function () { dragSource = null; }, 0);
    document.body.classList.remove('dragging');
    Array.prototype.forEach.call(list.querySelectorAll('.dragging'), function (el) { el.classList.remove('dragging'); });
    Array.prototype.forEach.call(list.querySelectorAll('.drop-hover'), function (el) { el.classList.remove('drop-hover'); });
  });

  list.addEventListener('dragover', function (event) {
    if (!dragSource) { return; }
    var section = event.target.closest('.cat');
    if (!section || !section.getAttribute('data-category')) { return; }
    event.preventDefault();
    if (event.dataTransfer) { event.dataTransfer.dropEffect = 'move'; }
    Array.prototype.forEach.call(list.querySelectorAll('.drop-hover'), function (el) { el.classList.remove('drop-hover'); });
    section.classList.add('drop-hover');
  });

  list.addEventListener('drop', function (event) {
    if (!dragSource) { return; }
    var section = event.target.closest('.cat');
    if (!section) { return; }
    event.preventDefault();
    var to = section.getAttribute('data-category');
    var source = dragSource;
    dragSource = null;
    Array.prototype.forEach.call(list.querySelectorAll('.drop-hover'), function (el) { el.classList.remove('drop-hover'); });
    if (!to || to === source.category) { return; }
    // Moving the currently open memory: flush pending edits first so the move
    // carries the latest content.
    if (fId.value === source.id) {
      saveBeforeSwitch().then(function () { moveMemory(source.category, source.id, to); });
    } else {
      moveMemory(source.category, source.id, to);
    }
  });

  /* Search (left pane). */
  var doSearch = debounce(function () {
    var q = search.value.trim();
    if (q.length < 2) { refreshTree(); return; }
    var url = endpoint.search + '?q=' + encodeURIComponent(q) + '&archived=' + encodeURIComponent(filterMode);
    api(url).then(function (res) {
      if (res.ok) { renderResults(res.data.results || []); }
    });
  }, 180);

  function syncSearchClear() {
    if (searchClear) { searchClear.hidden = '' === search.value; }
  }

  function clearSearch() {
    if ('' === search.value) { return; }
    search.value = '';
    syncSearchClear();
    doSearch.cancel();
    refreshTree();
    search.focus();
  }

  if (searchClear) {
    searchClear.addEventListener('click', clearSearch);
  }
  // The native clear affordance (WebKit) fires only a 'search' event.
  search.addEventListener('input', function () { syncSearchClear(); doSearch(); });
  search.addEventListener('search', function () { syncSearchClear(); doSearch(); });
  search.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { clearSearch(); }
  });

  /* -------------------------------------------------------- shortcuts */

  // Keyboard cursor over the visible items in the left list (tree or results).
  var cursorIndex = -1;

  function visibleItems() {
    return Array.prototype.slice.call(list.querySelectorAll('.mem'));
  }

  function setCursor(index) {
    var items = visibleItems();
    Array.prototype.forEach.call(items, function (el) { el.classList.remove('cursor'); });
    if (!items.length) { cursorIndex = -1; return; }
    cursorIndex = Math.max(0, Math.min(items.length - 1, index));
    var el = items[cursorIndex];
    el.classList.add('cursor');
    el.scrollIntoView({ block: 'nearest' });
  }

  function moveCursor(delta) {
    var items = visibleItems();
    if (!items.length) { return; }
    var next = cursorIndex < 0
      ? (delta > 0 ? 0 : items.length - 1)
      : (cursorIndex + delta + items.length) % items.length; // wrap around
    setCursor(next);
  }

  function clearCursor() {
    cursorIndex = -1;
    Array.prototype.forEach.call(list.querySelectorAll('.mem.cursor'), function (el) {
      el.classList.remove('cursor');
    });
  }

  function openCursor() {
    var items = visibleItems();
    if (cursorIndex < 0 || cursorIndex >= items.length) { return; }
    var el = items[cursorIndex];
    var category = el.getAttribute('data-category');
    var id = el.getAttribute('data-id');
    if (!id) { return; }
    if (id === fId.value && category === fCategory.value) { return; }
    saveBeforeSwitch().then(function (ok) {
      if (ok) { loadMemory(category, id); }
    });
  }

  // True when the user is typing in a field, so single-key shortcuts must not fire.
  function isTyping() {
    var el = document.activeElement;
    if (!el) { return false; }
    var tag = (el.tagName || '').toLowerCase();
    return 'input' === tag || 'textarea' === tag || 'select' === tag || el.isContentEditable;
  }

  function wireShortcuts() {
    // Clear the keyboard cursor when the pointer interacts with the list.
    list.addEventListener('mousedown', function () { clearCursor(); });

    search.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') { event.preventDefault(); moveCursor(1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); moveCursor(-1); }
      else if (event.key === 'Enter') { event.preventDefault(); openCursor(); }
    });

    document.addEventListener('keydown', function (event) {
      // Ctrl/Cmd+K focuses search from anywhere (even while typing).
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        search.focus();
        search.select();
        return;
      }

      // The remaining shortcuts are single keys: never while typing in a field.
      if (isTyping() || event.ctrlKey || event.metaKey || event.altKey) { return; }

      if ('/' === event.key) {
        event.preventDefault();
        search.focus();
        return;
      }

      if ('ArrowDown' === event.key) { event.preventDefault(); moveCursor(1); }
      else if ('ArrowUp' === event.key) { event.preventDefault(); moveCursor(-1); }
      else if ('Enter' === event.key) { event.preventDefault(); openCursor(); }
      else if ('n' === event.key.toLowerCase()) { event.preventDefault(); newBtn.click(); }
      else if ('e' === event.key.toLowerCase()) { event.preventDefault(); fTitle.focus(); }
    });
  }

  /* All / Active / Archived filter. */
  function markActiveFilter() {
    if (!filterBar) { return; }
    Array.prototype.forEach.call(filterBar.querySelectorAll('.filter-btn'), function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-filter') === filterMode);
    });
  }

  function wireFilter() {
    markActiveFilter();
    if (!filterBar) { return; }
    filterBar.addEventListener('click', function (event) {
      var btn = event.target.closest('.filter-btn');
      if (!btn) { return; }
      filterMode = btn.getAttribute('data-filter') || 'all';
      try { localStorage.setItem(FILTER_KEY, filterMode); } catch (e) { /* ignore */ }
      markActiveFilter();
      if (search.value.trim().length >= 2) { doSearch(); } else { refreshTree(); }
    });
  }

  /* Archive toggle: saved immediately (a discrete action, like a move). */
  fArchived.addEventListener('change', function () {
    if (isDirty()) { saveIdle.cancel(); save(); }
  });

  /* Write / Preview tabs. */
  Array.prototype.forEach.call(tabs, function (tab) {
    tab.addEventListener('click', function () {
      Array.prototype.forEach.call(tabs, function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      if (tab.getAttribute('data-tab') === 'preview') { showPreview(); } else { hidePreview(); }
    });
  });

  /* Editing: schedule autosave, update preview live, flag unsaved. */
  function onEdit() {
    if (isDirty()) { setStatus('Unsaved…'); } else { setStatus(''); }
    saveIdle();
    if (previewVisible()) { renderPreview(); }
  }

  fContent.addEventListener('input', onEdit);
  fTitle.addEventListener('input', onEdit);
  fTags.addEventListener('input', onEdit);

  // Metadata edits save as soon as the field loses focus, so they don't sit
  // "Unsaved" waiting for the 20s idle timer.
  function saveOnBlur() {
    if (isDirty()) {
      saveIdle.cancel();
      save();
    }
  }
  fTitle.addEventListener('blur', saveOnBlur);
  fTags.addEventListener('blur', saveOnBlur);

  /* Flush pending edits when the tab is hidden or closed (best effort). */
  function flush() {
    if (!isDirty() || '' === fContent.value.trim()) { return; }
    var payload = currentPayload();
    var body = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon) {
        // sendBeacon can't set the CSRF header; append it as a query parameter
        // is not supported server-side, so fall back to a sync XHR.
      }
    } catch (e) { /* ignore */ }
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint.memory, false);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.setRequestHeader('X-CSRF-Token', csrf);
      xhr.send(body);
    } catch (e) { /* ignore */ }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { flush(); }
  });
  window.addEventListener('beforeunload', function (event) {
    saveIdle.cancel();
    if (isDirty() && '' !== fContent.value.trim()) {
      flush();
      event.preventDefault();
      event.returnValue = '';
      return '';
    }
  });

  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      manualSave();
    }
  });


  /* ----------------------------------------------------------------- init */

  // Snapshot the server-rendered editor state so a freshly loaded page is not
  // considered dirty (which would block navigation / trigger a spurious save).
  snapshot(currentPayload());

  themeSwitcher();
  wireLogout();
  wireReindex();
  wireDrawers();
  wireFilter();
  wireShortcuts();
  applyCollapsed();
  syncSearchClear();
  // Re-render from the API so the persisted All/Active/Archived filter applies
  // to the server-rendered tree (which otherwise shows everything).
  refreshTree().catch(function () { /* ignore */ });
})();

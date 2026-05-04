/**
 * admin-image-picker.js — Phase 16
 *
 * Image picker sub-component for the dish editor modal. Mounted lazily
 * by admin-menu-modal.js into the «Изображение» tab pane.
 *
 * Responsibilities:
 *   - Enumerate /images/* subfolders via /file-manager.php?action=list
 *   - Render grouped grid of thumbnails
 *   - Search-filter by filename
 *   - Drag-drop / click-to-upload via /file-manager.php?action=upload
 *   - Click thumb → set value via host setValue() callback
 *   - "Без изображения" (clear) option
 */
(function () {
  'use strict';

  var FOLDER = 'images';

  function getCsrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return (meta && meta.content) || (document.body && document.body.dataset && document.body.dataset.csrfToken) || '';
  }

  function isImage(name) {
    return /\.(jpe?g|png|webp|gif|svg|avif)$/i.test(String(name || ''));
  }

  function listFolder(sub) {
    var url = '/file-manager.php?action=list&folder=' + encodeURIComponent(FOLDER)
      + '&subfolder=' + encodeURIComponent(sub || '');
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return null; });
  }

  function buildGroups(host) {
    // Discover top-level subfolders + show files in /images/ root and each sub.
    return listFolder('').then(function (top) {
      if (!top || (top.items === undefined && !top.success)) return [];
      var items = top.items || [];
      var groups = [];
      var rootImages = items.filter(function (it) { return it.type !== 'folder' && isImage(it.name); });
      if (rootImages.length) {
        groups.push({ subfolder: '', label: 'Корневая папка', files: rootImages });
      }
      var subdirs = items.filter(function (it) { return it.type === 'folder'; });
      return Promise.all(subdirs.map(function (d) {
        return listFolder(d.name).then(function (res) {
          if (!res || res.items === undefined) return null;
          var imgs = (res.items || []).filter(function (it) { return it.type !== 'folder' && isImage(it.name); });
          return { subfolder: d.name, label: d.name, files: imgs };
        });
      })).then(function (subGroups) {
        subGroups.forEach(function (g) { if (g) groups.push(g); });
        return groups;
      });
    });
  }

  function renderTile(group, file, currentValue, onPick) {
    var path = './images/' + (group.subfolder ? group.subfolder + '/' : '') + file.name;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'picker-tile';
    btn.dataset.path = path;
    btn.title = file.name;
    if (currentValue && (currentValue === path || currentValue === path.replace(/^\.\//, '/'))) {
      btn.classList.add('is-selected');
    }
    var img = document.createElement('img');
    img.loading = 'lazy';
    img.alt = file.name;
    img.src = path.replace(/^\.\//, '/');
    btn.appendChild(img);
    var lbl = document.createElement('span');
    lbl.className = 'picker-tile-name';
    lbl.textContent = file.name;
    btn.appendChild(lbl);
    btn.addEventListener('click', function () { onPick(path); });
    return btn;
  }

  function renderGroups(host, groups, currentValue, onPick) {
    var container = host.querySelector('.picker-groups');
    container.innerHTML = '';
    if (!groups.length) {
      container.innerHTML = '<p class="picker-status">Изображений нет — перетащите файлы в зону загрузки.</p>';
      return;
    }
    groups.forEach(function (g) {
      if (!g.files.length) return;
      var box = document.createElement('div');
      box.className = 'picker-folder-group';
      box.dataset.subfolder = g.subfolder;
      var title = document.createElement('h5');
      title.className = 'picker-folder-title';
      title.textContent = g.label + ' (' + g.files.length + ')';
      box.appendChild(title);
      var grid = document.createElement('div');
      grid.className = 'picker-grid';
      g.files.forEach(function (f) {
        grid.appendChild(renderTile(g, f, currentValue, onPick));
      });
      box.appendChild(grid);
      container.appendChild(box);
    });
  }

  function applyFilter(host, q) {
    var query = (q || '').trim().toLowerCase();
    var groups = host.querySelectorAll('.picker-folder-group');
    groups.forEach(function (group) {
      var anyVisible = false;
      var tiles = group.querySelectorAll('.picker-tile');
      tiles.forEach(function (tile) {
        var name = (tile.querySelector('.picker-tile-name').textContent || '').toLowerCase();
        var visible = !query || name.indexOf(query) !== -1;
        tile.hidden = !visible;
        if (visible) anyVisible = true;
      });
      group.hidden = !anyVisible;
    });
  }

  function uploadFile(file, subfolder) {
    var fd = new FormData();
    fd.append('folder', FOLDER);
    fd.append('subfolder', subfolder || '');
    fd.append('csrf_token', getCsrf());
    fd.append('files[]', file);
    return fetch('/file-manager.php?action=upload', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    }).then(function (r) { return r.json().catch(function () { return null; }); });
  }

  function uploadFiles(host, files, opts) {
    var subFolderSelect = host.querySelector('.picker-folder');
    var sub = subFolderSelect ? subFolderSelect.value : '';
    var status = host.querySelector('.picker-status');
    if (status) status.textContent = 'Загрузка ' + files.length + ' файл(ов)…';
    var done = 0, lastUploaded = null;
    return Promise.all(Array.from(files).map(function (f) {
      return uploadFile(f, sub).then(function (out) {
        done++;
        if (status) status.textContent = 'Загружено ' + done + ' / ' + files.length;
        if (out && out.success) lastUploaded = './images/' + (sub ? sub + '/' : '') + f.name;
      });
    })).then(function () {
      if (status) status.textContent = 'Готово.';
      // Refresh and auto-select most recent uploaded file
      mountReload(host, opts, lastUploaded);
    });
  }

  function mount(host, opts) {
    if (!host || host.dataset.pickerInited === '1') return;
    host.dataset.pickerInited = '1';

    host.innerHTML =
      '<div class="picker-toolbar">' +
        '<input type="search" class="picker-search" placeholder="Поиск по имени файла…">' +
        '<select class="picker-folder" aria-label="Папка для загрузки">' +
          '<option value="">/images/ (корень)</option>' +
        '</select>' +
        '<button type="button" class="checkout-btn picker-clear">Без изображения</button>' +
      '</div>' +
      '<div class="picker-dropzone" tabindex="0" role="button">' +
        'Перетащите файлы сюда или нажмите для выбора. Поддержка JPG / PNG / WebP / SVG.' +
        '<input type="file" class="picker-file-input" hidden multiple accept="image/*">' +
      '</div>' +
      '<div class="picker-status">Загрузка списка…</div>' +
      '<div class="picker-groups"></div>';

    var search   = host.querySelector('.picker-search');
    var folderSel = host.querySelector('.picker-folder');
    var clearBtn = host.querySelector('.picker-clear');
    var dropzone = host.querySelector('.picker-dropzone');
    var fileInput = host.querySelector('.picker-file-input');
    var status   = host.querySelector('.picker-status');

    function onPick(path) {
      opts.setValue(path);
      // Refresh selected highlight
      host.querySelectorAll('.picker-tile').forEach(function (t) {
        t.classList.toggle('is-selected', t.dataset.path === path);
      });
    }

    search.addEventListener('input', function () { applyFilter(host, search.value); });
    clearBtn.addEventListener('click', function () {
      opts.setValue('');
      host.querySelectorAll('.picker-tile.is-selected').forEach(function (t) { t.classList.remove('is-selected'); });
    });

    dropzone.addEventListener('click', function () { fileInput.click(); });
    dropzone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });
    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files.length) uploadFiles(host, fileInput.files, opts);
      fileInput.value = '';
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
      dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.add('is-dragover'); });
    });
    ['dragleave', 'dragend', 'drop'].forEach(function (ev) {
      dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.remove('is-dragover'); });
    });
    dropzone.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (files && files.length) uploadFiles(host, files, opts);
    });

    // Initial load
    buildGroups(host).then(function (groups) {
      // Populate folder select with discovered subdirs
      groups.forEach(function (g) {
        if (!g.subfolder) return;
        var opt = document.createElement('option');
        opt.value = g.subfolder;
        opt.textContent = g.subfolder + '/';
        folderSel.appendChild(opt);
      });
      status.textContent = '';
      renderGroups(host, groups, opts.getValue ? opts.getValue() : '', onPick);
    }).catch(function () { status.textContent = 'Не удалось загрузить список изображений.'; });
  }

  function mountReload(host, opts, autoSelect) {
    host.querySelector('.picker-status').textContent = 'Обновление списка…';
    buildGroups(host).then(function (groups) {
      function onPick(path) {
        opts.setValue(path);
        host.querySelectorAll('.picker-tile').forEach(function (t) {
          t.classList.toggle('is-selected', t.dataset.path === path);
        });
      }
      var current = autoSelect || (opts.getValue ? opts.getValue() : '');
      renderGroups(host, groups, current, onPick);
      host.querySelector('.picker-status').textContent = '';
      if (autoSelect) opts.setValue(autoSelect);
    });
  }

  window.AdminImagePicker = { mount: mount };
})();

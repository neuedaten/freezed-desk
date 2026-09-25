/* Freezed Desk UI. Vanilla JavaScript, no build step. Every behaviour hangs
   on a data attribute, so a project overlay can restyle the markup freely. */
(function () {
    'use strict';

    const csrf = document.body.dataset.csrf || '';
    const t = (key, fallback) => (window.deskStrings && window.deskStrings[key]) || fallback;

    // -------------------------------------------------------- helpers ---
    function on(root, selector, event, handler) {
        root.addEventListener(event, function (e) {
            const target = e.target.closest(selector);
            if (target && root.contains(target)) handler(e, target);
        });
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    }
    async function fetchJson(url, options) {
        options = options || {};
        options.headers = Object.assign({'Accept': 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf}, options.headers || {});
        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.error || response.statusText);
        return data;
    }
    function debounce(fn, ms) {
        let timer;
        return function () { clearTimeout(timer); const args = arguments; timer = setTimeout(() => fn.apply(this, args), ms); };
    }

    // ---------------------------------------------------- navigation ---
    on(document, '[data-toggle-nav]', 'click', () => {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) sidebar.classList.toggle('is-open');
    });

    // ------------------------------------------------------- confirm ---
    on(document, 'form[data-confirm]', 'submit', (e, form) => {
        if (!confirm(form.dataset.confirm)) e.preventDefault();
    });
    on(document, 'button[data-confirm]', 'click', (e, button) => {
        if (!confirm(button.dataset.confirm)) e.preventDefault();
    });

    // -------------------------------------------------- unsaved guard ---
    document.querySelectorAll('form[data-guard]').forEach(form => {
        let dirty = false;
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('change', () => { dirty = true; });
        form.addEventListener('submit', () => { dirty = false; });
        window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    });

    // ------------------------------------------------------ autogrow ---
    function autogrow(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight + 2, window.innerHeight * 0.7) + 'px';
    }
    document.querySelectorAll('textarea[data-autogrow]').forEach(autogrow);
    on(document, 'textarea[data-autogrow]', 'input', (e, el) => autogrow(el));

    // ---------------------------------------------------------- slug ---
    function slugify(text) {
        return text.toLowerCase()
            .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }
    const slugInput = document.querySelector('input[data-slug]');
    if (slugInput) {
        const form = slugInput.closest('form');
        const source = form && (form.querySelector('[data-slug-source]') || form.querySelector('#f-title') || form.querySelector('[name="fields[title]"]'));
        slugInput.addEventListener('input', () => { delete slugInput.dataset.slugAuto; });
        if (source && slugInput.dataset.slugAuto !== undefined) {
            source.addEventListener('input', () => {
                if (slugInput.dataset.slugAuto !== undefined) slugInput.value = slugify(source.value);
            });
        }
    }

    // --------------------------------------------------------- lists ---
    on(document, '[data-list-add]', 'click', (e, button) => {
        const list = button.closest('[data-list]');
        const rows = list.querySelector('[data-list-rows]');
        const max = parseInt(button.dataset.listMax || '0', 10);
        if (max && rows.querySelectorAll(':scope > [data-list-row]').length >= max) return;
        const template = list.querySelector(':scope > template[data-list-template]');
        const index = parseInt(list.dataset.next || '0', 10);
        list.dataset.next = String(index + 1);
        const html = template.innerHTML.split('__INDEX__').join(String(index));
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        const row = wrapper.firstElementChild;
        rows.appendChild(row);
        row.querySelectorAll('textarea[data-autogrow]').forEach(autogrow);
        const first = row.querySelector('input, textarea, select');
        if (first) first.focus();
    });
    on(document, '[data-list-remove]', 'click', (e, button) => {
        button.closest('[data-list-row]').remove();
    });
    on(document, '[data-list-up]', 'click', (e, button) => {
        const row = button.closest('[data-list-row]');
        if (row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
    });
    on(document, '[data-list-down]', 'click', (e, button) => {
        const row = button.closest('[data-list-row]');
        if (row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
    });

    // --------------------------------------------- generic drag sort ---
    // Works for any container whose direct children carry `draggable`;
    // calls onDrop(container) after a reorder.
    function makeSortable(container, itemSelector, onDrop) {
        let dragged = null;
        container.addEventListener('dragstart', e => {
            const item = e.target.closest(itemSelector);
            if (!item || !container.contains(item)) return;
            dragged = item;
            item.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
        });
        container.addEventListener('dragover', e => {
            if (!dragged) return;
            e.preventDefault();
            const over = e.target.closest(itemSelector);
            if (!over || over === dragged || !container.contains(over)) return;
            const rect = over.getBoundingClientRect();
            const horizontal = getComputedStyle(container).display.indexOf('grid') !== -1 || getComputedStyle(container).display.indexOf('flex') !== -1;
            const before = horizontal ? (e.clientX - rect.left) < rect.width / 2 : (e.clientY - rect.top) < rect.height / 2;
            over.parentNode.insertBefore(dragged, before ? over : over.nextSibling);
        });
        container.addEventListener('dragend', () => {
            if (!dragged) return;
            dragged.classList.remove('is-dragging');
            dragged = null;
            if (onDrop) onDrop(container);
        });
    }
    document.querySelectorAll('[data-list-rows]').forEach(rows => makeSortable(rows, '[data-list-row]'));
    document.querySelectorAll('[data-list-row]').forEach(row => { row.draggable = true; });

    // Table reorder (sort mode).
    document.querySelectorAll('table[data-sortable] tbody').forEach(tbody => {
        makeSortable(tbody, 'tr', async body => {
            const ids = Array.from(body.querySelectorAll('tr[data-id]')).map(tr => tr.dataset.id);
            try {
                await fetchJson(body.closest('table').dataset.reorderUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ids: ids, _token: csrf})
                });
            } catch (err) {
                alert(err.message);
            }
        });
    });

    // ---------------------------------------------------------- bulk ---
    const bulkbar = document.querySelector('[data-bulkbar]');
    if (bulkbar) {
        const checks = () => Array.from(document.querySelectorAll('[data-bulk-check]'));
        const update = () => { bulkbar.hidden = !checks().some(c => c.checked); };
        on(document, '[data-bulk-check]', 'change', update);
        on(document, '[data-bulk-all]', 'change', (e, all) => { checks().forEach(c => { c.checked = all.checked; }); update(); });
    }

    // ----------------------------------------------------- relations ---
    document.querySelectorAll('[data-relation-field]').forEach(field => {
        const types = field.dataset.types;
        const multiple = field.dataset.multiple === '1';
        const name = field.dataset.name;
        const selected = field.querySelector('[data-relation-selected]');
        const search = field.querySelector('[data-relation-search]');
        const results = field.querySelector('[data-relation-results]');
        let active = -1;
        let current = [];

        function has(value) {
            return Array.from(selected.querySelectorAll('[data-relation-chip]')).some(c => c.dataset.value === value);
        }
        function add(record) {
            if (has(record.value)) return;
            if (!multiple) selected.innerHTML = '';
            const chip = document.createElement('span');
            chip.className = 'chip chip--record';
            chip.dataset.relationChip = '';
            chip.dataset.value = record.value;
            chip.draggable = multiple;
            chip.innerHTML = '<input type="hidden" name="' + escapeHtml(name) + (multiple ? '[]' : '') + '" value="' + escapeHtml(record.value) + '">'
                + '<a href="/types/' + escapeHtml(record.type) + '/' + record.id + '" target="_blank">' + escapeHtml(record.title) + '</a>'
                + (record.typeLabel ? ' <small class="muted">' + escapeHtml(record.typeLabel) + '</small>' : '')
                + '<button type="button" class="chip__remove" data-relation-remove aria-label="remove">✕</button>';
            selected.appendChild(chip);
            field.dispatchEvent(new Event('change', {bubbles: true}));
        }
        function render() {
            results.innerHTML = current.map((r, i) =>
                '<li data-index="' + i + '" class="' + (i === active ? 'is-active' : '') + (has(r.value) ? ' muted' : '') + '">'
                + '<span>' + escapeHtml(r.title) + '</span><small>' + escapeHtml(r.typeLabel) + ' · ' + escapeHtml(r.status) + '</small></li>'
            ).join('');
            results.hidden = current.length === 0;
        }
        const load = debounce(async () => {
            const q = search.value.trim();
            try {
                const data = await fetchJson('/api/search?types=' + encodeURIComponent(types) + '&q=' + encodeURIComponent(q) + '&limit=15');
                current = data.results || [];
                active = current.length ? 0 : -1;
                render();
            } catch (err) {
                results.innerHTML = '<li class="muted">' + escapeHtml(err.message) + '</li>';
                results.hidden = false;
            }
        }, 180);

        search.addEventListener('input', load);
        search.addEventListener('focus', load);
        search.addEventListener('keydown', e => {
            if (results.hidden) return;
            if (e.key === 'ArrowDown') { active = Math.min(active + 1, current.length - 1); render(); e.preventDefault(); }
            else if (e.key === 'ArrowUp') { active = Math.max(active - 1, 0); render(); e.preventDefault(); }
            else if (e.key === 'Enter') { if (current[active]) { add(current[active]); search.value = ''; results.hidden = true; } e.preventDefault(); }
            else if (e.key === 'Escape') { results.hidden = true; }
        });
        search.addEventListener('blur', () => setTimeout(() => { results.hidden = true; }, 150));
        results.addEventListener('mousedown', e => {
            const li = e.target.closest('li[data-index]');
            if (!li) return;
            e.preventDefault();
            add(current[parseInt(li.dataset.index, 10)]);
            search.value = '';
            results.hidden = true;
        });
        selected.addEventListener('click', e => {
            const remove = e.target.closest('[data-relation-remove]');
            if (remove) { remove.closest('[data-relation-chip]').remove(); field.dispatchEvent(new Event('change', {bubbles: true})); }
        });
        if (multiple) makeSortable(selected, '[data-relation-chip]');
    });

    // --------------------------------------------------------- media ---
    const modal = document.querySelector('[data-media-modal]');
    const mediaPicker = {
        resolve: null,
        kind: 'all',
        multiple: false,
        chosen: new Map(),
        open(options) {
            if (!modal || typeof modal.showModal !== 'function') return Promise.resolve([]);
            this.kind = options.kind || 'all';
            this.multiple = !!options.multiple;
            this.chosen = new Map();
            modal.querySelector('[data-media-search]').value = '';
            modal.showModal();
            this.load();
            return new Promise(resolve => { this.resolve = resolve; });
        },
        close(result) {
            if (modal.open) modal.close();
            if (this.resolve) { const r = this.resolve; this.resolve = null; r(result || []); }
        },
        async load() {
            const grid = modal.querySelector('[data-media-grid]');
            const q = modal.querySelector('[data-media-search]').value.trim();
            grid.innerHTML = '<p class="muted">…</p>';
            try {
                const data = await fetchJson('/api/media?kind=' + encodeURIComponent(this.kind) + '&q=' + encodeURIComponent(q) + '&limit=120');
                this.render(data.results || []);
            } catch (err) {
                grid.innerHTML = '<p class="muted">' + escapeHtml(err.message) + '</p>';
            }
        },
        render(items) {
            const grid = modal.querySelector('[data-media-grid]');
            grid.innerHTML = items.map(m =>
                '<div class="media-card' + (this.chosen.has(m.id) ? ' is-selected' : '') + '" data-pick=\'' + escapeHtml(JSON.stringify(m)) + '\' data-id="' + m.id + '">'
                + (m.isImage ? '<img src="' + escapeHtml(m.thumb) + '" alt="' + escapeHtml(m.alt) + '" loading="lazy">' : '<div class="media-card__file">' + escapeHtml(m.mime) + '</div>')
                + '<div class="media-card__meta"><strong>' + escapeHtml(m.name) + '</strong><br><span class="muted">' + (m.width ? m.width + '×' + m.height : '') + '</span></div></div>'
            ).join('') || '<p class="muted">–</p>';
            this.status();
        },
        status() {
            modal.querySelector('[data-media-status]').textContent = this.chosen.size ? this.chosen.size + ' ✓' : '';
        }
    };
    if (modal) {
        modal.querySelector('[data-media-search]').addEventListener('input', debounce(() => mediaPicker.load(), 200));
        modal.querySelector('[data-media-close]').addEventListener('click', () => mediaPicker.close([]));
        modal.addEventListener('cancel', e => { e.preventDefault(); mediaPicker.close([]); });
        modal.querySelector('[data-media-confirm]').addEventListener('click', () => mediaPicker.close(Array.from(mediaPicker.chosen.values())));
        modal.querySelector('[data-media-grid]').addEventListener('click', e => {
            const card = e.target.closest('[data-pick]');
            if (!card) return;
            const media = JSON.parse(card.dataset.pick);
            if (mediaPicker.multiple) {
                if (mediaPicker.chosen.has(media.id)) { mediaPicker.chosen.delete(media.id); card.classList.remove('is-selected'); }
                else { mediaPicker.chosen.set(media.id, media); card.classList.add('is-selected'); }
                mediaPicker.status();
            } else {
                mediaPicker.close([media]);
            }
        });
        modal.querySelector('[data-media-grid]').addEventListener('dblclick', e => {
            const card = e.target.closest('[data-pick]');
            if (card) mediaPicker.close([JSON.parse(card.dataset.pick)]);
        });
        modal.querySelector('[data-media-upload]').addEventListener('change', async e => {
            const status = modal.querySelector('[data-media-status]');
            status.textContent = '…';
            try {
                const data = await upload(e.target.files);
                (data.media || []).forEach(m => mediaPicker.chosen.set(m.id, m));
                if (!mediaPicker.multiple && data.media && data.media.length) { mediaPicker.close([data.media[0]]); return; }
                await mediaPicker.load();
                Object.keys(data.errors || {}).forEach(name => alert(name + ': ' + data.errors[name]));
            } catch (err) {
                alert(err.message);
            }
            e.target.value = '';
        });
    }
    async function upload(files) {
        const form = new FormData();
        Array.from(files).forEach(f => form.append('files[]', f));
        form.append('_token', csrf);
        return fetchJson('/media/upload', {method: 'POST', body: form});
    }

    function mediaCard(m, name, multiple) {
        const card = document.createElement('div');
        card.className = 'media-card';
        card.dataset.mediaCard = '';
        card.dataset.id = m.id;
        card.draggable = multiple;
        card.innerHTML = (multiple ? '<input type="hidden" name="' + escapeHtml(name) + '[]" value="' + m.id + '">' : '')
            + (m.isImage ? '<img src="' + escapeHtml(m.thumb) + '" alt="' + escapeHtml(m.alt) + '">' : '<div class="media-card__file">' + escapeHtml(m.name) + '</div>')
            + '<div class="media-card__meta"><a href="/media/' + m.id + '" target="_blank">' + escapeHtml(m.name) + '</a>' + (m.width ? '<br><span class="muted">' + m.width + '×' + m.height + '</span>' : '') + '</div>'
            + '<button type="button" class="button button--icon button--danger media-card__remove" data-media-remove aria-label="remove">✕</button>';
        return card;
    }
    document.querySelectorAll('[data-media-field]').forEach(field => {
        const multiple = field.dataset.multiple === '1';
        const selected = field.querySelector('[data-media-selected]');
        const input = field.querySelector('[data-media-input]');
        const name = field.dataset.name;
        const max = parseInt(field.dataset.max || '0', 10);

        field.querySelector('[data-media-choose]').addEventListener('click', async () => {
            const chosen = await mediaPicker.open({kind: field.dataset.kind, multiple: multiple});
            if (!chosen.length) return;
            if (!multiple) {
                selected.innerHTML = '';
                selected.appendChild(mediaCard(chosen[0], name, false));
                input.value = chosen[0].id;
            } else {
                chosen.forEach(m => {
                    if (selected.querySelector('[data-media-card][data-id="' + m.id + '"]')) return;
                    if (max && selected.querySelectorAll('[data-media-card]').length >= max) return;
                    selected.appendChild(mediaCard(m, name, true));
                });
            }
            field.dispatchEvent(new Event('change', {bubbles: true}));
        });
        selected.addEventListener('click', e => {
            const remove = e.target.closest('[data-media-remove]');
            if (!remove) return;
            remove.closest('[data-media-card]').remove();
            if (input) input.value = '';
            field.dispatchEvent(new Event('change', {bubbles: true}));
        });
        if (multiple) makeSortable(selected, '[data-media-card]');
    });

    // Drop zone on the media page.
    document.querySelectorAll('[data-dropzone]').forEach(zone => {
        const input = zone.querySelector('[data-dropzone-input]');
        const status = zone.querySelector('[data-dropzone-status]');
        async function send(files) {
            if (!files.length) return;
            status.textContent = '… ' + files.length;
            try {
                const data = await upload(files);
                const errors = Object.keys(data.errors || {});
                status.textContent = (data.media || []).length + ' ✓' + (errors.length ? ' / ' + errors.map(n => n + ': ' + data.errors[n]).join('; ') : '');
                if ((data.media || []).length) setTimeout(() => location.reload(), 400);
            } catch (err) {
                status.textContent = err.message;
            }
        }
        zone.addEventListener('click', e => { if (!e.target.closest('input, button')) input.click(); });
        input.addEventListener('change', () => { send(input.files); input.value = ''; });
        ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('is-over'); }));
        ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('is-over'); }));
        zone.addEventListener('drop', e => send(e.dataTransfer.files));
    });

    // Focal point.
    document.querySelectorAll('[data-focal]').forEach(editor => {
        const img = editor.querySelector('img');
        const marker = editor.querySelector('[data-focal-marker]');
        const form = editor.closest('.card').querySelector('form');
        const x = form.querySelector('[data-focal-x]');
        const y = form.querySelector('[data-focal-y]');
        function show() {
            if (x.value === '' || y.value === '') { marker.hidden = true; return; }
            marker.style.left = (parseFloat(x.value) * 100) + '%';
            marker.style.top = (parseFloat(y.value) * 100) + '%';
            marker.hidden = false;
        }
        editor.addEventListener('click', e => {
            const rect = img.getBoundingClientRect();
            x.value = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width)).toFixed(4);
            y.value = Math.min(1, Math.max(0, (e.clientY - rect.top) / rect.height)).toFixed(4);
            show();
            form.dispatchEvent(new Event('change', {bubbles: true}));
        });
        const reset = form.querySelector('[data-focal-reset]');
        if (reset) reset.addEventListener('click', () => { x.value = ''; y.value = ''; show(); });
        if (img.complete) show(); else img.addEventListener('load', show);
    });

    // --------------------------------------------------------- hours ---
    on(document, '[data-hours-add]', 'click', (e, button) => {
        const day = button.closest('[data-hours-day]');
        const ranges = day.querySelector('[data-hours-ranges]');
        const closed = ranges.querySelector('[data-hours-closed]');
        if (closed) closed.remove();
        const index = ranges.querySelectorAll('[data-hours-range]').length + Math.floor(Math.random() * 1000) + 10;
        const template = button.closest('[data-hours]').querySelector('template[data-hours-template]');
        const html = template.innerHTML.split('__NAME__').join(button.dataset.name).split('__INDEX__').join(String(index));
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        ranges.appendChild(wrapper.firstElementChild);
    });
    on(document, '[data-hours-remove]', 'click', (e, button) => {
        button.closest('[data-hours-range]').remove();
    });

    // ------------------------------------------------------- actions ---
    on(document, '.action-run', 'click', async (e, button) => {
        const output = document.querySelector(button.dataset.output);
        if (!output) return;
        output.hidden = false;
        output.textContent = '';
        button.disabled = true;
        try {
            const response = await fetch(button.dataset.actionUrl, {
                method: 'POST',
                headers: {'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch'},
                body: new URLSearchParams({_token: csrf})
            });
            if (!response.body) { output.textContent = await response.text(); return; }
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            while (true) {
                const {done, value} = await reader.read();
                if (done) break;
                output.textContent += decoder.decode(value, {stream: true});
                output.scrollTop = output.scrollHeight;
            }
        } catch (err) {
            output.textContent += '\n' + err.message;
        } finally {
            button.disabled = false;
        }
    });
})();

/**
 * Файл: /admin/catalog/js/authorsearch.js
 *
 * =============================================================
 * AJAX: живой поиск категорий (родительская категория)
 * -------------------------------------------------------------
 */

(function () {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;

    const csrf = meta.getAttribute('content');

    const root = document.getElementById('parentSearchRoot');
    const input = document.getElementById('parent_search');
    const hiddenId = document.getElementById('parent_id');
    const hiddenName = document.getElementById('parent_name');
    const suggest = document.getElementById('parent_suggest');
    const clearBtn = document.getElementById('parent_clear');

    if (!root || !input || !hiddenId || !hiddenName || !suggest || !clearBtn) return;

    const excludeId = parseInt(root.dataset.excludeId || '0', 10) || 0;

    let debounceTimer = null;
    let lastQuery = '';

    function escapeHtml(str) {
        return String(str)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function hideSuggest() {
        suggest.classList.add('d-none');
        suggest.innerHTML = '';
    }

    function showSuggest(items) {
        if (!items || !items.length) {
            hideSuggest();
            return;
        }

        const html = items.map(item => {
            const name = escapeHtml(item.naime || '');
            const url = escapeHtml(item.url || '');
            const id = escapeHtml(item.id || '');
            return `
                <div class="parent-suggest-item" data-id="${id}" data-name="${name}">
                    <div><strong>#${id}</strong> ${name}</div>
                    <div class="parent-suggest-muted">${url}</div>
                </div>
            `;
        }).join('');

        suggest.innerHTML = html;
        suggest.classList.remove('d-none');
    }

    async function fetchSuggest(q) {
        const u = new URL(window.location.href);
        u.searchParams.set('action', 'parent_search');
        u.searchParams.set('q', q);
        if (excludeId > 0) u.searchParams.set('exclude_id', String(excludeId));

        const res = await fetch(u.toString(), {
            method: 'GET',
            headers: {
                'X-CSRF-Token': csrf,
                'Accept': 'application/json'
            }
        });

        if (!res.ok) {
            hideSuggest();
            return;
        }

        const data = await res.json();
        if (!data || data.error) {
            hideSuggest();
            return;
        }

        showSuggest(data.items || []);
    }

    function setParent(id, name) {
        hiddenId.value = String(id || 0);
        hiddenName.value = String(name || '');
        input.value = String(name || '');
        hideSuggest();
    }

    suggest.addEventListener('click', function (e) {
        const item = e.target.closest('.parent-suggest-item');
        if (!item) return;

        const id = item.getAttribute('data-id');
        const name = item.getAttribute('data-name');
        setParent(id, name);
    });

    input.addEventListener('input', function () {
        const q = input.value.trim();
        hiddenName.value = q;
        hiddenId.value = '0';

        if (debounceTimer) clearTimeout(debounceTimer);

        if (q.length < 1) {
            hideSuggest();
            return;
        }

        debounceTimer = setTimeout(() => {
            if (q === lastQuery) return;
            lastQuery = q;
            fetchSuggest(q);
        }, 250);
    });

    document.addEventListener('click', function (e) {
        if (e.target === input || suggest.contains(e.target)) return;
        hideSuggest();
    });

    clearBtn.addEventListener('click', function () {
        setParent(0, '');
    });
})();

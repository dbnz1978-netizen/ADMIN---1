/**
 * Файл: js/categorysearch.js
 *
 * =============================================================
 * AJAX: живой поиск категорий для новостных статей
 * -------------------------------------------------------------
 */

(function () {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;

    const csrf = meta.getAttribute('content');

    const root = document.getElementById('categorySearchRoot');
    const input = document.getElementById('category_search');
    const hiddenId = document.getElementById('category_id');
    const hiddenName = document.getElementById('category_name');
    const suggest = document.getElementById('category_suggest');
    const clearBtn = document.getElementById('category_clear');

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
            const name = escapeHtml(item.name || '');
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
        u.searchParams.set('action', 'category_search');
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

    function setCategory(id, name) {
        hiddenId.value = String(id || 0);
        hiddenName.value = String(name || '');
        input.value = String(name || '');
        hideSuggest();
    }

    suggest.addEventListener('click', function (e) {
        const item = e.target.closest('.parent-suggest-item');
        if (!item) return;

        const id = parseInt(item.dataset.id || '0', 10);
        const name = item.dataset.name || '';
        setCategory(id, name);
    });

    clearBtn.addEventListener('click', function () {
        setCategory(0, '');
    });

    input.addEventListener('input', function () {
        const q = input.value.trim();

        if (q.length < 2) {
            hideSuggest();
            return;
        }

        if (q === lastQuery) {
            return;
        }

        lastQuery = q;

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            fetchSuggest(q);
        }, 300);
    });

    input.addEventListener('blur', function () {
        setTimeout(() => {
            hideSuggest();
        }, 200);
    });

    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) {
            hideSuggest();
        }
    });
})();

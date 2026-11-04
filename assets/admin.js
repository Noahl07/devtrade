// Back-office : confirmations, slugs, éditeur HTML (outils, aperçu nettoyé, Ctrl+S), réordonnancement
// (glisser-déposer natif + boutons monter/descendre pour le clavier et le mobile), garde « modifications non enregistrées ».
// Aucune dépendance ni script en ligne (CSP stricte).
import './styles/ds/admin.css';

const root = document.querySelector('[data-admin]');
const csrf = root?.dataset.csrf ?? '';
const statusEl = document.querySelector('[data-admin-status]');

let statusTimer = 0;
function announce(message, kind = 'ok') {
    if (!statusEl) {
        return;
    }
    statusEl.textContent = message;
    statusEl.className = `admin-status is-visible is-${kind}`;
    clearTimeout(statusTimer);
    statusTimer = setTimeout(() => statusEl.classList.remove('is-visible'), 2600);
}

// ── Confirmations ──────────────────────────────────────────────────────────
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        } else {
            form.dataset.confirmed = '1';
        }
    });
});

// ── Filtres : envoi au changement ──────────────────────────────────────────
document.querySelectorAll('select[data-autosubmit]').forEach((select) => {
    select.addEventListener('change', () => select.form.requestSubmit());
});

// ── Slug : aperçu en direct tant que le champ est vide ─────────────────────
const slugify = (text) => text
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/['’]/g, '-').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

document.querySelectorAll('form').forEach((form) => {
    const source = form.querySelector('[data-slug-source]');
    const target = form.querySelector('[data-slug-target]');
    if (!source || !target) {
        return;
    }
    const sync = () => {
        target.placeholder = slugify(source.value) || 'générée depuis le titre';
    };
    source.addEventListener('input', sync);
    sync();
});

// ── Éditeur HTML ───────────────────────────────────────────────────────────
const editor = document.querySelector('[data-editor]');
if (editor) {
    const BLOCKS = {
        ul: '\n<ul>\n    <li>Élément</li>\n    <li>Élément</li>\n</ul>\n',
        blockquote: '\n<blockquote>Citation.</blockquote>\n',
        pre: '\n<pre><code>// code\n</code></pre>\n',
    };
    const replaceSelection = (text, selectFrom = 0, selectTo = text.length) => {
        const { selectionStart: start, selectionEnd: end } = editor;
        editor.focus();
        editor.setRangeText(text, start, end, 'end');
        editor.setSelectionRange(start + selectFrom, start + selectTo);
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    };

    document.querySelectorAll('[data-wrap]').forEach((button) => {
        button.addEventListener('click', () => {
            const tag = button.dataset.wrap;
            const selected = editor.value.slice(editor.selectionStart, editor.selectionEnd) || 'texte';
            const open = `<${tag}>`;
            replaceSelection(`${open}${selected}</${tag}>`, open.length, open.length + selected.length);
        });
    });
    document.querySelectorAll('[data-block]').forEach((button) => {
        button.addEventListener('click', () => replaceSelection(BLOCKS[button.dataset.block]));
    });

    // Compteur de mots et estimation du temps de lecture (200 mots/min)
    const counter = document.querySelector('[data-word-count]');
    const words = () => editor.value.replace(/<[^>]*>/g, ' ').split(/\s+/).filter(Boolean).length;
    const updateCount = () => {
        if (counter) {
            const n = words();
            counter.textContent = `${n.toLocaleString('fr-FR')} mot${n > 1 ? 's' : ''} · environ ${Math.max(1, Math.round(n / 200))} min`;
        }
    };
    editor.addEventListener('input', updateCount);
    updateCount();
    document.querySelector('[data-reading-estimate]')?.addEventListener('click', () => {
        const field = document.getElementById('readingTime');
        field.value = String(Math.max(1, Math.round(words() / 200)));
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // Aperçu : HTML nettoyé côté serveur par le même filtre que l'article publié
    const preview = document.querySelector('[data-preview]');
    const toggle = document.querySelector('[data-preview-toggle]');
    toggle?.addEventListener('click', async () => {
        const showing = toggle.getAttribute('aria-pressed') === 'true';
        if (showing) {
            preview.hidden = true;
            editor.hidden = false;
            toggle.setAttribute('aria-pressed', 'false');
            editor.focus();
            return;
        }
        toggle.disabled = true;
        try {
            const body = new FormData();
            body.append('content', editor.value);
            const response = await fetch(root.dataset.previewUrl, { method: 'POST', body, headers: { 'X-CSRF-Token': csrf } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            // Contenu déjà nettoyé par le serveur (SanitizeHtmlExtension) ; la CSP bloque de toute façon les scripts.
            preview.innerHTML = await response.text();
            preview.hidden = false;
            editor.hidden = true;
            toggle.setAttribute('aria-pressed', 'true');
        } catch {
            announce('Aperçu indisponible. Rechargez la page.', 'error');
        } finally {
            toggle.disabled = false;
        }
    });

    // Ctrl+S / Cmd+S : enregistrer sans quitter la page
    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            document.querySelector('[data-save-stay]')?.click();
        }
    });
}

// ── Modifications non enregistrées ─────────────────────────────────────────
document.querySelectorAll('form[data-dirty-guard]').forEach((form) => {
    let dirty = false;
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => {
        if (dirty) {
            event.preventDefault();
        }
    });
});

// ── Réordonnancement ───────────────────────────────────────────────────────
function initSortable(list) {
    const items = () => [...list.children].filter((el) => el.dataset.id);
    let dragged = null;
    let before = '';

    const renumber = () => items().forEach((item, i) => {
        const pos = item.querySelector(':scope [data-position]');
        if (pos) {
            pos.textContent = String(i + 1);
        }
    });

    const save = async () => {
        const order = items().map((item) => item.dataset.id).join(',');
        if (order === before) {
            return;
        }
        renumber();
        try {
            const response = await fetch(list.dataset.reorderUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ ids: order.split(',').map(Number) }),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.success) {
                throw new Error(result.error || String(response.status));
            }
            before = order;
            announce('Ordre enregistré.');
        } catch (error) {
            announce(`Ordre non enregistré : ${error.message}. Rechargez la page.`, 'error');
        }
    };

    before = items().map((item) => item.dataset.id).join(',');

    // Glisser-déposer (souris) : seulement depuis la poignée
    items().forEach((item) => {
        item.addEventListener('pointerdown', (event) => {
            // La poignée doit appartenir à cet élément (et non à un article imbriqué dans un module).
            item.draggable = event.target.closest('[data-drag-handle]')?.closest('[data-id]') === item;
        });
        item.addEventListener('dragstart', (event) => {
            if (!item.draggable || event.target !== item) {
                return;
            }
            event.stopPropagation();
            dragged = item;
            item.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', item.dataset.id);
        });
        item.addEventListener('dragend', (event) => {
            if (dragged !== item) {
                return;
            }
            event.stopPropagation();
            item.classList.remove('is-dragging');
            dragged = null;
            save();
        });
    });
    list.addEventListener('dragover', (event) => {
        if (!dragged || dragged.parentElement !== list) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const target = items().find((item) => item !== dragged && event.clientY < item.getBoundingClientRect().top + item.getBoundingClientRect().height / 2);
        list.insertBefore(dragged, target ?? null);
    });

    // Boutons monter / descendre (clavier, mobile)
    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-move]');
        if (!button) {
            return;
        }
        const item = button.closest('[data-id]');
        if (item?.parentElement !== list) {
            return;
        }
        const sibling = button.dataset.move === 'up' ? item.previousElementSibling : item.nextElementSibling;
        if (!sibling) {
            return;
        }
        button.dataset.move === 'up' ? list.insertBefore(item, sibling) : list.insertBefore(sibling, item);
        button.focus();
        save();
    });
}

document.querySelectorAll('[data-sortable]').forEach(initSortable);

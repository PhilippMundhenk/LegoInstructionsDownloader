<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/i18n.php';

$downloadsDir = getenv('DOWNLOADS_DIR') ?: '/downloads';
$sets = listSets($downloadsDir);
$cssVer = @filemtime(__DIR__ . '/main.css') ?: 1;
$diag = empty($sets) ? diagnoseDownloads($downloadsDir) : null;
$locale = getLocale();
$jsStrings = jsStrings();
$nSets = count($sets);
$countLabel = $nSets === 1 ? t('count.set', [$nSets]) : t('count.sets', [$nSets]);
?><!doctype html>
<html lang="<?= htmlspecialchars($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="main.css?v=<?= $cssVer ?>">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<title><?= htmlspecialchars(t('app.title')) ?></title>
</head>
<body>
<header class="topbar">
    <div class="brand">
        <img class="brand-mark" src="favicon.svg" alt="">
        <h1><?= htmlspecialchars(t('app.title')) ?></h1>
        <form class="lang-switch" method="get" action="">
            <label class="visually-hidden" for="lang"><?= htmlspecialchars(t('lang.label')) ?></label>
            <select id="lang" name="lang" onchange="this.form.submit()">
            <?php foreach (supportedLocales() as $code => $label): ?>
                <option value="<?= htmlspecialchars($code) ?>"<?= $code === $locale ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
            </select>
        </form>
    </div>
    <form id="download-form" class="download-form" autocomplete="off">
        <label class="visually-hidden" for="set_id"><?= htmlspecialchars(t('form.set_id_label')) ?></label>
        <input
            id="set_id"
            name="set_id"
            type="text"
            inputmode="numeric"
            placeholder="<?= htmlspecialchars(t('form.set_id_placeholder')) ?>"
            required>
        <button id="download-btn" type="submit">
            <span class="btn-label"><?= htmlspecialchars(t('form.download')) ?></span>
            <span class="btn-label-busy" hidden><?= htmlspecialchars(t('form.downloading')) ?></span>
            <span class="spinner" aria-hidden="true"></span>
        </button>
    </form>
    <div class="search-row">
        <input id="search" type="search" placeholder="<?= htmlspecialchars(t('search.placeholder')) ?>" autocomplete="off">
        <span id="count" class="count"><?= htmlspecialchars($countLabel) ?></span>
    </div>
    <div id="status" class="status" role="status" aria-live="polite"></div>
</header>

<main>
<?php if (empty($sets)): ?>
    <?php if ($diag && $diag['kind'] === 'no-sets'): ?>
        <p class="empty"><?= htmlspecialchars(t('empty.no_sets_diag', [$diag['detail']])) ?></p>
    <?php elseif ($diag && $diag['kind'] === 'unreadable'): ?>
        <p class="empty"><?= htmlspecialchars($diag['detail']) ?></p>
    <?php elseif ($diag && $diag['kind'] === 'missing'): ?>
        <p class="empty"><?= htmlspecialchars($diag['detail']) ?>. <?= htmlspecialchars(t('empty.check_mount')) ?></p>
    <?php else: ?>
        <p class="empty"><?= htmlspecialchars(t('empty.no_sets')) ?></p>
    <?php endif; ?>
<?php else: ?>
    <ul class="cards" id="cards">
    <?php foreach ($sets as $set): ?>
        <li class="card"
            data-id="<?= htmlspecialchars($set['id'], ENT_QUOTES) ?>"
            data-title="<?= htmlspecialchars(mb_strtolower($set['title']), ENT_QUOTES) ?>">
            <div class="card-image">
                <?php if ($set['image']): ?>
                    <img loading="lazy" src="<?= htmlspecialchars($set['image'], ENT_QUOTES) ?>" alt="">
                <?php else: ?>
                    <div class="no-image"><?= htmlspecialchars(t('card.no_image')) ?></div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="card-header">
                    <div class="card-meta">
                        <h2 class="card-title"><?= htmlspecialchars($set['title']) ?></h2>
                        <div class="card-id">#<?= htmlspecialchars($set['id']) ?></div>
                    </div>
                    <div class="card-menu">
                        <button class="card-menu-btn"
                                type="button"
                                aria-haspopup="true"
                                aria-expanded="false"
                                aria-label="<?= htmlspecialchars(t('card.menu_for', [$set['id']]), ENT_QUOTES) ?>"
                                title="<?= htmlspecialchars(t('card.more_actions'), ENT_QUOTES) ?>">
                            <span class="dots" aria-hidden="true">&#8942;</span>
                        </button>
                        <div class="card-menu-popover" role="menu" hidden>
                            <button class="card-menu-item card-rename"
                                    role="menuitem"
                                    type="button"><?= htmlspecialchars(t('menu.rename')) ?></button>
                            <?php foreach (externalLinks($set['id']) as $link): ?>
                                <a class="card-menu-item"
                                   role="menuitem"
                                   href="<?= htmlspecialchars($link['url'], ENT_QUOTES) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"><?= htmlspecialchars($link['label']) ?></a>
                            <?php endforeach; ?>
                            <button class="card-menu-item card-menu-item--danger card-delete"
                                    role="menuitem"
                                    type="button"><?= htmlspecialchars(t('menu.delete')) ?></button>
                        </div>
                    </div>
                </div>
                <?php if (!empty($set['instructions'])): ?>
                    <div class="instructions">
                    <?php foreach ($set['instructions'] as $instr): ?>
                        <a class="instruction"
                           href="<?= htmlspecialchars($instr['pdf'], ENT_QUOTES) ?>"
                           target="_blank"
                           rel="noopener">
                            <?php if ($instr['thumb']): ?>
                                <img loading="lazy" src="<?= htmlspecialchars($instr['thumb'], ENT_QUOTES) ?>" alt="">
                            <?php else: ?>
                                <span class="pdf-badge">PDF</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="instructions-empty"><?= htmlspecialchars(t('card.no_instructions')) ?></div>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</main>

<footer class="footer">
    <a href="log.php"><?= htmlspecialchars(t('footer.view_log')) ?></a>
</footer>

<script>
window.I18N = <?= json_encode($jsStrings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
(function () {
    const form    = document.getElementById('download-form');
    const btn     = document.getElementById('download-btn');
    const input   = document.getElementById('set_id');
    const status  = document.getElementById('status');
    const search  = document.getElementById('search');
    const cards   = document.getElementById('cards');
    const count   = document.getElementById('count');
    const topbar  = document.querySelector('.topbar');

    // sprintf-lite: replaces %s and %d (in order) with the provided args.
    function tr(key, ...args) {
        let s = (window.I18N && window.I18N[key]) || key;
        let i = 0;
        return s.replace(/%[sd]/g, () => i < args.length ? String(args[i++]) : '');
    }

    // Collapse the brand + download form once the user scrolls; the search
    // row stays. Big hysteresis (collapse>120, expand<40) keeps the topbar
    // from flickering when its own height change shifts the scroll offset.
    if (topbar) {
        let collapsed = false;
        let ticking = false;
        const apply = () => {
            ticking = false;
            const y = window.scrollY || window.pageYOffset;
            if (!collapsed && y > 120) {
                topbar.classList.add('is-scrolled');
                collapsed = true;
            } else if (collapsed && y < 40) {
                topbar.classList.remove('is-scrolled');
                collapsed = false;
            }
        };
        const updateTopbar = () => {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(apply);
        };
        window.addEventListener('scroll', updateTopbar, { passive: true });
        apply();
    }

    function setBusy(busy) {
        btn.disabled = busy;
        input.disabled = busy;
        btn.classList.toggle('busy', busy);
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function setStatus(msg, ok) {
        status.textContent = msg || '';
        status.className = 'status' + (msg ? (ok ? ' ok' : ' err') : '');
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const value = input.value.trim();
        if (!value) return;
        setBusy(true);
        setStatus(tr('status.downloading', value), true);
        try {
            const res = await fetch('download.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                body: 'set_id=' + encodeURIComponent(value),
            });
            const data = await res.json();
            if (data.ok) {
                setStatus(tr('status.done'), true);
                window.location.reload();
            } else {
                setBusy(false);
                setStatus(data.error || tr('status.download_failed'), false);
            }
        } catch (err) {
            setBusy(false);
            setStatus(tr('status.network_error', err.message), false);
        }
    });

    let items = cards ? Array.from(cards.children) : [];

    function refreshCount() {
        if (!count) return;
        const visible = items.filter(it => it.style.display !== 'none').length;
        count.textContent = tr(visible === 1 ? 'count.set' : 'count.sets', visible);
    }

    if (search && cards) {
        search.addEventListener('input', function () {
            const q = search.value.trim().toLowerCase();
            for (const it of items) {
                const id = it.dataset.id || '';
                const title = it.dataset.title || '';
                const match = !q || id.includes(q) || title.includes(q);
                it.style.display = match ? '' : 'none';
            }
            refreshCount();
        });
    }

    function closeAllMenus(except) {
        document.querySelectorAll('.card-menu-popover').forEach(p => {
            if (p === except) return;
            p.hidden = true;
            const btn = p.parentElement && p.parentElement.querySelector('.card-menu-btn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
    }

    if (cards) {
        cards.addEventListener('click', function (e) {
            const trigger = e.target.closest('.card-menu-btn');
            if (!trigger) return;
            e.stopPropagation();
            const popover = trigger.parentElement.querySelector('.card-menu-popover');
            if (!popover) return;
            const willOpen = popover.hidden;
            closeAllMenus(willOpen ? popover : null);
            popover.hidden = !willOpen;
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.card-menu')) closeAllMenus(null);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllMenus(null);
    });

    if (cards) {
        cards.addEventListener('click', async function (e) {
            const btn = e.target.closest('.card-delete');
            if (!btn) return;
            closeAllMenus(null);
            const card = btn.closest('.card');
            if (!card) return;
            const id = card.dataset.id || '';
            if (!id) return;
            if (!window.confirm(tr('delete.confirm', id))) return;
            btn.disabled = true;
            card.classList.add('deleting');
            try {
                const res = await fetch('delete.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                    body: 'set_id=' + encodeURIComponent(id),
                });
                const data = await res.json();
                if (data.ok) {
                    card.remove();
                    items = items.filter(it => it !== card);
                    refreshCount();
                    setStatus(tr('delete.success', id), true);
                } else {
                    btn.disabled = false;
                    card.classList.remove('deleting');
                    setStatus(data.error || tr('delete.failed'), false);
                }
            } catch (err) {
                btn.disabled = false;
                card.classList.remove('deleting');
                setStatus(tr('status.network_error', err.message), false);
            }
        });
    }

    if (cards) {
        cards.addEventListener('click', function (e) {
            const btn = e.target.closest('.card-rename');
            if (!btn) return;
            closeAllMenus(null);
            const card = btn.closest('.card');
            if (!card) return;
            startRename(card);
        });
    }

    function startRename(card) {
        const titleEl = card.querySelector('.card-title');
        if (!titleEl || card.classList.contains('renaming')) return;
        const original = titleEl.textContent;
        card.classList.add('renaming');

        const form = document.createElement('form');
        form.className = 'rename-form';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'rename-input';
        input.value = original;
        input.maxLength = 200;
        input.setAttribute('aria-label', tr('rename.label'));

        const save = document.createElement('button');
        save.type = 'submit';
        save.className = 'rename-save';
        save.textContent = tr('rename.save');

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'rename-cancel';
        cancel.textContent = tr('rename.cancel');

        form.appendChild(input);
        form.appendChild(save);
        form.appendChild(cancel);
        titleEl.replaceWith(form);
        input.focus();
        input.select();

        const finish = (newTitle) => {
            const h2 = document.createElement('h2');
            h2.className = 'card-title';
            h2.textContent = newTitle;
            form.replaceWith(h2);
            card.classList.remove('renaming');
            if (newTitle !== original) {
                card.dataset.title = newTitle.toLowerCase();
            }
        };

        cancel.addEventListener('click', () => finish(original));
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                finish(original);
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const next = input.value.trim();
            if (!next || next === original) { finish(original); return; }
            save.disabled = true;
            cancel.disabled = true;
            input.disabled = true;
            const id = card.dataset.id || '';
            try {
                const res = await fetch('rename.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                    body: 'set_id=' + encodeURIComponent(id) + '&name=' + encodeURIComponent(next),
                });
                const data = await res.json();
                if (data.ok) {
                    finish(data.name || next);
                    setStatus(tr('rename.success', id), true);
                } else {
                    save.disabled = false;
                    cancel.disabled = false;
                    input.disabled = false;
                    setStatus(data.error || tr('rename.failed'), false);
                }
            } catch (err) {
                save.disabled = false;
                cancel.disabled = false;
                input.disabled = false;
                setStatus(tr('status.network_error', err.message), false);
            }
        });
    }

    // "/" focuses the search box; Esc clears it.
    document.addEventListener('keydown', function (e) {
        const tag = (e.target.tagName || '').toLowerCase();
        const typing = tag === 'input' || tag === 'textarea';
        if (e.key === '/' && !typing && search) {
            e.preventDefault();
            search.focus();
        } else if (e.key === 'Escape' && document.activeElement === search) {
            search.value = '';
            search.dispatchEvent(new Event('input'));
        }
    });
})();
</script>
</body>
</html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$downloadsDir = getenv('DOWNLOADS_DIR') ?: '/downloads';
$sets = listSets($downloadsDir);
$cssVer = @filemtime(__DIR__ . '/main.css') ?: 1;
$diag = empty($sets) ? diagnoseDownloads($downloadsDir) : null;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="main.css?v=<?= $cssVer ?>">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='6' fill='%234f8cff'/%3E%3Ccircle cx='10' cy='12' r='3' fill='%23fff'/%3E%3Ccircle cx='22' cy='12' r='3' fill='%23fff'/%3E%3Crect x='4' y='17' width='24' height='11' rx='2' fill='%23ff8b3d'/%3E%3C/svg%3E">
<title>Lego Manager</title>
</head>
<body>
<header class="topbar">
    <div class="brand">
        <span class="brand-mark"></span>
        <h1>Lego Manager</h1>
    </div>
    <form id="download-form" class="download-form" autocomplete="off">
        <label class="visually-hidden" for="set_id">Set ID</label>
        <input
            id="set_id"
            name="set_id"
            type="text"
            inputmode="numeric"
            placeholder="Set ID (comma-separated for multiple)"
            required>
        <button id="download-btn" type="submit">
            <span class="btn-label">Download</span>
            <span class="spinner" aria-hidden="true"></span>
        </button>
    </form>
    <div class="search-row">
        <input id="search" type="search" placeholder="Search by number or title…" autocomplete="off">
        <span id="count" class="count"><?= count($sets) ?> sets</span>
    </div>
    <div id="status" class="status" role="status" aria-live="polite"></div>
</header>

<main>
<?php if (empty($sets)): ?>
    <?php if ($diag && $diag['kind'] === 'no-sets'): ?>
        <p class="empty">No set directories found — but <?= htmlspecialchars($diag['detail']) ?>.
            They may be missing <code>data.json</code> or <code>name.txt</code> markers,
            or their names don't look like set IDs.</p>
    <?php elseif ($diag && $diag['kind'] === 'unreadable'): ?>
        <p class="empty"><?= htmlspecialchars($diag['detail']) ?></p>
    <?php elseif ($diag && $diag['kind'] === 'missing'): ?>
        <p class="empty"><?= htmlspecialchars($diag['detail']) ?>. Check your volume mount.</p>
    <?php else: ?>
        <p class="empty">No sets yet. Enter a Set ID above to download instructions.</p>
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
                    <div class="no-image">No image</div>
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
                                aria-label="Menu for set <?= htmlspecialchars($set['id'], ENT_QUOTES) ?>"
                                title="More actions">
                            <span class="dots" aria-hidden="true">&#8942;</span>
                        </button>
                        <div class="card-menu-popover" role="menu" hidden>
                            <?php foreach (externalLinks($set['id']) as $link): ?>
                                <a class="card-menu-item"
                                   role="menuitem"
                                   href="<?= htmlspecialchars($link['url'], ENT_QUOTES) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"><?= htmlspecialchars($link['label']) ?></a>
                            <?php endforeach; ?>
                            <button class="card-menu-item card-menu-item--danger card-delete"
                                    role="menuitem"
                                    type="button">Delete set</button>
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
                    <div class="instructions-empty">No instructions found.</div>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</main>

<footer class="footer">
    <a href="log.php">View log</a>
</footer>

<script>
(function () {
    const form    = document.getElementById('download-form');
    const btn     = document.getElementById('download-btn');
    const input   = document.getElementById('set_id');
    const status  = document.getElementById('status');
    const search  = document.getElementById('search');
    const cards   = document.getElementById('cards');
    const count   = document.getElementById('count');
    const topbar  = document.querySelector('.topbar');

    // Collapse the brand + download form once the user scrolls; the search
    // row stays. 8/24 px hysteresis avoids flicker around the threshold.
    if (topbar) {
        let collapsed = false;
        const updateTopbar = () => {
            const y = window.scrollY || window.pageYOffset;
            if (!collapsed && y > 24) {
                topbar.classList.add('is-scrolled');
                collapsed = true;
            } else if (collapsed && y < 8) {
                topbar.classList.remove('is-scrolled');
                collapsed = false;
            }
        };
        window.addEventListener('scroll', updateTopbar, { passive: true });
        updateTopbar();
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
        setStatus('Downloading ' + value + '…', true);
        try {
            const res = await fetch('download.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                body: 'set_id=' + encodeURIComponent(value),
            });
            const data = await res.json();
            if (data.ok) {
                setStatus('Done. Reloading…', true);
                window.location.reload();
            } else {
                setBusy(false);
                setStatus(data.error || 'Download failed. See log.', false);
            }
        } catch (err) {
            setBusy(false);
            setStatus('Network error: ' + err.message, false);
        }
    });

    let items = cards ? Array.from(cards.children) : [];

    function refreshCount() {
        if (!count) return;
        const visible = items.filter(it => it.style.display !== 'none').length;
        count.textContent = visible + (visible === 1 ? ' set' : ' sets');
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
            if (!window.confirm('Delete set ' + id + '? This removes the downloaded files.')) return;
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
                    setStatus('Deleted set ' + id, true);
                } else {
                    btn.disabled = false;
                    card.classList.remove('deleting');
                    setStatus(data.error || 'Delete failed. See log.', false);
                }
            } catch (err) {
                btn.disabled = false;
                card.classList.remove('deleting');
                setStatus('Network error: ' + err.message, false);
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

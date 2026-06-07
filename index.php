<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$downloadsDir = getenv('DOWNLOADS_DIR') ?: '/downloads';
$sets = listSets($downloadsDir);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="main.css">
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
    <p class="empty">No sets yet. Enter a Set ID above to download instructions.</p>
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
                <h2 class="card-title"><?= htmlspecialchars($set['title']) ?></h2>
                <div class="card-id">#<?= htmlspecialchars($set['id']) ?></div>
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

    if (search && cards) {
        const items = Array.from(cards.children);
        search.addEventListener('input', function () {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            for (const it of items) {
                const id = it.dataset.id || '';
                const title = it.dataset.title || '';
                const match = !q || id.includes(q) || title.includes(q);
                it.style.display = match ? '' : 'none';
                if (match) shown++;
            }
            count.textContent = shown + (shown === 1 ? ' set' : ' sets');
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

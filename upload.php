<?php
/**
 * upload.php — single-file GET/POST handler for the timeline uploader.
 *
 * GET  -> renders the form
 * POST -> validates passcode + image, saves the file, appends to timeline.json
 *
 * ── SETUP ──────────────────────────────────────────────────────────────
 * 1. Change $PASSCODE below (or better: set it via an environment variable
 *    on the server instead of hardcoding it).
 * 2. Make sure this script's directory is writable by the PHP process:
 *      - timeline.json must be writable
 *      - an "uploads/" subfolder must exist and be writable (created
 *        automatically on first run if missing, but the parent dir needs
 *        write permission for mkdir to succeed)
 * 3. Drop this file anywhere on your vhost (e.g. /admin/upload.php) —
 *    it doesn't need to be literally named index.php, GET and POST both
 *    hit this same file same as discussed.
 * ──────────────────────────────────────────────────────────────────────
 */

// ── CONFIG ──────────────────────────────────────────────────────────────
$PASSCODE     = getenv('TIMELINE_PASSCODE') ?: 'change-me-1234'; // CHANGE THIS
$JSON_FILE    = __DIR__ . '/timeline.json';
$UPLOAD_DIR   = __DIR__ . '/uploads/';
$UPLOAD_URL   = 'uploads/'; // relative URL prefix stored as imageUrl
$MAX_BYTES    = 8 * 1024 * 1024; // 8MB
$ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
// Date default is always "now" in UTC+7 (Asia/Jakarta), independent of the
// server's own CEST clock and independent of the visitor's browser tz.
$DEFAULT_TZ = 'Asia/Jakarta';

/**
 * Turn a timeline "date" string into a sortable timestamp.
 *
 * Accepts the formats that actually show up in the JSON:
 *   "20 SEPTEMBER 2026"  -> full date
 *   "OCTOBER 2026"       -> month + year (day assumed as the 1st)
 *   "2026-09-20"         -> ISO, just in case
 *
 * Returns 0 for anything unparseable so those entries sink to the bottom
 * instead of poisoning the ordering.
 */
function timeline_date_value($raw) {
    $s = strtoupper(trim((string)$raw));
    if ($s === '') return 0;

    // "20 SEPTEMBER 2026" / "20 SEPT 2026" / "1 SEPTEMBER 2026"
    if (preg_match('/^(\d{1,2})\s+([A-Z]{3,})\s+(\d{4})$/', $s, $m)) {
        $ts = DateTime::createFromFormat('j F Y', $m[1] . ' ' . $m[2] . ' ' . $m[3], new DateTimeZone('UTC'));
        if ($ts !== false) return $ts->getTimestamp();
        $ts = DateTime::createFromFormat('j M Y', $m[1] . ' ' . $m[2] . ' ' . $m[3], new DateTimeZone('UTC'));
        if ($ts !== false) return $ts->getTimestamp();
    }

    // "OCTOBER 2026" -> assume the 1st of the month
    if (preg_match('/^([A-Z]{3,})\s+(\d{4})$/', $s, $m)) {
        $ts = DateTime::createFromFormat('F Y j', $m[1] . ' ' . $m[2] . ' 1', new DateTimeZone('UTC'));
        if ($ts !== false) return $ts->getTimestamp();
        $ts = DateTime::createFromFormat('M Y j', $m[1] . ' ' . $m[2] . ' 1', new DateTimeZone('UTC'));
        if ($ts !== false) return $ts->getTimestamp();
    }

    // "2026-09-20" or "2026/09/20"
    if (preg_match('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$#', $s, $m)) {
        $ts = DateTime::createFromFormat('Y-n-j', $m[1] . '-' . $m[2] . '-' . $m[3], new DateTimeZone('UTC'));
        if ($ts !== false) return $ts->getTimestamp();
    }

    return 0;
}

/**
 * Newest first. Ties keep their existing relative order (PHP's usort has
 * been stable since 8.0), so equal dates don't shuffle around on every save.
 * Entries with no parsable date always land at the very bottom.
 */
function timeline_sort_desc(&$data) {
    // Decorate with the original index so we can break ties manually and
    // stay deterministic even on PHP < 8.
    $decorated = [];
    foreach ($data as $i => $item) {
        $decorated[] = [
            'value' => timeline_date_value(is_array($item) ? ($item['date'] ?? '') : ''),
            'index' => $i,
            'item'  => $item,
        ];
    }

    usort($decorated, function ($a, $b) {
        if ($a['value'] === $b['value']) return $a['index'] <=> $b['index'];
        return $b['value'] <=> $a['value']; // descending
    });

    $data = array_column($decorated, 'item');
}

// ── STATE ───────────────────────────────────────────────────────────────
$errors  = [];
$success = false;
$old = ['title' => '', 'desc' => '', 'tags' => '', 'date' => ''];

if (!is_dir($UPLOAD_DIR)) {
    @mkdir($UPLOAD_DIR, 0755, true);
}

// ── HANDLE POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['title'] = trim($_POST['title'] ?? '');
    $old['desc']  = trim($_POST['desc'] ?? '');
    $old['tags']  = trim($_POST['tags'] ?? '');
    $old['date']  = trim($_POST['date'] ?? '');
    $passcode     = (string)($_POST['passcode'] ?? '');

    if (!hash_equals($PASSCODE, $passcode)) {
        $errors[] = 'incorrect passcode.';
    }
    if ($old['title'] === '') {
        $errors[] = 'title is required.';
    }

    $imgInfo = false;
    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'an image file is required.';
    } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'upload failed (error code ' . (int)$_FILES['image']['error'] . ').';
    } elseif ($_FILES['image']['size'] > $MAX_BYTES) {
        $errors[] = 'image must be smaller than 8MB.';
    } else {
        $imgInfo = @getimagesize($_FILES['image']['tmp_name']);
        if ($imgInfo === false || !isset($ALLOWED_MIME[$imgInfo['mime']])) {
            $errors[] = 'file must be a valid image (jpg, png, gif, or webp).';
        }
    }

    if (empty($errors)) {
        $ext      = $ALLOWED_MIME[$imgInfo['mime']];
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $UPLOAD_DIR . $filename;

        if (!is_dir($UPLOAD_DIR) || !is_writable($UPLOAD_DIR)) {
            $errors[] = 'uploads/ directory is missing or not writable on the server.';
        } elseif (!move_uploaded_file($_FILES['image']['tmp_name'], $destPath)) {
            $errors[] = 'failed to save the uploaded file to the server.';
        } else {
            // Date: use what was submitted, otherwise "now" in UTC+7 as
            // "DAY MONTH YEAR" (e.g. "20 SEPTEMBER 2026").
            if ($old['date'] !== '') {
                $dateStr = strtoupper($old['date']);
            } else {
                $now = new DateTime('now', new DateTimeZone($DEFAULT_TZ));
                $dateStr = strtoupper($now->format('j F Y'));
            }

            // Tags: comma-separated -> ["#TAG", ...]
            $tagsArr = [];
            if ($old['tags'] !== '') {
                foreach (explode(',', $old['tags']) as $t) {
                    $t = trim($t);
                    if ($t === '') continue;
                    if ($t[0] !== '#') $t = '#' . $t;
                    $tagsArr[] = strtoupper($t);
                }
            }

            $entry = [
                'title'    => $old['title'],
                'desc'     => $old['desc'],
                'date'     => $dateStr,
                'tags'     => $tagsArr,
                'imageUrl' => $UPLOAD_URL . $filename,
            ];

            // Append to timeline.json with an exclusive lock so concurrent
            // uploads can't clobber each other. The whole list is re-sorted
            // newest-first on every write, which both puts the new entry on
            // top and repairs any hand-edited/unsorted legacy data.
            $fp = fopen($JSON_FILE, 'c+');
            if ($fp && flock($fp, LOCK_EX)) {
                $content = stream_get_contents($fp);
                $data = json_decode(trim($content) === '' ? '[]' : $content, true);
                if (!is_array($data)) $data = [];
                $data[] = $entry;
                timeline_sort_desc($data);

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);

                $success = true;
                $old = ['title' => '', 'desc' => '', 'tags' => '', 'date' => ''];
            } else {
                if ($fp) fclose($fp);
                // Roll back the saved file since the JSON write failed.
                @unlink($destPath);
                $errors[] = 'could not write to timeline.json — check file permissions.';
            }
        }
    }
}

function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <link href="https://fonts.googleapis.com" rel="preconnect" />
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect" />
    <link
        href="https://fonts.googleapis.com/css2?family=Courier+Prime:ital,wght@0,400;0,700;1,400&family=JetBrains+Mono:wght@400;500;700&family=Press+Start+2P&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap"
        rel="stylesheet"
    />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
        rel="stylesheet"
    />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <title>upload — gensart timeline</title>
    <style>
        @layer base { html, body { margin: 0; padding: 0; } body { overscroll-behavior: none; } }
        ::-webkit-scrollbar { display: none; }
        .pixel-grid {
            background-image: radial-gradient(rgba(0, 232, 216, 0.06) 1px, transparent 0);
            background-size: 20px 20px;
        }
        .dropzone.drag-over { border-color: #00E8D8 !important; background-color: rgba(0,232,216,0.06); }
    </style>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        background: "#0D0D0D",
                        "surface-container-lowest": "#000a30",
                        "surface-container-low": "#00164e",
                        "surface-container": "#001a57",
                        "surface-container-high": "#00236e",
                        "surface-container-highest": "#002c86",
                        "primary-container": "#0D0D0D",
                        "on-surface": "#dce1ff",
                        "on-surface-variant": "#c4c7c7",
                        "pixel-cyan": "#00E8D8",
                        "pixel-yellow": "#F8D800",
                        "pixel-orange": "#F87800",
                        "pixel-white": "#FCFCFC",
                        secondary: "#57e245",
                        tertiary: "#ffb2bc",
                        outline: "#8e9192",
                    },
                    fontFamily: {
                        h1: ['"Press Start 2P"'],
                        "body-md": ['"Press Start 2P"'],
                        "headline-lg": ["Space Mono"],
                        "headline-md": ["Space Mono"],
                        "headline-sm": ["Space Mono"],
                        "body-lg": ["Courier Prime"],
                        "body-sm": ["Courier Prime"],
                        "label-md": ["JetBrains Mono"],
                        "label-sm": ["JetBrains Mono"],
                    },
                },
            },
        };
    </script>
</head>
<body class="bg-background text-on-surface font-body-sm selection:bg-pixel-cyan selection:text-background min-h-screen relative antialiased">
    <div class="fixed inset-0 pixel-grid pointer-events-none opacity-40 z-0"></div>

    <header class="fixed top-0 left-0 w-full z-[100] bg-background/95 border-b border-surface-container-high/60 backdrop-blur-sm">
        <div class="h-16 max-w-5xl mx-auto px-6 flex items-center justify-between">
            <a class="flex items-center gap-2 font-label-md text-pixel-white tracking-wider hover:text-pixel-cyan transition-colors" href="index.html">
                <span class="text-pixel-cyan font-bold">[■]</span>
                <span class="font-bold">gensart</span>
                <span class="inline-block w-2 h-3.5 bg-pixel-cyan animate-pulse"></span>
            </a>
            <a class="font-label-sm text-xs text-on-surface-variant hover:text-pixel-white transition-colors tracking-wide" href="index.html">&larr; back to timeline</a>
        </div>
    </header>

    <main class="relative z-10 w-full pt-28 pb-24 max-w-2xl mx-auto px-6">
        <div class="mb-8">
            <div class="flex items-center gap-2 font-label-sm text-pixel-cyan tracking-widest uppercase mb-2">
                <span>Admin</span><span class="text-on-surface-variant">//</span><span>New entry</span>
            </div>
            <h1 class="font-h1 text-lg md:text-xl text-pixel-white tracking-tight leading-snug">
                Add a <span class="text-pixel-cyan">TIMELINE</span> entry
            </h1>
        </div>

        <?php if ($success): ?>
        <div class="mb-6 p-4 bg-secondary/10 border border-secondary text-secondary font-label-sm text-sm shadow-[4px_4px_0px_#000000]">
            ✓ uploaded and saved to timeline.json.
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="mb-6 p-4 bg-tertiary/10 border border-tertiary text-tertiary font-label-sm text-sm shadow-[4px_4px_0px_#000000] space-y-1">
            <?php foreach ($errors as $err): ?>
                <div>✗ <?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="bg-surface-container-lowest/80 border border-surface-container-highest p-5 md:p-7 shadow-[4px_4px_0px_#000000] space-y-5">

            <!-- Image upload with preview -->
            <div>
                <label class="block font-label-sm text-xs text-pixel-yellow uppercase mb-2">Image *</label>
                <div id="dropzone" class="dropzone border-2 border-dashed border-surface-container-highest hover:border-pixel-cyan/60 transition-colors aspect-[4/3] flex items-center justify-center relative overflow-hidden cursor-pointer bg-surface-container-high">
                    <img id="preview" class="hidden w-full h-full object-cover absolute inset-0" />
                    <div id="dropzone-hint" class="text-center font-body-sm text-on-surface-variant text-sm px-4">
                        <div class="text-2xl mb-1">[+]</div>
                        click or drag an image here<br />
                        <span class="text-xs opacity-70">jpg · png · gif · webp — max 8MB</span>
                    </div>
                    <input type="file" name="image" id="image" accept="image/*" required class="hidden" />
                </div>
            </div>

            <!-- Title -->
            <div>
                <label for="title" class="block font-label-sm text-xs text-pixel-yellow uppercase mb-2">Title *</label>
                <input type="text" name="title" id="title" required value="<?= e($old['title']) ?>"
                    class="w-full bg-surface-container border border-surface-container-highest focus:border-pixel-cyan text-on-surface font-body-sm text-sm px-3 py-2 focus:outline-none focus:ring-0" />
            </div>

            <!-- Description -->
            <div>
                <label for="desc" class="block font-label-sm text-xs text-pixel-yellow uppercase mb-2">Description</label>
                <textarea name="desc" id="desc" rows="3"
                    class="w-full bg-surface-container border border-surface-container-highest focus:border-pixel-cyan text-on-surface font-body-sm text-sm px-3 py-2 focus:outline-none focus:ring-0"><?= e($old['desc']) ?></textarea>
            </div>

            <!-- Tags -->
            <div>
                <label for="tags" class="block font-label-sm text-xs text-pixel-yellow uppercase mb-2">Tags</label>
                <input type="text" name="tags" id="tags" placeholder="GODOT-4, GLSL, RAYCASTING" value="<?= e($old['tags']) ?>"
                    class="w-full bg-surface-container border border-surface-container-highest focus:border-pixel-cyan text-on-surface font-body-sm text-sm px-3 py-2 focus:outline-none focus:ring-0" />
                <p class="text-xs text-on-surface-variant mt-1">comma-separated. "#" is added automatically.</p>
            </div>

            <!-- Date -->
            <div>
                <label for="date" class="block font-label-sm text-xs text-pixel-yellow uppercase mb-2">Date</label>
                <input type="text" name="date" id="date" placeholder="leave blank for today (UTC+7)" value="<?= e($old['date']) ?>"
                    class="w-full bg-surface-container border border-surface-container-highest focus:border-pixel-cyan text-on-surface font-body-sm text-sm px-3 py-2 focus:outline-none focus:ring-0" />
                <p class="text-xs text-on-surface-variant mt-1">format: <span class="text-pixel-cyan">DAY MONTH YEAR</span>, e.g. "20 SEPTEMBER 2026". if left blank, today's date in UTC+7 is used.</p>
            </div>

            <!-- Passcode -->
            <div>
                <label for="passcode" class="block font-label-sm text-xs text-pixel-yellow uppercase mb-2">Passcode *</label>
                <input type="password" name="passcode" id="passcode" required
                    class="w-full bg-surface-container border border-surface-container-highest focus:border-pixel-cyan text-on-surface font-body-sm text-sm px-3 py-2 focus:outline-none focus:ring-0" />
            </div>

            <button type="submit"
                class="w-full font-label-sm text-sm px-4 py-3 bg-pixel-cyan text-background font-bold hover:bg-pixel-yellow transition-colors border border-outline/40">
                UPLOAD & SAVE
            </button>
        </form>
    </main>

    <script>
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('image');
        const preview = document.getElementById('preview');
        const hint = document.getElementById('dropzone-hint');

        function showPreview(file) {
            if (!file || !file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = (e) => {
                preview.src = e.target.result;
                preview.classList.remove('hidden');
                hint.classList.add('hidden');
            };
            reader.readAsDataURL(file);
        }

        dropzone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files[0]) showPreview(fileInput.files[0]);
        });

        ['dragenter', 'dragover'].forEach(evt =>
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropzone.classList.add('drag-over');
            })
        );
        ['dragleave', 'drop'].forEach(evt =>
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropzone.classList.remove('drag-over');
            })
        );
        dropzone.addEventListener('drop', (e) => {
            const file = e.dataTransfer.files[0];
            if (file) {
                fileInput.files = e.dataTransfer.files;
                showPreview(file);
            }
        });
    </script>
</body>
</html>

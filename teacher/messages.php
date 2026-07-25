<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';
require_once __DIR__ . '/../includes/message_service.php';

$teacher = require_teacher_login();

$db      = db();
$user    = current_user();
$meId    = (int) $user['id'];
$sy      = teacher_active_sy($db);
$syLabel = $sy['label'];
$ready   = msg_schema_ready($db);

$convId = to_int($_GET['c'] ?? 0);

// ── AJAX: poll a thread for new messages (keeps the chat live) ───────────────
if ($ready && isset($_GET['poll'])) {
    header('Content-Type: application/json');
    $cid = to_int($_GET['poll']);
    if (!msg_can_access($db, $cid, $meId)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    msg_mark_read($db, $cid, $meId);
    $out = [];
    foreach (msg_thread($db, $cid) as $m) {
        $out[] = [
            'id'   => (int) $m['id'],
            'mine' => (int) $m['sender_id'] === $meId,
            'body' => (string) $m['body'],          // escaped client-side
            'when' => msg_when((string) $m['created_at']),
        ];
    }
    echo json_encode(['messages' => $out, 'unread' => msg_unread_total($db, $meId)]);
    exit;
}

// ── POST: start a chat / send ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('messages.php');
    }
    try {
        if (!$ready) {
            throw new RuntimeException('Run migrations/teacher_messaging.sql first.');
        }
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'start') {
            $other = to_int($_POST['user_id'] ?? 0);
            $cid   = msg_conversation_with($db, $meId, $other);
            redirect_to('messages.php?c=' . $cid);
        }
        if ($action === 'send') {
            $cid = to_int($_POST['c'] ?? 0);
            msg_send($db, $cid, $meId, (string) ($_POST['body'] ?? ''));
            redirect_to('messages.php?c=' . $cid);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('messages.php' . ($convId ? '?c=' . $convId : ''));
    }
}

$inbox    = $ready ? msg_inbox($db, $meId) : [];
$q        = trim((string) ($_GET['q'] ?? ''));
$contacts = ($ready && ($q !== '' || !$inbox)) ? msg_contacts($db, $meId, $q) : [];

$thread = []; $other = null;
if ($ready && $convId > 0) {
    if (!msg_can_access($db, $convId, $meId)) {
        flash_set('error', 'That conversation is not yours.');
        redirect_to('messages.php');
    }
    msg_mark_read($db, $convId, $meId);
    $thread = msg_thread($db, $convId);
    $other  = msg_other_party($db, $convId, $meId);
}

$unread = $ready ? msg_unread_total($db, $meId) : 0;
$flash  = flash_get();
$csrf   = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages | ITFA Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(5,150,105,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .chat-scroll { scrollbar-width: thin; }
        .bubble-me   { background:#059669; color:#fff; border-radius:16px 16px 4px 16px; }
        .bubble-them { background:#fff; color:#0f172a; border:1px solid #e2e8f0; border-radius:16px 16px 16px 4px; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.10),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-emerald-100 shadow-panel p-6 mb-5">
            <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Teacher · Staff Room</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Messages
                <?php if ($unread > 0): ?>
                <span class="align-middle ml-2 text-xs font-extrabold bg-rose-600 text-white rounded-full px-2.5 py-1"><?= $unread ?> new</span>
                <?php endif; ?>
            </h1>
            <p class="text-slate-500 mt-1 text-sm">Message another teacher directly. Conversations are private between the two of you.</p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$ready): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ Messaging tables not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/teacher_messaging.sql</code> first.</p>
        </div>
        <?php else: ?>

        <div class="grid lg:grid-cols-[320px_1fr] gap-5 items-start">

            <!-- ── Left: inbox + find a teacher ── -->
            <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel overflow-hidden">
                <form method="GET" action="messages.php" class="p-4 border-b border-slate-100">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Find a teacher</label>
                    <div class="flex gap-2">
                        <input name="q" value="<?= h($q) ?>" placeholder="Type a name…"
                               class="flex-1 min-w-0 rounded-xl border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-3">Go</button>
                    </div>
                </form>

                <?php if ($contacts): ?>
                <div class="max-h-64 overflow-y-auto chat-scroll border-b border-slate-100">
                    <p class="px-4 pt-3 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-bold">Start a conversation</p>
                    <?php foreach ($contacts as $c): ?>
                    <form method="POST" action="messages.php">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="start">
                        <input type="hidden" name="user_id" value="<?= (int) $c['user_id'] ?>">
                        <button class="w-full text-left px-4 py-2.5 hover:bg-emerald-50 flex items-center gap-3 border-b border-slate-50">
                            <span class="w-9 h-9 shrink-0 rounded-full bg-emerald-100 text-emerald-800 font-extrabold text-xs flex items-center justify-center"><?= h(msg_initials((string) $c['display_name'])) ?></span>
                            <span class="min-w-0">
                                <span class="block text-sm font-bold truncate"><?= h((string) $c['display_name']) ?></span>
                                <span class="block text-xs text-slate-400 truncate"><?= h((string) ($c['Designation'] ?: 'Faculty')) ?></span>
                            </span>
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="max-h-[28rem] overflow-y-auto chat-scroll">
                    <p class="px-4 pt-3 pb-1 text-[10px] uppercase tracking-widest text-slate-400 font-bold">Conversations</p>
                    <?php if (!$inbox): ?>
                    <p class="px-4 py-6 text-sm text-slate-400 text-center">No conversations yet.<br>Search for a teacher above to start one.</p>
                    <?php else: foreach ($inbox as $c):
                        $on = (int) $c['id'] === $convId; $un = (int) $c['unread']; ?>
                    <a href="messages.php?c=<?= (int) $c['id'] ?>"
                       class="flex items-center gap-3 px-4 py-3 border-b border-slate-50 transition-colors <?= $on ? 'bg-emerald-50 border-l-4 border-l-emerald-600' : 'hover:bg-slate-50' ?>">
                        <span class="w-10 h-10 shrink-0 rounded-full bg-emerald-600 text-white font-extrabold text-xs flex items-center justify-center"><?= h(msg_initials((string) $c['display_name'])) ?></span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="text-sm font-bold truncate <?= $un ? 'text-slate-900' : 'text-slate-700' ?>"><?= h((string) $c['display_name']) ?></span>
                                <?php if ($un): ?><span class="ml-auto shrink-0 text-[10px] font-extrabold bg-rose-600 text-white rounded-full px-1.5 py-0.5"><?= $un ?></span><?php endif; ?>
                            </span>
                            <span class="block text-xs truncate <?= $un ? 'text-slate-700 font-semibold' : 'text-slate-400' ?>"><?= h((string) ($c['last_message'] ?: 'No messages yet')) ?></span>
                        </span>
                    </a>
                    <?php endforeach; endif; ?>
                </div>
            </section>

            <!-- ── Right: the conversation ── -->
            <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel flex flex-col" style="min-height:32rem">
                <?php if (!$other): ?>
                <div class="flex-1 flex flex-col items-center justify-center text-center p-10 text-slate-400">
                    <div class="w-16 h-16 rounded-2xl bg-emerald-50 flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    </div>
                    <p class="font-bold text-slate-600">Select a conversation</p>
                    <p class="text-sm mt-1">Or search for a teacher to start a new one.</p>
                </div>
                <?php else: ?>
                <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-100">
                    <span class="w-10 h-10 rounded-full bg-emerald-600 text-white font-extrabold text-xs flex items-center justify-center"><?= h(msg_initials((string) $other['display_name'])) ?></span>
                    <div class="min-w-0">
                        <p class="font-extrabold truncate"><?= h((string) $other['display_name']) ?></p>
                        <p class="text-xs text-slate-400 truncate"><?= h((string) ($other['Designation'] ?: 'Faculty')) ?></p>
                    </div>
                </div>

                <div id="chatBox" class="flex-1 overflow-y-auto chat-scroll px-5 py-4 space-y-3 bg-slate-50/50" style="max-height:26rem">
                    <?php if (!$thread): ?>
                    <p class="text-center text-sm text-slate-400 py-10">No messages yet — say salam 👋</p>
                    <?php endif; ?>
                </div>

                <form method="POST" action="messages.php" class="flex gap-2 p-4 border-t border-slate-100">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="c" value="<?= $convId ?>">
                    <input name="body" id="msgBody" maxlength="<?= MSG_MAX_LEN ?>" autocomplete="off" required
                           placeholder="Write a message…"
                           class="flex-1 min-w-0 rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5">Send</button>
                </form>
                <?php endif; ?>
            </section>
        </div>
        <?php endif; ?>
    </main>
</div>

<script>
const CONV = <?= (int) $convId ?>;
const box  = document.getElementById('chatBox');

// Escape on the client too — message bodies are stored raw and must never be
// injected as HTML. (The server escapes for the no-JS path; this is the JS path.)
const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

function render(msgs) {
    if (!box) { return; }
    if (!msgs.length) {
        box.innerHTML = '<p class="text-center text-sm text-slate-400 py-10">No messages yet — say salam 👋</p>';
        return;
    }
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    box.innerHTML = msgs.map(m => `
        <div class="flex ${m.mine ? 'justify-end' : 'justify-start'}">
            <div class="max-w-[75%]">
                <div class="${m.mine ? 'bubble-me' : 'bubble-them'} px-4 py-2 text-sm leading-snug whitespace-pre-wrap break-words">${esc(m.body)}</div>
                <p class="text-[10px] text-slate-400 mt-1 ${m.mine ? 'text-right' : ''}">${esc(m.when)}</p>
            </div>
        </div>`).join('');
    if (atBottom) { box.scrollTop = box.scrollHeight; }
}

async function poll() {
    if (!CONV) { return; }
    try {
        const r = await fetch('messages.php?poll=' + CONV, { cache: 'no-store' });
        if (!r.ok) { return; }
        const d = await r.json();
        render(d.messages || []);
    } catch (e) { /* offline — try again next tick */ }
}

if (CONV) {
    poll();
    setInterval(poll, 5000);   // light polling; no websockets on shared hosting
    document.getElementById('msgBody')?.focus();
}
</script>
</body>
</html>

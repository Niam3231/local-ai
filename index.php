<?php
session_start();

// Try to load models.json written by start.sh
$models_file = __DIR__ . '/models.json';
$catalog = [];

if (file_exists($models_file)) {
    $raw = @file_get_contents($models_file);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $m) {
            if (isset($m['id'], $m['name'])) {
                $catalog[] = [
                    'id' => $m['id'],
                    'name' => $m['name'],
                    'descr' => $m['descr'] ?? '',
                    'size' => $m['size'] ?? ''
                ];
            }
        }
    }
}

// Fallback minimal catalog if models.json missing or empty
if (count($catalog) === 0) {
    $catalog = [
        ['id'=>'gemma3:4b','name'=>'Gemma 3 4b','descr'=>'Smart and capable','size'=>'3.3 GB'],
        ['id'=>'qwen3:0.6b','name'=>'Qwen 3 0.6b','descr'=>'Fast and reasonable','size'=>'523 MB'],
        ['id'=>'gemma3:270m','name'=>'Gemma 3 270m','descr'=>'Very fast but very dumb','size'=>'292 MB'],
    ];
}

// Build a server-side map used for validation when changing models
$AVAILABLE_MODELS = [];
foreach ($catalog as $m) {
    // display string used server-side (not the UI JSON)
    $AVAILABLE_MODELS[$m['id']] = $m['name'] . ' - ' . $m['descr'];
}

$OLLAMA_URL = 'http://localhost:11434/api/chat';

// Ensure session has a model selected (default to the first available)
if (!isset($_SESSION['model'])) {
    $_SESSION['model'] = array_key_first($AVAILABLE_MODELS) ?: 'gemma3:4b';
}

// Endpoint: set model
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['set_model'])) {
    $model = $_POST['model'] ?? array_key_first($AVAILABLE_MODELS);
    if (isset($AVAILABLE_MODELS[$model])) {
        $_SESSION['model'] = $model;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'model' => $_SESSION['model']]);
    exit;
}

// Endpoint: new conversation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['new'])) {
    $_SESSION['conversation'] = [];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['cleared' => true]);
    exit;
}

// Ensure conversation exists
if (!isset($_SESSION['conversation'])) {
    $_SESSION['conversation'] = [];
}

// Primary POST handler: stream response from Ollama as SSE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // for nginx proxies to disable buffering

    $input = json_decode(file_get_contents('php://input'), true);
    $message = trim($input['message'] ?? '');

    if ($message === '') {
        echo "event: error\ndata: " . json_encode(['error' => 'Empty message']) . "\n\n";
        @ob_flush(); flush();
        exit;
    }

    $model = $_SESSION['model'] ?? array_key_first($AVAILABLE_MODELS);

    // append user message to session conversation
    $_SESSION['conversation'][] = ['role' => 'user', 'content' => $message];

    $payload = [
        'model' => $model,
        'messages' => $_SESSION['conversation'],
        'stream' => true
    ];

    $ch = curl_init($OLLAMA_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    // Write callback: parse Ollama streaming lines and forward token events to client
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) {
        foreach (explode("\n", $chunk) as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'data: [DONE]') continue;
            $json = json_decode($line, true);
            if (!$json || !isset($json['message']['content'])) continue;
            $token = $json['message']['content'];
            echo "event: token\ndata: " . json_encode($token) . "\n\n";
            @ob_flush(); flush();
        }
        return strlen($chunk);
    });
    curl_exec($ch);
    curl_close($ch);

    // Final done event
    echo "event: done\ndata: {}\n\n";
    @ob_flush(); flush();
    exit;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Ollama Live Chat</title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<style>
html, body {
    height: 100%;
    width: 100vw;
    margin: 0;
    padding: 0;
    background: #101217;
    color: #f3f4fa;
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 16px;
    overflow: hidden;
}
body, #chat-container {
    min-height: 100dvh;
    min-width: 100vw;
    height: 100dvh;
    width: 100vw;
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
}
#chat-container {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    height: 100dvh;
    width: 100vw;
    background: #181b20;
    position: fixed;
    top: 0; left: 0;
    z-index: 1;
    box-sizing: border-box;
}
#chat-header {
    background: #222635;
    padding: 0.7em 2vw;
    font-size: clamp(1.03em, 1vw + 1em, 1.13em);
    font-weight: bold;
    border-bottom: 1px solid #24272d;
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-height: 42px;
    width: 100vw;
    box-sizing: border-box;
    letter-spacing: 0.02em;
}
#chat-header .brand {
    color: #68a3ff;
    font-size: 0.87em;
    margin-left: 8px;
}
#chat-header .header-controls {
    display: flex;
    gap: 1vw;
    align-items: center;
}
#new-btn {
    background: #68a3ff;
    color: #fff;
    border: none;
    border-radius: 9px;
    font-size: 0.92em;
    font-weight: 500;
    padding: 0.33em 1.18em;
    cursor: pointer;
    transition: background 0.18s;
    box-shadow: 0 1px 2px #0002;
    outline: none;
    margin-left: 0.5em;
}
#new-btn:hover { background: #2563eb; }
#model-select {
    background: #191c21;
    color: #68a3ff;
    border: 1.5px solid #68a3ff;
    border-radius: 7px;
    font-size: 0.92em;
    font-weight: 600;
    padding: 0.22em 0.8em;
    outline: none;
    margin-right: 1em;
    margin-left: 0.4em;
}
#model-select:focus { border-color: #2563eb;}
#chat-box {
    flex: 1 1 auto;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 3vh 0 2vh 0;
    background: #181b20;
    display: flex;
    flex-direction: column;
    gap: 2.5vh;
    width: 100vw;
    box-sizing: border-box;
    min-height: 0;
}
.msg {
    width: 100%;
    display: flex;
    justify-content: flex-start;
    align-items: flex-start;
}
.msg.user { justify-content: flex-end; }
.bubble {
    max-width: 92vw;
    width: fit-content;
    padding: 2.5vh 3vw;
    border-radius: 18px;
    font-size: min(4.5vw, 1.09em);
    line-height: 1.6;
    word-break: break-word;
    white-space: pre-wrap;
    box-shadow: 0 2px 16px #0002;
    background: #222635;
    color: #f3f4fa;
    position: relative;
}
.msg.user .bubble {
    background: linear-gradient(120deg, #2563eb 70%, #3683ff);
    color: #fff;
}
.reasoning-anim {
    color: #fffb;
    background: #232945;
    border-radius: 12px;
    padding: 0.6em 1.1em;
    margin: 0.3em 0;
    font-size: 1em;
    font-family: 'Fira Mono', 'Consolas', monospace;
    box-shadow: 0 2px 8px #0e2568a0;
    display: inline-flex;
    align-items: center;
    gap: 0.75em;
    letter-spacing: 0.04em;
    border-left: 3px solid #68a3ff;
    border-right: 3px solid #68a3ff;
    transition: background 0.3s;
    animation: reasoning-glow 1.3s infinite alternate;
}
@keyframes reasoning-glow {
    0% { box-shadow: 0 2px 8px #0e2568a0; }
    100% { box-shadow: 0 2px 22px #68a3ff77; background: #24325e; }
}
.reasoning-anim .reason-icon {
    font-size: 1.14em;
    color: #68a3ff;
    animation: reasoning-icon-bounce 1.4s infinite alternate;
}
@keyframes reasoning-icon-bounce {
    0% { transform: scale(1); }
    40% { transform: scale(1.17); }
    100% { transform: scale(1); }
}
.reasoning-anim .reason-dots {
    display: inline-block;
    font-size: 1.2em;
    letter-spacing: 0.18em;
    color: #68a3ff;
    animation: reasoning-dot-blink 1.1s infinite;
}
@keyframes reasoning-dot-blink {
    0%,100% { opacity: 0.7; }
    50% { opacity: 1; }
}

#input-area {
    display: flex;
    flex-direction: row;
    gap: 2vw;
    padding: 2.5vh 4vw 2.5vh 4vw;
    background: #181b20;
    border-top: 1px solid #23283a;
    width: 100vw;
    min-height: min(12vh, 90px);
    box-sizing: border-box;
}
#msg {
    flex: 1 1 auto;
    background: #191c21;
    color: #f3f4fa;
    border: 1px solid #23283a;
    border-radius: 10px;
    font-size: min(4.2vw,1.08em);
    padding: 1.6vh 2vw;
    resize: none;
    min-height: 5vh;
    max-height: 24vh;
    box-shadow: 0 1px 4px #0002;
    outline: none;
    transition: border 0.15s;
}
#msg:focus { border: 1.5px solid #68a3ff; }
#send-btn {
    background: linear-gradient(90deg, #68a3ff 60%, #2563eb);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: min(4vw,1.08em);
    font-weight: bold;
    padding: 0 6vw;
    cursor: pointer;
    transition: background 0.18s;
    box-shadow: 0 1px 2px #0002;
    outline: none;
    min-height: 5vh;
}
#send-btn:hover { background: linear-gradient(90deg, #2563eb, #68a3ff 90%);}

#thinking-anim {
    color: #68a3ff;
    font-weight: 500;
    font-size: 1em;
    white-space: nowrap;
    display: inline-block;
    vertical-align: middle;
    letter-spacing: 0.04em;
}
@keyframes blink {
    0%, 20% { opacity: 1; }
    40% { opacity: .2; }
    60% { opacity: .2; }
    80%, 100% { opacity: 1; }
}
#thinking-anim .dot {
    display: inline-block;
    width: 0.75em;
    animation: blink 1.2s infinite both;
}
#thinking-anim .dot:nth-child(2) { animation-delay: 0.25s; }
#thinking-anim .dot:nth-child(3) { animation-delay: 0.5s; }

@media (max-width: 900px) {
    #chat-header {
        font-size: 1.13em;
        padding: 2vh 2vw;
        min-height: 44px;
    }
    #model-select, #new-btn { font-size: 1em; }
}
@media (max-width: 600px) {
    #chat-header, #input-area { padding-left: 2vw; padding-right: 2vw; }
    #chat-header { font-size: 1.09em; min-height: 44px; }
    #chat-box { font-size: 1em; gap: 2vh; }
    #input-area { min-height: 55px; }
    .bubble {
        padding: 2vh 2vw;
        font-size: min(5vw, 1em);
        max-width: 96vw;
    }
    #msg { font-size: min(5vw,1em); padding: 1.2vh 2vw;}
    #send-btn, #new-btn, #model-select { padding: 0 4vw; font-size: min(5vw,1em);}
    #model-select { margin-right: 0.5em; }
    .reasoning-anim {
        font-size: 0.97em;
        padding: 0.54em 0.7em;
    }
}
@media (max-width: 350px) {
    #chat-header, #input-area { padding: 1vw; }
    .bubble { padding: 1.5vh 1vw; font-size: 0.95em;}
    #send-btn, #new-btn, #model-select { padding: 0 2vw; }
}
</style>
</head>
<body>
<div id="chat-container">
    <div id="chat-header">
        <span>Ollama Live Chat <span class="brand" id="current-model-label"></span></span>
        <span class="header-controls">
          <select id="model-select" title="Choose model"></select>
          <button id="new-btn" onclick="startNewChat()" title="Start a new chat">New Chat</button>
        </span>
    </div>
    <div id="chat-box"></div>
    <form id="input-area" onsubmit="send();return false;" autocomplete="off">
        <textarea id="msg" placeholder="Type your message..." autocomplete="off"></textarea>
        <button id="send-btn" type="submit">Send</button>
    </form>
</div>

<script>
// Catalog provided by PHP (from models.json generated by start.sh)
const CATALOG = <?php echo json_encode($catalog, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
let currentModel = <?php echo json_encode($_SESSION['model'] ?? array_key_first($AVAILABLE_MODELS)); ?>;

const chatBox = document.getElementById('chat-box');
const msgInput = document.getElementById('msg');
const modelSelect = document.getElementById('model-select');
const currentModelLabel = document.getElementById('current-model-label');
let sending = false;
let thinkingAnimInterval = null;

// Populate model dropdown: UI shows only Name and Description, tooltip has full info
function populateModelSelect() {
    modelSelect.innerHTML = '';
    for (const m of CATALOG) {
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.name + ' — ' + m.descr; // visible in dropdown per user request
        opt.title = `${m.id} • Size: ${m.size}`;    // full info on hover
        if (m.id === currentModel) opt.selected = true;
        modelSelect.appendChild(opt);
    }
    updateCurrentModelLabel();
}

function updateCurrentModelLabel() {
    const m = CATALOG.find(x => x.id === currentModel);
    if (m) {
        currentModelLabel.textContent = `${m.name} — ${m.descr}`;
    } else {
        currentModelLabel.textContent = '';
    }
}

modelSelect && modelSelect.addEventListener('change', async function() {
    const selected = modelSelect.value;
    if (selected === currentModel) return;
    const form = new FormData();
    form.append('model', selected);
    await fetch('?set_model=1', {method: 'POST', body: form});
    currentModel = selected;
    updateCurrentModelLabel();
});

populateModelSelect();

// Session-local conversation (kept in JS for UI; server keeps canonical conversation in session too)
let conversation = [];

function renderConversation() {
    chatBox.innerHTML = '';
    for (const m of conversation) {
        addMsg(m.role, m.content);
    }
    chatBox.scrollTop = chatBox.scrollHeight;
}

function addMsg(role, text) {
    const msgDiv = document.createElement('div');
    msgDiv.className = 'msg ' + role;
    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.textContent = text;
    msgDiv.appendChild(bubble);
    chatBox.appendChild(msgDiv);
    chatBox.scrollTop = chatBox.scrollHeight;
    return bubble;
}

function addThinkingAnimation(bubble) {
    bubble.innerHTML = `<span id="thinking-anim">Thinking<span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></span>`;
    chatBox.scrollTop = chatBox.scrollHeight;
    const dots = bubble.querySelectorAll('.dot');
    let dotCount = 0;
    if (thinkingAnimInterval) clearInterval(thinkingAnimInterval);
    thinkingAnimInterval = setInterval(() => {
        dotCount = (dotCount + 1) % 4;
        dots.forEach((d, i) => {
            d.style.visibility = i < dotCount ? 'visible' : 'hidden';
        });
        if (dotCount === 0) dots.forEach((d,i)=>d.style.visibility = (i===0 ? 'visible':'hidden'));
    }, 380);
    return () => {
        bubble.innerHTML = '';
        if (thinkingAnimInterval) clearInterval(thinkingAnimInterval);
        thinkingAnimInterval = null;
    };
}

// Reasoning animation for <think>...</think> region
function addReasoningAnimation(parent, innerText = '') {
    let old = parent.querySelector('.reasoning-anim');
    if (old) parent.removeChild(old);
    const anim = document.createElement('span');
    anim.className = 'reasoning-anim';
    anim.innerHTML = `<span class="reason-icon">🧠</span><span>${innerText || 'Reasoning...'}</span><span class="reason-dots">...</span>`;
    parent.appendChild(anim);
    chatBox.scrollTop = chatBox.scrollHeight;
    return anim;
}
function removeReasoningAnimation(parent) {
    let old = parent.querySelector('.reasoning-anim');
    if (old) parent.removeChild(old);
}

async function send() {
    if (sending) return;
    const msg = msgInput.value.trim();
    if (!msg) return;
    sending = true;

    conversation.push({role: 'user', content: msg});
    addMsg('user', msg);
    msgInput.value = '';
    msgInput.disabled = true;

    const bubble = addMsg('assistant', '');
    let removeThinking = addThinkingAnimation(bubble);
    let gotToken = false;
    let reasoningActive = false;
    let reasoningBuffer = '';

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({message: msg})
    });

    if (!res.body) {
        removeThinking();
        bubble.textContent = 'Error: no response body from server';
        msgInput.disabled = false;
        sending = false;
        return;
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const {done, value} = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, {stream: true});
        let e;
        while ((e = buffer.indexOf('\n\n')) !== -1) {
            const eventBlock = buffer.slice(0, e).trim();
            buffer = buffer.slice(e + 2);

            let lines = eventBlock.split('\n');
            let eventType = 'message';
            let data = '';
            for (let line of lines) {
                if (line.startsWith('event:')) {
                    eventType = line.slice(6).trim();
                } else if (line.startsWith('data:')) {
                    data += line.slice(5).trim() + '\n';
                }
            }
            data = data.trim();

            if (eventType === 'token') {
                let token = '';
                try { token = JSON.parse(data); } catch { token = data; }
                if (!gotToken) {
                    removeThinking();
                    gotToken = true;
                }

                // Reasoning detection and animation
                if (!reasoningActive && token.includes('<think>')) {
                    reasoningActive = true;
                    reasoningBuffer = '';
                    token = token.replace('<think>', '');
                    removeReasoningAnimation(bubble);
                    addReasoningAnimation(bubble, 'Reasoning...');
                }
                if (reasoningActive) {
                    if (token.includes('</think>')) {
                        token = token.replace('</think>', '');
                        let reasonAnim = bubble.querySelector('.reasoning-anim');
                        if (reasonAnim) reasonAnim.innerHTML = `<span class="reason-icon">🧠</span><span>${reasoningBuffer.trim()}</span>`;
                        setTimeout(() => removeReasoningAnimation(bubble), 700);
                        reasoningActive = false;
                        reasoningBuffer = '';
                        bubble.textContent += token;
                    } else {
                        reasoningBuffer += token;
                        let reasonAnim = bubble.querySelector('.reasoning-anim');
                        if (reasonAnim) reasonAnim.innerHTML = `<span class="reason-icon">🧠</span><span>${reasoningBuffer.trim() || 'Reasoning...'}</span><span class="reason-dots">...</span>`;
                    }
                } else {
                    bubble.textContent += token;
                }
                chatBox.scrollTop = chatBox.scrollHeight;
            } else if (eventType === 'done') {
                // conversation finished: append assistant to local conversation store
                conversation.push({role: 'assistant', content: bubble.textContent});
                sending = false;
                msgInput.disabled = false;
                msgInput.focus();
            } else if (eventType === 'error') {
                sending = false;
                removeThinking();
                bubble.textContent = 'Error: ' + data;
                msgInput.disabled = false;
                msgInput.focus();
            }
        }
    }

    if (!gotToken) removeThinking();
}

function startNewChat() {
    fetch('?new=1', {method:'POST'}).then(()=> {
        conversation = [];
        document.getElementById('chat-box').innerHTML = '';
        msgInput.value = '';
        msgInput.disabled = false;
        msgInput.focus();
    });
}

window.onload = () => {
    renderConversation();
    msgInput.focus();
};
msgInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
    }
});
const observer = new MutationObserver(() => {
    chatBox.scrollTop = chatBox.scrollHeight;
});
observer.observe(chatBox, { childList: true });
</script>
</body>
</html>

<?php
session_start();

// ----------------- CONFIG -----------------
$models_file = __DIR__ . '/models.json';
$OLLAMA_URL = 'http://localhost:11434/api/chat';

// ----------------- KNOWN MODELS METADATA -----------------
// Edit/add descriptions here as you get new model IDs
$MODEL_INFO = [
    'smollm2:135m'     => ['name'=>'SmolLM2 135m', 'descr'=>"Its very dumb. But extremely fast, even on phones it generates at the speed of 2 times ChatGPT. You could call it a RageBait-AI because it's very dumb.", 'size'=>'271 MB'],
    'gemma3:270m'     => ['name'=>'Gemma 3 270m', 'descr'=>"With its low parameters it very dumb. But its very fast, even on phones it generates at the speed of ChatGPT.", 'size'=>'292 MB'],
    'qwen3:0.6b'      => ['name'=>'Qwen 3 0.6b', 'descr'=>"With its low parameters it generates fast. But because of its reasoning capability it's not dumb and can detect differences between different subjects.", 'size'=>'523 MB'],
    'deepseek-r1:1.5b'=> ['name'=>'DeepSeek R1 1.5b', 'descr'=>"For low-end Laptops/PC even phones. It's a bit smarter than Qwen 3 0.6 and slower. But can also reason.", 'size'=>'1.1 GB'],
    'gemma3:4b'       => ['name'=>'Gemma 3 4b', 'descr'=>"Despite its high parameters it can run on a single GPU in many configs—very capable.", 'size'=>'3.3 GB'],
    'deepseek-r1:7b'  => ['name'=>'DeepSeek R1 7b', 'descr'=>"For mid-end laptops / powerful phones. Smart and can reason.", 'size'=>'4.7 GB'],
    'deepseek-r1:14b' => ['name'=>'DeepSeek R1 14b', 'descr'=>"For mid/high-end machines. Will need memory/VRAM.", 'size'=>'9.0 GB'],
    'deepseek-r1:32b' => ['name'=>'DeepSeek R1 32b', 'descr'=>"Very large model — needs high-end GPU. Most people don't need this.", 'size'=>'20 GB'],
];

// ----------------- READ & ENRICH models.json -----------------
$catalog = [];
if (file_exists($models_file)) {
    $raw = file_get_contents($models_file);
    // remove possible BOM and control whitespace
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $raw = trim($raw);
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach ($decoded as $m) {
            if (!empty($m['id'])) {
                $id = $m['id'];
                $info = $MODEL_INFO[$id] ?? ['name' => $id, 'descr' => 'No description available.', 'size' => 'Unknown'];
                $catalog[] = array_merge(['id' => $id], $info);
            }
        }
    } else {
        error_log("models.json parse error: " . json_last_error_msg());
    }
} else {
    error_log("models.json not found at $models_file");
}

// Fallback if nothing found (should rarely happen)
if (count($catalog) === 0) {
    $catalog = [
        ['id'=>'gemma3:4b','name'=>'Gemma 3 4b','descr'=>'Smart and capable','size'=>'3.3 GB'],
        ['id'=>'qwen3:0.6b','name'=>'Qwen 3 0.6b','descr'=>'Fast and reasonable','size'=>'523 MB'],
        ['id'=>'gemma3:270m','name'=>'Gemma 3 270m','descr'=>'Very fast but very dumb','size'=>'292 MB'],
    ];
}

// server-side quick lookup for validation
$AVAILABLE_MODELS = [];
foreach ($catalog as $m) $AVAILABLE_MODELS[$m['id']] = $m['name'] . ' - ' . $m['descr'];

// ensure defaults
if (!isset($_SESSION['model'])) $_SESSION['model'] = array_key_first($AVAILABLE_MODELS) ?: $catalog[0]['id'];
if (!isset($_SESSION['conversation'])) $_SESSION['conversation'] = [];

// ----------------- ENDPOINTS -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) set model
    if (isset($_GET['set_model'])) {
        $model = $_POST['model'] ?? array_key_first($AVAILABLE_MODELS);
        if (isset($AVAILABLE_MODELS[$model])) $_SESSION['model'] = $model;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true, 'model'=>$_SESSION['model']]);
        exit;
    }

    // 2) new conversation
    if (isset($_GET['new'])) {
        $_SESSION['conversation'] = [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['cleared'=>true]);
        exit;
    }

    // 3) append assistant (client should call this after it finishes streaming assistant tokens)
    if (isset($_GET['append_assistant'])) {
        $input = json_decode(file_get_contents('php://input'), true);
        $content = trim($input['content'] ?? '');
        if ($content !== '') {
            $_SESSION['conversation'][] = ['role' => 'assistant', 'content' => $content];
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true]);
            exit;
        }
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['ok'=>false, 'error'=>'Empty content']);
        exit;
    }

    // 4) main streaming chat endpoint: server acts as proxy to Ollama and forwards SSE-style events to the client
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $input = json_decode(file_get_contents('php://input'), true);
    $message = trim($input['message'] ?? '');
    if ($message === '') {
        echo "event: error\ndata:" . json_encode(['error'=>'Empty message']) . "\n\n";
        @ob_flush(); flush();
        exit;
    }

    // append user message to server session conversation for context
    $_SESSION['conversation'][] = ['role' => 'user', 'content' => $message];

    $model = $_SESSION['model'] ?? array_key_first($AVAILABLE_MODELS);
    $payload = ['model' => $model, 'messages' => $_SESSION['conversation'], 'stream' => true];

    $ch = curl_init($OLLAMA_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    // Make sure HTTP/1.1 for chunked streaming compatibility in some environments
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) {
        // Ollama streams lines of JSON (one per token). We forward them as event: token with JSON-encoded payload
        // Each chunk may contain many lines or partial lines; explode on newline and forward any valid JSON token lines.
        foreach (explode("\n", $chunk) as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'data: [DONE]') continue;
            $json = json_decode($line, true);
            if (!$json) continue;
            // Ollama token path may vary; we expect message.content as string
            $token = $json['message']['content'] ?? null;
            if ($token === null) continue;
            // forward token as SSE event with JSON-encoded payload (client will JSON.parse)
            echo "event: token\ndata: " . json_encode($token) . "\n\n";
            @ob_flush(); flush();
        }
        return strlen($chunk);
    });

    curl_exec($ch);
    curl_close($ch);

    // final event
    echo "event: done\ndata: {}\n\n";
    @ob_flush(); flush();
    exit;
}

// ----------------- NORMAL PAGE -----------------
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Ollama Live Chat</title>
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<style>
/* layout */
html,body{margin:0;padding:0;height:100%;width:100vw;font-family:'Segoe UI',Arial,sans-serif;background:#101217;color:#f3f4fa;overflow:hidden;}
#chat-container{position:fixed;inset:0;display:flex;flex-direction:column;background:#181b20;}
/* header */
#chat-header{background:#222635;padding:.7em 2vw;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #24272d;flex-wrap:wrap;gap:.4em;}
#chat-header .brand{color:#68a3ff;font-size:.9em;margin-left:6px;max-width:60vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;}
.header-controls{display:flex;gap:0.8em;align-items:center;flex-wrap:wrap;}
#model-select{background:#191c21;color:#68a3ff;border:1.5px solid #68a3ff;border-radius:8px;padding:.28em .9em;font-weight:600;outline:none;}
#new-btn{background:#68a3ff;color:#fff;border:none;border-radius:9px;padding:.3em .9em;cursor:pointer;}
#new-btn:hover{background:#2563eb;}
/* chat area */
#chat-box{flex:1;overflow-y:auto;padding:3vh 2vw;box-sizing:border-box;display:flex;flex-direction:column;gap:1.2rem;}
.msg{display:flex;align-items:flex-start;}
.msg.user{justify-content:flex-end;}
.bubble{max-width:92vw;background:#222635;padding:12px 16px;border-radius:14px;box-shadow:0 2px 12px #0002;white-space:pre-wrap;word-break:break-word;font-size:1rem;line-height:1.5;}
.msg.user .bubble{background:linear-gradient(120deg,#2563eb 40%,#3683ff);color:#fff;}
/* input */
#input-area{display:flex;gap:12px;padding:12px 16px;border-top:1px solid #23283a;align-items:center;}
#msg{flex:1;background:#191c21;border:1px solid #23283a;border-radius:10px;padding:10px 12px;color:#f3f4fa;min-height:44px;max-height:200px;resize:none;outline:none;}
#send-btn{background:linear-gradient(90deg,#68a3ff 60%,#2563eb);color:#fff;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-weight:700;}
/* thinking / reasoning small styles */
.reasoning-anim{display:inline-flex;align-items:center;gap:.6em;background:#232945;padding:6px 10px;border-radius:10px;border-left:3px solid #68a3ff;color:#cfe8ff;}
@media (max-width:600px){
  #chat-header{flex-direction:column;align-items:flex-start;}
  #chat-header .brand{white-space:normal;max-width:100%;}
  .header-controls{width:100%;justify-content:space-between;}
}
</style>
</head>
<body>
<div id="chat-container">
  <div id="chat-header">
    <div><strong>Ollama Live Chat</strong> <span class="brand" id="current-model-label"></span></div>
    <div class="header-controls">
      <select id="model-select" title="Choose model"></select>
      <button id="new-btn" title="Start a new chat">New Chat</button>
    </div>
  </div>

  <div id="chat-box" aria-live="polite"></div>

  <form id="input-area" onsubmit="sendMessage(event)" autocomplete="off">
    <textarea id="msg" placeholder="Type your message..." autocomplete="off" rows="2"></textarea>
    <button id="send-btn" type="submit">Send</button>
  </form>
</div>

<script>
// --- server-provided data ---
const CATALOG = <?php echo json_encode($catalog, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
let currentModel = <?php echo json_encode($_SESSION['model']); ?>;
let serverConversation = <?php echo json_encode($_SESSION['conversation'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?> || [];

// --- UI refs ---
const chatBox = document.getElementById('chat-box');
const msgInput = document.getElementById('msg');
const modelSelect = document.getElementById('model-select');
const currentModelLabel = document.getElementById('current-model-label');
const newBtn = document.getElementById('new-btn');
let sending = false;

// --- populate models ---
function populateModelSelect(){
  modelSelect.innerHTML = '';
  for (const m of CATALOG) {
    const opt = document.createElement('option');
    opt.value = m.id;
    opt.textContent = m.name + ' — ' + m.descr;
    opt.title = `${m.id} • Size: ${m.size}`;
    if (m.id === currentModel) opt.selected = true;
    modelSelect.appendChild(opt);
  }
  updateCurrentModelLabel();
}
function updateCurrentModelLabel(){
  const m = CATALOG.find(x => x.id === currentModel);
  currentModelLabel.textContent = m ? `${m.name} — ${m.descr}` : '';
}
modelSelect.addEventListener('change', async () => {
  const selected = modelSelect.value;
  if (selected === currentModel) return;
  const form = new FormData();
  form.append('model', selected);
  await fetch('?set_model=1', { method: 'POST', body: form });
  currentModel = selected;
  updateCurrentModelLabel();
});
newBtn.addEventListener('click', async () => {
  await fetch('?new=1', { method: 'POST' });
  serverConversation = [];
  chatBox.innerHTML = '';
  msgInput.value = '';
  msgInput.disabled = false;
  msgInput.focus();
});

// --- rendering helpers ---
function addBubble(role, text){
  const wr = document.createElement('div');
  wr.className = 'msg ' + role;
  const b = document.createElement('div');
  b.className = 'bubble';
  b.textContent = text;
  wr.appendChild(b);
  chatBox.appendChild(wr);
  chatBox.scrollTop = chatBox.scrollHeight;
  return b;
}
function renderConversationFromServer(){
  chatBox.innerHTML = '';
  for (const m of serverConversation) addBubble(m.role, m.content);
  chatBox.scrollTop = chatBox.scrollHeight;
}

// --- streaming send logic ---
function safeJSONParse(s) {
  try { return JSON.parse(s); } catch { return undefined; }
}

function typeTextSmoothly(container, text, speed = 30) {
  return new Promise(resolve => {
    let i = 0;
    function type() {
      if (i < text.length) {
        container.textContent += text.charAt(i);
        i++;
        setTimeout(type, speed);
      } else {
        resolve();
      }
    }
    type();
  });
}

async function sendMessage(e){
  if (e) e.preventDefault();
  if (sending) return;
  const msg = msgInput.value.trim();
  if (!msg) return;
  sending = true;
  msgInput.value = '';
  msgInput.disabled = true;

  // append user message locally & to chat
  serverConversation.push({ role:'user', content: msg });
  addBubble('user', msg);

  // assistant bubble to fill while streaming
  const assistantBubble = addBubble('assistant', '');

  // Add a loading message immediately:
  const loadingSpan = document.createElement('span');
  loadingSpan.style.fontStyle = 'italic';
  loadingSpan.style.opacity = '0.7';
  loadingSpan.textContent = 'Preparing response...';
  assistantBubble.appendChild(loadingSpan);
  chatBox.scrollTop = chatBox.scrollHeight;

  try {
    const res = await fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: msg })
    });

    if (!res.body) throw new Error('No response body from server');

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let gotAnyToken = false;
    let reasoning = false;
    let reasoningBuffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });

      let idx;
      while ((idx = buffer.indexOf("\n\n")) !== -1) {
        const block = buffer.slice(0, idx).trim();
        buffer = buffer.slice(idx + 2);
        if (!block) continue;

        let eventType = 'message';
        let dataLines = [];
        for (const line of block.split("\n")) {
          if (line.startsWith('event:')) eventType = line.slice(6).trim();
          else if (line.startsWith('data:')) dataLines.push(line.slice(5).trim());
        }
        const dataStr = dataLines.join("\n").trim();

        if (eventType === 'token') {
          let token = safeJSONParse(dataStr);
          if (typeof token === 'undefined') token = dataStr;

          if (!gotAnyToken) {
            gotAnyToken = true;
            // Remove loading message on first token
            if (loadingSpan && loadingSpan.parentNode) loadingSpan.parentNode.removeChild(loadingSpan);
          }

          // Reasoning logic unchanged...
          if (!reasoning && typeof token === 'string' && token.includes('<think>')) {
            reasoning = true;
            token = token.replace('<think>', '');
            reasoningBuffer = '';
            assistantBubble.appendChild(createReasoningAnim('Reasoning...'));
          }

          if (reasoning) {
            if (typeof token === 'string' && token.includes('</think>')) {
              token = token.replace('</think>', '');
              reasoningBuffer += token;
              const reasonElem = assistantBubble.querySelector('.reasoning-anim');
              if (reasonElem) reasonElem.textContent = reasoningBuffer.trim() || 'Reasoning...';
              setTimeout(() => {
                const re = assistantBubble.querySelector('.reasoning-anim');
                if (re && re.parentNode) re.parentNode.removeChild(re);
              }, 700);
              reasoning = false;
            } else {
              reasoningBuffer += (token || '');
              const reasonElem = assistantBubble.querySelector('.reasoning-anim');
              if (reasonElem) reasonElem.textContent = reasoningBuffer.trim() || 'Reasoning...';
            }
          } else {
            await typeTextSmoothly(assistantBubble, token, 8);
          }
          chatBox.scrollTop = chatBox.scrollHeight;

        } else if (eventType === 'done') {
          const assistantText = assistantBubble.textContent;
          serverConversation.push({ role:'assistant', content: assistantText });
          try {
            await fetch('?append_assistant=1', {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({ content: assistantText })
            });
          } catch (err) {
            console.warn('Failed to update server conversation:', err);
          }

          sending = false;
          msgInput.disabled = false;
          msgInput.focus();
        } else if (eventType === 'error') {
          assistantBubble.textContent = 'Error: ' + dataStr;
          sending = false;
          msgInput.disabled = false;
          msgInput.focus();
        }
      }
    }
  } catch (err) {
    addBubble('assistant', 'Network error: ' + (err.message || err));
    sending = false;
    msgInput.disabled = false;
    msgInput.focus();
  }
}

// small helper to create reasoning animation element
function createReasoningAnim(text) {
  const el = document.createElement('span');
  el.className = 'reasoning-anim';
  el.textContent = text;
  return el;
}

// enter sends
msgInput.addEventListener('keydown', (ev) => {
  if (ev.key === 'Enter' && !ev.shiftKey) {
    ev.preventDefault();
    sendMessage();
  }
});

// initial setup
populateModelSelect();
renderConversationFromServer();
msgInput.focus();
</script>
</body>
</html>
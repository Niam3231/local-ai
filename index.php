<?php
session_start();

/* === CONFIG === */
$OLLAMA_URL = 'http://localhost:11434/api/chat';
$models_file = __DIR__ . '/models.json';

/* === MODEL METADATA === */
$MODEL_INFO = [
    'gemma3:270m' => [
        'name' => 'Gemma 3 270m',
        'descr' => 'With its low parameters it very dumb. But its very fast, even on phones it generates at the speed of ChatGPT. You could call it a RageBait-AI because it\'s very dumb.',
        'size' => '292 MB'
    ],
    'qwen3:0.6b' => [
        'name' => 'Qwen 3 0.6b',
        'descr' => 'With its low parameters it generates fast. But because of its reasoning capability it\'s not dumb and can detect differences between different subjects.',
        'size' => '523 MB'
    ],
    'deepseek-r1:1.5b' => [
        'name' => 'DeepSeek R1 1.5b',
        'descr' => 'For low-end Laptops/PC even phones. It\'s a bit smarter than Qwen 3 0.6 and slower. But can also reason.',
        'size' => '1.1 GB'
    ],
    'gemma3:4b' => [
        'name' => 'Gemma 3 4b',
        'descr' => 'Despite its high parameters for low-end Laptops/PC or phones, it\'s very capable to run on a single GPU, so it could be faster than other models of the same smartness.',
        'size' => '3.3 GB'
    ],
    'deepseek-r1:7b' => [
        'name' => 'DeepSeek R1 7b',
        'descr' => 'A model for mid-end Laptops or even very powerful phones. It\'s smart and can reason.',
        'size' => '4.7 GB'
    ],
    'deepseek-r1:14b' => [
        'name' => 'DeepSeek R1 14b',
        'descr' => 'For mid/high-end Laptops or PCs. Only the most recent and expensive phones can run it. It can reason and is smart. But if you have the wrong hardware this will probably not even load because of lack of memory or VRAM.',
        'size' => '9.0 GB'
    ],
    'deepseek-r1:32b' => [
        'name' => 'DeepSeek R1 32b',
        'descr' => 'Needs a high-end PC with a powerful GPU. Phones and (almost any) laptops cannot run this. It\'s a very smart model. You probably won\'t even need this.',
        'size' => '20 GB'
    ]
];

/* === LOAD MODELS FROM JSON === */
$catalog = [];
if (file_exists($models_file)) {
    $raw = trim(file_get_contents($models_file), "\xEF\xBB\xBF");
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach ($decoded as $m) {
            $id = $m['id'] ?? null;
            if (!$id) continue;
            $info = $MODEL_INFO[$id] ?? [
                'name' => $id,
                'descr' => 'No description available.',
                'size' => 'Unknown'
            ];
            $catalog[] = array_merge(['id' => $id], $info);
        }
    }
}

/* === FALLBACK IF FILE EMPTY === */
if (empty($catalog)) {
    $catalog = [
        ['id'=>'gemma3:270m','name'=>'Gemma 3 270m','descr'=>'Very fast but dumb','size'=>'292 MB'],
        ['id'=>'qwen3:0.6b','name'=>'Qwen 3 0.6b','descr'=>'Fast and reasonable','size'=>'523 MB'],
        ['id'=>'gemma3:4b','name'=>'Gemma 3 4b','descr'=>'Smart and capable','size'=>'3.3 GB'],
    ];
}

/* === MAP FOR VALIDATION === */
$AVAILABLE_MODELS = [];
foreach ($catalog as $m) {
    $AVAILABLE_MODELS[$m['id']] = $m['name'];
}

/* === SESSION MODEL + CHAT === */
if (!isset($_SESSION['model'])) {
    $_SESSION['model'] = array_key_first($AVAILABLE_MODELS) ?: 'gemma3:270m';
}
if (!isset($_SESSION['conversation'])) {
    $_SESSION['conversation'] = [];
}

/* === HANDLE POST REQUESTS === */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Model switching
    if (isset($_GET['set_model'])) {
        $model = $_POST['model'] ?? '';
        if (isset($AVAILABLE_MODELS[$model])) $_SESSION['model'] = $model;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'model'=>$_SESSION['model']]);
        exit;
    }

    // New chat reset
    if (isset($_GET['new'])) {
        $_SESSION['conversation'] = [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['cleared'=>true]);
        exit;
    }

    // Streaming chat
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $input = json_decode(file_get_contents('php://input'), true);
    $msg = trim($input['message'] ?? '');
    if ($msg === '') {
        echo "event: error\ndata:" . json_encode(['error'=>'Empty message']) . "\n\n";
        @ob_flush(); flush();
        exit;
    }

    $model = $_SESSION['model'] ?? array_key_first($AVAILABLE_MODELS);
    $_SESSION['conversation'][] = ['role'=>'user','content'=>$msg];

    $payload = [
        'model' => $model,
        'messages' => $_SESSION['conversation'],
        'stream' => true
    ];

    $ch = curl_init($OLLAMA_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION => function($ch, $chunk) {
            foreach (explode("\n", $chunk) as $line) {
                $line = trim($line);
                if ($line === '' || $line === 'data: [DONE]') continue;
                $json = json_decode($line, true);
                if (!isset($json['message']['content'])) continue;
                $token = $json['message']['content'];
                echo "event: token\ndata:" . json_encode($token) . "\n\n";
                @ob_flush(); flush();
            }
            return strlen($chunk);
        }
    ]);
    curl_exec($ch);
    curl_close($ch);

    echo "event: done\ndata:{}\n\n";
    @ob_flush(); flush();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Ollama Live Chat</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
html,body{margin:0;padding:0;height:100%;width:100%;background:#101217;color:#f3f4fa;font-family:'Segoe UI',Arial,sans-serif;overflow:hidden;}
#chat-container{display:flex;flex-direction:column;height:100vh;width:100%;background:#181b20;}
#chat-header{background:#222635;padding:.6em 1em;border-bottom:1px solid #2a2d34;display:flex;justify-content:space-between;align-items:center;gap:.5em;}
#model-select{background:#191c21;color:#68a3ff;border:1.5px solid #68a3ff;border-radius:8px;padding:.3em .8em;font-weight:600;}
#chat-box{flex:1;overflow-y:auto;padding:1.5em;display:flex;flex-direction:column;gap:1.2em;}
.msg{display:flex;width:100%;}
.msg.user{justify-content:flex-end;}
.bubble{max-width:90%;padding:1em 1.4em;border-radius:14px;background:#222635;box-shadow:0 2px 6px #0003;}
.msg.user .bubble{background:linear-gradient(120deg,#2563eb 70%,#3683ff);color:#fff;}
#input-area{display:flex;gap:.8em;padding:.8em;border-top:1px solid #23283a;background:#181b20;}
#msg{flex:1;background:#191c21;color:#fff;border:1px solid #2b2f3a;border-radius:10px;padding:.7em;font-size:1em;resize:none;}
#send-btn,#new-btn{border:none;border-radius:10px;padding:0 1em;font-weight:600;cursor:pointer;}
#send-btn{background:#2563eb;color:#fff;}
#send-btn:hover{background:#3a7bff;}
#new-btn{background:#68a3ff;color:#fff;}
#new-btn:hover{background:#2563eb;}
</style>
</head>
<body>
<div id="chat-container">
  <div id="chat-header">
    <span>Ollama Live Chat <span id="current-model-label" style="color:#68a3ff;font-weight:500;"></span></span>
    <span>
      <select id="model-select"></select>
      <button id="new-btn" onclick="startNewChat()">New Chat</button>
    </span>
  </div>
  <div id="chat-box"></div>
  <form id="input-area" onsubmit="send();return false;">
    <textarea id="msg" placeholder="Type your message..." autocomplete="off"></textarea>
    <button id="send-btn" type="submit">Send</button>
  </form>
</div>

<script>
const CATALOG = <?php echo json_encode($catalog, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
let currentModel = <?php echo json_encode($_SESSION['model']); ?>;
const chatBox=document.getElementById('chat-box');
const msgInput=document.getElementById('msg');
const modelSelect=document.getElementById('model-select');
const currentModelLabel=document.getElementById('current-model-label');

function populateModels(){
  modelSelect.innerHTML='';
  for(const m of CATALOG){
    const opt=document.createElement('option');
    opt.value=m.id;
    opt.textContent=m.name;
    if(m.id===currentModel) opt.selected=true;
    modelSelect.appendChild(opt);
  }
  updateModelLabel();
}
function updateModelLabel(){
  const m=CATALOG.find(x=>x.id===currentModel);
  currentModelLabel.textContent=m?`— ${m.name}`:'';
}
modelSelect.addEventListener('change',async()=>{
  const selected=modelSelect.value;
  if(selected===currentModel)return;
  const f=new FormData();f.append('model',selected);
  await fetch('?set_model=1',{method:'POST',body:f});
  currentModel=selected;
  updateModelLabel();
});
populateModels();

function addMsg(role,text){
  const m=document.createElement('div');m.className='msg '+role;
  const b=document.createElement('div');b.className='bubble';b.textContent=text;
  m.appendChild(b);chatBox.appendChild(m);chatBox.scrollTop=chatBox.scrollHeight;return b;
}
function startNewChat(){fetch('?new=1',{method:'POST'}).then(()=>{chatBox.innerHTML='';msgInput.value='';msgInput.disabled=false;msgInput.focus();});}

msgInput.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});

async function send(){
  const text=msgInput.value.trim();
  if(!text)return;
  msgInput.value='';msgInput.disabled=true;
  addMsg('user',text);
  const aiBubble=addMsg('assistant','');
  const src=new EventSourcePolyfill('index.php',{method:'POST',headers:{'Content-Type':'application/json'},payload:JSON.stringify({message:text})});
  src.addEventListener('token',e=>{aiBubble.textContent+=JSON.parse(e.data);chatBox.scrollTop=chatBox.scrollHeight;});
  src.addEventListener('done',()=>{msgInput.disabled=false;msgInput.focus();src.close();});
  src.addEventListener('error',()=>{aiBubble.textContent+='\n[connection lost]';msgInput.disabled=false;src.close();});
}

/* Polyfill for POST streaming */
class EventSourcePolyfill{
  constructor(url,opt={}){this.url=url;this.opt=opt;this.listeners={};this.ctrl=new AbortController();this.start();}
  start(){
    fetch(this.url,{method:this.opt.method||'GET',headers:this.opt.headers||{},body:this.opt.payload||null,signal:this.ctrl.signal})
      .then(async r=>{
        const rd=r.body.getReader();const dec=new TextDecoder();let buf='';
        while(true){const{done,value}=await rd.read();if(done)break;buf+=dec.decode(value,{stream:true});
          const parts=buf.split('\n\n');buf=parts.pop();
          for(const chunk of parts){const[type,data]=chunk.split('\n');if(!data)continue;
            const ev=type?.split(': ')[1]||'message';const d=data.slice(5);
            if(this.listeners[ev])this.listeners[ev].forEach(fn=>fn({data:d}));
          }
        }
      }).catch(()=>{});
  }
  addEventListener(ev,fn){if(!this.listeners[ev])this.listeners[ev]=[];this.listeners[ev].push(fn);}
  close(){this.ctrl.abort();}
}

window.onload=()=>{msgInput.focus();}
</script>
</body>
</html>
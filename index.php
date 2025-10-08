<?php
session_start();

// === CONFIG ===
$models_file = __DIR__ . '/models.json';
$OLLAMA_URL = 'http://localhost:11434/api/chat';

// === HARD-CODED MODEL METADATA ===
$MODEL_INFO = [
    'gemma3:270m' => [
        'name' => 'Gemma 3 270m',
        'descr' => "With its low parameters it very dumb. But its very fast, even on phones it generates at the speed of ChatGPT. You could call it a RageBait-AI because it's very dumb.",
        'size' => '292 MB'
    ],
    'qwen3:0.6b' => [
        'name' => 'Qwen 3 0.6b',
        'descr' => "With its low parameters it generates fast. But because of its reasoning capability it's not dumb and can detect differences between different subjects.",
        'size' => '523 MB'
    ],
    'deepseek-r1:1.5b' => [
        'name' => 'DeepSeek R1 1.5b',
        'descr' => "For low-end Laptops/PC even phones. It's a bit smarter than Qwen 3 0.6 and slower. But can also reason.",
        'size' => '1.1 GB'
    ],
    'gemma3:4b' => [
        'name' => 'Gemma 3 4b',
        'descr' => "Despite its high parameters for low-end Laptops/PC or phones, it's very capable to run on a single GPU, so it could be faster than other models of the same smartness.",
        'size' => '3.3 GB'
    ],
    'deepseek-r1:7b' => [
        'name' => 'DeepSeek R1 7b',
        'descr' => "A model for mid-end Laptops or even very powerful phones. It's smart and can reason.",
        'size' => '4.7 GB'
    ],
    'deepseek-r1:14b' => [
        'name' => 'DeepSeek R1 14b',
        'descr' => "For mid/high-end Laptops or PCs. Only the most recent and expensive phones can run it. It can reason and is smart. But if you have the wrong hardware this will probably not even load because of lack of memory or VRAM.",
        'size' => '9.0 GB'
    ],
    'deepseek-r1:32b' => [
        'name' => 'DeepSeek R1 32b',
        'descr' => "Needs a high-end PC with a powerful GPU. Phones and (almost any) laptops cannot run this. It's a very smart model. You probably won't even need this.",
        'size' => '20 GB'
    ]
];

// === LOAD CATALOG ===
$catalog = [];
if (file_exists($models_file)) {
    $raw = trim(file_get_contents($models_file), "\xEF\xBB\xBF"); // remove BOM if present
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach ($decoded as $m) {
            if (!empty($m['id'])) {
                $id = $m['id'];
                $info = $MODEL_INFO[$id] ?? ['name'=>$id,'descr'=>'No description available.','size'=>'Unknown'];
                $catalog[] = array_merge(['id'=>$id], $info);
            }
        }
    }
}

// Fallback
if (count($catalog) === 0) {
    $catalog = [
        ['id'=>'gemma3:4b','name'=>'Gemma 3 4b','descr'=>'Smart and capable','size'=>'3.3 GB'],
        ['id'=>'qwen3:0.6b','name'=>'Qwen 3 0.6b','descr'=>'Fast and reasonable','size'=>'523 MB'],
        ['id'=>'gemma3:270m','name'=>'Gemma 3 270m','descr'=>'Very fast but very dumb','size'=>'292 MB']
    ];
}

// Available models map for server-side validation
$AVAILABLE_MODELS = [];
foreach ($catalog as $m) $AVAILABLE_MODELS[$m['id']] = $m['name'].' - '.$m['descr'];

// Default session model
if (!isset($_SESSION['model'])) $_SESSION['model'] = array_key_first($AVAILABLE_MODELS) ?: 'gemma3:4b';

// Conversation
if (!isset($_SESSION['conversation'])) $_SESSION['conversation'] = [];

// === ENDPOINTS ===
if ($_SERVER['REQUEST_METHOD']==='POST') {
    // Set model
    if (isset($_GET['set_model'])) {
        $model = $_POST['model'] ?? array_key_first($AVAILABLE_MODELS);
        if (isset($AVAILABLE_MODELS[$model])) $_SESSION['model'] = $model;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'model'=>$_SESSION['model']]);
        exit;
    }

    // New conversation
    if (isset($_GET['new'])) {
        $_SESSION['conversation'] = [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['cleared'=>true]);
        exit;
    }

    // Stream chat
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $input = json_decode(file_get_contents('php://input'), true);
    $message = trim($input['message'] ?? '');
    if ($message==='') {
        echo "event: error\ndata:".json_encode(['error'=>'Empty message'])."\n\n";
        @ob_flush(); flush(); exit;
    }

    $model = $_SESSION['model'] ?? array_key_first($AVAILABLE_MODELS);
    $_SESSION['conversation'][] = ['role'=>'user','content'=>$message];

    $payload = ['model'=>$model,'messages'=>$_SESSION['conversation'],'stream'=>true];
    $ch = curl_init($OLLAMA_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch,$chunk){
        foreach(explode("\n",$chunk) as $line){
            $line = trim($line);
            if($line==='' || $line==='data: [DONE]') continue;
            $json = json_decode($line,true);
            if(!$json || !isset($json['message']['content'])) continue;
            $token = $json['message']['content'];

            // escape sequence cleanup
            $token = json_decode(json_encode($token));

            echo "event: token\ndata:".json_encode($token)."\n\n";
            @ob_flush(); flush();
        }
        return strlen($chunk);
    });
    curl_exec($ch);
    curl_close($ch);

    echo "event: done\ndata:{}\n\n";
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
html,body{margin:0;padding:0;height:100%;width:100vw;font-family:'Segoe UI',Arial,sans-serif;color:#f3f4fa;background:#101217;overflow:hidden;}
body,#chat-container{display:flex;flex-direction:column;min-height:100dvh;width:100vw;box-sizing:border-box;}
#chat-container{flex:1;background:#181b20;position:fixed;top:0;left:0;z-index:1;width:100vw;}
#chat-header{background:#222635;padding:.7em 2vw;font-weight:bold;border-bottom:1px solid #24272d;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.4em;min-height:42px;width:100%;}
#chat-header .brand{color:#68a3ff;font-size:.85em;margin-left:6px;max-width:60vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:middle;}
#chat-header .header-controls{display:flex;gap:.5em;align-items:center;flex-wrap:wrap;justify-content:flex-end;width:auto;}
#input-area{display:flex;flex-direction:row;gap:2vw;padding:2.5vh 4vw 2.5vh 4vw;background:#181b20;border-top:1px solid #23283a;width:100vw;min-height:min(12vh,90px);box-sizing:border-box;}
#msg{flex:1;background:#191c21;color:#f3f4fa;border:1px solid #23283a;border-radius:10px;font-size:min(4.2vw,1.08em);padding:1.6vh 2vw;resize:none;min-height:5vh;max-height:24vh;box-shadow:0 1px 4px #0002;outline:none;}
#msg:focus{border:1.5px solid #68a3ff;}
#send-btn,#new-btn{border:none;border-radius:10px;padding:0 4vw;cursor:pointer;outline:none;font-size:min(5vw,1em);}
#send-btn{background:linear-gradient(90deg,#68a3ff 60%,#2563eb);color:#fff;font-weight:bold;min-height:5vh;}
#send-btn:hover{background:linear-gradient(90deg,#2563eb,#68a3ff 90%);}
#new-btn{background:#68a3ff;color:#fff;font-weight:500;}
#new-btn:hover{background:#2563eb;}
#model-select{background:#191c21;color:#68a3ff;border:1.5px solid #68a3ff;border-radius:7px;font-weight:600;padding:.22em .8em;outline:none;margin-right:.5em;}
#model-select:focus{border-color:#2563eb;}
#chat-box{flex:1;overflow-y:auto;overflow-x:hidden;padding:3vh 0 2vh 0;background:#181b20;display:flex;flex-direction:column;gap:2.5vh;width:100vw;box-sizing:border-box;}
.msg{width:100%;display:flex;justify-content:flex-start;align-items:flex-start;}
.msg.user{justify-content:flex-end;}
.bubble{max-width:92vw;width:fit-content;padding:2.5vh 3vw;border-radius:18px;font-size:min(4.5vw,1.09em);line-height:1.6;word-break:break-word;white-space:pre-wrap;box-shadow:0 2px 16px #0002;background:#222635;color:#f3f4fa;position:relative;}
.msg.user .bubble{background:linear-gradient(120deg,#2563eb 70%,#3683ff);color:#fff;}
.reasoning-anim{color:#fffb;background:#232945;border-radius:12px;padding:.6em 1.1em;font-size:1em;font-family:'Fira Mono',monospace;box-shadow:0 2px 8px #0e2568a0;display:inline-flex;align-items:center;gap:.75em;letter-spacing:.04em;border-left:3px solid #68a3ff;border-right:3px solid #68a3ff;transition:background .3s;animation:reasoning-glow 1.3s infinite alternate;}
@keyframes reasoning-glow{0%{box-shadow:0 2px 8px #0e2568a0;}100%{box-shadow:0 2px 22px #68a3ff77;background:#24325e;}}
.reasoning-anim .reason-icon{font-size:1.14em;color:#68a3ff;animation:reasoning-glow-icon 1.2s infinite alternate;} @keyframes reasoning-glow-icon{0%{transform:translateY(0px);}50%{transform:translateY(-2px);}100%{transform:translateY(0px);}} @media(max-width:600px){#chat-header{flex-direction:column;align-items:flex-start;gap:.6em;}#chat-header .brand{max-width:100%;white-space:normal;text-overflow:unset;}#chat-header .header-controls{width:100%;justify-content:space-between;}} </style>

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
</div><script>
let conversation = [];

function renderConversation(){
    chatBox.innerHTML='';
    for(const m of conversation) addMsg(m.role,m.content);
    chatBox.scrollTop = chatBox.scrollHeight;
}

function addMsg(role,text){
    const msgDiv = document.createElement('div');
    msgDiv.className = 'msg '+role;

    // Handle <think> reasoning blocks
    const parts = text.split(/<think>|<\/think>/);
    parts.forEach((p,i)=>{
        if(i%2===1){ // reasoning block
            const span = document.createElement('span');
            span.className = 'reasoning-anim';
            span.innerHTML = `<span class="reason-icon">🧠</span> ${p}`;
            msgDiv.appendChild(span);
        } else if(p.trim()!==''){
            const bubble = document.createElement('div');
            bubble.className='bubble';
            bubble.textContent = p;
            msgDiv.appendChild(bubble);
        }
    });

    chatBox.appendChild(msgDiv);
    chatBox.scrollTop = chatBox.scrollHeight;
    return msgDiv;
}

async function send(){
    const msg = msgInput.value.trim();
    if(!msg) return;
    conversation.push({role:'user',content:msg});
    addMsg('user',msg);
    msgInput.value='';
    msgInput.disabled=true;

    try {
        const res = await fetch('', {
            method:'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({message: msg})
        });
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while(true){
            const {done,value} = await reader.read();
            if(done) break;
            buffer += decoder.decode(value,{stream:true});
            let lines = buffer.split("\n\n");
            buffer = lines.pop()||'';
            for(const line of lines){
                if(line.startsWith("event: token")){
                    let data=line.split("data:")[1].trim();
                    let text='';
                    try{text=JSON.parse(data);}catch{}
                    conversation.push({role:'assistant',content:text});
                    addMsg('assistant',text);
                }
            }
        }
    } catch(e){
        addMsg('assistant','Error sending message: '+e.message);
    }

    msgInput.disabled=false;
    msgInput.focus();
}

msgInput.addEventListener('keydown', function(e){
    if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); send(); }
});

window.onload = ()=>{ renderConversation(); msgInput.focus(); };
</script></body>
</html>

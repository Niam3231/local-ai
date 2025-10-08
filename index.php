<?php
session_start();

// === CONFIG ===
$models_file = __DIR__ . '/models.json';
$OLLAMA_URL = 'http://localhost:11434/api/chat';

// === LOAD MODELS ===
$MODEL_INFO = [
    'gemma3:270m'=>['name'=>'Gemma 3 270m','descr'=>'With its low parameters it very dumb. But its very fast, even on phones it generates at the speed of ChatGPT. You could call it a RageBait-AI because it\'s very dumb.','size'=>'292 MB'],
    'qwen3:0.6b'=>['name'=>'Qwen 3 0.6b','descr'=>'With its low parameters it generates fast. But because of its reasoning capability it\'s not dumb and can detect differences between different subjects.','size'=>'523 MB'],
    'deepseek-r1:1.5b'=>['name'=>'DeepSeek R1 1.5b','descr'=>'For low-end Laptops/PC even phones. It\'s a bit smarter than Qwen 3 0.6 and slower. But can also reason.','size'=>'1.1 GB'],
    'gemma3:4b'=>['name'=>'Gemma 3 4b','descr'=>'Despite its high parameters for low-end Laptops/PC or phones, it\'s very capable to run on a single GPU, so it could be faster than other models of the same smartness.','size'=>'3.3 GB'],
    'deepseek-r1:7b'=>['name'=>'DeepSeek R1 7b','descr'=>'A model for mid-end Laptops or even very powerful phones. It\'s smart and can reason.','size'=>'4.7 GB'],
    'deepseek-r1:14b'=>['name'=>'DeepSeek R1 14b','descr'=>'For mid/high-end Laptops or PCs. Only the most recent and expensive phones can run it. It can reason and is smart. But if you have the wrong hardware this will probably not even load because of lack of memory or VRAM.','size'=>'9.0 GB'],
    'deepseek-r1:32b'=>['name'=>'DeepSeek R1 32b','descr'=>'Needs a high-end PC with a powerful GPU. Phones and (almost any) laptops cannot run this. It\'s a very smart model. You probably won\'t even need this.','size'=>'20 GB']
];

$catalog=[];
if(file_exists($models_file)){
    $raw=file_get_contents($models_file);
    $raw=trim($raw,"\xEF\xBB\xBF");
    $decoded=json_decode($raw,true);
    if(is_array($decoded)){
        foreach($decoded as $m){
            if(!empty($m['id'])){
                $id=$m['id'];
                $info=$MODEL_INFO[$id]??['name'=>$id,'descr'=>'No description available.','size'=>'Unknown'];
                $catalog[]=array_merge(['id'=>$id],$info);
            }
        }
    }
}

if(count($catalog)===0){
    $catalog=[
        ['id'=>'gemma3:4b','name'=>'Gemma 3 4b','descr'=>'Smart and capable','size'=>'3.3 GB'],
        ['id'=>'qwen3:0.6b','name'=>'Qwen 3 0.6b','descr'=>'Fast and reasonable','size'=>'523 MB'],
        ['id'=>'gemma3:270m','name'=>'Gemma 3 270m','descr'=>'Very fast but very dumb','size'=>'292 MB'],
    ];
}

$AVAILABLE_MODELS=[];
foreach($catalog as $m){
    $AVAILABLE_MODELS[$m['id']]=$m['name'].' - '.$m['descr'];
}

// Default model
if(!isset($_SESSION['model'])){
    $_SESSION['model']=array_key_first($AVAILABLE_MODELS)?:'gemma3:4b';
}

// Conversation
if(!isset($_SESSION['conversation'])) $_SESSION['conversation']=[];

// === ENDPOINTS ===
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(isset($_GET['set_model'])){
        $model=$_POST['model']??array_key_first($AVAILABLE_MODELS);
        if(isset($AVAILABLE_MODELS[$model])) $_SESSION['model']=$model;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'model'=>$_SESSION['model']]);
        exit;
    }

    if(isset($_GET['new'])){
        $_SESSION['conversation']=[];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['cleared'=>true]);
        exit;
    }

    // Stream chat
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $input=json_decode(file_get_contents('php://input'),true);
    $message=trim($input['message']??'');
    if($message===''){
        echo "event: error\ndata:".json_encode(['error'=>'Empty message'])."\n\n";
        @ob_flush(); flush();
        exit;
    }

    $model=$_SESSION['model']??array_key_first($AVAILABLE_MODELS);
    $_SESSION['conversation'][]=['role'=>'user','content'=>$message];

    $payload=['model'=>$model,'messages'=>$_SESSION['conversation'],'stream'=>true];
    $ch=curl_init($OLLAMA_URL);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,false);
    curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload));
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json']);
    curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($ch,$chunk){
        foreach(explode("\n",$chunk) as $line){
            $line=trim($line);
            if($line===''||$line==='data: [DONE]') continue;
            $json=json_decode($line,true);
            if(!$json||!isset($json['message']['content'])) continue;
            $token=$json['message']['content'];
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
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<style>
html,body{margin:0;padding:0;height:100%;width:100vw;font-family:'Segoe UI',Arial,sans-serif;color:#f3f4fa;background:#101217;overflow:hidden;}
body,#chat-container{display:flex;flex-direction:column;min-height:100dvh;width:100vw;box-sizing:border-box;}
#chat-container{flex:1;background:#181b20;position:fixed;top:0;left:0;z-index:1;width:100vw;}
#chat-header{background:#222635;padding:.7em 2vw;font-weight:bold;border-bottom:1px solid #24272d;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.4em;min-height:42px;width:100%;}
#chat-header .brand{color:#68a3ff;font-size:.85em;margin-left:6px;max-width:60vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:middle;}
#chat-header .header-controls{display:flex;gap:.5em;align-items:center;flex-wrap:wrap;justify-content:flex-end;width:auto;}
#chat-box{flex:1;overflow-y:auto;overflow-x:hidden;padding:3vh 0 2vh 0;background:#181b20;display:flex;flex-direction:column;gap:2.5vh;width:100vw;box-sizing:border-box;}
.msg{width:100%;display:flex;justify-content:flex-start;align-items:flex-start;}
.msg.user{justify-content:flex-end;}
.bubble{max-width:92vw;width:fit-content;padding:2.5vh 3vw;border-radius:18px;font-size:min(4.5vw,1.09em);line-height:1.6;word-break:break-word;white-space:pre-wrap;box-shadow:0 2px 16px #0002;background:#222635;color:#f3f4fa;position:relative;}
.msg.user .bubble{background:linear-gradient(120deg,#2563eb 70%,#3683ff);color:#fff;}
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
@media(max-width:600px){
  #chat-header{flex-direction:column;align-items:flex-start;gap:.6em;}
  #chat-header .brand{max-width:100%;white-space:normal;text-overflow:unset;}
  #chat-header .header-controls{width:100%;justify-content:space-between;}
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
  <form id="input-area" onsubmit="sendMessage();return false;" autocomplete="off">
    <textarea id="msg" placeholder="Type your message..." autocomplete="off"></textarea>
    <button id="send-btn" type="submit">Send</button>
  </form>
</div>

<script>
const CATALOG = <?php echo json_encode($catalog, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
let currentModel = <?php echo json_encode($_SESSION['model']); ?>;
const chatBox = document.getElementById('chat-box');
const msgInput = document.getElementById('msg');
const modelSelect = document.getElementById('model-select');
const currentModelLabel = document.getElementById('current-model-label');

let conversation = <?php echo json_encode($_SESSION['conversation']); ?>;

// Populate model dropdown
function populateModelSelect(){
  modelSelect.innerHTML='';
  for(const m of CATALOG){
    const opt=document.createElement('option');
    opt.value=m.id;
    opt.textContent=m.name+' — '+m.descr;
    opt.title=`${m.id} • Size: ${m.size}`;
    if(m.id===currentModel) opt.selected=true;
    modelSelect.appendChild(opt);
  }
  updateCurrentModelLabel();
}
function updateCurrentModelLabel(){
  const m=CATALOG.find(x=>x.id===currentModel);
  currentModelLabel.textContent=m?`${m.name} — ${m.descr}`:'';
}

modelSelect.addEventListener('change',async function(){
  const selected=modelSelect.value;
  if(selected===currentModel) return;
  const form=new FormData(); form.append('model',selected);
  await fetch('?set_model=1',{method:'POST',body:form});
  currentModel=selected;
  updateCurrentModelLabel();
});

populateModelSelect();

// Chat rendering
function renderConversation(){
  chatBox.innerHTML='';
  for(const m of conversation) addMessage(m.role,m.content);
  chatBox.scrollTop=chatBox.scrollHeight;
}
function addMessage(role,text){
  const msgDiv=document.createElement('div');
  msgDiv.className='msg '+role;
  const bubble=document.createElement('div');
  bubble.className='bubble';
  bubble.textContent=text;
  msgDiv.appendChild(bubble);
  chatBox.appendChild(msgDiv);
  chatBox.scrollTop=chatBox.scrollHeight;
  return bubble;
}

// New chat
function startNewChat(){
  fetch('?new=1',{method:'POST'}).then(()=>{conversation=[];chatBox.innerHTML='';msgInput.value='';msgInput.disabled=false;msgInput.focus();});
}

// Send message with SSE streaming
function sendMessage(){
  const msg = msgInput.value.trim();
  if(!msg) return;
  msgInput.value='';
  msgInput.disabled=true;
  conversation.push({role:'user',content:msg});
  addMessage('user',msg);

  const bubble=addMessage('assistant','');
  const evtSource = new EventSourcePolyfill('?',{method:'POST',body:JSON.stringify({message:msg}),headers:{'Content-Type':'application/json'}});

  evtSource.addEventListener('token',e=>{
    bubble.textContent += e.data;
    chatBox.scrollTop=chatBox.scrollHeight;
  });
  evtSource.addEventListener('done',()=>{
    evtSource.close();
    msgInput.disabled=false;
    msgInput.focus();
  });
}

// Handle Enter key
msgInput.addEventListener('keydown',function(e){
  if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();}
});

// Initial render
window.onload=()=>{renderConversation();msgInput.focus();};

// EventSource polyfill for POST (works with fetch polyfill)
class EventSourcePolyfill {
  constructor(url,opt={}){
    this.listeners={};
    fetch(url,{method:opt.method||'GET',body:opt.body,headers:opt.headers}).then(r=>{
      const reader=r.body.getReader();
      const decoder=new TextDecoder();
      const pump=()=>reader.read().then(({done,value})=>{
        if(done) return;
        const chunk=decoder.decode(value,{stream:true});
        for(const line of chunk.split("\n")){
          const l=line.trim();
          if(!l.startsWith('event:')) continue;
          const ev=l.split(' ')[1];
          const dataLine=chunk.split("\n").find(x=>x.startsWith('data:'));
          if(!dataLine) continue;
          const data=dataLine.replace(/^data:\s*/,'');
          if(this.listeners[ev]) this.listeners[ev].forEach(fn=>fn({data}));
        }
        return pump();
      });
      pump();
    });
  }
  addEventListener(ev,fn){if(!this.listeners[ev]) this.listeners[ev]=[]; this.listeners[ev].push(fn);}
  close(){}
}
</script>
</body>
</html>

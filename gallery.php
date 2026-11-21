<?php
/**
* Gallery — Place a file in the "Images" folder, and that’s it. 
* Subfolders become albums. Thumbnails and colors are generated automatically.
 */
$C = [
    'title'  => 'Galerie',
    'cache'  => '.gallery-cache',   // Thumbnails + index (must be writable)
    'depth'  => 3,                  // Depth of subfolders
    'max'    => 4000,               // Maximum number of images
    'budget' => 14,                 // Color analyses per page view
    'exts'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'],
];
$root = realpath(__DIR__);
$cacheDir = $root . DIRECTORY_SEPARATOR . $C['cache'];
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
$cacheOk = is_dir($cacheDir) && is_writable($cacheDir);

function ext_of($f) { return strtolower(pathinfo($f, PATHINFO_EXTENSION)); }

function orient($abs, $ext) {
    if (!in_array($ext, ['jpg', 'jpeg'], true) || !function_exists('exif_read_data')) return 1;
    $e = @exif_read_data($abs);
    return (int)(isset($e['Orientation']) ? $e['Orientation'] : 1);
}

function make_thumb($abs, $w, $dir, $key) {
    $dst = $dir . '/' . md5($key . '|' . $w) . '.jpg';
    if (is_file($dst)) return $dst;
    if (!function_exists('imagecreatetruecolor')) return null;
    $info = @getimagesize($abs);
    if (!$info || $info[0] * $info[1] > 40000000) return null;
    $e = ext_of($abs);
    $im = false;
    if ($e === 'jpg' || $e === 'jpeg') $im = @imagecreatefromjpeg($abs);
    elseif ($e === 'png') $im = @imagecreatefrompng($abs);
    elseif ($e === 'webp' && function_exists('imagecreatefromwebp')) $im = @imagecreatefromwebp($abs);
    elseif ($e === 'avif' && function_exists('imagecreatefromavif')) $im = @imagecreatefromavif($abs);
    if (!$im) return null;
    $o = orient($abs, $e);
    if ($o === 3) $im = imagerotate($im, 180, 0);
    elseif ($o === 6) $im = imagerotate($im, -90, 0);
    elseif ($o === 8) $im = imagerotate($im, 90, 0);
    $sw = imagesx($im); $sh = imagesy($im);
    $nw = min($w, $sw); $nh = max(1, (int)round($sh * $nw / $sw));
    $t = imagecreatetruecolor($nw, $nh);
    imagefill($t, 0, 0, imagecolorallocate($t, 20, 21, 23));
    imagecopyresampled($t, $im, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
    imagejpeg($t, $dst . '.tmp', 82);
    @rename($dst . '.tmp', $dst);
    return is_file($dst) ? $dst : null;
}

function avg_color($file) {
    $im = @imagecreatefromjpeg($file);
    if (!$im) return '6b6f7a';
    $p = imagecreatetruecolor(1, 1);
    imagecopyresampled($p, $im, 0, 0, 0, 0, 1, 1, imagesx($im), imagesy($im));
    $c = imagecolorat($p, 0, 0);
    return sprintf('%02x%02x%02x', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255);
}

/* ---- Vorschaubild-Endpunkt: ?t=pfad&w=640 ---- */
if (isset($_GET['t'])) {
    $p = str_replace('\\', '/', (string)$_GET['t']);
    $w = (int)(isset($_GET['w']) ? $_GET['w'] : 640);
    if (!in_array($w, [320, 640, 1024], true)) $w = 640;
    $abs = ($p === '' || $p[0] === '.' || strpos($p, '/.') !== false) ? false : realpath($root . '/' . $p);
    if ($abs === false || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)
        || !in_array(ext_of($abs), $C['exts'], true)) {
        http_response_code(404); exit;
    }
    $f = $cacheOk ? make_thumb($abs, $w, $cacheDir, $p . '|' . filemtime($abs) . '|' . filesize($abs)) : null;
    if (!$f) {
        header('Location: ./' . implode('/', array_map('rawurlencode', explode('/', $p))), true, 302);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($f);
    exit;
}

/* ---- Bilder einsammeln ---- */
$files = [];
try {
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($cur) { return $cur->getFilename()[0] !== '.'; }
    ));
    $it->setMaxDepth($C['depth']);
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $e = ext_of($f->getFilename());
        if (!in_array($e, $C['exts'], true)) continue;
        $p = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        $files[] = [$p, (int)$f->getMTime(), (int)$f->getSize(), $f->getPathname(), $e];
        if (count($files) >= $C['max']) break;
    }
} catch (Exception $ex) {}

$idxFile = $cacheDir . '/index.json';
$old = ($cacheOk && is_file($idxFile)) ? json_decode((string)@file_get_contents($idxFile), true) : [];
if (!is_array($old)) $old = [];
$new = []; $out = []; $pending = 0; $dirty = false; $budget = $C['budget']; $t0 = microtime(true);

foreach ($files as [$p, $m, $s, $abs, $e]) {
    $k = $p . '|' . $m . '|' . $s;
    $r = isset($old[$k]) ? $old[$k] : null;
    if (!$r) {
        $i = @getimagesize($abs);
        $w = $i ? $i[0] : 4; $h = $i ? $i[1] : 3;
        $o = orient($abs, $e);
        if ($o >= 5 && $o <= 8) { $tmp = $w; $w = $h; $h = $tmp; }
        $r = [$w, $h, '']; $dirty = true;
    }
    if ($r[2] === '' && $cacheOk && $budget > 0 && microtime(true) - $t0 < 2.5) {
        $budget--;
        $th = make_thumb($abs, 320, $cacheDir, $k);
        $r[2] = $th ? avg_color($th) : '6b6f7a';
        $dirty = true;
    }
    if ($r[2] === '') $pending++;
    $new[$k] = $r;
    $out[] = [$p, $r[0], $r[1], $r[2], $m];
}
if ($cacheOk && ($dirty || count($new) !== count($old))) {
    if (@file_put_contents($idxFile . '.tmp', json_encode($new), LOCK_EX) !== false) @rename($idxFile . '.tmp', $idxFile);
}
$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP);
$title = htmlspecialchars($C['title'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<style>
:root{color-scheme:dark light;--bg:#121315;--bar:#17181b;--fg:#e8e9ec;--mut:#8b8f98;--ln:#2a2c31;--acc:#e8e9ec;--gap:3px}
@media(prefers-color-scheme:light){:root{--bg:#f1f1ef;--bar:#fafaf9;--fg:#18191b;--mut:#6b6f78;--ln:#dcdcd8;--acc:#18191b}}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--fg);font:13px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
button,input,select{font:inherit;color:inherit}
header{position:sticky;top:0;z-index:5;background:color-mix(in srgb,var(--bar) 90%,transparent);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid var(--ln);padding:8px var(--gap) 8px}
.r1,.r2{display:flex;align-items:center;gap:8px;padding:0 6px}
.r1{margin-bottom:7px;flex-wrap:wrap}
h1{font-size:14px;margin:0;font-weight:700;letter-spacing:.01em}
#cnt{color:var(--mut);font-variant-numeric:tabular-nums}
.sp{flex:1}
input[type=search],select{background:transparent;border:1px solid var(--ln);border-radius:8px;padding:5px 9px;height:30px}
input[type=search]{width:170px}
select{padding-right:4px}
input[type=range]{width:90px;accent-color:var(--acc)}
.r2{overflow-x:auto;scrollbar-width:none}.r2::-webkit-scrollbar{display:none}
.chip{flex:none;border:1px solid var(--ln);background:transparent;border-radius:999px;padding:3px 11px;cursor:pointer;white-space:nowrap}
.chip small{color:var(--mut);margin-left:5px}
.chip.on{background:var(--fg);color:var(--bg);border-color:var(--fg)}.chip.on small{color:inherit;opacity:.6}
.div{flex:none;width:1px;height:18px;background:var(--ln);margin:0 4px}
.dot{flex:none;width:18px;height:18px;border-radius:50%;border:2px solid transparent;cursor:pointer;padding:0;box-shadow:inset 0 0 0 1px #0004}
.dot.on{border-color:var(--fg)}
#grid{padding:var(--gap)}
.row{display:flex;gap:var(--gap);margin-bottom:var(--gap)}
.t{position:relative;flex:none;display:block;overflow:hidden;cursor:zoom-in}
.t img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .35s}
.t img.l{opacity:1}
.t span{position:absolute;left:0;right:0;bottom:0;padding:16px 8px 6px;font-size:11px;color:#fff;background:linear-gradient(transparent,#000b);transform:translateY(100%);transition:transform .18s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.t:hover{z-index:1;box-shadow:0 0 0 2px var(--acc)}
.t:hover span{transform:none}
@media(hover:none){.t span{display:none}}
#empty{display:none;padding:60px 20px;text-align:center;color:var(--mut)}
footer{padding:14px 10px 26px;color:var(--mut);text-align:center;font-size:12px}
#lb{position:fixed;inset:0;z-index:20;background:#060607;transition:background .4s;touch-action:pan-y}
#lb[hidden]{display:none}
.lbimg{position:absolute;inset:46px 0 0 0;display:flex;align-items:center;justify-content:center}
#lbi{max-width:100%;max-height:100%;object-fit:contain;transition:opacity .25s;user-select:none;-webkit-user-drag:none}
.lbbar{position:absolute;top:0;left:0;right:0;height:46px;display:flex;align-items:center;gap:4px;padding:0 8px 0 14px;color:#fff;background:linear-gradient(#000a,transparent);z-index:2}
#lbn{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#lbc{color:#fffa;font-variant-numeric:tabular-nums;margin-right:6px}
.lbbar button,.lbbar a,.nav{background:none;border:0;color:#fff;cursor:pointer;text-decoration:none;font-size:18px;width:38px;height:38px;display:grid;place-items:center;border-radius:50%}
.lbbar button:hover,.lbbar a:hover,.nav:hover{background:#fff2}
.nav{position:absolute;top:50%;transform:translateY(-50%);width:48px;height:64px;border-radius:12px;font-size:34px;z-index:2}
.prev{left:6px}.next{right:6px}
@media(max-width:700px){.nav{display:none}input[type=search]{width:120px;flex:1}.sp{display:none}}
button:focus-visible,input:focus-visible,select:focus-visible,.t:focus-visible{outline:2px solid var(--acc);outline-offset:2px}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>
<header>
  <div class="r1">
    <h1><?= $title ?></h1><span id="cnt"></span><span class="sp"></span>
    <input type="search" id="q" placeholder="Suchen …" aria-label="Suchen" autocomplete="off">
    <select id="sort" aria-label="Sortierung">
      <option value="new">Neueste zuerst</option><option value="old">Älteste zuerst</option>
      <option value="name">Name</option><option value="rand">Zufall</option>
    </select>
    <input type="range" id="dens" min="80" max="360" step="10" aria-label="Bildgröße" title="Bildgröße">
  </div>
  <div class="r2" id="chips"></div>
</header>
<main id="grid"></main>
<div id="empty">Keine Bilder gefunden. Lege Bilder in diesen Ordner oder ändere den Filter.</div>
<footer id="foot"><?= $pending > 0 ? 'Farbanalyse läuft: ' . (int)$pending . ' offen. Seite neu laden.' : '' ?></footer>

<div id="lb" hidden role="dialog" aria-modal="true" aria-label="Bildansicht">
  <div class="lbbar">
    <span id="lbn"></span><span id="lbc"></span>
    <button id="lbPlay" title="Diashow (Leertaste)" aria-label="Diashow">▶</button>
    <button id="lbFs" title="Vollbild (F)" aria-label="Vollbild">⤢</button>
    <a id="lbO" target="_blank" rel="noopener" title="Original öffnen" aria-label="Original öffnen">↗</a>
    <button id="lbX" title="Schließen (Esc)" aria-label="Schließen">✕</button>
  </div>
  <div class="lbimg"><img id="lbi" alt=""></div>
  <button class="nav prev" id="lbP" aria-label="Zurück">‹</button>
  <button class="nav next" id="lbN" aria-label="Weiter">›</button>
</div>

<script>
const D=<?= $json ?>;
const GAP=3,$=id=>document.getElementById(id);
const COL={dark:['dunkel','#26272b'],light:['hell','#ececee'],gray:['grau','#8a8d94'],red:['rot','#e5484d'],orange:['orange','#f08a24'],yellow:['gelb','#f5d90a'],green:['grün','#30a46c'],cyan:['cyan','#12a594'],blue:['blau','#3e63dd'],violet:['violett','#8e4ec6'],pink:['pink','#d6409f']};
function cat(c){
  const r=parseInt(c.slice(0,2),16)/255,g=parseInt(c.slice(2,4),16)/255,b=parseInt(c.slice(4,6),16)/255;
  const mx=Math.max(r,g,b),mn=Math.min(r,g,b),l=(mx+mn)/2,d=mx-mn;
  if(l<.18)return'dark';if(l>.85)return'light';
  if(d===0||d/(1-Math.abs(2*l-1))<.14)return'gray';
  let h=mx===r?((g-b)/d)%6:mx===g?(b-r)/d+2:(r-g)/d+4;h=(h*60+360)%360;
  return h<20||h>=340?'red':h<45?'orange':h<70?'yellow':h<170?'green':h<200?'cyan':h<260?'blue':h<300?'violet':'pink';
}
const items=D.map(([p,w,h,c,m])=>{
  c=c||'6b6f7a';
  const i=p.lastIndexOf('/');
  return{p,w,h,c,m,n:p.slice(i+1),a:i<0?'':p.slice(0,i),ar:w&&h?w/h:1.5,u:'./'+p.split('/').map(encodeURIComponent).join('/'),k:cat(c),gif:/\.gif$/i.test(p),s:p.toLowerCase()};
});
const grid=$('grid'),S={a:'*',k:'',q:'',sort:'new'};
let view=[],cur=0,lbOpen=false,timer=null,rnd=new Map();

function applyFilter(){
  const q=S.q.trim().toLowerCase();
  view=items.filter(it=>(S.a==='*'||it.a===S.a)&&(!S.k||it.k===S.k)&&(!q||it.s.includes(q)));
  const f={new:(a,b)=>b.m-a.m,old:(a,b)=>a.m-b.m,name:(a,b)=>a.p.localeCompare(b.p,'de',{numeric:true}),rand:(a,b)=>(rnd.get(a)||0)-(rnd.get(b)||0)}[S.sort];
  view.sort(f);
}
function thumb(it,w){
  if(it.gif)return it.u;
  const need=Math.ceil(w*(devicePixelRatio||1)),b=need<=320?320:need<=640?640:1024;
  return it.w&&it.w<=b?it.u:'?t='+encodeURIComponent(it.p)+'&w='+b;
}
function layout(){
  const cw=grid.clientWidth-GAP*2,th=+$('dens').value;
  grid.textContent='';
  if(cw<=0)return;
  let row=[],sum=0;
  const flush=last=>{
    const gaps=(row.length-1)*GAP;let h=(cw-gaps)/sum;
    if(last&&h>th*1.25)h=th;
    const rd=document.createElement('div');rd.className='row';rd.style.height=h+'px';
    row.forEach(i=>{
      const it=view[i],w=it.ar*h;
      const a=document.createElement('a');a.className='t';a.href=it.u;a.dataset.i=i;a.style.width=w+'px';a.style.background='#'+it.c;a.tabIndex=0;
      const im=new Image();im.alt=it.n;im.loading='lazy';im.decoding='async';
      im.onload=()=>im.classList.add('l');
      im.onerror=()=>{if(!im.dataset.f){im.dataset.f=1;im.src=it.u}};
      im.src=thumb(it,w);
      const sp=document.createElement('span');sp.textContent=it.n;
      a.append(im,sp);rd.append(a);
    });
    grid.append(rd);row=[];sum=0;
  };
  view.forEach((it,i)=>{row.push(i);sum+=it.ar;if(sum*th+(row.length-1)*GAP>=cw)flush(false)});
  if(row.length)flush(true);
}
function chips(){
  const box=$('chips');box.textContent='';
  const albums=[...new Set(items.map(i=>i.a))].sort((x,y)=>x.localeCompare(y,'de',{numeric:true}));
  const mk=(txt,n,on,fn,cls)=>{const b=document.createElement('button');b.className=cls||'chip'+(on?' on':'');b.textContent=txt;
    if(n!=null){const s=document.createElement('small');s.textContent=n;b.append(s)}b.onclick=fn;return b};
  if(albums.length>1){
    box.append(mk('Alle',items.length,S.a==='*',()=>{S.a='*';go()}));
    albums.forEach(a=>box.append(mk(a||'Hauptordner',items.filter(i=>i.a===a).length,S.a===a,()=>{S.a=a;go()})));
    const d=document.createElement('span');d.className='div';box.append(d);
  }
  const have=new Set(items.map(i=>i.k));
  Object.keys(COL).filter(k=>have.has(k)).forEach(k=>{
    const b=document.createElement('button');b.className='dot'+(S.k===k?' on':'');b.style.background=COL[k][1];
    b.title=COL[k][0];b.setAttribute('aria-label','Farbe '+COL[k][0]);
    b.onclick=()=>{S.k=S.k===k?'':k;go()};box.append(b);
  });
}
function go(){
  applyFilter();chips();layout();
  $('cnt').textContent=view.length+' / '+items.length;
  $('empty').style.display=view.length?'none':'block';
}

/* Steuerung */
let qt;$('q').oninput=e=>{clearTimeout(qt);qt=setTimeout(()=>{S.q=e.target.value;go()},120)};
$('sort').onchange=e=>{S.sort=e.target.value;if(S.sort==='rand')items.forEach(i=>rnd.set(i,Math.random()));go()};
$('dens').value=(()=>{try{return localStorage.getItem('gal-dens')}catch(e){return null}})()||(innerWidth<600?110:170);
$('dens').oninput=()=>{try{localStorage.setItem('gal-dens',$('dens').value)}catch(e){}layout()};
let lastW=0;new ResizeObserver(()=>{const w=grid.clientWidth;if(w!==lastW){lastW=w;layout()}}).observe(grid);
grid.addEventListener('mouseover',e=>{const t=e.target.closest('.t');if(t)document.documentElement.style.setProperty('--acc','#'+view[t.dataset.i].c)});
grid.addEventListener('mouseleave',()=>document.documentElement.style.removeProperty('--acc'));
grid.addEventListener('click',e=>{
  const t=e.target.closest('.t');
  if(!t||e.metaKey||e.ctrlKey||e.shiftKey||e.button)return;
  e.preventDefault();open(+t.dataset.i);
});

/* Lightbox */
const lb=$('lb'),lbi=$('lbi');
lbi.onload=()=>lbi.style.opacity=1;
function show(){
  const it=view[cur];if(!it)return;
  lbi.style.opacity=.2;lbi.alt=it.n;lbi.src=it.u;
  lb.style.background='color-mix(in srgb,#'+it.c+' 20%,#060607)';
  $('lbn').textContent=it.p;$('lbc').textContent=(cur+1)+' / '+view.length;$('lbO').href=it.u;
  [1,-1].forEach(d=>{const n=view[(cur+d+view.length)%view.length];if(n)new Image().src=n.u});
}
function open(i,push=true){
  cur=i;const h='#'+encodeURIComponent(view[i].p);
  if(!lbOpen){lbOpen=true;lb.hidden=false;document.body.style.overflow='hidden';if(push)history.pushState({lb:1},'',h)}
  else history.replaceState({lb:1},'',h);
  show();
}
function step(d){if(!view.length)return;open((cur+d+view.length)%view.length)}
function stop(){clearInterval(timer);timer=null;$('lbPlay').textContent='▶'}
function close(fromPop){
  if(!lbOpen)return;
  lbOpen=false;lb.hidden=true;stop();document.body.style.overflow='';
  if(document.fullscreenElement)document.exitFullscreen();
  if(fromPop)return;
  if(history.state&&history.state.lb)history.back();else history.replaceState(null,'',location.pathname+location.search);
}
addEventListener('popstate',()=>{if(lbOpen&&!(history.state&&history.state.lb))close(true)});
$('lbX').onclick=()=>close();
$('lbP').onclick=()=>{stop();step(-1)};$('lbN').onclick=()=>{stop();step(1)};
$('lbPlay').onclick=()=>{if(timer)return stop();$('lbPlay').textContent='❚❚';timer=setInterval(()=>step(1),4000)};
$('lbFs').onclick=()=>document.fullscreenElement?document.exitFullscreen():lb.requestFullscreen&&lb.requestFullscreen();
lb.addEventListener('click',e=>{if(e.target===lb||e.target.classList.contains('lbimg'))close()});
let sx=null;
lb.addEventListener('pointerdown',e=>{sx=e.target===lbi?e.clientX:null});
lb.addEventListener('pointerup',e=>{if(sx===null)return;const dx=e.clientX-sx;sx=null;if(Math.abs(dx)>50){stop();step(dx<0?1:-1)}});
addEventListener('keydown',e=>{
  if(!lbOpen||e.target.tagName==='INPUT')return;
  if(e.key==='Escape')close();
  else if(e.key==='ArrowRight'){stop();step(1)}
  else if(e.key==='ArrowLeft'){stop();step(-1)}
  else if(e.key===' '){e.preventDefault();$('lbPlay').click()}
  else if(e.key==='f'||e.key==='F')$('lbFs').click();
});

go();
if(location.hash.length>1){
  let p='';try{p=decodeURIComponent(location.hash.slice(1))}catch(e){}
  let i=view.findIndex(it=>it.p===p);
  if(i<0&&items.some(it=>it.p===p)){S.a='*';S.k='';S.q='';go();i=view.findIndex(it=>it.p===p)}
  if(i>=0)open(i,false);
}
</script>
</body>
</html>

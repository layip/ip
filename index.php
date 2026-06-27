<?php
/**
 * SENTINEL - PHP standalone safe conversion
 * Chạy: php -S localhost:8000 index.php
 * Không có Telegram bot/camera/IP. Có thể lưu vị trí GPS chỉ khi người xem bấm nút đồng ý rõ ràng.
 */
declare(strict_types=1);
session_start();

const DB_FILE = __DIR__ . '/sentinel.sqlite';
const ADMIN_PASS = '123';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS links (
        id TEXT PRIMARY KEY,
        title TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '',
        image TEXT NOT NULL DEFAULT '',
        redirect_url TEXT NOT NULL DEFAULT '',
        clicks INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        color TEXT NOT NULL DEFAULT '#2563eb',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    ensure_column($pdo, 'links', 'category_id', 'INTEGER');
    ensure_column($pdo, 'links', 'fake_slug', 'TEXT');
    ensure_column($pdo, 'links', 'preview_mode', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'links', 'location_consent', 'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS location_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        link_id TEXT NOT NULL,
        lat REAL NOT NULL,
        lon REAL NOT NULL,
        accuracy REAL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    seed_categories($pdo);
    seed_settings($pdo);
    return $pdo;
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if (($col['name'] ?? '') === $column) return;
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function seed_categories(PDO $pdo): void {
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO categories (name, color) VALUES (:name, :color)');
    foreach ([['Chung', '#2563eb'], ['Ảnh', '#9333ea'], ['Tin tức', '#16a34a']] as $row) {
        $stmt->execute([':name' => $row[0], ':color' => $row[1]]);
    }
}

function seed_settings(PDO $pdo): void {
    $defaults = [
        'root_title' => 'Security Sync',
        'root_desc' => 'Identity Verification Required',
        'root_img' => 'https://www.gstatic.com/images/branding/product/2x/photos_96dp.png',
        'root_redir' => 'https://google.com',
        'ui_msg' => 'ĐANG LOADING...',
        'ui_st' => 'KIỂM TRA TRÌNH DUYỆT',
        'btn_text' => 'TIẾP TỤC',
        'brand_color' => '#2563eb',
        'accent_color' => '#9333ea',
        'card_radius' => '28',
        'custom_css' => '',
        'bg_color' => '#f1f5f9',
        'card_color' => '#ffffff',
        'text_color' => '#0f172a',
        'button_radius' => '999',
        'font_family' => 'system',
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
    foreach ($defaults as $key => $value) $stmt->execute([':key' => $key, ':value' => $value]);
}

function setting(string $key): string {
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : '';
}
function set_setting(string $key, string $value): void {
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([':key' => $key, ':value' => $value]);
}
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function make_id(string $url): string {
    $host = parse_url($url, PHP_URL_HOST) ?: 'link';
    $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $host)), '-') ?: 'link';
    return substr($slug, 0, 24) . '-' . bin2hex(random_bytes(3));
}
function categories(): array { return db()->query('SELECT * FROM categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); }
function category_options(?int $selected = null): string {
    $html = '<option value="">Không phân loại</option>';
    foreach (categories() as $cat) {
        $sel = ((int) $cat['id'] === (int) $selected) ? ' selected' : '';
        $html .= '<option value="' . h((string) $cat['id']) . '"' . $sel . '>' . h($cat['name']) . '</option>';
    }
    return $html;
}
function current_url(array $params = []): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?');
    return $scheme . '://' . $host . $path . ($params ? '?' . http_build_query($params) : '');
}
function redirect_to(string $url): never { header('Location: ' . $url, true, 302); exit; }

if (($_GET['action'] ?? '') === 'logout') { unset($_SESSION['sentinel_auth']); redirect_to(current_url(['admin' => '1'])); }
if (($_GET['action'] ?? '') === 'save_location') { handle_location_event(); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') handle_post();
if (isset($_GET['admin'])) { render_admin(); exit; }
render_public();


function handle_location_event(): void {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) { http_response_code(400); echo json_encode(['ok' => false]); return; }
    $linkId = trim((string) ($payload['link_id'] ?? ''));
    $lat = filter_var($payload['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($payload['lon'] ?? null, FILTER_VALIDATE_FLOAT);
    $accuracy = filter_var($payload['accuracy'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    if ($linkId === '' || $lat === false || $lon === false || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
        http_response_code(422); echo json_encode(['ok' => false]); return;
    }
    $stmt = db()->prepare('SELECT location_consent FROM links WHERE id = :id');
    $stmt->execute([':id' => $linkId]);
    if ((int) ($stmt->fetchColumn() ?: 0) !== 1) { http_response_code(403); echo json_encode(['ok' => false]); return; }
    db()->prepare('INSERT INTO location_events (link_id, lat, lon, accuracy) VALUES (:link_id, :lat, :lon, :accuracy)')->execute([
        ':link_id' => $linkId,
        ':lat' => (float) $lat,
        ':lon' => (float) $lon,
        ':accuracy' => $accuracy === false ? null : $accuracy,
    ]);
    echo json_encode(['ok' => true]);
}

function handle_post(): void {
    if (isset($_POST['login'])) {
        if (hash_equals(ADMIN_PASS, (string) ($_POST['password'] ?? ''))) $_SESSION['sentinel_auth'] = true;
        redirect_to(current_url(['admin' => '1']));
    }
    if (empty($_SESSION['sentinel_auth'])) redirect_to(current_url(['admin' => '1']));

    if (isset($_POST['save_settings'])) {
        foreach (['root_title','root_desc','root_img','root_redir','ui_msg','ui_st','btn_text','brand_color','accent_color','card_radius','custom_css','bg_color','card_color','text_color','button_radius','font_family'] as $key) {
            set_setting($key, trim((string) ($_POST[$key] ?? '')));
        }
        redirect_to(current_url(['admin' => '1', 'tab' => 'settings']));
    }
    if (isset($_POST['save_category'])) {
        $name = trim((string) ($_POST['category_name'] ?? ''));
        $color = trim((string) ($_POST['category_color'] ?? '#2563eb'));
        if ($name !== '') {
            $stmt = db()->prepare('INSERT INTO categories (name, color) VALUES (:name, :color) ON CONFLICT(name) DO UPDATE SET color = excluded.color');
            $stmt->execute([':name' => $name, ':color' => $color]);
        }
        redirect_to(current_url(['admin' => '1', 'tab' => 'categories']));
    }
    if (isset($_POST['delete_category'])) {
        $id = (int) ($_POST['category_id'] ?? 0);
        db()->prepare('UPDATE links SET category_id = NULL WHERE category_id = :id')->execute([':id' => $id]);
        db()->prepare('DELETE FROM categories WHERE id = :id')->execute([':id' => $id]);
        redirect_to(current_url(['admin' => '1', 'tab' => 'categories']));
    }
    if (isset($_POST['save_link'])) {
        $id = trim((string) ($_POST['id'] ?? ''));
        $redirectUrl = trim((string) ($_POST['redirect_url'] ?? ''));
        if ($id === '') $id = make_id($redirectUrl);
        $fakeSlug = trim((string) ($_POST['fake_slug'] ?? '')) ?: (string) preg_replace('/[^a-z0-9-]+/i', '-', strtolower($id));
        $stmt = db()->prepare('INSERT INTO links (id, title, description, image, redirect_url, category_id, fake_slug, preview_mode, location_consent) VALUES (:id, :title, :description, :image, :redirect_url, :category_id, :fake_slug, :preview_mode, :location_consent) ON CONFLICT(id) DO UPDATE SET title = excluded.title, description = excluded.description, image = excluded.image, redirect_url = excluded.redirect_url, category_id = excluded.category_id, fake_slug = excluded.fake_slug, preview_mode = excluded.preview_mode, location_consent = excluded.location_consent');
        $stmt->execute([
            ':id' => $id,
            ':title' => trim((string) ($_POST['title'] ?? '')),
            ':description' => trim((string) ($_POST['description'] ?? '')),
            ':image' => trim((string) ($_POST['image'] ?? '')),
            ':redirect_url' => $redirectUrl,
            ':category_id' => ($_POST['category_id'] ?? '') === '' ? null : (int) $_POST['category_id'],
            ':fake_slug' => $fakeSlug,
            ':preview_mode' => isset($_POST['preview_mode']) ? 1 : 0,
            ':location_consent' => isset($_POST['location_consent']) ? 1 : 0,
        ]);
        redirect_to(current_url(['admin' => '1']));
    }
    if (isset($_POST['delete_link'])) {
        db()->prepare('DELETE FROM links WHERE id = :id')->execute([':id' => (string) ($_POST['id'] ?? '')]);
        redirect_to(current_url(['admin' => '1']));
    }
    redirect_to(current_url(['admin' => '1']));
}

function render_public(): void {
    $linkId = trim((string) ($_GET['v'] ?? ''));
    $imageSlug = trim((string) ($_GET['img'] ?? ''));
    $link = null;
    if ($linkId !== '' || $imageSlug !== '') {
        $stmt = db()->prepare($imageSlug !== '' ? 'SELECT * FROM links WHERE fake_slug = :id' : 'SELECT * FROM links WHERE id = :id');
        $stmt->execute([':id' => $imageSlug !== '' ? $imageSlug : $linkId]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($link) db()->prepare('UPDATE links SET clicks = clicks + 1 WHERE id = :id')->execute([':id' => $link['id']]);
    }
    $title = $link['title'] ?? setting('root_title');
    $desc = $link['description'] ?? setting('root_desc');
    $img = $link['image'] ?? setting('root_img');
    $redir = $link['redirect_url'] ?? setting('root_redir');
    $canShareLocation = $link && (int) ($link['location_consent'] ?? 0) === 1;
    $preview = $link && ((int) ($link['preview_mode'] ?? 0) === 1 || $imageSlug !== '' || $canShareLocation);
    ?>
<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= h($title) ?></title><meta name="description" content="<?= h($desc) ?>"><meta property="og:title" content="<?= h($title) ?>"><meta property="og:description" content="<?= h($desc) ?>"><meta property="og:image" content="<?= h($img) ?>"><style><?= base_css() ?></style></head>
<body class="public"><main class="verify-card">
  <div class="loader" aria-hidden="true"></div><p class="eyebrow"><?= h(setting('ui_msg')) ?></p><h1><?= h($title) ?></h1><p><?= h($desc) ?></p>
  <?php if ($img !== ''): ?><img class="preview" src="<?= h($img) ?>" alt="Ảnh minh họa"><?php endif; ?>
  <p class="safe-note">Trang này không dùng bot/camera/IP. Vị trí GPS chỉ được lưu nếu bạn bấm nút chia sẻ vị trí và trình duyệt hiển thị hộp thoại cho phép.</p>
  <?php if ($canShareLocation): ?><button class="button ghost" type="button" onclick="shareLocation()">Chia sẻ vị trí của tôi</button><p id="geoStatus" class="geo-status"></p><?php endif; ?>
  <?php if ($preview): ?><a class="button" href="<?= h($redir) ?>" rel="nofollow noopener">Mở link / xem ảnh</a><?php else: ?><script>setTimeout(()=>{location.href=<?= json_encode($redir) ?>},900)</script><a class="button" href="<?= h($redir) ?>" rel="nofollow noopener"><?= h(setting('btn_text')) ?></a><?php endif; ?>
</main><?php if ($canShareLocation): ?><script>async function shareLocation(){const s=document.getElementById('geoStatus'); if(!navigator.geolocation){s.textContent='Trình duyệt không hỗ trợ vị trí.';return;} s.textContent='Đang chờ bạn cấp quyền vị trí...'; navigator.geolocation.getCurrentPosition(async p=>{const res=await fetch('?action=save_location',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({link_id:<?= json_encode((string) $link['id']) ?>,lat:p.coords.latitude,lon:p.coords.longitude,accuracy:p.coords.accuracy})}); s.textContent=res.ok?'Đã lưu vị trí theo đồng ý của bạn.':'Không lưu được vị trí.';},()=>{s.textContent='Bạn đã từ chối hoặc trình duyệt chặn quyền vị trí.';},{enableHighAccuracy:true,timeout:15000,maximumAge:0});}</script><?php endif; ?></body></html><?php
}

function render_admin(): void {
    if (empty($_SESSION['sentinel_auth'])) { render_login(); return; }
    $edit = null;
    if (!empty($_GET['edit'])) { $stmt = db()->prepare('SELECT * FROM links WHERE id = :id'); $stmt->execute([':id' => (string) $_GET['edit']]); $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null; }
    $links = db()->query('SELECT links.*, categories.name AS category_name, categories.color AS category_color FROM links LEFT JOIN categories ON categories.id = links.category_id ORDER BY clicks DESC, links.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $categoryStats = db()->query("SELECT COALESCE(categories.name, 'Không phân loại') AS name, COALESCE(categories.color, '#64748b') AS color, COUNT(links.id) AS total, COALESCE(SUM(links.clicks),0) AS clicks FROM links LEFT JOIN categories ON categories.id = links.category_id GROUP BY links.category_id ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
    $locations = db()->query('SELECT location_events.*, links.title FROM location_events LEFT JOIN links ON links.id = location_events.link_id ORDER BY location_events.id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    ?>
<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin - Sentinel PHP</title><style><?= base_css() ?></style></head><body>
<div class="admin-layout"><aside class="sidebar"><h1>SENTINEL PHP</h1><p>Full code PHP, không bot, quản lý link an toàn.</p><a class="button secondary" href="<?= h(current_url()) ?>">Xem trang public</a><a class="danger-link" href="<?= h(current_url(['action' => 'logout'])) ?>">Đăng xuất</a></aside>
<main class="admin-main">
<section class="panel"><h2><?= $edit ? 'Sửa link' : 'Tạo link' ?></h2><form method="post" class="form-grid"><input type="hidden" name="save_link" value="1"><label>ID<input name="id" value="<?= h($edit['id'] ?? '') ?>" placeholder="Tự tạo nếu bỏ trống" <?= $edit ? 'readonly' : '' ?>></label><label>Tiêu đề<input name="title" value="<?= h($edit['title'] ?? '') ?>" required></label><label>Mô tả<textarea name="description" rows="3"><?= h($edit['description'] ?? '') ?></textarea></label><label>Ảnh OG / ảnh hiển thị<input name="image" value="<?= h($edit['image'] ?? '') ?>" placeholder="https://..."></label><label>Redirect URL / link ảnh thật<input name="redirect_url" value="<?= h($edit['redirect_url'] ?? '') ?>" placeholder="https://..." required></label><label>Phân loại<select name="category_id"><?= category_options(isset($edit['category_id']) ? (int) $edit['category_id'] : null) ?></select></label><label>Slug link ảnh ẩn<input name="fake_slug" value="<?= h($edit['fake_slug'] ?? '') ?>" placeholder="anh-hd-rieng-tu"></label><label class="check-row"><input type="checkbox" name="preview_mode" value="1" <?= !empty($edit['preview_mode']) ? 'checked' : '' ?>> Bật trang xem trước trước khi chuyển hướng</label><label class="check-row"><input type="checkbox" name="location_consent" value="1" <?= !empty($edit['location_consent']) ? 'checked' : '' ?>> Cho phép người xem tự bấm chia sẻ vị trí GPS (minh bạch)</label><button class="button" type="submit">Lưu link</button></form></section>
<section class="panel"><h2>Cấu hình giao diện</h2><form method="post" class="form-grid"><input type="hidden" name="save_settings" value="1"><label>Root title<input name="root_title" value="<?= h(setting('root_title')) ?>"></label><label>Root desc<textarea name="root_desc" rows="2"><?= h(setting('root_desc')) ?></textarea></label><label>Root image<input name="root_img" value="<?= h(setting('root_img')) ?>"></label><label>Root redirect<input name="root_redir" value="<?= h(setting('root_redir')) ?>"></label><label>Loading text<input name="ui_msg" value="<?= h(setting('ui_msg')) ?>"></label><label>Status text<input name="ui_st" value="<?= h(setting('ui_st')) ?>"></label><label>Button text<input name="btn_text" value="<?= h(setting('btn_text')) ?>"></label><label>Màu chính<input type="color" name="brand_color" value="<?= h(setting('brand_color')) ?>"></label><label>Màu nền<input type="color" name="bg_color" value="<?= h(setting('bg_color')) ?>"></label><label>Màu card<input type="color" name="card_color" value="<?= h(setting('card_color')) ?>"></label><label>Màu chữ<input type="color" name="text_color" value="<?= h(setting('text_color')) ?>"></label><label>Màu phụ<input type="color" name="accent_color" value="<?= h(setting('accent_color')) ?>"></label><label>Bo góc card (px)<input type="number" min="8" max="64" name="card_radius" value="<?= h(setting('card_radius')) ?>"></label><label>Bo góc nút (px)<input type="number" min="0" max="999" name="button_radius" value="<?= h(setting('button_radius')) ?>"></label><label>Font<select name="font_family"><option value="system" <?= setting('font_family')==='system'?'selected':'' ?>>System</option><option value="serif" <?= setting('font_family')==='serif'?'selected':'' ?>>Serif</option><option value="mono" <?= setting('font_family')==='mono'?'selected':'' ?>>Mono</option></select></label><label>CSS tùy chỉnh<textarea name="custom_css" rows="4" placeholder=".verify-card{...}"><?= h(setting('custom_css')) ?></textarea></label><button class="button" type="submit">Lưu cấu hình</button></form></section>
<section class="panel"><h2>Phân loại</h2><form method="post" class="form-grid"><input type="hidden" name="save_category" value="1"><label>Tên phân loại<input name="category_name" placeholder="Ví dụ: Ảnh, Tin tức, Khách hàng" required></label><label>Màu<input type="color" name="category_color" value="#2563eb"></label><button class="button" type="submit">Thêm / cập nhật phân loại</button></form><div class="chips"><?php foreach (categories() as $cat): ?><form method="post" class="chip-form" onsubmit="return confirm('Xóa phân loại này?')"><input type="hidden" name="delete_category" value="1"><input type="hidden" name="category_id" value="<?= h((string) $cat['id']) ?>"><button class="chip" style="--chip: <?= h($cat['color']) ?>"><?= h($cat['name']) ?> ×</button></form><?php endforeach; ?></div></section>
<section class="panel full"><h2>Tổng quan phân loại</h2><div class="stat-grid"><?php foreach ($categoryStats as $stat): ?><div class="stat-card" style="--chip: <?= h($stat['color']) ?>"><b><?= h($stat['name']) ?></b><span><?= (int) $stat['total'] ?> link · <?= (int) $stat['clicks'] ?> lượt mở</span></div><?php endforeach; ?><?php if (!$categoryStats): ?><p>Chưa có dữ liệu phân loại.</p><?php endif; ?></div></section><section class="panel full"><h2>Vị trí đã được người xem đồng ý chia sẻ</h2><div class="table-wrap"><table><thead><tr><th>Link</th><th>Tọa độ</th><th>Độ chính xác</th><th>Thời gian</th><th>Map</th></tr></thead><tbody><?php foreach ($locations as $loc): ?><tr><td><?= h($loc['title'] ?: $loc['link_id']) ?></td><td><code><?= h((string) $loc['lat']) ?>, <?= h((string) $loc['lon']) ?></code></td><td><?= h((string) ($loc['accuracy'] ?? '')) ?>m</td><td><?= h($loc['created_at']) ?></td><td><a class="button secondary" target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= h((string) $loc['lat']) ?>,<?= h((string) $loc['lon']) ?>">Maps</a></td></tr><?php endforeach; ?><?php if (!$locations): ?><tr><td colspan="5">Chưa có vị trí nào được chia sẻ bằng nút đồng ý.</td></tr><?php endif; ?></tbody></table></div></section><section class="panel full"><h2>Danh sách link</h2><div class="table-wrap"><table><thead><tr><th>Meta</th><th>URL</th><th>Link ảnh</th><th>Vị trí</th><th>Lượt mở</th><th>Hành động</th></tr></thead><tbody><?php foreach ($links as $link): $publicUrl = current_url(['v' => $link['id']]); ?><tr><td><strong><?= h($link['title']) ?></strong><br><code><?= h($link['id']) ?></code><?php if (!empty($link['category_name'])): ?><br><span class="mini-chip" style="--chip: <?= h($link['category_color']) ?>"><?= h($link['category_name']) ?></span><?php endif; ?></td><td><a href="<?= h($publicUrl) ?>"><?= h($publicUrl) ?></a><br><small><?= h($link['redirect_url']) ?></small></td><td><?php if (!empty($link['fake_slug'])): ?><a href="<?= h(current_url(['img' => $link['fake_slug']])) ?>"><?= h(current_url(['img' => $link['fake_slug']])) ?></a><?php else: ?>—<?php endif; ?></td><td><?= !empty($link['location_consent']) ? 'Nút đồng ý' : 'Tắt' ?></td><td><?= (int) $link['clicks'] ?></td><td class="actions"><a class="button secondary" href="<?= h(current_url(['admin' => '1', 'edit' => $link['id']])) ?>">Sửa</a><form method="post" onsubmit="return confirm('Xóa link này?')"><input type="hidden" name="delete_link" value="1"><input type="hidden" name="id" value="<?= h($link['id']) ?>"><button class="button danger" type="submit">Xóa</button></form></td></tr><?php endforeach; ?><?php if (!$links): ?><tr><td colspan="5">Chưa có link nào.</td></tr><?php endif; ?></tbody></table></div></section>
</main></div></body></html><?php
}

function render_login(): void { ?>
<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Đăng nhập</title><style><?= base_css() ?></style></head><body class="public"><form method="post" class="verify-card login-card"><h1>Admin</h1><p>Nhập mật khẩu quản trị để tạo/sửa link.</p><input type="hidden" name="login" value="1"><label>Mật khẩu<input type="password" name="password" autofocus required></label><button class="button" type="submit">Đăng nhập</button></form></body></html><?php }

function base_css(): string {
    $brand = preg_match('/^#[0-9a-f]{6}$/i', setting('brand_color')) ? setting('brand_color') : '#2563eb';
    $accent = preg_match('/^#[0-9a-f]{6}$/i', setting('accent_color')) ? setting('accent_color') : '#9333ea';
    $radius = max(8, min(64, (int) setting('card_radius')));
    $buttonRadius = max(0, min(999, (int) setting('button_radius')));
    $bg = preg_match('/^#[0-9a-f]{6}$/i', setting('bg_color')) ? setting('bg_color') : '#f1f5f9';
    $card = preg_match('/^#[0-9a-f]{6}$/i', setting('card_color')) ? setting('card_color') : '#ffffff';
    $ink = preg_match('/^#[0-9a-f]{6}$/i', setting('text_color')) ? setting('text_color') : '#0f172a';
    $fontMap = ['system' => 'system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif', 'serif' => 'Georgia,"Times New Roman",serif', 'mono' => '"SFMono-Regular",Consolas,monospace'];
    $font = $fontMap[setting('font_family')] ?? $fontMap['system'];
    $custom = setting('custom_css');
    return <<<CSS
:root{color-scheme:light dark;--bg:{$bg};--card:{$card};--ink:{$ink};--muted:#64748b;--brand:{$brand};--accent:{$accent};--radius:{$radius}px;--button-radius:{$buttonRadius}px;--line:#dbe3ef;--danger:#dc2626}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:{$font}}.public{min-height:100vh;display:grid;place-items:center;padding:24px;background:linear-gradient(135deg,color-mix(in srgb,var(--brand),white 80%),color-mix(in srgb,var(--accent),white 88%))}.verify-card{width:min(460px,100%);background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:32px;text-align:center;box-shadow:0 24px 70px rgba(15,23,42,.16)}.verify-card h1{margin:8px 0 10px;font-size:clamp(1.6rem,5vw,2.4rem)}.verify-card p{color:var(--muted);line-height:1.7}.eyebrow{font-size:.75rem;font-weight:900;letter-spacing:.18em;text-transform:uppercase}.loader{width:44px;height:44px;margin:0 auto 18px;border:4px solid #bfdbfe;border-top-color:var(--brand);border-radius:50%;animation:spin 1s linear infinite}.preview{width:100%;max-height:220px;object-fit:cover;border-radius:18px;border:1px solid var(--line);margin:10px 0}.safe-note{padding:12px;border-radius:14px;background:#ecfdf5;color:#047857!important;font-size:.9rem}.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:var(--button-radius);background:var(--brand);color:#fff;padding:12px 18px;text-decoration:none;font-weight:800;cursor:pointer}.button.secondary{background:#e2e8f0;color:#0f172a}.button.ghost{background:transparent;color:var(--brand);border:1px solid var(--brand)}.geo-status{font-size:.9rem;font-weight:700}.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.stat-card{border:1px solid color-mix(in srgb,var(--chip),transparent 55%);background:color-mix(in srgb,var(--chip),transparent 90%);border-radius:18px;padding:16px;display:grid;gap:6px}.stat-card b{color:var(--chip)}.stat-card span{color:var(--muted)}.button.danger{background:var(--danger)}.danger-link{color:#fca5a5;text-decoration:none;font-weight:700}.admin-layout{min-height:100vh;display:grid;grid-template-columns:260px 1fr}.sidebar{background:linear-gradient(180deg,#0f172a,color-mix(in srgb,var(--brand),#0f172a 75%));color:#e2e8f0;padding:28px;display:flex;flex-direction:column;gap:16px}.sidebar h1{margin:0}.sidebar p{color:#94a3b8}.admin-main{padding:28px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;align-content:start}.panel{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:22px;box-shadow:0 18px 45px rgba(15,23,42,.08)}.panel.full{grid-column:1/-1}.form-grid{display:grid;gap:12px}label{display:grid;gap:6px;color:var(--muted);font-size:.9rem;font-weight:700;text-align:left}input,select,textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 12px;background:var(--card);color:var(--ink);font:inherit}textarea{resize:vertical}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:14px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}small,code{color:var(--muted)}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.actions form{margin:0}.check-row{display:flex;grid-template-columns:auto 1fr;align-items:center}.check-row input{width:auto}.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.chip-form{margin:0}.chip,.mini-chip{border:0;border-radius:999px;background:color-mix(in srgb,var(--chip),transparent 82%);color:var(--chip);padding:7px 10px;font-weight:800}.mini-chip{display:inline-flex;margin-top:6px;font-size:.78rem}{$custom}@keyframes spin{to{transform:rotate(360deg)}}@media (max-width:850px){.admin-layout{grid-template-columns:1fr}.admin-main{grid-template-columns:1fr}.sidebar{position:static}}@media (prefers-color-scheme:dark){:root{--bg:#020617;--card:#0f172a;--ink:#e2e8f0;--muted:#94a3b8;--line:#1e293b}.button.secondary{background:#1e293b;color:#e2e8f0}.safe-note{background:#052e16;color:#bbf7d0!important}}
CSS;
}

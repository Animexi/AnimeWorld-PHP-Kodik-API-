<?php
// ============================================
// Anime Site with Kodik API - Complete Edition (FINAL FIX)
// ============================================
// Features:
// - User system (register, login, profiles)
// - Bookmarks with categories (plan, watching, completed, favorite)
// - Watch history per episode (beautiful design)
// - Episode watched status visual indicator
// - Kodik API integration with database caching & retry logic
// - Search with pagination (limit 100), genre filter, sorting, visual highlight
// - Anime page with full material data
// - Threaded comments (reply) only on watch page
// - Fully responsive (desktop/mobile) with custom UI
// - Custom context menu (right-click) and left-click actions
// - Custom confirmation modals
// - First-time user mini guide (interactive tour)
// ============================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// ============================================
// Database Setup (SQLite)
// ============================================
$db_file = __DIR__ . '/anime.db';
try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            nickname TEXT,
            bio TEXT,
            avatar TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS bookmarks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            anime_id TEXT NOT NULL,
            title TEXT,
            poster TEXT,
            category TEXT DEFAULT 'plan',
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, anime_id)
        );

        CREATE TABLE IF NOT EXISTS history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            anime_id TEXT NOT NULL,
            anime_title TEXT,
            season INTEGER,
            episode INTEGER,
            translation_id INTEGER,
            watched_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS user_episodes (
            user_id INTEGER NOT NULL,
            anime_id TEXT NOT NULL,
            season INTEGER NOT NULL,
            episode INTEGER NOT NULL,
            watched BOOLEAN DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, anime_id, season, episode)
        );

        CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            anime_id TEXT NOT NULL,
            parent_id INTEGER DEFAULT 0,
            content TEXT NOT NULL,
            likes INTEGER DEFAULT 0,
            dislikes INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS comment_votes (
            user_id INTEGER NOT NULL,
            comment_id INTEGER NOT NULL,
            vote_type TEXT CHECK(vote_type IN ('like','dislike')),
            PRIMARY KEY (user_id, comment_id)
        );

        CREATE TABLE IF NOT EXISTS anime_cache (
            id TEXT PRIMARY KEY,  -- shikimori_id or kodik internal id
            data TEXT NOT NULL,   -- JSON
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// ============================================
// Kodik API Configuration
// ============================================
define('KODIK_TOKEN', 'ИСПОЛЬЗУЙТЕ ВАШ API КЛЮЧ');
define('KODIK_API_URL', 'https://kodikapi.com');
define('DEFAULT_LIMIT', 100); // Increased limit to 100

// ============================================
// Helper Functions
// ============================================
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUser($id = null) {
    global $pdo;
    if ($id === null && isLoggedIn()) $id = $_SESSION['user_id'];
    if (!$id) return null;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Cached Kodik API request with retry logic
function kodikRequest($endpoint, $params = [], $method = 'POST', $cache_ttl = 3600, $retries = 3) {
    $params['token'] = KODIK_TOKEN;
    ksort($params);
    $cache_key = md5($endpoint . serialize($params) . $method);
    $cache_file = __DIR__ . '/cache/' . $cache_key . '.json';
    if (!is_dir(__DIR__ . '/cache')) mkdir(__DIR__ . '/cache', 0777, true);
    
    // Check cache
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_ttl)) {
        return json_decode(file_get_contents($cache_file), true);
    }
    
    $url = KODIK_API_URL . '/' . $endpoint;
    $attempt = 0;
    while ($attempt < $retries) {
        $ch = curl_init();
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            $url .= '?' . http_build_query($params);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpcode === 200) {
            $data = json_decode($response, true);
            file_put_contents($cache_file, json_encode($data));
            return $data;
        }
        $attempt++;
        if ($attempt < $retries) {
            sleep(1); // wait before retry
        }
    }
    return null;
}

// Get anime by ID with retry
function getAnimeById($id) {
    global $pdo;
    // Check cache first (7 days)
    $stmt = $pdo->prepare("SELECT data FROM anime_cache WHERE id = ? AND datetime(fetched_at) > datetime('now', '-7 day')");
    $stmt->execute([$id]);
    $cached = $stmt->fetch();
    if ($cached) {
        return json_decode($cached['data'], true);
    }
    // Determine if ID is shikimori_id (numeric) or kodik id (contains hyphen)
    if (strpos($id, '-') !== false) {
        // Kodik internal id
        $params = ['id' => $id];
    } else {
        // Assume shikimori_id
        $params = ['shikimori_id' => $id];
    }
    $params['with_material_data'] = 'true';
    $params['with_episodes_data'] = 'true';
    $result = kodikRequest('search', $params, 'POST', 3600, 5); // 5 retries
    
    if ($result && isset($result['results']) && count($result['results']) > 0) {
        $anime = $result['results'][0];
        // Store in cache
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO anime_cache (id, data) VALUES (?, ?)");
        $stmt->execute([$id, json_encode($anime)]);
        return $anime;
    }
    return null;
}

// Search with pagination
function searchAnime($title, $limit = DEFAULT_LIMIT, $next = null) {
    $params = ['title' => $title, 'limit' => $limit, 'with_material_data' => 'true'];
    if ($next) $params['next'] = $next;
    return kodikRequest('search', $params, 'POST', 1800); // 30 min cache
}

// Get list with pagination
function getAnimeList($filters = [], $limit = DEFAULT_LIMIT, $next = null) {
    $params = array_merge(['limit' => $limit, 'with_material_data' => 'true'], $filters);
    if ($next) $params['next'] = $next;
    return kodikRequest('list', $params, 'POST', 3600); // 1 hour cache
}

// Extract next page token from full URL
function extractNextToken($next_page_url) {
    if (!$next_page_url) return null;
    $parts = parse_url($next_page_url);
    parse_str($parts['query'] ?? '', $query);
    return $query['next'] ?? null;
}

// ============================================
// Routing & POST Handling
// ============================================
$page = $_GET['page'] ?? 'home';
$action = $_GET['action'] ?? '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['register'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $nickname = trim($_POST['nickname']);
        $bio = trim($_POST['bio']);
        $avatar = '';
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/avatars/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $avatar = 'uploads/avatars/' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['avatar']['tmp_name'], __DIR__ . '/' . $avatar);
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, nickname, bio, avatar) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $password, $nickname, $bio, $avatar]);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            header('Location: ?page=profile');
            exit;
        } catch (PDOException $e) {
            $error = "Username or email already exists.";
        }
    } elseif (isset($_POST['login'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            header('Location: ?page=profile');
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    } elseif (isset($_POST['logout'])) {
        session_destroy();
        header('Location: ?page=home');
        exit;
    } elseif (isset($_POST['update_profile']) && isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        $nickname = trim($_POST['nickname']);
        $bio = trim($_POST['bio']);
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/avatars/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $avatar = 'uploads/avatars/' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['avatar']['tmp_name'], __DIR__ . '/' . $avatar);
            $stmt = $pdo->prepare("UPDATE users SET nickname = ?, bio = ?, avatar = ? WHERE id = ?");
            $stmt->execute([$nickname, $bio, $avatar, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET nickname = ?, bio = ? WHERE id = ?");
            $stmt->execute([$nickname, $bio, $user_id]);
        }
        header('Location: ?page=profile');
        exit;
    } elseif (isset($_POST['add_bookmark']) && isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        $anime_id = $_POST['anime_id'];
        $title = $_POST['title'];
        $poster = $_POST['poster'];
        $category = $_POST['category'] ?? 'plan';
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO bookmarks (user_id, anime_id, title, poster, category) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $anime_id, $title, $poster, $category]);
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    } elseif (isset($_POST['remove_bookmark']) && isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        $anime_id = $_POST['anime_id'];
        $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ? AND anime_id = ?");
        $stmt->execute([$user_id, $anime_id]);
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    } elseif (isset($_POST['add_comment']) && isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        $anime_id = $_POST['anime_id'];
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $content = trim($_POST['content']);
        if (!empty($content)) {
            $stmt = $pdo->prepare("INSERT INTO comments (user_id, anime_id, parent_id, content) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $anime_id, $parent_id, $content]);
        }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    } elseif (isset($_POST['edit_comment']) && isLoggedIn()) {
        $comment_id = $_POST['comment_id'];
        $content = trim($_POST['content']);
        $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
        $stmt->execute([$comment_id]);
        $c = $stmt->fetch();
        if ($c && $c['user_id'] == $_SESSION['user_id']) {
            $stmt = $pdo->prepare("UPDATE comments SET content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$content, $comment_id]);
        }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    } elseif (isset($_POST['delete_comment']) && isLoggedIn()) {
        $comment_id = $_POST['comment_id'];
        $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
        $stmt->execute([$comment_id]);
        $c = $stmt->fetch();
        if ($c && $c['user_id'] == $_SESSION['user_id']) {
            $pdo->prepare("DELETE FROM comments WHERE id = ? OR parent_id = ?")->execute([$comment_id, $comment_id]);
            $pdo->prepare("DELETE FROM comment_votes WHERE comment_id = ?")->execute([$comment_id]);
        }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    } elseif (isset($_POST['vote_comment']) && isLoggedIn()) {
        $comment_id = $_POST['comment_id'];
        $vote = $_POST['vote_comment'];
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT vote_type FROM comment_votes WHERE user_id = ? AND comment_id = ?");
        $stmt->execute([$user_id, $comment_id]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ($existing['vote_type'] == $vote) {
                $pdo->prepare("DELETE FROM comment_votes WHERE user_id = ? AND comment_id = ?")->execute([$user_id, $comment_id]);
                if ($vote == 'like') {
                    $pdo->prepare("UPDATE comments SET likes = likes - 1 WHERE id = ?")->execute([$comment_id]);
                } else {
                    $pdo->prepare("UPDATE comments SET dislikes = dislikes - 1 WHERE id = ?")->execute([$comment_id]);
                }
            } else {
                $pdo->prepare("UPDATE comment_votes SET vote_type = ? WHERE user_id = ? AND comment_id = ?")->execute([$vote, $user_id, $comment_id]);
                if ($vote == 'like') {
                    $pdo->prepare("UPDATE comments SET likes = likes + 1, dislikes = dislikes - 1 WHERE id = ?")->execute([$comment_id]);
                } else {
                    $pdo->prepare("UPDATE comments SET dislikes = dislikes + 1, likes = likes - 1 WHERE id = ?")->execute([$comment_id]);
                }
            }
        } else {
            $pdo->prepare("INSERT INTO comment_votes (user_id, comment_id, vote_type) VALUES (?, ?, ?)")->execute([$user_id, $comment_id, $vote]);
            if ($vote == 'like') {
                $pdo->prepare("UPDATE comments SET likes = likes + 1 WHERE id = ?")->execute([$comment_id]);
            } else {
                $pdo->prepare("UPDATE comments SET dislikes = dislikes + 1 WHERE id = ?")->execute([$comment_id]);
            }
        }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    } elseif (isset($_POST['mark_episode']) && isLoggedIn()) {
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data) {
            $stmt = $pdo->prepare("INSERT INTO user_episodes (user_id, anime_id, season, episode, watched) VALUES (?, ?, ?, ?, 1) ON CONFLICT(user_id, anime_id, season, episode) DO UPDATE SET watched = 1, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$_SESSION['user_id'], $data['anime_id'], $data['season'], $data['episode']]);
            echo json_encode(['success' => true]);
            exit;
        }
    }
}

// ============================================
// AJAX Handlers (must be before HTML output)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $ajax_page = $_GET['page'] ?? '';
    
    if ($ajax_page == 'search') {
        $query = $_GET['q'] ?? '';
        $genre = $_GET['genre'] ?? '';
        $sort = $_GET['sort'] ?? 'shikimori_rating';
        $order = $_GET['order'] ?? 'desc';
        $next = $_GET['next'] ?? null;
        $seen_ids = isset($_GET['seen']) ? explode(',', $_GET['seen']) : [];
        
        $filters = [];
        if ($genre) $filters['anime_genres'] = $genre;
        $filters['sort'] = $sort;
        $filters['order'] = $order;
        
        if ($query) {
            $results = searchAnime($query, DEFAULT_LIMIT, $next);
        } else {
            $results = getAnimeList($filters, DEFAULT_LIMIT, $next);
        }
        
        if ($results && isset($results['results'])) {
            $new_seen = [];
            ob_start();
            foreach ($results['results'] as $item) {
                $id = $item['shikimori_id'] ?? $item['id'];
                if (!$id || in_array($id, $seen_ids) || in_array($id, $new_seen)) continue;
                $new_seen[] = $id;
                $title = $item['title'] ?? $item['title_orig'] ?? 'Без названия';
                $poster = $item['material_data']['poster_url'] ?? $item['screenshots'][0] ?? '';
                $genre_first = $item['material_data']['anime_genres'][0] ?? '';
                $episodes = $item['episodes_count'] ?? '?';
                $episodes_total = $item['material_data']['episodes_total'] ?? '?';
                $bookmarked = false;
                if (isLoggedIn()) {
                    global $pdo;
                    $stmt = $pdo->prepare("SELECT 1 FROM bookmarks WHERE user_id = ? AND anime_id = ?");
                    $stmt->execute([$_SESSION['user_id'], $id]);
                    $bookmarked = $stmt->fetch() ? true : false;
                }
                ?>
                <a href="?page=anime&id=<?= urlencode($id) ?>" class="anime-card <?= $bookmarked ? 'bookmarked' : '' ?>">
                    <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($title) ?>" loading="lazy">
                    <div class="info">
                        <h3><?= htmlspecialchars($title) ?></h3>
                        <div class="meta">
                            <span class="badge"><?= htmlspecialchars($genre_first) ?></span>
                            <span class="badge"><?= $episodes ?>/<?= $episodes_total ?></span>
                        </div>
                    </div>
                </a>
                <?php
            }
            $html = ob_get_clean();
            $next_token = extractNextToken($results['next_page'] ?? null);
            echo json_encode(['html' => $html, 'next' => $next_token, 'new_seen' => implode(',', array_merge($seen_ids, $new_seen))]);
            exit;
        }
        echo json_encode(['html' => '', 'next' => null]);
        exit;
    }
    
    if ($ajax_page == 'recent') {
        $next = $_GET['next'] ?? null;
        $seen_ids = isset($_GET['seen']) ? explode(',', $_GET['seen']) : [];
        
        $recent = getAnimeList(['sort' => 'created_at', 'order' => 'desc'], DEFAULT_LIMIT, $next);
        if ($recent && isset($recent['results'])) {
            $new_seen = [];
            ob_start();
            foreach ($recent['results'] as $item) {
                $id = $item['shikimori_id'] ?? $item['id'];
                if (!$id || in_array($id, $seen_ids) || in_array($id, $new_seen)) continue;
                $new_seen[] = $id;
                $title = $item['title'] ?? $item['title_orig'] ?? 'Без названия';
                $poster = $item['material_data']['poster_url'] ?? $item['screenshots'][0] ?? '';
                $genre = $item['material_data']['anime_genres'][0] ?? '';
                $episodes = $item['episodes_count'] ?? '?';
                $episodes_total = $item['material_data']['episodes_total'] ?? '?';
                $bookmarked = false;
                if (isLoggedIn()) {
                    global $pdo;
                    $stmt = $pdo->prepare("SELECT 1 FROM bookmarks WHERE user_id = ? AND anime_id = ?");
                    $stmt->execute([$_SESSION['user_id'], $id]);
                    $bookmarked = $stmt->fetch() ? true : false;
                }
                ?>
                <a href="?page=anime&id=<?= urlencode($id) ?>" class="anime-card <?= $bookmarked ? 'bookmarked' : '' ?>">
                    <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($title) ?>" loading="lazy">
                    <div class="info">
                        <h3><?= htmlspecialchars($title) ?></h3>
                        <div class="meta">
                            <span class="badge"><?= htmlspecialchars($genre) ?></span>
                            <span class="badge"><?= $episodes ?>/<?= $episodes_total ?></span>
                        </div>
                    </div>
                </a>
                <?php
            }
            $html = ob_get_clean();
            $next_token = extractNextToken($recent['next_page'] ?? null);
            echo json_encode(['html' => $html, 'next' => $next_token, 'new_seen' => implode(',', array_merge($seen_ids, $new_seen))]);
            exit;
        }
        echo json_encode(['html' => '', 'next' => null]);
        exit;
    }
    
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// ============================================
// HTML Output
// ============================================
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>AnimeWorld</title>
    <!-- Font Awesome Icons (real icons, no emoji) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- video.js -->
    <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet" />
    <script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>
    <style>
        /* ========== GLOBAL DESIGN TOKENS ========== */
        :root {
            --bg-dark: #0b0c10;
            --surface-dark: rgba(18, 20, 28, 0.8);
            --surface-glass: rgba(30, 32, 40, 0.7);
            --border-light: rgba(255, 180, 71, 0.2);
            --accent: #ffb347;
            --accent-glow: rgba(255, 180, 71, 0.4);
            --text-primary: #f0f0f0;
            --text-secondary: #b0b0b0;
            --card-bg: rgba(25, 28, 38, 0.9);
            --card-border: rgba(255, 180, 71, 0.15);
            --glass-blur: blur(16px);
            --radius-lg: 28px;
            --radius-md: 20px;
            --radius-sm: 16px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: var(--bg-dark);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            padding-bottom: env(safe-area-inset-bottom);
        }

        a {
            color: var(--accent);
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 24px;
        }

        /* ========== GLASS BUTTONS ========== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--accent);
            color: #0b0c10;
            padding: 12px 28px;
            border: none;
            border-radius: 40px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            box-shadow: 0 6px 20px var(--accent-glow);
            backdrop-filter: var(--glass-blur);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .btn:hover {
            background: #ffa01c;
            transform: translateY(-2px);
            box-shadow: 0 12px 28px var(--accent-glow);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--accent);
            color: var(--accent);
            box-shadow: none;
        }

        .btn-outline:hover {
            background: var(--accent);
            color: #0b0c10;
        }

        .btn-danger {
            background: #dc3545;
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.3);
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-sm {
            padding: 8px 20px;
            font-size: 14px;
        }

        /* ========== CARDS WITH GLASS ========== */
        .card {
            background: var(--surface-glass);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border-radius: var(--radius-lg);
            padding: 28px;
            margin-bottom: 28px;
            border: 1px solid var(--border-light);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
        }

        /* ========== GRID ========== */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 24px;
        }

        /* ========== ANIME CARD ========== */
        .anime-card {
            background: var(--card-bg);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border-radius: var(--radius-md);
            overflow: hidden;
            transition: transform 0.25s, box-shadow 0.25s;
            cursor: pointer;
            border: 1px solid var(--card-border);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.5);
        }

        .anime-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.8), 0 0 0 2px var(--accent-glow);
            border-color: var(--accent);
        }

        .anime-card img {
            width: 100%;
            height: 260px;
            object-fit: cover;
            border-bottom: 2px solid var(--accent);
        }

        .anime-card .info {
            padding: 18px 16px;
        }

        .anime-card h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: #fff;
            font-weight: 600;
            line-height: 1.3;
        }

        .anime-card .meta {
            font-size: 14px;
            color: var(--text-secondary);
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .anime-card .badge {
            background: rgba(255, 180, 71, 0.15);
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid rgba(255, 180, 71, 0.3);
        }

        .bookmarked {
            position: relative;
        }

        .bookmarked::after {
            content: "\f005"; /* fa-star */
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            top: 12px;
            right: 12px;
            background: var(--accent);
            color: #0b0c10;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.5);
            border: 2px solid rgba(255,255,255,0.3);
        }

        /* ========== FLEX UTILITIES ========== */
        .flex { display: flex; gap: 20px; flex-wrap: wrap; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .flex-center { display: flex; align-items: center; gap: 8px; }

        /* ========== FORMS ========== */
        .form-group { margin-bottom: 24px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-secondary); }
        .form-control {
            width: 100%;
            padding: 16px 20px;
            border: 1px solid rgba(255,180,71,0.3);
            border-radius: 60px;
            background: rgba(0,0,0,0.3);
            color: #fff;
            font-size: 16px;
            transition: 0.2s;
            backdrop-filter: blur(10px);
        }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }

        /* ========== HEADER & NAV (DESKTOP) ========== */
        .desktop-nav {
            background: rgba(18, 20, 28, 0.6);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border-bottom: 1px solid rgba(255, 180, 71, 0.25);
            padding: 12px 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .desktop-nav .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 28px;
            font-weight: 800;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 2px;
            text-shadow: 0 0 15px var(--accent-glow);
        }

        .nav-links {
            display: flex;
            gap: 28px;
            align-items: center;
        }

        .nav-links a {
            color: var(--text-primary);
            font-weight: 500;
            font-size: 16px;
            padding: 8px 0;
            position: relative;
        }

        .nav-links a:hover {
            color: var(--accent);
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0%;
            height: 2px;
            background: var(--accent);
            transition: width 0.2s;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        /* ========== BOTTOM NAV (MOBILE / TABLET) ========== */
        .bottom-nav {
            display: none;
            position: fixed;
            bottom: 16px;
            left: 16px;
            right: 16px;
            background: rgba(18, 20, 28, 0.85);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border-radius: 60px;
            padding: 8px 12px;
            border: 1px solid rgba(255, 180, 71, 0.5);
            box-shadow: 0 10px 30px rgba(0,0,0,0.7);
            z-index: 1000;
            overflow-x: auto;
            white-space: nowrap;
            justify-content: flex-start;
            align-items: center;
            gap: 4px;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none; /* Firefox */
        }

        .bottom-nav::-webkit-scrollbar {
            display: none; /* Chrome/Safari */
        }

        .bottom-nav a, .bottom-nav button {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            color: var(--text-secondary);
            font-size: 11px;
            padding: 6px 12px;
            border-radius: 40px;
            transition: 0.2s;
            gap: 2px;
            flex-shrink: 0;
            background: none;
            border: none;
            cursor: pointer;
        }

        .bottom-nav a i, .bottom-nav button i {
            font-size: 20px;
        }

        .bottom-nav a.active, .bottom-nav a:hover, .bottom-nav button.active, .bottom-nav button:hover {
            color: var(--accent);
            background: rgba(255, 180, 71, 0.15);
        }

        /* Hide desktop nav on mobile/tablet (width <= 900px) */
        @media (max-width: 900px) {
            .desktop-nav {
                display: none;
            }
            .bottom-nav {
                display: flex;
            }
            body {
                padding-bottom: 80px; /* space for bottom nav */
            }
            .container {
                padding: 16px;
            }
            .card {
                padding: 20px;
            }
        }

        /* ========== EPISODE GRID ========== */
        .episode-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 20px 0;
        }

        .episode-btn {
            background: rgba(255,255,255,0.05);
            color: #fff;
            padding: 12px 20px;
            border-radius: 40px;
            border: 1px solid rgba(255,180,71,0.3);
            transition: 0.2s;
            font-size: 15px;
            font-weight: 500;
            backdrop-filter: blur(4px);
        }

        .episode-btn:hover {
            background: rgba(255,180,71,0.2);
            border-color: var(--accent);
        }

        .episode-btn.watched {
            background: rgba(46, 125, 50, 0.6);
            border-color: #2e7d32;
            color: white;
        }

        /* ========== WATCH PAGE PREV/NEXT BUTTONS ========== */
        .watch-nav {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
        }

        @media (max-width: 900px) {
            .watch-nav {
                gap: 4px;
            }
            .watch-nav .btn-sm {
                padding: 8px 12px;
                font-size: 13px;
            }
        }

        /* ========== COMMENTS REDESIGN ========== */
        .comment {
            border-bottom: 1px solid rgba(255,180,71,0.2);
            padding: 28px 0;
        }

        .comment:last-child { border-bottom: none; }

        .comment-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .comment-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            object-fit: cover;
            background: #333;
            border: 2px solid var(--accent);
            box-shadow: 0 0 0 3px rgba(255,180,71,0.3);
        }

        .comment-author-info { display: flex; flex-direction: column; }

        .comment-author {
            font-weight: 600;
            color: var(--accent);
            font-size: 16px;
        }

        .comment-date {
            font-size: 12px;
            color: #888;
        }

        .comment-content {
            background: rgba(0,0,0,0.3);
            padding: 20px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            line-height: 1.5;
            border: 1px solid rgba(255,180,71,0.2);
        }

        .comment-actions {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .comment-actions button {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s;
            padding: 6px 12px;
            border-radius: 40px;
            background: rgba(255,255,255,0.03);
        }

        .comment-actions button:hover {
            color: var(--accent);
            background: rgba(255,180,71,0.15);
        }

        .vote-btn.liked { color: #ffb347; }
        .vote-btn.disliked { color: #ffb347; }

        .reply-form { margin-top: 20px; margin-left: 64px; }
        .replies { margin-left: 64px; }

        @media (max-width: 900px) {
            .reply-form { margin-left: 20px; }
            .replies { margin-left: 20px; }
        }

        /* ========== HISTORY CARD ========== */
        .history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .history-card {
            background: var(--card-bg);
            backdrop-filter: var(--glass-blur);
            border-radius: var(--radius-md);
            padding: 20px;
            display: flex;
            gap: 20px;
            align-items: center;
            border: 1px solid var(--card-border);
            transition: 0.2s;
        }

        .history-card:hover {
            border-color: var(--accent);
            transform: translateY(-4px);
        }

        .history-card img {
            width: 90px;
            height: 90px;
            border-radius: var(--radius-sm);
            object-fit: cover;
            border: 2px solid var(--accent);
        }

        /* ========== SKELETON LOADERS ========== */
        .skeleton {
            background: linear-gradient(90deg, #2a2a35 25%, #3a3a48 50%, #2a2a35 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: var(--radius-sm);
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .skeleton-card {
            height: 360px;
            width: 100%;
            border-radius: var(--radius-md);
        }

        /* ========== GENRE BUTTONS ========== */
        .genre-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .genre-btn {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,180,71,0.3);
            color: var(--text-primary);
            padding: 8px 22px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 500;
            transition: 0.2s;
            backdrop-filter: blur(4px);
        }

        .genre-btn:hover, .genre-btn.active {
            background: var(--accent);
            color: #0b0c10;
            border-color: var(--accent);
        }

        /* ========== SORT SELECT ========== */
        .sort-select {
            background: rgba(0,0,0,0.4);
            border: 1px solid rgba(255,180,71,0.4);
            color: #fff;
            padding: 10px 24px;
            border-radius: 40px;
            font-size: 14px;
            cursor: pointer;
            backdrop-filter: blur(4px);
        }

        .sort-select:focus { outline: none; border-color: var(--accent); }

        /* ========== MODAL (GUIDE & CONFIRM) ========== */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            align-items: center;
            justify-content: center;
            z-index: 2000;
            backdrop-filter: blur(8px);
        }

        .modal-content {
            background: var(--surface-glass);
            backdrop-filter: var(--glass-blur);
            padding: 32px;
            border-radius: 48px;
            max-width: 500px;
            width: 90%;
            text-align: center;
            border: 1px solid var(--accent);
            box-shadow: 0 30px 60px rgba(0,0,0,0.8);
        }

        .modal-buttons { display: flex; gap: 16px; justify-content: center; margin-top: 24px; flex-wrap: wrap; }

        /* Guide specific */
        .guide-slide {
            display: none;
        }
        .guide-slide.active {
            display: block;
        }
        .guide-slide h2 {
            margin-bottom: 16px;
            color: var(--accent);
        }
        .guide-slide p {
            margin-bottom: 12px;
            font-size: 16px;
        }
        .guide-slide i {
            font-size: 48px;
            color: var(--accent);
            margin-bottom: 16px;
        }
        .guide-dots {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin: 20px 0;
        }
        .guide-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--text-secondary);
            cursor: pointer;
            transition: 0.2s;
        }
        .guide-dot.active {
            background: var(--accent);
            transform: scale(1.2);
        }

        /* ========== CONTEXT MENU ========== */
        .context-menu {
            display: none;
            position: absolute;
            background: var(--surface-glass);
            backdrop-filter: var(--glass-blur);
            border: 1px solid var(--accent);
            border-radius: 24px;
            padding: 8px 0;
            min-width: 200px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.8);
            z-index: 1000;
        }

        .context-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 22px;
            color: var(--text-primary);
            font-size: 15px;
            transition: 0.2s;
        }

        .context-menu a:hover {
            background: rgba(255,180,71,0.2);
            color: var(--accent);
        }

        /* ========== WELCOME TEXT ANIMATION ========== */
        .welcome-text {
            text-align: center;
            animation: fadeInUp 0.8s ease forwards;
            margin-bottom: 20px;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ========== RESPONSIVE FINE-TUNING ========== */
        @media (max-width: 480px) {
            .grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .anime-card img {
                height: 200px;
            }
            .btn {
                width: 100%;
            }
            .genre-btn {
                flex: 1 1 calc(50% - 12px);
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- DESKTOP NAVIGATION (hidden on mobile/tablet) -->
    <div class="desktop-nav">
        <div class="container">
            <a href="?page=home" class="logo">AnimeWorld</a>
            <div class="nav-links">
                <a href="?page=home"><i class="fas fa-home"></i> Главная</a>
                <a href="?page=search"><i class="fas fa-search"></i> Поиск</a>
                <a href="?page=recent"><i class="fas fa-clock"></i> Новинки</a>
                <?php if (isLoggedIn()): ?>
                    <a href="?page=profile"><i class="fas fa-user"></i> Профиль</a>
                    <a href="?page=bookmarks"><i class="fas fa-bookmark"></i> Закладки</a>
                    <a href="?page=history"><i class="fas fa-history"></i> История</a>
                    <form method="post" style="display:inline;"><button type="submit" name="logout" class="btn btn-danger btn-sm"><i class="fas fa-sign-out-alt"></i> Выйти</button></form>
                <?php else: ?>
                    <a href="?page=login"><i class="fas fa-sign-in-alt"></i> Вход</a>
                    <a href="?page=register"><i class="fas fa-user-plus"></i> Регистрация</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- BOTTOM NAVIGATION (mobile/tablet) - scrollable if needed -->
    <div class="bottom-nav">
        <a href="?page=home" class="<?= $page == 'home' ? 'active' : '' ?>"><i class="fas fa-home"></i><span>Главная</span></a>
        <a href="?page=search" class="<?= $page == 'search' ? 'active' : '' ?>"><i class="fas fa-search"></i><span>Поиск</span></a>
        <a href="?page=recent" class="<?= $page == 'recent' ? 'active' : '' ?>"><i class="fas fa-clock"></i><span>Новинки</span></a>
        <?php if (isLoggedIn()): ?>
            <a href="?page=profile" class="<?= $page == 'profile' ? 'active' : '' ?>"><i class="fas fa-user"></i><span>Профиль</span></a>
            <a href="?page=bookmarks" class="<?= $page == 'bookmarks' ? 'active' : '' ?>"><i class="fas fa-bookmark"></i><span>Закладки</span></a>
            <a href="?page=history" class="<?= $page == 'history' ? 'active' : '' ?>"><i class="fas fa-history"></i><span>История</span></a>
            <form method="post" style="display:inline; margin:0; padding:0;"><button type="submit" name="logout" style="background:none; border:none; color:inherit; display:inline-flex; flex-direction:column; align-items:center; padding:6px 12px;"><i class="fas fa-sign-out-alt"></i><span>Выйти</span></button></form>
        <?php else: ?>
            <a href="?page=login" class="<?= $page == 'login' ? 'active' : '' ?>"><i class="fas fa-sign-in-alt"></i><span>Вход</span></a>
            <a href="?page=register" class="<?= $page == 'register' ? 'active' : '' ?>"><i class="fas fa-user-plus"></i><span>Регистрация</span></a>
        <?php endif; ?>
    </div>

    <main class="container">
        <?php
        // Page routing (same PHP logic as before, no changes)
        switch ($page) {
            case 'home':
                ?>
                <div class="welcome-text">
                    <h1>Добро пожаловать на AnimeWorld!</h1>
                    <p class="mb-2">Смотрите аниме онлайн бесплатно, без регистрации.</p>
                </div>
                <div class="card">
                    <h2>Поиск</h2>
                    <form method="get" action="?">
                        <input type="hidden" name="page" value="search">
                        <div class="flex">
                            <input type="text" name="q" placeholder="Название аниме..." class="form-control" style="flex:1;">
                            <button type="submit" class="btn"><i class="fas fa-search"></i> Найти</button>
                        </div>
                    </form>
                </div>
                <div class="card">
                    <h2>Популярное</h2>
                    <?php
                    $popular = getAnimeList(['sort' => 'shikimori_rating', 'order' => 'desc'], DEFAULT_LIMIT);
                    if ($popular && isset($popular['results'])):
                    ?>
                    <div class="grid">
                        <?php 
                        $seen = [];
                        foreach ($popular['results'] as $item): 
                            $id = $item['shikimori_id'] ?? $item['id'];
                            if (!$id || isset($seen[$id])) continue;
                            $seen[$id] = true;
                            $title = $item['title'] ?? $item['title_orig'] ?? 'Без названия';
                            $poster = $item['material_data']['poster_url'] ?? $item['screenshots'][0] ?? '';
                            $genre = $item['material_data']['anime_genres'][0] ?? '';
                            $episodes = $item['episodes_count'] ?? '?';
                            $episodes_total = $item['material_data']['episodes_total'] ?? '?';
                            $bookmarked = false;
                            if (isLoggedIn()) {
                                $stmt = $pdo->prepare("SELECT 1 FROM bookmarks WHERE user_id = ? AND anime_id = ?");
                                $stmt->execute([$_SESSION['user_id'], $id]);
                                $bookmarked = $stmt->fetch() ? true : false;
                            }
                        ?>
                        <a href="?page=anime&id=<?= urlencode($id) ?>" class="anime-card <?= $bookmarked ? 'bookmarked' : '' ?>">
                            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($title) ?>" loading="lazy">
                            <div class="info">
                                <h3><?= htmlspecialchars($title) ?></h3>
                                <div class="meta">
                                    <span class="badge"><?= htmlspecialchars($genre) ?></span>
                                    <span class="badge"><?= $episodes ?>/<?= $episodes_total ?></span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p>Не удалось загрузить данные.</p>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <h2>Недавно добавленные</h2>
                    <?php
                    $recent = getAnimeList(['sort' => 'created_at', 'order' => 'desc'], DEFAULT_LIMIT);
                    if ($recent && isset($recent['results'])):
                    ?>
                    <div class="grid">
                        <?php 
                        $seen = [];
                        foreach ($recent['results'] as $item): 
                            $id = $item['shikimori_id'] ?? $item['id'];
                            if (!$id || isset($seen[$id])) continue;
                            $seen[$id] = true;
                            $title = $item['title'] ?? $item['title_orig'] ?? 'Без названия';
                            $poster = $item['material_data']['poster_url'] ?? $item['screenshots'][0] ?? '';
                            $genre = $item['material_data']['anime_genres'][0] ?? '';
                            $episodes = $item['episodes_count'] ?? '?';
                            $episodes_total = $item['material_data']['episodes_total'] ?? '?';
                            $bookmarked = false;
                            if (isLoggedIn()) {
                                $stmt = $pdo->prepare("SELECT 1 FROM bookmarks WHERE user_id = ? AND anime_id = ?");
                                $stmt->execute([$_SESSION['user_id'], $id]);
                                $bookmarked = $stmt->fetch() ? true : false;
                            }
                        ?>
                        <a href="?page=anime&id=<?= urlencode($id) ?>" class="anime-card <?= $bookmarked ? 'bookmarked' : '' ?>">
                            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($title) ?>" loading="lazy">
                            <div class="info">
                                <h3><?= htmlspecialchars($title) ?></h3>
                                <div class="meta">
                                    <span class="badge"><?= htmlspecialchars($genre) ?></span>
                                    <span class="badge"><?= $episodes ?>/<?= $episodes_total ?></span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p>Не удалось загрузить данные.</p>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'search':
                $query = $_GET['q'] ?? '';
                $genre = $_GET['genre'] ?? '';
                $sort = $_GET['sort'] ?? 'shikimori_rating';
                $order = $_GET['order'] ?? 'desc';
                $next = $_GET['next'] ?? null;
                
                $filters = [];
                if ($genre) {
                    $filters['anime_genres'] = $genre;
                }
                $filters['sort'] = $sort;
                $filters['order'] = $order;
                
                if ($query) {
                    $results = searchAnime($query, DEFAULT_LIMIT, $next);
                } else {
                    $results = getAnimeList($filters, DEFAULT_LIMIT, $next);
                }
                ?>
                <h1>Поиск аниме</h1>
                <div class="card">
                    <form method="get" action="?">
                        <input type="hidden" name="page" value="search">
                        <div class="flex">
                            <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Название..." class="form-control" style="flex:1;">
                            <button type="submit" class="btn"><i class="fas fa-search"></i> Искать</button>
                        </div>
                    </form>
                    <div style="margin-top:20px;">
                        <div class="flex-between">
                            <h3>Жанры</h3>
                            <div class="flex-center">
                                <label for="sort"><i class="fas fa-sort"></i></label>
                                <select name="sort" id="sort" class="sort-select" onchange="window.location.href='?page=search&q=<?= urlencode($query) ?>&genre=<?= urlencode($genre) ?>&sort='+this.value+'&order=<?= $order ?>'">
                                    <option value="shikimori_rating" <?= $sort=='shikimori_rating' ? 'selected' : '' ?>>По рейтингу</option>
                                    <option value="created_at" <?= $sort=='created_at' ? 'selected' : '' ?>>По дате</option>
                                    <option value="title" <?= $sort=='title' ? 'selected' : '' ?>>По названию</option>
                                </select>
                                <select name="order" class="sort-select" onchange="window.location.href='?page=search&q=<?= urlencode($query) ?>&genre=<?= urlencode($genre) ?>&sort=<?= $sort ?>&order='+this.value">
                                    <option value="desc" <?= $order=='desc' ? 'selected' : '' ?>>↓ Убыв.</option>
                                    <option value="asc" <?= $order=='asc' ? 'selected' : '' ?>>↑ Возр.</option>
                                </select>
                            </div>
                        </div>
                        <div class="genre-buttons">
                            <?php
                            $genres = ['Экшен', 'Приключения', 'Комедия', 'Драма', 'Фэнтези', 'Романтика', 'Фантастика', 'Сёнен', 'Повседневность'];
                            foreach ($genres as $g):
                                $active = ($genre === $g) ? 'active' : '';
                            ?>
                            <a href="?page=search&q=<?= urlencode($query) ?>&genre=<?= urlencode($g) ?>&sort=<?= $sort ?>&order=<?= $order ?>" class="genre-btn <?= $active ?>"><?= $g ?></a>
                            <?php endforeach; ?>
                            <?php if ($genre): ?>
                            <a href="?page=search&q=<?= urlencode($query) ?>&sort=<?= $sort ?>&order=<?= $order ?>" class="genre-btn">Сбросить</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($results && isset($results['results']) && count($results['results']) > 0): 
                    $seen = [];
                    $next_token = extractNextToken($results['next_page'] ?? null);
                ?>
                <div class="card">
                    <h2>Результаты (<?= $results['total'] ?? '?' ?>)</h2>
                    <div class="grid" id="search-results">
                        <?php 
                        $seen_ids_js = [];
                        foreach ($results['results'] as $item): 
                            $id = $item['shikimori_id'] ?? $item['id'];
                            if (!$id || isset($seen[$id])) continue;
                            $seen[$id] = true;
                            $seen_ids_js[] = $id;
                            $title = $item['title'] ?? $item['title_orig'] ?? 'Без названия';
                            $poster = $item['material_data']['poster_url'] ?? $item['screenshots'][0] ?? '';
                            $genre_first = $item['material_data']['anime_genres'][0] ?? '';
                            $episodes = $item['episodes_count'] ?? '?';
                            $episodes_total = $item['material_data']['episodes_total'] ?? '?';
                            $bookmarked = false;
                            if (isLoggedIn()) {
                                $stmt = $pdo->prepare("SELECT 1 FROM bookmarks WHERE user_id = ? AND anime_id = ?");
                                $stmt->execute([$_SESSION['user_id'], $id]);
                                $bookmarked = $stmt->fetch() ? true : false;
                            }
                        ?>
                        <a href="?page=anime&id=<?= urlencode($id) ?>" class="anime-card <?= $bookmarked ? 'bookmarked' : '' ?>">
                            <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($title) ?>" loading="lazy">
                            <div class="info">
                                <h3><?= htmlspecialchars($title) ?></h3>
                                <div class="meta">
                                    <span class="badge"><?= htmlspecialchars($genre_first) ?></span>
                                    <span class="badge"><?= $episodes ?>/<?= $episodes_total ?></span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($next_token): ?>
                    <div class="text-center mt-2">
                        <button class="btn" id="load-more" data-next="<?= htmlspecialchars($next_token) ?>" data-query="<?= htmlspecialchars($query) ?>" data-genre="<?= htmlspecialchars($genre) ?>" data-sort="<?= htmlspecialchars($sort) ?>" data-order="<?= htmlspecialchars($order) ?>" data-seen="<?= implode(',', $seen_ids_js) ?>"><i class="fas fa-spinner"></i> Загрузить ещё</button>
                    </div>
                    <?php endif; ?>
                </div>
                <script>
                document.getElementById('load-more')?.addEventListener('click', function() {
                    const btn = this;
                    const next = btn.dataset.next;
                    const query = btn.dataset.query;
                    const genre = btn.dataset.genre;
                    const sort = btn.dataset.sort;
                    const order = btn.dataset.order;
                    const seen = btn.dataset.seen;
                    
                    // Add skeleton cards
                    const grid = document.getElementById('search-results');
                    for (let i = 0; i < 12; i++) {
                        const skeleton = document.createElement('div');
                        skeleton.className = 'skeleton-card skeleton';
                        grid.appendChild(skeleton);
                    }
                    
                    fetch(`?page=search&ajax=1&q=${encodeURIComponent(query)}&genre=${encodeURIComponent(genre)}&sort=${sort}&order=${order}&next=${encodeURIComponent(next)}&seen=${encodeURIComponent(seen)}`)
                        .then(r => r.json())
                        .then(data => {
                            // Remove skeletons
                            document.querySelectorAll('.skeleton-card').forEach(el => el.remove());
                            if (data.html) {
                                grid.insertAdjacentHTML('beforeend', data.html);
                                if (data.next) {
                                    btn.dataset.next = data.next;
                                    btn.dataset.seen = data.new_seen;
                                } else {
                                    btn.remove();
                                }
                            }
                        })
                        .catch(err => {
                            document.querySelectorAll('.skeleton-card').forEach(el => el.remove());
                            console.error('Error loading more:', err);
                        });
                });
                </script>
                <?php else: ?>
                <p>Ничего не найдено.</p>
                <?php endif;
                break;

            case 'anime':
                $id = $_GET['id'] ?? '';
                if (!$id) {
                    echo "<p>Аниме не указано.</p>";
                    break;
                }
                $anime = getAnimeById($id);
                if (!$anime):
                ?>
                <p>Аниме не найдено. Возможно, оно не добавлено в базу Kodik.</p>
                <?php else:
                    $title = $anime['title'] ?? $anime['title_orig'] ?? 'Без названия';
                    $poster = $anime['material_data']['poster_url'] ?? $anime['screenshots'][0] ?? '';
                    $description = $anime['material_data']['description'] ?? 'Описание отсутствует.';
                    $genres = $anime['material_data']['anime_genres'] ?? [];
                    $status = $anime['material_data']['anime_status'] ?? 'неизвестно';
                    $year = $anime['year'] ?? '';
                    $shikimori_id = $anime['shikimori_id'] ?? $id;
                    $seasons = $anime['seasons'] ?? null;
                    $material = $anime['material_data'] ?? [];
                    $episodes_total = $material['episodes_total'] ?? 0;
                    // Bookmark
                    $bookmark = null;
                    if (isLoggedIn()) {
                        $stmt = $pdo->prepare("SELECT category FROM bookmarks WHERE user_id = ? AND anime_id = ?");
                        $stmt->execute([$_SESSION['user_id'], $shikimori_id]);
                        $bookmark = $stmt->fetch();
                    }
                    // Watched episodes
                    $watched = [];
                    if (isLoggedIn()) {
                        $stmt = $pdo->prepare("SELECT season, episode FROM user_episodes WHERE user_id = ? AND anime_id = ? AND watched = 1");
                        $stmt->execute([$_SESSION['user_id'], $shikimori_id]);
                        while ($row = $stmt->fetch()) {
                            $watched[$row['season']][$row['episode']] = true;
                        }
                    }
                ?>
                <div class="card">
                    <div class="flex" style="align-items: flex-start;">
                        <?php if ($poster): ?>
                        <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($title) ?>" style="max-width:300px; width:100%; border-radius:24px; border: 2px solid var(--accent);">
                        <?php endif; ?>
                        <div style="flex:1;">
                            <h1><?= htmlspecialchars($title) ?></h1>
                            <p><strong>Год:</strong> <?= htmlspecialchars($year) ?></p>
                            <p><strong>Статус:</strong> <?= htmlspecialchars($status) ?></p>
                            <p><strong>Жанры:</strong> <?= implode(', ', $genres) ?></p>
                            <p><strong>Студия:</strong> <?= htmlspecialchars($material['anime_studios'][0] ?? 'неизвестно') ?></p>
                            <p><strong>Рейтинг (Shikimori):</strong> <?= $material['shikimori_rating'] ?? '?' ?> (<?= $material['shikimori_votes'] ?? '0' ?> голосов)</p>
                            <p><strong>Всего серий:</strong> <?= $episodes_total ?></p>
                            <p><strong>Длительность:</strong> <?= $material['duration'] ?? '?' ?> мин.</p>
                            <p><strong>Описание:</strong> <?= nl2br(htmlspecialchars($description)) ?></p>
                            <?php if (isLoggedIn()): ?>
                                <div class="flex" style="margin-top:20px;">
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="anime_id" value="<?= htmlspecialchars($shikimori_id) ?>">
                                        <input type="hidden" name="title" value="<?= htmlspecialchars($title) ?>">
                                        <input type="hidden" name="poster" value="<?= htmlspecialchars($poster) ?>">
                                        <?php if ($bookmark): ?>
                                            <button type="submit" name="remove_bookmark" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Удалить из закладок</button>
                                        <?php else: ?>
                                            <button type="submit" name="add_bookmark" class="btn"><i class="fas fa-bookmark"></i> В закладки</button>
                                        <?php endif; ?>
                                    </form>
                                    <?php if ($bookmark): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="anime_id" value="<?= htmlspecialchars($shikimori_id) ?>">
                                        <input type="hidden" name="title" value="<?= htmlspecialchars($title) ?>">
                                        <input type="hidden" name="poster" value="<?= htmlspecialchars($poster) ?>">
                                        <select name="category" onchange="this.form.submit()" class="form-control" style="width:auto;">
                                            <option value="plan" <?= $bookmark['category'] == 'plan' ? 'selected' : '' ?>>В планах</option>
                                            <option value="watching" <?= $bookmark['category'] == 'watching' ? 'selected' : '' ?>>Смотрю</option>
                                            <option value="completed" <?= $bookmark['category'] == 'completed' ? 'selected' : '' ?>>Просмотрено</option>
                                            <option value="favorite" <?= $bookmark['category'] == 'favorite' ? 'selected' : '' ?>>Любимое</option>
                                        </select>
                                        <input type="hidden" name="add_bookmark" value="1">
                                    </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Episodes -->
                <div class="card">
                    <h2><i class="fas fa-film"></i> Серии</h2>
                    <?php 
                    $has_episodes = false;
                    if ($seasons): 
                        foreach ($seasons as $season_num => $season_data):
                            $episodes = $season_data['episodes'] ?? [];
                            if (empty($episodes)) continue;
                            $has_episodes = true;
                            $episode_list = array_keys($episodes);
                            sort($episode_list, SORT_NUMERIC);
                            // Разбиваем на группы по 24 серии для удобства
                            $chunks = array_chunk($episode_list, 24, true);
                            foreach ($chunks as $chunk_index => $chunk):
                                if (empty($chunk)) continue;
                                $first_ep = reset($chunk);
                                $last_ep = end($chunk);
                    ?>
                    <div style="margin-bottom:20px;">
                        <h3>Сезон <?= $season_num ?> (серии <?= $first_ep ?>-<?= $last_ep ?>)</h3>
                        <div class="episode-grid">
                            <?php foreach ($chunk as $ep_num): 
                                $ep_data = $episodes[$ep_num];
                                $ep_link = $ep_data['link'] ?? '';
                                if ($ep_link && strpos($ep_link, '//') === 0) $ep_link = 'https:' . $ep_link;
                                $watched_class = (isset($watched[$season_num][$ep_num])) ? 'watched' : '';
                            ?>
                            <a href="?page=watch&anime_id=<?= urlencode($shikimori_id) ?>&season=<?= $season_num ?>&episode=<?= $ep_num ?>&title=<?= urlencode($title) ?>" class="episode-btn <?= $watched_class ?>"><?= $ep_num ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php 
                            endforeach;
                        endforeach;
                    endif;
                    
                    // Если сезонов нет, но есть episodes_total == 1, возможно это фильм или односерийное аниме
                    if (!$has_episodes && $episodes_total == 1) {
                        // Попробуем получить ссылку из основного link элемента
                        $main_link = $anime['link'] ?? '';
                        if ($main_link) {
                            if (strpos($main_link, '//') === 0) $main_link = 'https:' . $main_link;
                            echo '<div class="episode-grid">';
                            echo '<a href="?page=watch&anime_id='.urlencode($shikimori_id).'&season=1&episode=1&title='.urlencode($title).'" class="episode-btn">1</a>';
                            echo '</div>';
                            $has_episodes = true;
                        }
                    }
                    
                    if (!$has_episodes): ?>
                        <p>Информация о сериях отсутствует.</p>
                    <?php endif; ?>
                </div>

                <?php // Comments are only on watch page ?>
                <?php endif;
                break;

            case 'watch':
                $anime_id = $_GET['anime_id'] ?? '';
                $season = (int)($_GET['season'] ?? 1);
                $episode = (int)($_GET['episode'] ?? 1);
                $title = $_GET['title'] ?? 'Аниме';
                if (!$anime_id) break;
                $anime = getAnimeById($anime_id);
                if (!$anime) {
                    echo "<p>Аниме не найдено.</p>";
                    break;
                }
                $seasons = $anime['seasons'] ?? null;
                $embed_link = '';
                if ($seasons && isset($seasons[$season]['episodes'][$episode]['link'])) {
                    $embed_link = $seasons[$season]['episodes'][$episode]['link'];
                    if (strpos($embed_link, '//') === 0) $embed_link = 'https:' . $embed_link;
                } else {
                    // Если нет сезонов, но episodes_total == 1, попробуем основной link
                    if ($episode == 1 && isset($anime['link'])) {
                        $embed_link = $anime['link'];
                        if (strpos($embed_link, '//') === 0) $embed_link = 'https:' . $embed_link;
                    }
                }
                if (!$embed_link) {
                    echo "<p>Ссылка на видео не найдена.</p>";
                    break;
                }
                if (isLoggedIn()) {
                    $pdo->prepare("INSERT INTO user_episodes (user_id, anime_id, season, episode, watched) VALUES (?, ?, ?, ?, 1) ON CONFLICT(user_id, anime_id, season, episode) DO UPDATE SET watched = 1, updated_at = CURRENT_TIMESTAMP")->execute([$_SESSION['user_id'], $anime_id, $season, $episode]);
                    $pdo->prepare("INSERT INTO history (user_id, anime_id, anime_title, season, episode) VALUES (?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'], $anime_id, $title, $season, $episode]);
                }
                // Prev/next
                $prev_ep = null; $next_ep = null;
                if ($seasons && isset($seasons[$season]['episodes'])) {
                    $eps = array_keys($seasons[$season]['episodes']);
                    sort($eps, SORT_NUMERIC);
                    $idx = array_search($episode, $eps);
                    if ($idx !== false) {
                        if ($idx > 0) $prev_ep = $eps[$idx-1];
                        if ($idx < count($eps)-1) $next_ep = $eps[$idx+1];
                    }
                }
                ?>
                <div class="card">
                    <div class="flex-between">
                        <h2><i class="fas fa-play-circle"></i> <?= htmlspecialchars($title) ?> - Сезон <?= $season ?>, серия <?= $episode ?></h2>
                        <div class="watch-nav">
                            <?php if ($prev_ep): ?>
                            <a href="?page=watch&anime_id=<?= urlencode($anime_id) ?>&season=<?= $season ?>&episode=<?= $prev_ep ?>&title=<?= urlencode($title) ?>" class="btn btn-sm"><i class="fas fa-arrow-left"></i> <?= $prev_ep ?></a>
                            <?php endif; ?>
                            <?php if ($next_ep): ?>
                            <a href="?page=watch&anime_id=<?= urlencode($anime_id) ?>&season=<?= $season ?>&episode=<?= $next_ep ?>&title=<?= urlencode($title) ?>" class="btn btn-sm"><?= $next_ep ?> <i class="fas fa-arrow-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="position:relative; padding-bottom:56.25%; height:0; overflow:hidden; border-radius:24px; margin:20px 0; border: 2px solid var(--accent);">
                        <iframe src="<?= htmlspecialchars($embed_link) ?>" style="position:absolute; top:0; left:0; width:100%; height:100%;" frameborder="0" allowfullscreen></iframe>
                    </div>
                    <a href="?page=anime&id=<?= urlencode($anime_id) ?>" class="btn"><i class="fas fa-arrow-left"></i> Назад к аниме</a>
                </div>

                <!-- Comments on watch page (redesigned) -->
                <div class="card" id="comments">
                    <h2><i class="fas fa-comments"></i> Комментарии</h2>
                    <?php
                    $shikimori_id = $anime['shikimori_id'] ?? $anime_id;
                    if (isLoggedIn()): ?>
                    <form method="post">
                        <input type="hidden" name="anime_id" value="<?= htmlspecialchars($shikimori_id) ?>">
                        <div class="form-group">
                            <textarea name="content" class="form-control" rows="3" placeholder="Ваш комментарий..." required></textarea>
                        </div>
                        <button type="submit" name="add_comment" class="btn"><i class="fas fa-paper-plane"></i> Отправить</button>
                    </form>
                    <?php else: ?>
                    <p><a href="?page=login">Войдите</a>, чтобы оставить комментарий.</p>
                    <?php endif; ?>

                    <?php
                    $stmt = $pdo->prepare("SELECT c.*, u.username, u.nickname, u.avatar FROM comments c JOIN users u ON c.user_id = u.id WHERE c.anime_id = ? AND c.parent_id = 0 ORDER BY c.created_at DESC");
                    $stmt->execute([$shikimori_id]);
                    $comments = $stmt->fetchAll();
                    foreach ($comments as $c):
                    ?>
                    <div class="comment">
                        <div class="comment-header">
                            <img src="<?= htmlspecialchars($c['avatar'] ?? 'default-avatar.png') ?>" class="comment-avatar" onerror="this.src='https://via.placeholder.com/52'">
                            <div class="comment-author-info">
                                <span class="comment-author"><?= htmlspecialchars($c['nickname'] ?? $c['username']) ?></span>
                                <span class="comment-date"><?= $c['created_at'] ?></span>
                            </div>
                        </div>
                        <div class="comment-content"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
                        <div class="comment-actions">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
                                <button type="submit" name="vote_comment" value="like" class="vote-btn <?= $c['likes'] > 0 ? 'liked' : '' ?>"><i class="fas fa-thumbs-up"></i> <?= $c['likes'] ?></button>
                                <button type="submit" name="vote_comment" value="dislike" class="vote-btn <?= $c['dislikes'] > 0 ? 'disliked' : '' ?>"><i class="fas fa-thumbs-down"></i> <?= $c['dislikes'] ?></button>
                            </form>
                            <?php if (isLoggedIn()): ?>
                                <button onclick="showReplyForm(<?= $c['id'] ?>)"><i class="fas fa-reply"></i> Ответить</button>
                            <?php endif; ?>
                            <?php if (isLoggedIn() && ($_SESSION['user_id'] == $c['user_id'])): ?>
                                <button onclick="editComment(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['content'])) ?>')"><i class="fas fa-edit"></i> Редактировать</button>
                                <button onclick="confirmDelete(<?= $c['id'] ?>)"><i class="fas fa-trash-alt"></i> Удалить</button>
                            <?php endif; ?>
                        </div>
                        <!-- Reply form -->
                        <div id="reply-<?= $c['id'] ?>" class="reply-form" style="display:none;">
                            <form method="post">
                                <input type="hidden" name="anime_id" value="<?= htmlspecialchars($shikimori_id) ?>">
                                <input type="hidden" name="parent_id" value="<?= $c['id'] ?>">
                                <textarea name="content" class="form-control" rows="2" placeholder="Ваш ответ..." required></textarea>
                                <button type="submit" name="add_comment" class="btn mt-2"><i class="fas fa-reply"></i> Ответить</button>
                            </form>
                        </div>
                        <!-- Replies -->
                        <div class="replies">
                            <?php
                            $stmt2 = $pdo->prepare("SELECT c.*, u.username, u.nickname, u.avatar FROM comments c JOIN users u ON c.user_id = u.id WHERE c.parent_id = ? ORDER BY c.created_at");
                            $stmt2->execute([$c['id']]);
                            $replies = $stmt2->fetchAll();
                            foreach ($replies as $r):
                            ?>
                            <div class="comment">
                                <div class="comment-header">
                                    <img src="<?= htmlspecialchars($r['avatar'] ?? 'default-avatar.png') ?>" class="comment-avatar" style="width:42px;height:42px;">
                                    <div class="comment-author-info">
                                        <span class="comment-author"><?= htmlspecialchars($r['nickname'] ?? $r['username']) ?></span>
                                        <span class="comment-date"><?= $r['created_at'] ?></span>
                                    </div>
                                </div>
                                <div class="comment-content"><?= nl2br(htmlspecialchars($r['content'])) ?></div>
                                <div class="comment-actions">
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="comment_id" value="<?= $r['id'] ?>">
                                        <button type="submit" name="vote_comment" value="like" class="vote-btn <?= $r['likes'] > 0 ? 'liked' : '' ?>"><i class="fas fa-thumbs-up"></i> <?= $r['likes'] ?></button>
                                        <button type="submit" name="vote_comment" value="dislike" class="vote-btn <?= $r['dislikes'] > 0 ? 'disliked' : '' ?>"><i class="fas fa-thumbs-down"></i> <?= $r['dislikes'] ?></button>
                                    </form>
                                    <?php if (isLoggedIn() && ($_SESSION['user_id'] == $r['user_id'])): ?>
                                        <button onclick="editComment(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['content'])) ?>')"><i class="fas fa-edit"></i></button>
                                        <button onclick="confirmDelete(<?= $r['id'] ?>)"><i class="fas fa-trash-alt"></i></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php
                break;

            case 'recent':
                ?>
                <h1>Недавно добавленные</h1>
                <?php
                $next = $_GET['next'] ?? null;
                $recent = getAnimeList(['sort' => 'created_at', 'order' => 'desc'], DEFAULT_LIMIT, $next);
                if ($recent && isset($recent['results'])):
                    $seen = [];
                    $next_token = extractNextToken($recent['next_page'] ?? null);
                    $seen_ids_js = [];
                ?>
                <div class="grid" id="recent-results">
                    <?php foreach ($recent['results'] as $item): 
                        $id = $item['shikimori_id'] ?? $item['id'];
                        if (!$id || isset($seen[$id])) continue;
                        $seen[$id] = true;
                        $seen_ids_js[] = $id;
                        $title = $item['title'] ?? $item['title_orig'] ?? 'Без названия';
                        $poster = $item['material_data']['poster_url'] ?? $item['screenshots'][0] ?? '';
                        $genre = $item['material_data']['anime_genres'][0] ?? '';
                        $episodes = $item['episodes_count'] ?? '?';
                        $episodes_total = $item['material_data']['episodes_total'] ?? '?';
                        $bookmarked = false;
                        if (isLoggedIn()) {
                            $stmt = $pdo->prepare("SELECT 1 FROM bookmarks WHERE user_id = ? AND anime_id = ?");
                            $stmt->execute([$_SESSION['user_id'], $id]);
                            $bookmarked = $stmt->fetch() ? true : false;
                        }
                    ?>
                    <a href="?page=anime&id=<?= urlencode($id) ?>" class="anime-card <?= $bookmarked ? 'bookmarked' : '' ?>">
                        <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($title) ?>" loading="lazy">
                        <div class="info">
                            <h3><?= htmlspecialchars($title) ?></h3>
                            <div class="meta">
                                <span class="badge"><?= htmlspecialchars($genre) ?></span>
                                <span class="badge"><?= $episodes ?>/<?= $episodes_total ?></span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($next_token): ?>
                <div class="text-center mt-2">
                    <button class="btn" id="load-more-recent" data-next="<?= htmlspecialchars($next_token) ?>" data-seen="<?= implode(',', $seen_ids_js) ?>"><i class="fas fa-spinner"></i> Загрузить ещё</button>
                </div>
                <script>
                document.getElementById('load-more-recent')?.addEventListener('click', function() {
                    const btn = this;
                    const next = btn.dataset.next;
                    const seen = btn.dataset.seen;
                    
                    const grid = document.getElementById('recent-results');
                    for (let i = 0; i < 12; i++) {
                        const skeleton = document.createElement('div');
                        skeleton.className = 'skeleton-card skeleton';
                        grid.appendChild(skeleton);
                    }
                    
                    fetch(`?page=recent&ajax=1&next=${encodeURIComponent(next)}&seen=${encodeURIComponent(seen)}`)
                        .then(r => r.json())
                        .then(data => {
                            document.querySelectorAll('.skeleton-card').forEach(el => el.remove());
                            if (data.html) {
                                grid.insertAdjacentHTML('beforeend', data.html);
                                if (data.next) {
                                    btn.dataset.next = data.next;
                                    btn.dataset.seen = data.new_seen;
                                } else {
                                    btn.remove();
                                }
                            }
                        })
                        .catch(err => {
                            document.querySelectorAll('.skeleton-card').forEach(el => el.remove());
                            console.error('Error loading more:', err);
                        });
                });
                </script>
                <?php endif; ?>
                <?php else: ?>
                <p>Не удалось загрузить данные.</p>
                <?php endif;
                break;

            case 'login':
                if (isLoggedIn()) header('Location: ?page=profile');
                ?>
                <h1 class="text-center"><i class="fas fa-sign-in-alt"></i> Вход</h1>
                <div class="card" style="max-width:400px; margin:0 auto;">
                    <?php if (isset($error)) echo "<p style='color:#ff4444;'>$error</p>"; ?>
                    <form method="post">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Имя пользователя или Email</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Пароль</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" name="login" class="btn" style="width:100%;"><i class="fas fa-sign-in-alt"></i> Войти</button>
                    </form>
                    <p class="text-center mt-2">Нет аккаунта? <a href="?page=register">Зарегистрируйтесь</a></p>
                </div>
                <?php
                break;

            case 'register':
                if (isLoggedIn()) header('Location: ?page=profile');
                ?>
                <h1 class="text-center"><i class="fas fa-user-plus"></i> Регистрация</h1>
                <div class="card" style="max-width:400px; margin:0 auto;">
                    <?php if (isset($error)) echo "<p style='color:#ff4444;'>$error</p>"; ?>
                    <form method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Имя пользователя</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Пароль</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Никнейм</label>
                            <input type="text" name="nickname" class="form-control">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-info-circle"></i> О себе</label>
                            <textarea name="bio" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-image"></i> Аватар</label>
                            <input type="file" name="avatar" accept="image/*" class="form-control">
                        </div>
                        <button type="submit" name="register" class="btn" style="width:100%;"><i class="fas fa-user-plus"></i> Зарегистрироваться</button>
                    </form>
                    <p class="text-center mt-2">Уже есть аккаунт? <a href="?page=login">Войдите</a></p>
                </div>
                <?php
                break;

            case 'profile':
                if (!isLoggedIn()) { header('Location: ?page=login'); exit; }
                $user_id = $_GET['id'] ?? $_SESSION['user_id'];
                $user = getUser($user_id);
                if (!$user) { echo "<p>Пользователь не найден.</p>"; break; }
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookmarks WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $bookmarks_count = $stmt->fetchColumn();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM history WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $history_count = $stmt->fetchColumn();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $comments_count = $stmt->fetchColumn();
                ?>
                <h1><i class="fas fa-user-circle"></i> Профиль</h1>
                <div class="card">
                    <div class="flex" style="align-items: center;">
                        <img src="<?= htmlspecialchars($user['avatar'] ?? 'default-avatar.png') ?>" style="width:150px; height:150px; border-radius:50%; object-fit:cover; border:3px solid var(--accent);" onerror="this.src='https://via.placeholder.com/150'">
                        <div style="flex:1;">
                            <h2><?= htmlspecialchars($user['nickname'] ?? $user['username']) ?></h2>
                            <p><strong>Имя пользователя:</strong> <?= htmlspecialchars($user['username']) ?></p>
                            <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
                            <p><strong>О себе:</strong> <?= nl2br(htmlspecialchars($user['bio'] ?? '')) ?></p>
                            <p><strong>Статистика:</strong> Закладок: <?= $bookmarks_count ?>, Просмотрено серий: <?= $history_count ?>, Комментариев: <?= $comments_count ?></p>
                        </div>
                    </div>
                    <?php if ($_SESSION['user_id'] == $user_id): ?>
                    <button onclick="document.getElementById('editProfile').style.display='block'" class="btn mt-2"><i class="fas fa-edit"></i> Редактировать профиль</button>
                    <div id="editProfile" style="display:none; margin-top:20px;">
                        <form method="post" enctype="multipart/form-data">
                            <div class="form-group">
                                <label>Никнейм</label>
                                <input type="text" name="nickname" value="<?= htmlspecialchars($user['nickname'] ?? '') ?>" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>О себе</label>
                                <textarea name="bio" class="form-control" rows="3"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Новый аватар</label>
                                <input type="file" name="avatar" accept="image/*" class="form-control">
                            </div>
                            <button type="submit" name="update_profile" class="btn"><i class="fas fa-save"></i> Сохранить</button>
                            <button type="button" onclick="document.getElementById('editProfile').style.display='none'" class="btn btn-danger">Отмена</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'bookmarks':
                if (!isLoggedIn()) { header('Location: ?page=login'); exit; }
                $user_id = $_SESSION['user_id'];
                $category_filter = $_GET['category'] ?? '';
                $sql = "SELECT * FROM bookmarks WHERE user_id = ?";
                $params = [$user_id];
                if ($category_filter) {
                    $sql .= " AND category = ?";
                    $params[] = $category_filter;
                }
                $sql .= " ORDER BY added_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $bookmarks = $stmt->fetchAll();
                ?>
                <h1><i class="fas fa-bookmark"></i> Мои закладки</h1>
                <div class="flex" style="margin-bottom:20px;">
                    <a href="?page=bookmarks" class="btn <?= !$category_filter ? 'btn' : 'btn-outline' ?>">Все</a>
                    <a href="?page=bookmarks&category=plan" class="btn <?= $category_filter=='plan' ? 'btn' : 'btn-outline' ?>">В планах</a>
                    <a href="?page=bookmarks&category=watching" class="btn <?= $category_filter=='watching' ? 'btn' : 'btn-outline' ?>">Смотрю</a>
                    <a href="?page=bookmarks&category=completed" class="btn <?= $category_filter=='completed' ? 'btn' : 'btn-outline' ?>">Просмотрено</a>
                    <a href="?page=bookmarks&category=favorite" class="btn <?= $category_filter=='favorite' ? 'btn' : 'btn-outline' ?>">Любимое</a>
                </div>
                <?php if (count($bookmarks) > 0): ?>
                <div class="grid">
                    <?php foreach ($bookmarks as $b): ?>
                    <a href="?page=anime&id=<?= urlencode($b['anime_id']) ?>" class="anime-card bookmarked">
                        <img src="<?= htmlspecialchars($b['poster']) ?>" alt="<?= htmlspecialchars($b['title']) ?>" loading="lazy">
                        <div class="info">
                            <h3><?= htmlspecialchars($b['title']) ?></h3>
                            <span class="badge"><?= $b['category'] ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p>У вас пока нет закладок.</p>
                <?php endif;
                break;

            case 'history':
                if (!isLoggedIn()) { header('Location: ?page=login'); exit; }
                $user_id = $_SESSION['user_id'];
                $stmt = $pdo->prepare("SELECT * FROM history WHERE user_id = ? ORDER BY watched_at DESC LIMIT 100");
                $stmt->execute([$user_id]);
                $history = $stmt->fetchAll();
                ?>
                <h1><i class="fas fa-history"></i> История просмотров</h1>
                <?php if (count($history) > 0): ?>
                <div class="history-grid">
                    <?php foreach ($history as $h): 
                        $anime = getAnimeById($h['anime_id']);
                        $poster = $anime['material_data']['poster_url'] ?? $anime['screenshots'][0] ?? '';
                    ?>
                    <div class="history-card">
                        <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($h['anime_title']) ?>">
                        <div class="history-info">
                            <h4><a href="?page=anime&id=<?= urlencode($h['anime_id']) ?>"><?= htmlspecialchars($h['anime_title']) ?></a></h4>
                            <p>Сезон <?= $h['season'] ?>, серия <?= $h['episode'] ?></p>
                            <small><?= $h['watched_at'] ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p>История пуста. Начните смотреть аниме!</p>
                <?php endif;
                break;

            default:
                echo "<h1>404 - Страница не найдена</h1>";
        }
        ?>
    </main>

    <!-- Custom context menu -->
    <div class="context-menu" id="contextMenu">
        <a href="#" id="contextCopyLink"><i class="fas fa-link"></i> Копировать ссылку</a>
        <a href="#" id="contextOpenNewTab"><i class="fas fa-external-link-alt"></i> Открыть в новой вкладке</a>
    </div>

    <!-- Custom modal for confirmation -->
    <div class="modal" id="confirmModal">
        <div class="modal-content">
            <p id="modalMessage">Вы уверены?</p>
            <div class="modal-buttons">
                <button class="btn" id="modalConfirm">Да</button>
                <button class="btn btn-danger" id="modalCancel">Нет</button>
            </div>
        </div>
    </div>

    <!-- First-time user guide modal -->
    <div class="modal" id="guideModal">
        <div class="modal-content" style="max-width: 600px;">
            <div id="guideSlides">
                <!-- Slide 1 -->
                <div class="guide-slide active" data-index="0">
                    <i class="fas fa-star"></i>
                    <h2>Добро пожаловать в AnimeWorld!</h2>
                    <p>Это краткое руководство поможет вам освоиться на сайте.</p>
                    <p>Здесь вы найдете тысячи аниме, сможете добавлять в закладки, отмечать просмотренные серии и общаться с другими зрителями.</p>
                </div>
                <!-- Slide 2 -->
                <div class="guide-slide" data-index="1">
                    <i class="fas fa-compass"></i>
                    <h2>Навигация</h2>
                    <p>На компьютере меню находится сверху. На телефоне и планшете — снизу, его можно прокручивать горизонтально, чтобы увидеть все пункты.</p>
                    <p>Используйте «Главная», «Поиск», «Новинки», а также личные разделы после входа.</p>
                </div>
                <!-- Slide 3 -->
                <div class="guide-slide" data-index="2">
                    <i class="fas fa-search"></i>
                    <h2>Поиск и фильтры</h2>
                    <p>На странице поиска вы можете искать по названию, фильтровать по жанрам и сортировать результаты по рейтингу, дате или названию.</p>
                    <p>Кнопка «Загрузить ещё» подгружает следующие 100 тайтлов.</p>
                </div>
                <!-- Slide 4 -->
                <div class="guide-slide" data-index="3">
                    <i class="fas fa-layer-group"></i>
                    <h2>Карточки аниме</h2>
                    <p>На карточке отображается постер, название, жанр и количество серий. Если аниме добавлено в закладки, в углу появляется звездочка.</p>
                    <p>Кликните по карточке, чтобы перейти на страницу аниме.</p>
                </div>
                <!-- Slide 5 -->
                <div class="guide-slide" data-index="4">
                    <i class="fas fa-bookmark"></i>
                    <h2>Закладки и история</h2>
                    <p>На странице аниме вы можете добавить его в закладки и выбрать категорию (В планах, Смотрю и т.д.).</p>
                    <p>Просмотренные серии автоматически отмечаются и попадают в историю.</p>
                </div>
                <!-- Slide 6 -->
                <div class="guide-slide" data-index="5">
                    <i class="fas fa-comments"></i>
                    <h2>Комментарии</h2>
                    <p>Под плеером на странице просмотра можно оставлять комментарии, отвечать на другие, ставить лайки и дизлайки.</p>
                    <p>Автор может редактировать или удалять свои сообщения.</p>
                </div>
                <!-- Slide 7 -->
                <div class="guide-slide" data-index="6">
                    <i class="fas fa-user"></i>
                    <h2>Профиль</h2>
                    <p>В профиле отображается ваша статистика, вы можете загрузить аватар и указать информацию о себе.</p>
                    <p>Также доступны ваши закладки и история просмотров.</p>
                </div>
            </div>
            <div class="guide-dots" id="guideDots"></div>
            <div class="modal-buttons">
                <button class="btn btn-outline" id="guidePrev"><i class="fas fa-arrow-left"></i> Назад</button>
                <button class="btn" id="guideNext">Далее <i class="fas fa-arrow-right"></i></button>
                <button class="btn btn-danger" id="guideClose">Закрыть</button>
            </div>
        </div>
    </div>

    <script>
    // Context menu
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        const menu = document.getElementById('contextMenu');
        menu.style.display = 'block';
        menu.style.left = e.pageX + 'px';
        menu.style.top = e.pageY + 'px';
    });
    document.addEventListener('click', function() {
        document.getElementById('contextMenu').style.display = 'none';
    });
    document.getElementById('contextCopyLink').addEventListener('click', function(e) {
        e.preventDefault();
        navigator.clipboard.writeText(window.location.href);
        showModal('Ссылка скопирована!', function() {});
    });
    document.getElementById('contextOpenNewTab').addEventListener('click', function(e) {
        e.preventDefault();
        window.open(window.location.href, '_blank');
    });

    // Custom confirmation
    let confirmCallback = null;
    function showModal(message, onConfirm) {
        document.getElementById('modalMessage').innerText = message;
        document.getElementById('confirmModal').style.display = 'flex';
        confirmCallback = onConfirm;
    }
    document.getElementById('modalConfirm').addEventListener('click', function() {
        document.getElementById('confirmModal').style.display = 'none';
        if (confirmCallback) confirmCallback();
    });
    document.getElementById('modalCancel').addEventListener('click', function() {
        document.getElementById('confirmModal').style.display = 'none';
    });

    // Delete comment with confirmation
    function confirmDelete(commentId) {
        showModal('Удалить комментарий?', function() {
            const form = document.createElement('form');
            form.method = 'post';
            form.innerHTML = `<input type="hidden" name="comment_id" value="${commentId}"><input type="hidden" name="delete_comment" value="1">`;
            document.body.appendChild(form);
            form.submit();
        });
    }

    // Reply form toggle
    function showReplyForm(commentId) {
        const form = document.getElementById('reply-' + commentId);
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
    }

    // Edit comment
    function editComment(id, content) {
        const newContent = prompt('Редактировать комментарий:', content);
        if (newContent && newContent !== content) {
            const form = document.createElement('form');
            form.method = 'post';
            form.innerHTML = `<input type="hidden" name="comment_id" value="${id}"><input type="hidden" name="content" value="${newContent}"><input type="hidden" name="edit_comment" value="1">`;
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Active bottom nav highlight
    const bottomNavLinks = document.querySelectorAll('.bottom-nav a, .bottom-nav button');
    const currentPage = '<?= $page ?>';
    bottomNavLinks.forEach(link => {
        if (link.getAttribute('href') && link.getAttribute('href').includes('page=' + currentPage)) {
            link.classList.add('active');
        }
    });

    // First-time guide
    (function() {
        const guideModal = document.getElementById('guideModal');
        const slides = document.querySelectorAll('.guide-slide');
        const dotsContainer = document.getElementById('guideDots');
        const prevBtn = document.getElementById('guidePrev');
        const nextBtn = document.getElementById('guideNext');
        const closeBtn = document.getElementById('guideClose');
        let currentSlide = 0;

        // Create dots
        slides.forEach((_, i) => {
            const dot = document.createElement('span');
            dot.classList.add('guide-dot');
            dot.dataset.index = i;
            dot.addEventListener('click', () => goToSlide(i));
            dotsContainer.appendChild(dot);
        });
        const dots = document.querySelectorAll('.guide-dot');

        function updateDots() {
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === currentSlide);
            });
        }

        function goToSlide(index) {
            slides.forEach(s => s.classList.remove('active'));
            slides[index].classList.add('active');
            currentSlide = index;
            updateDots();
        }

        function nextSlide() {
            if (currentSlide < slides.length - 1) {
                goToSlide(currentSlide + 1);
            } else {
                // Last slide, close guide
                guideModal.style.display = 'none';
                localStorage.setItem('guideSeen', 'true');
            }
        }

        function prevSlide() {
            if (currentSlide > 0) {
                goToSlide(currentSlide - 1);
            }
        }

        nextBtn.addEventListener('click', nextSlide);
        prevBtn.addEventListener('click', prevSlide);
        closeBtn.addEventListener('click', function() {
            guideModal.style.display = 'none';
            localStorage.setItem('guideSeen', 'true');
        });

        // Check if first visit
        if (!localStorage.getItem('guideSeen')) {
            guideModal.style.display = 'flex';
            goToSlide(0);
        }
    })();
    </script>
</body>
</html>

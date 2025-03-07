<?php
session_start();

// ====================
//  1) DB CONNECTION & TABLE CREATION
// ====================
$db = new SQLite3('../databases/mediumblue.db');

// Existing tables...
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT,
    email TEXT,
    username TEXT UNIQUE,
    password TEXT,
    is_admin INTEGER DEFAULT 0
)");

$db->exec("CREATE TABLE IF NOT EXISTS categorias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT,
    parent_id INTEGER
)");

// Notice the updated table definition for noticias with new field 'url'
$db->exec("CREATE TABLE IF NOT EXISTS noticias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    categoria_id INTEGER,
    titulo TEXT,
    cuerpo TEXT,
    imagen TEXT,
    url TEXT,
    creado_en DATETIME
)");

// NEW TABLE: site_settings
$db->exec("CREATE TABLE IF NOT EXISTS site_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_title TEXT,
    site_subtitle TEXT,
    meta_title TEXT,
    meta_description TEXT,
    meta_keywords TEXT,
    meta_author TEXT
)");

// NEW TABLE: comments
$db->exec("CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    news_id INTEGER,
    user_id INTEGER,
    nombre TEXT,
    email TEXT,
    comment TEXT,
    created_at DATETIME
)");

// Insert admin user by default (if not exists)
$adminUser  = 'admin';
$adminPass  = 'admin';
$adminEmail = 'admin@planesvalencia.com';
$adminName  = 'Administrador';

$checkAdmin = $db->prepare("SELECT id FROM users WHERE username = :u");
$checkAdmin->bindValue(':u', $adminUser, SQLITE3_TEXT);
$resAdmin   = $checkAdmin->execute();
if (!$resAdmin->fetchArray()) {
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $ins  = $db->prepare("INSERT INTO users (nombre, email, username, password, is_admin)
                          VALUES (:n, :e, :u, :p, 1)");
    $ins->bindValue(':n', $adminName,  SQLITE3_TEXT);
    $ins->bindValue(':e', $adminEmail, SQLITE3_TEXT);
    $ins->bindValue(':u', $adminUser,  SQLITE3_TEXT);
    $ins->bindValue(':p', $hash,       SQLITE3_TEXT);
    $ins->execute();
}

// ====================
//  2) HELPER FUNCTIONS
// ====================
function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}
function isAdmin() {
    return (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1);
}

// Build a category tree (for submenus, etc.)
function getCategoryTree($db) {
    $allCats = [];
    $res = $db->query("SELECT * FROM categorias");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['children'] = [];
        $allCats[$row['id']] = $row;
    }
    // Link subcategories to their parent
    foreach ($allCats as $id => $cat) {
        if (!empty($cat['parent_id']) && isset($allCats[$cat['parent_id']])) {
            $allCats[$cat['parent_id']]['children'][] = &$allCats[$id];
        }
    }
    // Top-level categories only
    $tree = [];
    foreach ($allCats as $id => $cat) {
        if (empty($cat['parent_id'])) {
            $tree[] = $cat;
        }
    }
    return $tree;
}

// Print category menu recursively
function printCategoryMenu(array $cats) {
    if (empty($cats)) return;
    echo '<ul class="cat-submenu">';
    foreach ($cats as $cat) {
        $catLink = 'index.php?page=cat&cid=' . $cat['id'];
        echo '<li>';
        echo '<a href="'.htmlspecialchars($catLink).'">'.htmlspecialchars($cat['nombre']).'</a>';
        if (!empty($cat['children'])) {
            printCategoryMenu($cat['children']);
        }
        echo '</li>';
    }
    echo '</ul>';
}

// Multi-column layout logic for news listing
function getColumnsForRow($rowIndex) {
    if ($rowIndex === 1) {
        return 1;
    } elseif ($rowIndex === 2 || $rowIndex === 3) {
        return 2;
    } elseif ($rowIndex === 4 || $rowIndex === 5) {
        return 3;
    } else {
        return 4;
    }
}

// ====================
//  3) LOAD SITE SETTINGS FROM DB
// ====================
$siteSettingsRow = $db->query("SELECT * FROM site_settings LIMIT 1")->fetchArray(SQLITE3_ASSOC);

$site_title       = $siteSettingsRow ? $siteSettingsRow['site_title']       : 'PlanesValencia';
$site_subtitle    = $siteSettingsRow ? $siteSettingsRow['site_subtitle']    : 'Descubre lo mejor de Valencia';
$meta_title       = $siteSettingsRow ? $siteSettingsRow['meta_title']       : 'PlanesValencia';
$meta_description = $siteSettingsRow ? $siteSettingsRow['meta_description'] : 'Default meta description';
$meta_keywords    = $siteSettingsRow ? $siteSettingsRow['meta_keywords']    : 'valencia, planes, noticias';
$meta_author      = $siteSettingsRow ? $siteSettingsRow['meta_author']      : 'Admin';

// ====================
//  4) GENERATE SITEMAP (FOR SEO)
// ====================
function generateSitemap($db) {
    $urls = [];

    // Home:
    $urls[] = [
        'loc'        => 'https://example.com/index.php',
        'lastmod'    => date('Y-m-d'),
        'changefreq' => 'daily',
        'priority'   => '1.0'
    ];

    // Categories
    $catRes = $db->query("SELECT id, nombre FROM categorias");
    while ($cat = $catRes->fetchArray(SQLITE3_ASSOC)) {
        $cid = $cat['id'];
        $urls[] = [
            'loc'        => "https://example.com/index.php?page=cat&cid={$cid}",
            'lastmod'    => date('Y-m-d'),
            'changefreq' => 'weekly',
            'priority'   => '0.8'
        ];
    }

    // Single news
    $newsRes = $db->query("SELECT id, creado_en FROM noticias");
    while ($n = $newsRes->fetchArray(SQLITE3_ASSOC)) {
        $nid = $n['id'];
        $lastmod = substr($n['creado_en'], 0, 10);
        $urls[] = [
            'loc'        => "https://example.com/index.php?page=single&nid={$nid}",
            'lastmod'    => $lastmod,
            'changefreq' => 'monthly',
            'priority'   => '0.5'
        ];
    }

    // Build XML
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$u['loc']}</loc>\n";
        $xml .= "    <lastmod>{$u['lastmod']}</lastmod>\n";
        $xml .= "    <changefreq>{$u['changefreq']}</changefreq>\n";
        $xml .= "    <priority>{$u['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';
    file_put_contents(__DIR__ . '/sitemap.xml', $xml);
}
generateSitemap($db);

// ====================
//  5) PROCESS FORMS (REGISTER, LOGIN, ADD_NEWS, ADD_COMMENT)
// ====================
$error   = '';
$success = '';

if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'register':
            $nombre   = trim($_POST['nombre']);
            $email    = trim($_POST['email']);
            $username = trim($_POST['username']);
            $password = trim($_POST['password']);

            $st = $db->prepare("SELECT id FROM users WHERE username = :u");
            $st->bindValue(':u', $username, SQLITE3_TEXT);
            $exists = $st->execute();
            if ($exists->fetchArray()) {
                $error = "El nombre de usuario ya está en uso.";
            } else {
                $hashPass = password_hash($password, PASSWORD_DEFAULT);
                $insUser  = $db->prepare("INSERT INTO users (nombre, email, username, password, is_admin)
                                          VALUES (:n, :e, :u, :p, 0)");
                $insUser->bindValue(':n', $nombre,   SQLITE3_TEXT);
                $insUser->bindValue(':e', $email,    SQLITE3_TEXT);
                $insUser->bindValue(':u', $username, SQLITE3_TEXT);
                $insUser->bindValue(':p', $hashPass, SQLITE3_TEXT);
                $insUser->execute();
                $success = "¡Registro exitoso! Ahora puedes iniciar sesión.";
            }
            break;

        case 'login':
            $username = trim($_POST['username']);
            $password = trim($_POST['password']);
            $st = $db->prepare("SELECT * FROM users WHERE username = :u");
            $st->bindValue(':u', $username, SQLITE3_TEXT);
            $res = $st->execute()->fetchArray(SQLITE3_ASSOC);

            if ($res && password_verify($password, $res['password'])) {
                $_SESSION['user_id']  = $res['id'];
                $_SESSION['username'] = $res['username'];
                $_SESSION['is_admin'] = $res['is_admin'];
                $_SESSION['nombre']   = $res['nombre'];
                header("Location: index.php");
                exit;
            } else {
                $error = "Usuario o contraseña incorrectos.";
            }
            break;

        case 'add_news':
            if (!isLoggedIn()) {
                $error = "Debes iniciar sesión para publicar noticias.";
                break;
            }
            $titulo       = trim($_POST['titulo']);
            $categoria_id = (int)$_POST['categoria_id'];
            $cuerpo       = trim($_POST['cuerpo']);
            $url          = trim($_POST['url']);

            $imagen = null;
            if (!empty($_FILES['imagen']['name'])) {
                $fileName  = $_FILES['imagen']['name'];
                $tmpName   = $_FILES['imagen']['tmp_name'];
                $fileError = $_FILES['imagen']['error'];
                $ext       = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if ($fileError === 0 && $ext === 'jpg') {
                    if (!is_dir('uploads')) {
                        mkdir('uploads', 0777, true);
                    }
                    $uniqueName = uniqid().'.jpg';
                    $dest       = 'uploads/'.$uniqueName;
                    move_uploaded_file($tmpName, $dest);
                    $imagen     = $dest;
                } else {
                    $error = "Error subiendo imagen (solo formato JPG).";
                }
            }

            if (!$error) {
                $uid = $_SESSION['user_id'];
                $stInsert = $db->prepare("INSERT INTO noticias (user_id, categoria_id, titulo, cuerpo, imagen, url, creado_en)
                                         VALUES (:u, :c, :t, :b, :img, :url, :fe)");
                $stInsert->bindValue(':u',   $uid,   SQLITE3_INTEGER);
                $stInsert->bindValue(':c',   $categoria_id, SQLITE3_INTEGER);
                $stInsert->bindValue(':t',   $titulo, SQLITE3_TEXT);
                $stInsert->bindValue(':b',   $cuerpo, SQLITE3_TEXT);
                $stInsert->bindValue(':img', $imagen, SQLITE3_TEXT);
                $stInsert->bindValue(':url', $url,    SQLITE3_TEXT);
                $stInsert->bindValue(':fe',  date('Y-m-d H:i:s'), SQLITE3_TEXT);
                $stInsert->execute();
                $success = "¡Noticia publicada con éxito!";
            }
            break;

        case 'add_comment':
            $news_id = (int)$_POST['news_id'];
            // If logged in, use session data; otherwise, require name and email.
            if (isLoggedIn()) {
                $user_id = $_SESSION['user_id'];
                $nombre = $_SESSION['nombre'];
                $email = "";
            } else {
                $user_id = null;
                $nombre = trim($_POST['nombre']);
                $email = trim($_POST['email']);
                if (empty($nombre) || empty($email)) {
                    $error = "Nombre y email son requeridos para comentar.";
                    break;
                }
            }
            $comment = trim($_POST['comment']);
            if (empty($comment)) {
                $error = "El comentario no puede estar vacío.";
                break;
            }
            $stmtComment = $db->prepare("INSERT INTO comments (news_id, user_id, nombre, email, comment, created_at)
                                         VALUES (:news_id, :user_id, :nombre, :email, :comment, :created_at)");
            $stmtComment->bindValue(':news_id', $news_id, SQLITE3_INTEGER);
            if ($user_id === null) {
                $stmtComment->bindValue(':user_id', null, SQLITE3_NULL);
            } else {
                $stmtComment->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
            }
            $stmtComment->bindValue(':nombre', $nombre, SQLITE3_TEXT);
            $stmtComment->bindValue(':email', $email, SQLITE3_TEXT);
            $stmtComment->bindValue(':comment', $comment, SQLITE3_TEXT);
            $stmtComment->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            $stmtComment->execute();
            $success = "Comentario agregado.";
            break;
    }
}

// ====================
//  6) HANDLE LOGOUT
// ====================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// ====================
//  7) FETCH CATEGORIES FOR THE HEADER MENU
// ====================
$catTree = getCategoryTree($db);

// Define the placeholder image (update this path as needed)
$placeholder = 'uploads/placeholder.jpg';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <!-- Use dynamic meta info from site_settings -->
    <title><?= htmlspecialchars($meta_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($meta_description) ?>">
    <meta name="keywords"    content="<?= htmlspecialchars($meta_keywords) ?>">
    <meta name="author"      content="<?= htmlspecialchars($meta_author) ?>">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/svg+xml" href="mediumblue.png" />
</head>
<body>

<header>
    <!-- H1 and H2 from site_settings -->
    <h1><img src="mediumblue.png"><?= htmlspecialchars($site_title) ?></h1>
    <h2><?= htmlspecialchars($site_subtitle) ?></h2>

    <nav>
        <ul class="top-menu">
            <li><a href="index.php">Inicio</a></li>
            <?php if (isLoggedIn()): ?>
                <li><a href="?action=logout">Cerrar Sesión (<?=htmlspecialchars($_SESSION['username'])?>)</a></li>
                <?php if (isAdmin()): ?>
                    <li><a href="admin.php" style="color: #ffcc00;">Panel Admin</a></li>
                <?php endif; ?>
            <?php else: ?>
                <li><a href="?page=login">Iniciar Sesión</a></li>
                <li><a href="?page=register">Registrarse</a></li>
            <?php endif; ?>

            <!-- Category menu (top-level) -->
            <?php foreach ($catTree as $cat): ?>
                <li>
                    <a href="index.php?page=cat&cid=<?=$cat['id']?>">
                        <?=htmlspecialchars($cat['nombre'])?>
                    </a>
                    <?php if (!empty($cat['children'])): ?>
                        <?php printCategoryMenu($cat['children']); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</header>

<main>
<?php
// Display alerts
if ($error) {
    echo '<div class="alert alert-error">'.htmlspecialchars($error).'</div>';
}
if ($success) {
    echo '<div class="alert alert-success">'.htmlspecialchars($success).'</div>';
}

$page = isset($_GET['page']) ? $_GET['page'] : 'home';

switch($page) {
    case 'register':
        if (isLoggedIn()) {
            echo "<p>Ya estás registrado e iniciaste sesión.</p>";
        } else {
            ?>
            <h2>Registrarse</h2>
            <form method="post">
                <input type="hidden" name="action" value="register">
                <div>
                    <label>Nombre:</label>
                    <input type="text" name="nombre" required>
                </div>
                <div>
                    <label>Email:</label>
                    <input type="email" name="email" required>
                </div>
                <div>
                    <label>Usuario:</label>
                    <input type="text" name="username" required>
                </div>
                <div>
                    <label>Contraseña:</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit">Registrarse</button>
            </form>
            <?php
        }
        break;

    case 'login':
        if (isLoggedIn()) {
            echo "<p>Ya iniciaste sesión.</p>";
        } else {
            ?>
            <h2>Iniciar Sesión</h2>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <div>
                    <label>Usuario:</label>
                    <input type="text" name="username" required>
                </div>
                <div>
                    <label>Contraseña:</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit">Entrar</button>
            </form>
            <?php
        }
        break;

    case 'single':
        // Single news view
        $nid = isset($_GET['nid']) ? (int)$_GET['nid'] : 0;
        $stmt = $db->prepare("SELECT n.*, u.nombre AS autor, c.nombre AS cat_name
                              FROM noticias n
                              LEFT JOIN users u ON n.user_id = u.id
                              LEFT JOIN categorias c ON n.categoria_id = c.id
                              WHERE n.id = :nid");
        $stmt->bindValue(':nid', $nid, SQLITE3_INTEGER);
        $single = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$single) {
            echo "<p>Noticia no encontrada.</p>";
        } else {
            // Check image; if not set or file missing, use placeholder
            $img = $single['imagen'];
            if (empty($img) || !file_exists($img)) {
                $img = $placeholder;
            }
            ?>
            <div class="single-news">
                <img src="<?=htmlspecialchars($img)?>" alt="Noticia">
                <h2><?=htmlspecialchars($single['titulo'])?></h2>
                <div class="meta">
                    Categoría: <?=htmlspecialchars($single['cat_name'] ?: 'N/A')?> |
                    Autor: <?=htmlspecialchars($single['autor'] ?: 'Anónimo')?> |
                    Fecha: <?=htmlspecialchars($single['creado_en'])?>
                </div>
                <p><?=nl2br(htmlspecialchars($single['cuerpo']))?></p>
                <?php if (!empty($single['url'])): ?>
                    <p><a href="<?=htmlspecialchars($single['url'])?>" target="_blank">Leer más</a></p>
                <?php endif; ?>
            </div>
            <!-- Comments Section -->
            <h3>Comentarios</h3>
            <?php
            $commentsStmt = $db->prepare("SELECT * FROM comments WHERE news_id = :news_id ORDER BY created_at DESC");
            $commentsStmt->bindValue(':news_id', $nid, SQLITE3_INTEGER);
            $commentsRes = $commentsStmt->execute();
            while($c = $commentsRes->fetchArray(SQLITE3_ASSOC)) {
                echo "<div class='comment'>";
                echo "<strong>".htmlspecialchars($c['nombre'])."</strong> ";
                echo "<small>".htmlspecialchars($c['created_at'])."</small>";
                echo "<p>".nl2br(htmlspecialchars($c['comment']))."</p>";
                echo "</div>";
            }
            ?>
            <!-- Comment form -->
            <h4>Deja un comentario</h4>
            <form method="post">
                <input type="hidden" name="action" value="add_comment">
                <input type="hidden" name="news_id" value="<?= $nid ?>">
                <?php if (!isLoggedIn()): ?>
                <div>
                    <label>Nombre:</label>
                    <input type="text" name="nombre" required>
                </div>
                <div>
                    <label>Email:</label>
                    <input type="email" name="email" required>
                </div>
                <?php endif; ?>
                <div>
                    <label>Comentario:</label><br>
                    <textarea name="comment" rows="4" required></textarea>
                </div>
                <button type="submit">Enviar comentario</button>
            </form>
            <?php
        }
        break;

    case 'cat':
        // Category page
        $cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
        $catNewsStmt = $db->prepare("SELECT n.*, u.nombre AS autor, c.nombre AS cat_name
                                     FROM noticias n
                                     LEFT JOIN users u ON n.user_id = u.id
                                     LEFT JOIN categorias c ON n.categoria_id = c.id
                                     WHERE n.categoria_id = :cid
                                     ORDER BY n.id DESC");
        $catNewsStmt->bindValue(':cid', $cid, SQLITE3_INTEGER);
        $catNewsRes = $catNewsStmt->execute();

        // Get category name
        $catNameStmt = $db->prepare("SELECT nombre FROM categorias WHERE id = :cid");
        $catNameStmt->bindValue(':cid', $cid, SQLITE3_INTEGER);
        $catNameRow = $catNameStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $catName = $catNameRow ? $catNameRow['nombre'] : 'Categoría';

        echo '<h2>Noticias en '.htmlspecialchars($catName).'</h2>';

        $catNewsArr = [];
        while ($row = $catNewsRes->fetchArray(SQLITE3_ASSOC)) {
            $catNewsArr[] = $row;
        }

        if (empty($catNewsArr)) {
            echo '<p>No hay noticias en esta categoría.</p>';
            break;
        }

        $index = 0;
        $rowIndex = 1;
        $total = count($catNewsArr);

        while ($index < $total) {
            $cols = getColumnsForRow($rowIndex);
            $remaining = $total - $index;
            $countInRow = min($cols, $remaining);
            $rowItems = array_slice($catNewsArr, $index, $countInRow);

            echo '<div class="row columns-'.$countInRow.'">';
            foreach ($rowItems as $nItem) {
                $img = $nItem['imagen'];
                if (empty($img) || !file_exists($img)) {
                    $img = $placeholder;
                }
                echo '<div class="news-item">';
                    echo '<h3><a href="index.php?page=single&nid='.$nItem['id'].'">'
                         .htmlspecialchars($nItem['titulo']).'</a></h3>';
                    echo '<small class="date">'.htmlspecialchars($nItem['creado_en']).'</small>';
                    echo '<img src="'.htmlspecialchars($img).'" alt="Noticia">';
                    echo '<p>'.nl2br(htmlspecialchars(substr($nItem['cuerpo'], 0, 100))).'...</p>';
                    if (!empty($nItem['url'])) {
                        echo '<p><a href="'.htmlspecialchars($nItem['url']).'" target="_blank">Leer más</a></p>';
                    }
                echo '</div>';
            }
            echo '</div>';

            $index += $countInRow;
            $rowIndex++;
        }
        break;

    case 'home':
    default:
        // If logged in, show "add news" form
        if (isLoggedIn()) {
            ?>
            <h2>Publicar Noticia</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_news">
                <div>
                    <label>Título:</label>
                    <input type="text" name="titulo" required>
                </div>
                <div>
                    <label>Categoría:</label>
                    <select name="categoria_id">
                        <option value="">-- Ninguna --</option>
                        <?php
                        $catRes = $db->query("SELECT id, nombre FROM categorias ORDER BY nombre ASC");
                        while ($cRow = $catRes->fetchArray(SQLITE3_ASSOC)) {
                            echo '<option value="'.$cRow['id'].'">'.htmlspecialchars($cRow['nombre']).'</option>';
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label>Cuerpo:</label><br>
                    <textarea name="cuerpo" rows="5" style="width:400px;" required></textarea>
                </div>
                <div>
                    <label>URL:</label>
                    <input type="text" name="url" placeholder="https://ejemplo.com/more-info">
                </div>
                <div>
                    <label>Imagen (JPG):</label>
                    <input type="file" name="imagen" accept=".jpg">
                </div>
                <button type="submit">Publicar</button>
            </form>
            <?php
        }

        // Show all news in multi-row
        $newsRes = $db->query("SELECT n.*, u.nombre AS autor, c.nombre AS cat_name
                               FROM noticias n
                               LEFT JOIN users u ON n.user_id = u.id
                               LEFT JOIN categorias c ON n.categoria_id = c.id
                               ORDER BY n.id DESC");
        $allNews = [];
        while ($row = $newsRes->fetchArray(SQLITE3_ASSOC)) {
            $allNews[] = $row;
        }

        echo '<h2>Noticias</h2>';

        $index = 0;
        $rowIndex = 1;
        $total = count($allNews);

        while ($index < $total) {
            $cols = getColumnsForRow($rowIndex);
            $remaining = $total - $index;
            $countInRow = min($cols, $remaining);
            $rowItems = array_slice($allNews, $index, $countInRow);

            echo '<div class="row columns-'.$countInRow.'">';
            foreach ($rowItems as $nItem) {
                $img = $nItem['imagen'];
                if (empty($img)) {
                    $img = $placeholder;
                }
                echo '<div class="news-item">';
                    echo '<h3><a href="index.php?page=single&nid='.$nItem['id'].'">'
                         .htmlspecialchars($nItem['titulo']).'</a></h3>';
                    echo '<small class="date">'.htmlspecialchars($nItem['creado_en']).'</small>';
                    echo '<img src="'.htmlspecialchars($img).'" alt="Noticia">';
                    echo '<p>'.nl2br(htmlspecialchars(substr($nItem['cuerpo'], 0, 100))).'...</p>';
                    if (!empty($nItem['url'])) {
                        echo '<p><a href="'.htmlspecialchars($nItem['url']).'" target="_blank">Leer más</a></p>';
                    }
                echo '</div>';
            }
            echo '</div>';

            $index += $countInRow;
            $rowIndex++;
        }
        break;
}
?>
</main>

<footer>
    <p>&copy; <?=date('Y')?> - PlanesValencia</p>
</footer>
<script src="https://ghostwhite.jocarsa.com/analytics.js?user=planesvalencia.es"></script>
</body>
</html>


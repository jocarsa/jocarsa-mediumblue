<?php
session_start();
$db = new SQLite3('portal.db');

// -------------- Funciones Auxiliares --------------
function isAdmin() {
    return (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1);
}
function getAllUsers($db) {
    $users = [];
    $res = $db->query("SELECT * FROM users ORDER BY id ASC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    return $users;
}
function getAllCategories($db) {
    $cats = [];
    $res = $db->query("SELECT * FROM categorias ORDER BY nombre ASC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $cats[] = $row;
    }
    return $cats;
}
function getAllNews($db) {
    $news = [];
    $res = $db->query("SELECT n.*, u.nombre AS autor, c.nombre AS cat_name
                       FROM noticias n
                       LEFT JOIN users u ON n.user_id = u.id
                       LEFT JOIN categorias c ON n.categoria_id = c.id
                       ORDER BY n.id DESC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $news[] = $row;
    }
    return $news;
}
// NEW: Get all comments
function getAllComments($db) {
    $comments = [];
    $res = $db->query("SELECT * FROM comments ORDER BY created_at DESC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $comments[] = $row;
    }
    return $comments;
}
// Recursive delete categories if needed
function deleteCategoryRecursive($db, $catId) {
    // find subcats
    $stSub = $db->prepare("SELECT id FROM categorias WHERE parent_id = :p");
    $stSub->bindValue(':p', $catId, SQLITE3_INTEGER);
    $resSub = $stSub->execute();
    while($sub = $resSub->fetchArray(SQLITE3_ASSOC)) {
        deleteCategoryRecursive($db, $sub['id']);
    }
    // delete category itself
    $db->exec("DELETE FROM categorias WHERE id = $catId");
}

// -------------- Manejo de Login Admin --------------
$error   = '';
$success = '';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// If not admin, show login form
if (!isAdmin()) {
    if (isset($_POST['action']) && $_POST['action'] === 'login_admin') {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $st = $db->prepare("SELECT * FROM users WHERE username = :u AND is_admin = 1");
        $st->bindValue(':u', $username, SQLITE3_TEXT);
        $res = $st->execute()->fetchArray(SQLITE3_ASSOC);
        if ($res && password_verify($password, $res['password'])) {
            $_SESSION['user_id']  = $res['id'];
            $_SESSION['username'] = $res['username'];
            $_SESSION['is_admin'] = $res['is_admin'];
            $_SESSION['nombre']   = $res['nombre'];
            header("Location: admin.php");
            exit;
        } else {
            $error = "Usuario o contraseña de administrador incorrectos.";
        }
    }

    // Show admin login
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Panel Admin - Login</title>
        <link rel="stylesheet" href="admin.css">
    </head>
    <body>
        <div class="login-container">
            <h1>Acceso Admin</h1>
            <?php
            if ($error) {
                echo '<div class="alert alert-error">'.htmlspecialchars($error).'</div>';
            }
            ?>
            <form method="post">
                <input type="hidden" name="action" value="login_admin">
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
        </div>
    </body>
    </html>
    <?php
    exit;
}

// -------------- If Admin, handle CRUD --------------
if (isset($_POST['action'])) {
    switch($_POST['action']) {

        // ========== SITE SETTINGS ==========
        case 'update_site_settings':
            $site_title       = trim($_POST['site_title']);
            $site_subtitle    = trim($_POST['site_subtitle']);
            $meta_title       = trim($_POST['meta_title']);
            $meta_description = trim($_POST['meta_description']);
            $meta_keywords    = trim($_POST['meta_keywords']);
            $meta_author      = trim($_POST['meta_author']);

            // check if we already have 1 row
            $checkRow = $db->query("SELECT id FROM site_settings LIMIT 1");
            $existing = $checkRow->fetchArray(SQLITE3_ASSOC);

            if ($existing) {
                // Update
                $update = $db->prepare("UPDATE site_settings
                    SET site_title = :st,
                        site_subtitle = :ss,
                        meta_title = :mt,
                        meta_description = :md,
                        meta_keywords = :mk,
                        meta_author = :ma
                    WHERE id = :id");
                $update->bindValue(':st', $site_title,       SQLITE3_TEXT);
                $update->bindValue(':ss', $site_subtitle,    SQLITE3_TEXT);
                $update->bindValue(':mt', $meta_title,       SQLITE3_TEXT);
                $update->bindValue(':md', $meta_description, SQLITE3_TEXT);
                $update->bindValue(':mk', $meta_keywords,    SQLITE3_TEXT);
                $update->bindValue(':ma', $meta_author,      SQLITE3_TEXT);
                $update->bindValue(':id', $existing['id'],   SQLITE3_INTEGER);
                $update->execute();
            } else {
                // Insert
                $ins = $db->prepare("INSERT INTO site_settings
                    (site_title, site_subtitle, meta_title, meta_description, meta_keywords, meta_author)
                    VALUES (:st, :ss, :mt, :md, :mk, :ma)");
                $ins->bindValue(':st', $site_title,       SQLITE3_TEXT);
                $ins->bindValue(':ss', $site_subtitle,    SQLITE3_TEXT);
                $ins->bindValue(':mt', $meta_title,       SQLITE3_TEXT);
                $ins->bindValue(':md', $meta_description, SQLITE3_TEXT);
                $ins->bindValue(':mk', $meta_keywords,    SQLITE3_TEXT);
                $ins->bindValue(':ma', $meta_author,      SQLITE3_TEXT);
                $ins->execute();
            }

            $success = "Site settings updated.";
            break;

        // ========== USERS ==========
        case 'create_user':
            $nombre   = trim($_POST['nombre']);
            $email    = trim($_POST['email']);
            $username = trim($_POST['username']);
            $password = trim($_POST['password']);
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;

            if ($username && $password) {
                // check duplicate
                $stCheck = $db->prepare("SELECT id FROM users WHERE username = :u");
                $stCheck->bindValue(':u', $username, SQLITE3_TEXT);
                $reCheck = $stCheck->execute();
                if ($reCheck->fetchArray()) {
                    $error = "Ese usuario ya existe.";
                } else {
                    $hashPass = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $db->prepare("INSERT INTO users (nombre, email, username, password, is_admin)
                                         VALUES (:n, :e, :u, :p, :a)");
                    $ins->bindValue(':n', $nombre,   SQLITE3_TEXT);
                    $ins->bindValue(':e', $email,    SQLITE3_TEXT);
                    $ins->bindValue(':u', $username, SQLITE3_TEXT);
                    $ins->bindValue(':p', $hashPass, SQLITE3_TEXT);
                    $ins->bindValue(':a', $is_admin, SQLITE3_INTEGER);
                    $ins->execute();
                    $success = "Usuario creado correctamente.";
                }
            }
            break;

        case 'update_user':
            $user_id  = (int)$_POST['user_id'];
            $nombre   = trim($_POST['nombre']);
            $email    = trim($_POST['email']);
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;

            $up = $db->prepare("UPDATE users SET nombre=:n, email=:e, is_admin=:a WHERE id=:id");
            $up->bindValue(':n', $nombre, SQLITE3_TEXT);
            $up->bindValue(':e', $email,  SQLITE3_TEXT);
            $up->bindValue(':a', $is_admin, SQLITE3_INTEGER);
            $up->bindValue(':id', $user_id,  SQLITE3_INTEGER);
            $up->execute();
            $success = "Usuario actualizado.";
            break;

        case 'delete_user':
            $user_id = (int)$_POST['user_id'];
            $db->exec("DELETE FROM users WHERE id = $user_id AND username != 'admin'");
            $success = "Usuario borrado (a menos que fuera el admin principal).";
            break;

        // ========== CATEGORIAS ==========
        case 'create_cat':
            $nombreCat = trim($_POST['nombre']);
            $parentId  = ($_POST['parent_id'] !== '') ? (int)$_POST['parent_id'] : null;
            if ($nombreCat) {
                $st = $db->prepare("INSERT INTO categorias (nombre, parent_id) VALUES (:n, :p)");
                $st->bindValue(':n', $nombreCat, SQLITE3_TEXT);
                if ($parentId === null) {
                    $st->bindValue(':p', null, SQLITE3_NULL);
                } else {
                    $st->bindValue(':p', $parentId, SQLITE3_INTEGER);
                }
                $st->execute();
                $success = "Categoría creada.";
            }
            break;

        case 'update_cat':
            $cat_id   = (int)$_POST['cat_id'];
            $nombre   = trim($_POST['nombre']);
            $parentId = ($_POST['parent_id'] !== '') ? (int)$_POST['parent_id'] : null;
            $upc = $db->prepare("UPDATE categorias SET nombre=:n, parent_id=:p WHERE id=:id");
            $upc->bindValue(':n', $nombre, SQLITE3_TEXT);
            if ($parentId === null) {
                $upc->bindValue(':p', null, SQLITE3_NULL);
            } else {
                $upc->bindValue(':p', $parentId, SQLITE3_INTEGER);
            }
            $upc->bindValue(':id', $cat_id, SQLITE3_INTEGER);
            $upc->execute();
            $success = "Categoría actualizada.";
            break;

        case 'delete_cat':
            $cat_id = (int)$_POST['cat_id'];
            deleteCategoryRecursive($db, $cat_id);
            $success = "Categoría borrada (y subcategorías).";
            break;

        // ========== NOTICIAS ==========
        case 'create_news':
            $titulo   = trim($_POST['titulo']);
            $cat_id   = (int)$_POST['categoria_id'];
            $cuerpo   = trim($_POST['cuerpo']);
            $user_id  = (int)$_POST['user_id'];
            $url      = trim($_POST['url']);
            $imagen   = null;
            if (!empty($_FILES['imagen']['name'])) {
                $fName = $_FILES['imagen']['name'];
                $tmp   = $_FILES['imagen']['tmp_name'];
                $err   = $_FILES['imagen']['error'];
                $ext   = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                if ($err === 0 && $ext === 'jpg') {
                    if (!is_dir('uploads')) {
                        mkdir('uploads', 0777, true);
                    }
                    $uniqueName = uniqid().'.jpg';
                    $dest       = 'uploads/'.$uniqueName;
                    move_uploaded_file($tmp, $dest);
                    $imagen     = $dest;
                } else {
                    $error = "Error subiendo imagen (solo JPG).";
                }
            }
            if (!$error) {
                $stn = $db->prepare("INSERT INTO noticias (user_id, categoria_id, titulo, cuerpo, imagen, url, creado_en)
                                     VALUES (:u, :c, :t, :b, :img, :url, :fe)");
                $stn->bindValue(':u',   $user_id, SQLITE3_INTEGER);
                $stn->bindValue(':c',   $cat_id,  SQLITE3_INTEGER);
                $stn->bindValue(':t',   $titulo,  SQLITE3_TEXT);
                $stn->bindValue(':b',   $cuerpo,  SQLITE3_TEXT);
                $stn->bindValue(':img', $imagen,  SQLITE3_TEXT);
                $stn->bindValue(':url', $url,     SQLITE3_TEXT);
                $stn->bindValue(':fe',  date('Y-m-d H:i:s'), SQLITE3_TEXT);
                $stn->execute();
                $success = "Noticia creada.";
            }
            break;

        case 'update_news':
            $nid     = (int)$_POST['nid'];
            $titulo  = trim($_POST['titulo']);
            $cid     = (int)$_POST['categoria_id'];
            $cuerpo  = trim($_POST['cuerpo']);
            $url     = trim($_POST['url']);
            $imagen  = null;

            if (!empty($_FILES['imagen']['name'])) {
                $fName = $_FILES['imagen']['name'];
                $tmp   = $_FILES['imagen']['tmp_name'];
                $err   = $_FILES['imagen']['error'];
                $ext   = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                if ($err === 0 && $ext === 'jpg') {
                    if (!is_dir('uploads')) {
                        mkdir('uploads', 0777, true);
                    }
                    $uniqueName = uniqid().'.jpg';
                    $dest       = 'uploads/'.$uniqueName;
                    move_uploaded_file($tmp, $dest);
                    $imagen     = $dest;
                } else {
                    $error = "Error subiendo imagen (solo JPG).";
                }
            }

            if (!$error) {
                if ($imagen) {
                    // update with new image
                    $upn = $db->prepare("UPDATE noticias
                                         SET titulo=:t, categoria_id=:c, cuerpo=:b, imagen=:img, url=:url
                                         WHERE id=:id");
                    $upn->bindValue(':img', $imagen, SQLITE3_TEXT);
                } else {
                    // keep old image
                    $upn = $db->prepare("UPDATE noticias
                                         SET titulo=:t, categoria_id=:c, cuerpo=:b, url=:url
                                         WHERE id=:id");
                }
                $upn->bindValue(':t', $titulo,   SQLITE3_TEXT);
                $upn->bindValue(':c', $cid,      SQLITE3_INTEGER);
                $upn->bindValue(':b', $cuerpo,   SQLITE3_TEXT);
                $upn->bindValue(':url', $url,    SQLITE3_TEXT);
                $upn->bindValue(':id', $nid,     SQLITE3_INTEGER);
                $upn->execute();
                $success = "Noticia actualizada.";
            }
            break;

        case 'delete_news':
            $nid = (int)$_POST['nid'];
            $db->exec("DELETE FROM noticias WHERE id = $nid");
            $success = "Noticia borrada.";
            break;

        // ========== COMENTARIOS ==========
        case 'delete_comment':
            $comment_id = (int)$_POST['comment_id'];
            $db->exec("DELETE FROM comments WHERE id = $comment_id");
            $success = "Comentario borrado.";
            break;

        case 'update_comment':
            $comment_id = (int)$_POST['comment_id'];
            $comment_text = trim($_POST['comment']);
            $upd = $db->prepare("UPDATE comments SET comment = :comment WHERE id = :id");
            $upd->bindValue(':comment', $comment_text, SQLITE3_TEXT);
            $upd->bindValue(':id', $comment_id, SQLITE3_INTEGER);
            $upd->execute();
            $success = "Comentario actualizado.";
            break;
    }
}

// -------------- Show Admin Panel HTML --------------
$section = isset($_GET['section']) ? $_GET['section'] : 'dashboard';
$users      = getAllUsers($db);
$categorias = getAllCategories($db);
$noticias   = getAllNews($db);
$comments   = getAllComments($db);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Administración</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>

<header class="admin-header">
    <h1>Panel de Administración - PlanesValencia</h1>
</header>

<div class="admin-container">
    <nav class="admin-sidebar">
        <a href="?section=dashboard" class="<?=($section==='dashboard'?'active':'')?>">Dashboard</a>
        <a href="?section=users"     class="<?=($section==='users'?'active':'')?>">Usuarios</a>
        <a href="?section=cats"      class="<?=($section==='cats'?'active':'')?>">Categorías</a>
        <a href="?section=news"      class="<?=($section==='news'?'active':'')?>">Noticias</a>
        <a href="?section=comments"  class="<?=($section==='comments'?'active':'')?>">Comentarios</a>
        <a href="?section=site_settings" class="<?=($section==='site_settings'?'active':'')?>">Site Settings</a>
        <a href="?action=logout">Cerrar Sesión</a>
    </nav>

    <main class="admin-content">
        <?php
        if ($error) {
            echo '<div class="alert alert-error">'.htmlspecialchars($error).'</div>';
        }
        if ($success) {
            echo '<div class="alert alert-success">'.htmlspecialchars($success).'</div>';
        }

        switch($section) {
            case 'dashboard':
                echo "<h2>Bienvenido, ".$_SESSION['nombre']."</h2>";
                echo "<p>Este es tu panel de administración.<br>
                      Utiliza el menú de la izquierda para gestionar usuarios, categorías, noticias, comentarios y la configuración del sitio.</p>";
                break;

            case 'users':
                echo "<h2>Gestión de Usuarios</h2>";
                ?>
                <!-- Crear Usuario -->
                <form method="post">
                    <input type="hidden" name="action" value="create_user">
                    <h3>Crear Usuario</h3>
                    <div>
                        <label>Nombre:</label>
                        <input type="text" name="nombre">
                    </div>
                    <div>
                        <label>Email:</label>
                        <input type="email" name="email">
                    </div>
                    <div>
                        <label>Usuario:</label>
                        <input type="text" name="username" required>
                    </div>
                    <div>
                        <label>Contraseña:</label>
                        <input type="password" name="password" required>
                    </div>
                    <div>
                        <label>Admin:</label>
                        <input type="checkbox" name="is_admin" value="1">
                    </div>
                    <button type="submit">Crear</button>
                </form>

                <!-- Listado de Usuarios -->
                <h3>Listado de Usuarios</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th><th>Nombre</th><th>Usuario</th><th>Email</th><th>Admin</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($users as $u): ?>
                        <tr>
                            <td><?=$u['id']?></td>
                            <td><?=htmlspecialchars($u['nombre'])?></td>
                            <td><?=htmlspecialchars($u['username'])?></td>
                            <td><?=htmlspecialchars($u['email'])?></td>
                            <td><?=$u['is_admin']?></td>
                            <td>
                                <!-- Update Form -->
                                <form method="post" style="display:inline-block;">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="user_id" value="<?=$u['id']?>">
                                    <input type="hidden" name="nombre" value="<?=htmlspecialchars($u['nombre'])?>">
                                    <input type="hidden" name="email" value="<?=htmlspecialchars($u['email'])?>">
                                    <input type="hidden" name="is_admin" value="<?=$u['is_admin']?>">
                                    <button type="button" onclick="editUser(this.form)">Editar</button>
                                </form>
                                <!-- Delete Form -->
                                <?php if($u['username'] !== 'admin'): ?>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('¿Borrar usuario?')">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?=$u['id']?>">
                                    <button type="submit">Borrar</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <script>
                function editUser(form) {
                    const nombre = prompt("Nombre:", form.nombre.value);
                    if(nombre===null) return;
                    const email = prompt("Email:", form.email.value);
                    if(email===null) return;
                    const isAdmin = confirm("¿Marcar como Admin?");
                    form.nombre.value = nombre;
                    form.email.value = email;
                    form.is_admin.value = isAdmin ? 1 : 0;
                    form.submit();
                }
                </script>
                <?php
                break;

            case 'cats':
                echo "<h2>Gestión de Categorías</h2>";
                ?>
                <form method="post">
                    <input type="hidden" name="action" value="create_cat">
                    <h3>Crear Categoría</h3>
                    <div>
                        <label>Nombre:</label>
                        <input type="text" name="nombre" required>
                    </div>
                    <div>
                        <label>Padre:</label>
                        <select name="parent_id">
                            <option value="">(Ninguna)</option>
                            <?php foreach($categorias as $c): ?>
                                <option value="<?=$c['id']?>"><?=htmlspecialchars($c['nombre'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit">Crear</button>
                </form>

                <h3>Listado de Categorías</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th><th>Nombre</th><th>Padre</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($categorias as $c): ?>
                        <tr>
                            <td><?=$c['id']?></td>
                            <td><?=htmlspecialchars($c['nombre'])?></td>
                            <td><?=$c['parent_id'] ?: '—'?></td>
                            <td>
                                <form method="post" style="display:inline-block;">
                                    <input type="hidden" name="action" value="update_cat">
                                    <input type="hidden" name="cat_id" value="<?=$c['id']?>">
                                    <input type="hidden" name="nombre" value="<?=htmlspecialchars($c['nombre'])?>">
                                    <input type="hidden" name="parent_id" value="<?=$c['parent_id']?>">
                                    <button type="button" onclick="editCat(this.form)">Editar</button>
                                </form>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('¿Borrar categoría?')">
                                    <input type="hidden" name="action" value="delete_cat">
                                    <input type="hidden" name="cat_id" value="<?=$c['id']?>">
                                    <button type="submit">Borrar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <script>
                function editCat(form) {
                    const nombre = prompt("Nombre de la categoría:", form.nombre.value);
                    if(nombre===null) return;
                    const parentId = prompt("ID de la categoría padre (dejar vacío para ninguna):", form.parent_id.value || "");
                    if(parentId===null) return;
                    form.nombre.value = nombre;
                    form.parent_id.value = parentId;
                    form.submit();
                }
                </script>
                <?php
                break;

            case 'news':
                echo "<h2>Gestión de Noticias</h2>";
                ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_news">
                    <h3>Crear Noticia</h3>
                    <div>
                        <label>Título:</label>
                        <input type="text" name="titulo" required>
                    </div>
                    <div>
                        <label>Categoría:</label>
                        <select name="categoria_id">
                            <option value="">(Ninguna)</option>
                            <?php foreach($categorias as $c): ?>
                                <option value="<?=$c['id']?>"><?=htmlspecialchars($c['nombre'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>User ID:</label>
                        <select name="user_id">
                            <?php foreach($users as $u): ?>
                                <option value="<?=$u['id']?>"><?=$u['id']?> - <?=htmlspecialchars($u['username'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Cuerpo:</label><br>
                        <textarea name="cuerpo" rows="5" cols="60"></textarea>
                    </div>
                    <div>
                        <label>URL:</label>
                        <input type="text" name="url" placeholder="https://ejemplo.com/more-info">
                    </div>
                    <div>
                        <label>Imagen (JPG):</label>
                        <input type="file" name="imagen" accept=".jpg">
                    </div>
                    <button type="submit">Crear</button>
                </form>

                <h3>Listado de Noticias</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th><th>Título</th><th>Categoría</th><th>Autor</th><th>Fecha</th><th>URL</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($noticias as $n): ?>
                        <tr>
                            <td><?=$n['id']?></td>
                            <td><?=htmlspecialchars($n['titulo'])?></td>
                            <td><?=htmlspecialchars($n['cat_name'] ?: '—')?></td>
                            <td><?=htmlspecialchars($n['autor'] ?: '—')?></td>
                            <td><?=htmlspecialchars($n['creado_en'])?></td>
                            <td><?=htmlspecialchars($n['url'])?></td>
                            <td>
                                <form method="post" enctype="multipart/form-data" style="display:inline-block;">
                                    <input type="hidden" name="action" value="update_news">
                                    <input type="hidden" name="nid" value="<?=$n['id']?>">
                                    <input type="hidden" name="titulo" value="<?=htmlspecialchars($n['titulo'])?>">
                                    <input type="hidden" name="categoria_id" value="<?=$n['categoria_id']?>">
                                    <input type="hidden" name="cuerpo" value="<?=htmlspecialchars($n['cuerpo'])?>">
                                    <input type="hidden" name="url" value="<?=htmlspecialchars($n['url'])?>">
                                    <button type="button" onclick="editNews(this.form)">Editar</button>
                                </form>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('¿Borrar noticia?')">
                                    <input type="hidden" name="action" value="delete_news">
                                    <input type="hidden" name="nid" value="<?=$n['id']?>">
                                    <button type="submit">Borrar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <script>
                function editNews(form) {
                    const titulo = prompt("Título:", form.titulo.value);
                    if(titulo===null) return;
                    const catId = prompt("ID Categoría:", form.categoria_id.value);
                    if(catId===null) return;
                    const cuerpo = prompt("Cuerpo:", form.cuerpo.value);
                    if(cuerpo===null) return;
                    const url = prompt("URL:", form.url.value);
                    if(url===null) return;

                    form.titulo.value = titulo;
                    form.categoria_id.value = catId;
                    form.cuerpo.value = cuerpo;
                    form.url.value = url;

                    form.submit();
                }
                </script>
                <?php
                break;

            case 'comments':
                echo "<h2>Gestión de Comentarios</h2>";
                ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Noticia ID</th>
                            <th>User ID</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Comentario</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($comments as $c): ?>
                        <tr>
                            <td><?=$c['id']?></td>
                            <td><?=$c['news_id']?></td>
                            <td><?=$c['user_id'] ?: '—'?></td>
                            <td><?=htmlspecialchars($c['nombre'])?></td>
                            <td><?=htmlspecialchars($c['email'])?></td>
                            <td><?=htmlspecialchars($c['comment'])?></td>
                            <td><?=htmlspecialchars($c['created_at'])?></td>
                            <td>
                                <form method="post" style="display:inline-block;">
                                    <input type="hidden" name="action" value="update_comment">
                                    <input type="hidden" name="comment_id" value="<?=$c['id']?>">
                                    <input type="hidden" name="comment" value="<?=htmlspecialchars($c['comment'])?>">
                                    <button type="button" onclick="editComment(this.form)">Editar</button>
                                </form>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('¿Borrar comentario?')">
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?=$c['id']?>">
                                    <button type="submit">Borrar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <script>
                function editComment(form) {
                    const comment = prompt("Editar comentario:", form.comment.value);
                    if(comment===null) return;
                    form.comment.value = comment;
                    form.submit();
                }
                </script>
                <?php
                break;

            case 'site_settings':
                echo "<h2>Site Settings</h2>";
                // Load existing row
                $rowSettings = $db->query("SELECT * FROM site_settings LIMIT 1")->fetchArray(SQLITE3_ASSOC);

                $site_title       = $rowSettings ? $rowSettings['site_title']       : '';
                $site_subtitle    = $rowSettings ? $rowSettings['site_subtitle']    : '';
                $meta_title       = $rowSettings ? $rowSettings['meta_title']       : '';
                $meta_description = $rowSettings ? $rowSettings['meta_description'] : '';
                $meta_keywords    = $rowSettings ? $rowSettings['meta_keywords']    : '';
                $meta_author      = $rowSettings ? $rowSettings['meta_author']      : '';

                ?>
                <form method="post">
                    <input type="hidden" name="action" value="update_site_settings">

                    <div>
                        <label>H1 Title:</label>
                        <input type="text" name="site_title" value="<?=htmlspecialchars($site_title)?>">
                    </div>
                    <div>
                        <label>H2 Subtitle:</label>
                        <input type="text" name="site_subtitle" value="<?=htmlspecialchars($site_subtitle)?>">
                    </div>
                    <div>
                        <label>Meta Title:</label>
                        <input type="text" name="meta_title" value="<?=htmlspecialchars($meta_title)?>">
                    </div>
                    <div>
                        <label>Meta Desc.:</label><br>
                        <textarea name="meta_description" rows="2" cols="60"><?=htmlspecialchars($meta_description)?></textarea>
                    </div>
                    <div>
                        <label>Meta Keywords:</label><br>
                        <textarea name="meta_keywords" rows="2" cols="60"><?=htmlspecialchars($meta_keywords)?></textarea>
                    </div>
                    <div>
                        <label>Meta Author:</label>
                        <input type="text" name="meta_author" value="<?=htmlspecialchars($meta_author)?>">
                    </div>

                    <button type="submit">Guardar</button>
                </form>
                <?php
                break;

            default:
                echo "<h2>Sección no encontrada.</h2>";
                break;
        }
        ?>
    </main>
</div>

<script>
/* Additional JS if needed */
</script>
</body>
</html>


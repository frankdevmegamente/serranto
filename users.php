<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
requireAdmin();

$error = '';
$success = '';

// Handle add user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';

        if (empty($username) || empty($password)) {
            $error = 'Usuario y contraseña son requeridos.';
        } elseif (strlen($password) < 4) {
            $error = 'La contraseña debe tener al menos 4 caracteres.';
        } elseif (getUser($username)) {
            $error = 'El usuario ya existe.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            if (createUser($username, $hash, $role)) {
                $success = 'Usuario creado correctamente.';
            } else {
                $error = 'Error al crear el usuario.';
            }
        }
    } elseif ($_POST['action'] === 'delete_user') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $user = getUserById($id);
        if ($user && $user['username'] === ADMIN_USER) {
            $error = 'No puedes eliminar al administrador principal.';
        } elseif ($user && deleteUser($id)) {
            $success = 'Usuario eliminado.';
        } else {
            $error = 'Error al eliminar el usuario.';
        }
    } elseif ($_POST['action'] === 'change_password') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) < 4) {
            $error = 'La contraseña debe tener al menos 4 caracteres.';
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            if (updateUserPassword($id, $hash)) {
                $success = 'Contraseña actualizada.';
            } else {
                $error = 'Error al actualizar contraseña.';
            }
        }
    }
}

$users = getUsers();
$adminDbUser = getUser(ADMIN_USER);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios — Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .users-body { background: var(--bg-body); padding: 2rem; max-width: 800px; margin: 0 auto; }
        .users-body h1 { font-size: 1.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .users-body h1 a { font-size: 0.85rem; font-weight: 400; text-decoration: none; color: var(--color-primary); }
        .user-table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
        .user-table th, .user-table td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .user-table th { font-weight: 600; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .user-table .badge { display: inline-block; padding: 0.15rem 0.4rem; border-radius: 99px; font-size: 0.7rem; font-weight: 600; }
        .badge-admin { background: #dbeafe; color: #1d4ed8; }
        .badge-viewer { background: #f1f5f9; color: #475569; }
        .form-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.5rem; }
        .form-card h3 { font-size: 0.95rem; margin-bottom: 1rem; }
        .form-card .form-group { margin-bottom: 0.75rem; }
        .form-card label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.25rem; }
        .form-card input, .form-card select { width: 100%; padding: 0.45rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg-card); color: var(--text); }
        .actions { display: flex; gap: 0.5rem; align-items: center; }
        .actions form { display: inline; }
        .btn-danger { background: #dc2626; color: #fff; border: none; padding: 0.35rem 0.65rem; border-radius: var(--radius-sm); cursor: pointer; font-size: 0.75rem; }
        .btn-danger:hover { background: #b91c1c; }
        .back-link { display: inline-block; margin-bottom: 1rem; color: var(--color-primary); text-decoration: none; font-size: 0.85rem; }
    </style>
</head>
<body class="users-body">
    <a href="dashboard.php" class="back-link">← Volver al Dashboard</a>
    <h1>👥 Usuarios</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-info"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="form-card">
        <h3>Agregar usuario</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            <div class="form-group">
                <label for="username">Nombre de usuario</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required minlength="4">
            </div>
            <div class="form-group">
                <label for="role">Rol</label>
                <select id="role" name="role">
                    <option value="viewer">Viewer (solo ver)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Agregar usuario</button>
        </form>
    </div>

    <table class="user-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Usuario</th>
                <th>Rol</th>
                <th>Creado</th>
                <th>Último login</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><span class="badge badge-<?= $u['role'] ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                    <td><?= htmlspecialchars($u['created_at'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($u['last_login'] ?? '—') ?></td>
                    <td class="actions">
                        <form method="POST" onsubmit="return confirm('¿Cambiar contraseña de <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?')">
                            <input type="hidden" name="action" value="change_password">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="password" name="new_password" placeholder="Nueva contraseña" required minlength="4" style="width:130px;padding:0.3rem;font-size:0.75rem;border:1px solid var(--border);border-radius:var(--radius-sm);">
                            <button type="submit" class="btn" style="padding:0.3rem 0.5rem;font-size:0.75rem;">Cambiar</button>
                        </form>
                        <?php if ($u['username'] !== ADMIN_USER): ?>
                            <form method="POST" onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?')">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn-danger">Eliminar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>

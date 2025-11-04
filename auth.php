<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getDbConnection(): PDO
{
    static $pdo;
    if (!$pdo) {
        $dbPath = __DIR__ . '/app.db';
        if (!file_exists($dbPath)) {
            die("Файл базы данных не найден. Пожалуйста, запустите setup_database.php");
        }
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function handleLogin(): void
{
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        displayLoginForm("Имя пользователя и пароль не могут быть пустыми.");
        return;
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $username;
            header("Location: create_site.php"); // Перенаправляем на главную страницу
            exit;
        } else {
            displayLoginForm("Неверное имя пользователя или пароль.");
        }
    } catch (PDOException $e) {
        displayLoginForm("Ошибка базы данных: " . $e->getMessage());
    }
}

function handleLogout(): void
{
    session_unset();
    session_destroy();
    header("Location: create_site.php");
    exit;
}

function displayLoginForm(string $error = ''): void
{
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>Вход</title>
        <style>
            body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; block-size: 100vh; background-color: #f4f4f4; }
            .login-container { background: #fff; padding: 2em; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); inline-size: 300px; }
            h1 { text-align: center; }
            .error { color: #d93025; margin-block-end: 1em; }
            label { display: block; margin-block-end: 0.5em; }
            input { inline-size: 100%; padding: 8px; margin-block-end: 1em; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
            button { inline-size: 100%; background-color: #007bff; color: white; padding: 10px; border: none; border-radius: 4px; cursor: pointer; }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h1>Вход в панель</h1>
            <?php if ($error): ?>
                <p class="error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            <form action="create_site.php?action=login" method="POST">
                <div>
                    <label for="username">Имя пользователя:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div>
                    <label for="password">Пароль:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit">Войти</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}
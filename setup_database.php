<?php
declare(strict_types=1);

// Расположение файла базы данных
$dbPath = __DIR__ . '/app.db';

// Пароль для первого администратора (ИЗМЕНИТЕ ЭТО!)
$adminPassword = 'YourStrongPassword123!';

try {
    // Удаляем старую БД, если она существует, для чистого старта
    if (file_exists($dbPath)) {
        unlink($dbPath);
    }

    // Создаем новый экземпляр PDO для SQLite
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "База данных успешно создана по пути: {$dbPath}\n";

    // SQL для создания таблицы пользователей
    $sql = "
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL
    );";
    $pdo->exec($sql);
    echo "Таблица 'users' успешно создана.\n";

    // SQL для создания таблицы сайтов
    $sqlSites = "
    CREATE TABLE sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL UNIQUE,
        username TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );";
    $pdo->exec($sqlSites);
    echo "Таблица 'sites' успешно создана.\n";

    // Добавляем первого пользователя-администратора
    $hashedPassword = password_hash($adminPassword, PASSWORD_ARGON2ID);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->execute(['admin', $hashedPassword]);
    echo "Пользователь 'admin' успешно создан.\n";

} catch (PDOException $e) {
    die("Ошибка при настройке базы данных: " . $e->getMessage() . "\n");
}
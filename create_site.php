<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';


// /var/www/app/public/create_site.php

// --- Configuration ---
$config = [
    'vhost_template_path' => '/var/www/app/templates/vhost_template.conf',
    'sites_available_path' => '/etc/apache2/sites-available/',
    'hosts_base_path' => '/var/www/hosts/',
    'ssl_certificate_path' => '/etc/ssl/certs/',
    'ssl_private_key_path' => '/etc/ssl/private/',
    'provisioning_scripts_path' => '/var/www/app/provisioning_scripts/',
    'admin_email' => 'your-email@example.com' // ВАЖНО: Укажите ваш email для Let's Encrypt
];

// Простой маршрутизатор на основе GET-параметра 'action'
$action = $_GET['action'] ?? 'home';

if (!isLoggedIn()) {
    // Если пользователь не вошел в систему, все действия, кроме входа, запрещены
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        handleLogin();
    } else {
        displayLoginForm();
    }
    exit;
}

// --- Маршрутизация для залогиненных пользователей ---
if ($action === 'logout') {
    handleLogout();
} elseif ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handleSiteCreationForm($config);
} else {
    displayCreateSiteForm();
}

/**
 * Handles the form submission to create a new site.
 */
function handleSiteCreationForm(array $config): void
{
    // --- Input Processing & Validation ---
    $domain = filter_input(INPUT_POST, 'domain', FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    $username = filter_input(INPUT_POST, 'username', FILTER_VALIDATE_REGEXP, [
        'options' => ['regexp' => '/^[a-z_][a-z0-9_-]{3,15}$/']
    ]);

    if (!$domain) {
        displayCreateSiteForm("Ошибка: Неверный формат доменного имени.");
        return;
    }
    if (!$username) {
        displayCreateSiteForm("Ошибка: Неверный формат имени пользователя.");
        return;
    }

    // Отображаем страницу с результатом
    displayPageHeader('Результат создания сайта');
    try {
        echo "Starting provisioning for domain: {$domain}<br>";

        $userWebDir = "{$config['hosts_base_path']}{$username}/public_html";

        createDirectory($userWebDir);
        createIndexFile($userWebDir, $domain, $username);

        $vhostConfPath = "{$config['sites_available_path']}{$domain}.conf";
        $tempVhostPath = createVhost($config['vhost_template_path'], $config['sites_available_path'], $domain, $username);

        $provisioningScriptPath = createProvisioningScript(
            $config['provisioning_scripts_path'],
            $domain,
            $username,
            $tempVhostPath,
            $vhostConfPath,
            $config['admin_email']
        );

        // Сохраняем информацию о сайте в базу данных
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO sites (domain, username) VALUES (?, ?)");
        $stmt->execute([$domain, $username]);
        echo "Информация о сайте добавлена в базу данных.<br>";

        echo "<h2>Success!</h2>";
        echo "Site for <strong>{$domain}</strong> has been prepared.<br>";
        echo "To complete the setup, a privileged user must run the following command:<br>";
        echo "<pre><code>sudo bash {$provisioningScriptPath}</code></pre>";

    } catch (Exception $e) {
        echo "<h2>An Error Occurred:</h2>";
        echo "<p style='color:red;'>" . $e->getMessage() . "</p>";
    }
    echo '<br><a href="create_site.php">Вернуться к форме</a>';
    displayPageFooter();
}

/**
 * Creates the user's web directory.
 */
function createDirectory(string $userWebDir): void
{
    echo "Creating directory: {$userWebDir}<br>";
    if (!is_dir($userWebDir)) {
        if (!mkdir($userWebDir, 0755, true)) {
            throw new Exception("Failed to create user directory. Check permissions.");
        }
    }
}

/**
 * Creates a placeholder index file.
 */
function createIndexFile(string $userWebDir, string $domain, string $username): void
{
    $indexPath = "{$userWebDir}/index.html";
    $indexContent = "<h1>Welcome to {$domain}!</h1><p>Site created for user: {$username}</p>";
    file_put_contents($indexPath, $indexContent);
    echo "Created placeholder index.html file.<br>";
}

/**
 * Creates the Virtual Host configuration from the template.
 */
function createVhost(string $vhostTemplatePath, string $sitesAvailablePath, string $domain, string $username): string
{
    echo "Reading vhost template...<br>";
    $vhostTemplate = file_get_contents($vhostTemplatePath);
    if ($vhostTemplate === false) {
        throw new Exception("Could not read vhost template file.");
    }

    $vhostConfig = str_replace(['{DOMAIN}', '{USERNAME}'], [$domain, $username], $vhostTemplate);
    $vhostConfFile = "{$domain}.conf";
    $vhostConfPath = "{$sitesAvailablePath}{$vhostConfFile}";

    echo "Writing new Apache config to: {$vhostConfPath}<br>";
    // This will be moved by the provisioning script
    $tempVhostPath = tempnam(sys_get_temp_dir(), 'vhost');
    file_put_contents($tempVhostPath, $vhostConfig);

    return $tempVhostPath;
}

/**
 * Creates a shell script to be run by a privileged user.
 */
function createProvisioningScript(
    string $provisioningScriptsPath,
    string $domain,
    string $username,
    string $tempVhostPath,
    string $vhostConfPath,
    string $adminEmail
): string {
    if (!is_dir($provisioningScriptsPath) && !mkdir($provisioningScriptsPath, 0750, true)) {
        throw new Exception("Could not create provisioning scripts directory.");
    }

    $scriptName = "provision_{$domain}.sh";
    $scriptPath = "{$provisioningScriptsPath}{$scriptName}";
    $vhostConfFile = basename($vhostConfPath);

    $scriptContent = <<<EOT
#!/bin/bash
set -e

echo "--- Running provisioning for {$domain} ---"

# Move Apache config
mv {$tempVhostPath} {$vhostConfPath}

# Enable the site
a2ensite {$vhostConfFile}

# Reload Apache to activate the HTTP site
echo "Reloading Apache to activate HTTP site..."
systemctl reload apache2

# Obtain Let's Encrypt certificate
echo "Requesting Let's Encrypt certificate for {$domain}..."
certbot --apache -d {$domain} -d www.{$domain} --non-interactive --agree-tos -m {$adminEmail} --redirect

# Certbot reloads Apache automatically after success
systemctl reload apache2

echo "--- Provisioning for {$domain} complete ---"
EOT;

    file_put_contents($scriptPath, $scriptContent);
    chmod($scriptPath, 0750);

    return $scriptPath;
}

/**
 * Displays the HTML form to create a new site.
 */
function displayCreateSiteForm(string $error = ''): void
{
    displayPageHeader('Создать новый сайт');
    ?>
    <h1>Создать новый сайт</h1>
    <?php if ($error): ?>
        <p style="color: #d93025;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form action="create_site.php?action=create" method="POST">
        <div>
            <label for="domain">Доменное имя:</label>
            <input type="text" id="domain" name="domain" placeholder="e.g., my-new-project.com" required>
        </div>
        <div>
            <label for="username">Системное имя пользователя:</label>
            <input type="text" id="username" name="username" placeholder="e.g., project_user" required pattern="^[a-z_][a-z0-9_-]{3,15}$">
        </div>
        <button type="submit">Создать сайт</button>
    </form>
    <?php
    displayPageFooter();
}

function displayPageHeader(string $title): void
{
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> - Панель управления</title>
        <style>
            body { font-family: sans-serif; line-height: 1.6; margin: 0; background-color: #f4f4f4; color: #333; }
            .main-container { max-inline-size: 800px; margin: auto; background: #fff; padding: 2em; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); min-block-size: 300px; margin-block-start: 2em; }
            header { background-color: #333; color: white; padding: 1em 2em; display: flex; justify-content: space-between; align-items: center; }
            header a { color: white; text-decoration: none; }
            h1 { color: #333; }
            label { display: block; margin-block-end: 0.5em; font-weight: bold; }
            input[type="text"] { inline-size: 100%; padding: 8px; margin-block-end: 1em; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
            button { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
            button:hover { background-color: #0056b3; }
        </style>
    </head>
    <body>
    <header>
        <span>Панель управления хостингом</span>
        <div>
            <a href="create_site.php" style="margin-inline-end: 15px;">Создать сайт</a>
            <a href="delete_site.php" style="margin-inline-end: 15px;">Удалить сайт</a>
            <a href="create_site.php?action=logout">Выйти (<?= htmlspecialchars($_SESSION['username'] ?? '') ?>)</a>
        </div>
    </header>
    <main class="main-container">
    <?php
}

function displayPageFooter(): void
{
    ?>
    </main>
    </body>
    </html>
    <?php
}

?>

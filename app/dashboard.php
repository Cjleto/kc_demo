<?php
session_start();
if (empty($_SESSION['kc_user'])) {
    header('Location: index.php');
    exit;
}
$user = $_SESSION['kc_user'];
$roles = array_filter($user['roles'], fn($r) => !in_array($r, ['offline_access', 'uma_authorization']));
?>
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Dashboard - Demo OIDC</title></head>
<body>
<h2>Benvenuto, <?= htmlspecialchars($user['name'] ?: $user['username']) ?></h2>

<table border="1" cellpadding="6">
  <tr><th>Username</th><td><?= htmlspecialchars($user['username']) ?></td></tr>
  <tr><th>Nome</th><td><?= htmlspecialchars($user['name']) ?></td></tr>
  <tr><th>Email</th><td><?= htmlspecialchars($user['email']) ?></td></tr>
  <tr><th>Ruoli</th><td><?= htmlspecialchars(implode(', ', $roles)) ?></td></tr>
  <tr><th>Token scade</th><td><?= date('H:i:s', $user['exp']) ?></td></tr>
</table>

<br>
<a href="logout.php"><button>Esci</button></a>
</body>
</html>

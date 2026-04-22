<?php
session_start();
if (!empty($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Demo OIDC - Fonarcom</title></head>
<body>
<h2>Demo OIDC - Fonarcom</h2>
<p>Questa demo mostra il flusso Authorization Code Flow con Keycloak.</p>
<a href="login.php"><button>Accedi con Keycloak</button></a>
</body>
</html>

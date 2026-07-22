<?php
/** Cierre de sesión · SIGA-REPORTER */
require __DIR__ . '/Auth.php';
(new Auth())->logout();
header('Location: login.php');
exit;
<?php
require_once 'chat.php'; // ou Mailer.php si séparé

$mailer = new Mailer($mailCfg);

$ok = $mailer->send('teesamig@gmail.com', 'Test SMTP', 'Bonjour !');
if ($ok) {
    echo "Mail envoyé ✅";
} else {
    echo "Erreur : " . $mailer->getLastError();
}

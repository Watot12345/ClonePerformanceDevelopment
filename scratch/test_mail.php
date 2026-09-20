<?php
require_once __DIR__ . '/../mailer.php';

$res = sendMail('hresources771@gmail.com', 'Test Email Connection', '<h3>SMTP is working properly!</h3>');
echo json_encode($res, JSON_PRETTY_PRINT);

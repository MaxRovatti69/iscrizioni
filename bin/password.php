<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDOUT, "Password (almeno 12 caratteri; non verrà mostrata): ");
$hidden = PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec') && stream_isatty(STDIN);
if ($hidden) shell_exec('stty -echo');
try { $password = rtrim(fgets(STDIN) ?: '', "\r\n"); }
finally { if ($hidden) shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
if (strlen($password) < 12) { fwrite(STDERR, "Password troppo corta.\n"); exit(1); }
echo password_hash($password, PASSWORD_DEFAULT), "\n";

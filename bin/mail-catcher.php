<?php

/**
 * The mail catcher of the local site. Without env.php, config.php hands
 * every mail of Kirby to this script in place of sendmail, for example the
 * login code of the Panel. The script saves the mail as <time>.eml in the
 * given folder and prints its subject and text in the terminal of make
 * dev. With MAIL_CATCHER_QUIET=1 it prints nothing (the tests set it).
 *
 * Usage: php bin/mail-catcher.php <folder> [sendmail options]
 */

$dir = $argv[1] ?? '';
if ($dir === '' || (!is_dir($dir) && !mkdir($dir, 0777, true))) {
    fwrite(STDERR, "mail-catcher: cannot write to the folder '$dir'\n");
    exit(1);
}
$mail = (string) stream_get_contents(STDIN);
$file = sprintf('%s/%s-%s.eml', $dir, date('Ymd-His'), bin2hex(random_bytes(3)));
if (file_put_contents($file, $mail) === false) {
    fwrite(STDERR, "mail-catcher: cannot write $file\n");
    exit(1);
}

if (getenv('MAIL_CATCHER_QUIET') !== '1') {
    [$head, $body] = array_pad(preg_split('/\R\R/', $mail, 2) ?: [], 2, '');
    $subject = preg_match('/^Subject: (.*)$/mi', $head, $m) ? (string) iconv_mime_decode(trim($m[1]), 0, 'UTF-8') : '';
    if (preg_match('/^Content-Transfer-Encoding: quoted-printable/mi', $head)) {
        $body = quoted_printable_decode($body);
    }
    fwrite(STDERR, "\n--- Mail: $subject\n" . trim($body) . "\n--- $file\n\n");
}

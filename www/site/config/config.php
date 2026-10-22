<?php

/**
 * The Kirby configuration for every host. A value that differs per host,
 * such as debug, url or the assistant's key, comes from env.php on the
 * server (bin/deploy.sh writes it, see env.example.php) and from .env
 * locally. Neither is in git or in the backups.
 */

// Dates in the Panel (also the default "now" of a post's date) and in the
// feed use Berlin time.
date_default_timezone_set('Europe/Berlin');

// json_encode() writes a float with the digits of serialize_precision. The
// web PHP of the host has a fixed value there that writes a map point such
// as 12.3 as 12.300000000000000710542735760100185871124267578125. -1 writes
// the shortest form that reads back as the same float.
ini_set('serialize_precision', '-1');

$env = is_file(__DIR__ . '/env.php') ? require __DIR__ . '/env.php' : [];

// Locally there is no env.php: make exports DEBUG and ANTHROPIC_API_KEY from
// .env, and make dev starts PHP with them. A value in env.php wins, so on
// the server the environment of the web server changes nothing.
$fromEnvironment = array_filter([
    'debug' => getenv('DEBUG') === false ? null : filter_var(getenv('DEBUG'), FILTER_VALIDATE_BOOL),
    'assistantKey' => getenv('ANTHROPIC_API_KEY') ?: null,
], fn($value) => $value !== null);
$env = [...$fromEnvironment, ...$env];

// Kirby puts a hash with this salt into every media URL (the thumbnails
// under /media/). The salt is in .salt, which every backup includes and git
// does not. A new salt would change every image URL.
$salt = is_file(__DIR__ . '/.salt') ? trim(file_get_contents(__DIR__ . '/.salt')) : null;

// A host with env.php but without .salt has lost its salt, and it must come
// back from a backup. Without this check, Kirby would derive a salt from the
// content path, and every media URL would change without an error.
if (is_file(__DIR__ . '/env.php') && ($salt === null || $salt === '')) {
    throw new RuntimeException('site/config/.salt is missing: restore it from a backup.');
}

// Locally there is no mail server, and Kirby would send the login code of
// the Panel from noreply@localhost, which is no valid address, so the login
// fails. Without env.php, every mail goes to bin/mail-catcher.php in place of
// sendmail. The script saves it in storage/mail/ next to www/, where the web
// server cannot reach it, and prints it in the terminal of make dev.
$local = is_file(__DIR__ . '/env.php') ? [] : [
    'auth' => ['challenge' => ['email' => ['from' => 'panel@localhost.test']]],
    'email' => [
        'beforeSend' => function (PHPMailer\PHPMailer\PHPMailer $mailer) {
            $catcher = realpath(Composer\InstalledVersions::getRootPackage()['install_path']) . '/bin/mail-catcher.php';
            $mailer->isSendmail();
            // PHPMailer runs the command through escapeshellcmd(), which keeps
            // quotes that come in pairs. The quotes keep a path with spaces
            // in one argument.
            $mailer->Sendmail = sprintf('"%s" "%s" "%s/mail"', PHP_BINARY, $catcher, Website\Blog\Storage::root());
            return $mailer;
        },
    ],
];

return array_replace_recursive([
    'locale' => 'en_US.utf-8',
    'content' => array_filter(['salt' => $salt]),
    // A single line break in Markdown does not become a <br>. The posts are
    // written for this setting.
    'markdown' => ['breaks' => false],
    // SmartyPants replaces plain punctuation with typographic characters.
    // "qDe" sets curly quotes (q), "--" as en dash and "---" as em dash (D),
    // and ellipses (e). Kirby's default makes "--" an em dash.
    'smartypants' => ['attr' => 'qDe'],
    // GD, plus conversion to sRGB for photos with another color profile
    // (Website\Blog\SrgbGd).
    'thumbs' => ['driver' => 'blog-gd'],
    // The page cache keeps the rendered HTML of each page. Kirby empties it
    // on every change in the Panel. The cache type blog-file
    // (Website\Blog\FileCache) empties it under a lock, so parallel uploads
    // do not fail. About is never cached, because its visitor numbers
    // change all day.
    'cache' => [
        'pages' => ['active' => true, 'type' => 'blog-file', 'ignore' => ['about']],
    ],
    'routes' => require __DIR__ . '/routes.php',
    // A Panel login needs the password and a code. The code comes from an
    // authenticator app (TOTP) when the account has one, and by email when
    // it has none. The account menu offers the TOTP setup only when 2fa is on.
    'auth' => ['methods' => ['password' => ['2fa' => true]]],
    'panel' => [
        'language' => 'de',
        // Each post type gets its own entry in the Panel menu, which links to
        // its parent page (for example /panel/pages/photos). The entry of the
        // site is marked as current only outside these four pages. Areas and
        // tabs have German ids, because the ids appear in Panel URLs such as
        // /panel/besucher. All other names in the code are English.
        // The view "Ausrüstung" (plugins/gear) follows the post types, because
        // it names the gear of the photos.
        'menu' => function (Kirby\Cms\App $kirby) {
            $types = array_column(Website\Blog\Types::ALL, null, 'section');
            $path = $kirby->path();
            $inType = fn(string $slug) => preg_match('!^panel/pages/' . $slug . '(\+|/|$)!', $path) === 1;
            $menu = ['site' => ['current' => fn(?string $current) => $current === 'site' && !array_filter(array_keys($types), $inType)]];
            foreach ($types as $slug => $type) {
                $menu[$slug] = ['label' => $type['menu'], 'icon' => $type['icon'], 'link' => "pages/$slug", 'current' => fn() => $inType($slug)];
            }
            return [...$menu, 'ausruestung', '-', 'besucher', 'likes', 'verlauf', 'assistent', '-', 'users', 'system'];
        },
        // The buttons at the top of a page in the Panel: Kirby's own and the
        // button of the assistant (plugins/assistant), which shows on posts
        // and About.
        'viewButtons' => [
            'page' => ['open', 'preview', '-', 'assistant', 'settings', 'languages', 'status'],
        ],
    ],
], $local, $env);

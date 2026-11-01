<?php
/** Struttura comune delle pagine */

function logo_url(): string
{
    foreach (['logo.png', 'logo.jpg', 'logo.webp', 'logo.svg'] as $f) {
        if (is_file(__DIR__ . '/../assets/img/' . $f)) {
            return 'assets/img/' . $f;
        }
    }
    return 'assets/img/logo.svg';
}

function page_head(string $title, string $width = 'narrow'): void
{
    $flash = flash();
    ?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> - <?= e(SITE_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=2">
</head>
<body>
<main class="shell <?= e($width) ?>">
<div class="panel">
<?php if ($flash): ?>
  <div class="flash <?= e($flash['type']) ?>"><?= $flash['msg'] /* HTML costruito lato server, i dati utente sono gia' escapati */ ?></div>
<?php endif;
}

function page_foot(array $scripts = ['assets/js/app.js?v=2']): void
{
    ?>
</div>
</main>
<div class="redbar"></div>
<?php foreach ($scripts as $s): ?>
<script src="<?= e($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

function brand_block(): string
{
    return '<div class="brand"><img src="' . e(logo_url()) . '" alt="ARES 118"><div><b>ARES 118 LAZIO</b><span>Unità di Emergenza</span></div></div>';
}

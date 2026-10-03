<?php
/**
 * Shared HTML shell. Expects: $title, $content, $bodyClass (optional).
 *
 * @var string $title
 * @var string $content
 * @var string $bodyClass
 */
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= \MemoryDown\Web\WebApp::h($title) ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="<?= \MemoryDown\Web\WebApp::h($bodyClass) ?>">
<?= $content ?>
<script src="/assets/app.js" defer></script>
</body>
</html>

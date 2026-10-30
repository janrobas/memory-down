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
<?php /* Marks the document as scripted before first paint, so CSS can hide the
         inline command bar in favour of the ⋯ menu without a flash. Without JS
         the class is absent and the UI degrades to the plain inline toolbar.
         A separate file, not inline, because the admin CSP is script-src 'self'. */ ?>
<script src="/assets/boot.js"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="<?= \MemoryDown\Web\WebApp::h($bodyClass) ?>">
<?= $content ?>
<script src="/assets/app.js" defer></script>
</body>
</html>

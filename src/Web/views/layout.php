<?php
/**
 * Shared HTML shell. Expects: $title, $content, and optionally $bodyClass, $lang
 * and $i18nJson (the strings app.js renders).
 *
 * @var string $title
 * @var string $content
 * @var string $bodyClass
 * @var string $lang
 * @var string $i18nJson
 */
$bodyClass = $bodyClass ?? '';
$lang = $lang ?? 'en';
$i18nJson = $i18nJson ?? '{}';
?>
<!DOCTYPE html>
<html lang="<?= \MemoryDown\Web\WebApp::h($lang) ?>">
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
<?php /* The strings app.js paints itself travel in an attribute rather than an
         inline <script>: the same strict CSP (script-src 'self') applies to a
         script block, and a data attribute needs no exception. */ ?>
<body class="<?= \MemoryDown\Web\WebApp::h($bodyClass) ?>" data-i18n="<?= $i18nJson ?>">
<?= $content ?>
<script src="/assets/app.js" defer></script>
</body>
</html>

<!doctype html>
<html lang="<?= kirby()->language()?->code() ?? 'en' ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $page->isHomePage() ? $site->title()->html() : $page->title()->html() . ' | ' . $site->title()->html() ?></title>
</head>
<body>

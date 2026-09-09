<?php snippet('header') ?>

<main>
	<h1><?= $page->title()->html() ?></h1>
	<?= $page->text()->kirbytext() ?>
</main>

<?php snippet('footer') ?>

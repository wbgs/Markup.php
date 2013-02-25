Markup.php
==========

PHP port of [Markup.js](https://github.com/adammark/Markup.js)

The test suite has also been ported, with some changes to take into account differences between PHP and JavaScript. Specifically:

- PHP has no equivalent of <code>undefined</code>
- PHP can not call methods on primitives (like strings, ints)

In general, JavaScript's behavior is maintained, for example <code>true + " " + false</code> in JS evaluates to <code>"true false"</code>, whereas in PHP it would evaluate to <code>"1 "</code>.

To use:

	<?php
	require('Mark.php');
	use Markup\Mark;
	$context = [
		'name' => [ 'first' => 'John', 'last' => 'Doe' ],
	];
	$template = "{{name.last}}, {{name.first}}";
	$result = Mark::up($template, $context);
	?>

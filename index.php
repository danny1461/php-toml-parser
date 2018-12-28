<?php

ini_set('xdebug.var_display_max_depth', '10');
ini_set('xdebug.var_display_max_data', '1024');

require __DIR__ . '/parser.php';

if (isset($_POST['toml'])) {
	$toml = $_POST['toml'];

	try {
		$tomlObj = new Toml($toml);
	}
	catch (Exception $e) {
		$exception = $e;
	}
}

if (!isset($toml)) {
	$toml = file_get_contents(__DIR__ . '/sample.toml');
}

?><<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<title>TOML Parser</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link href="https://stackpath.bootstrapcdn.com/bootstrap/4.2.1/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GJzZqFGwb1QTTN6wy59ffF1BuGJpLSa9DkKMp0DgiMDm4iYMj70gZWKYbI706tWS" crossorigin="anonymous">
	<style>
		textarea.form-control {
			height: 400px;
			font-family: "Courier New";
		}

		.line-col {
			font-size: 60%;
		}

		pre {
			font-size: 80%;
			padding: 10px;
			background-color: #ededed;
		}
	</style>
	<script src="https://code.jquery.com/jquery-2.2.4.min.js"></script>
	<script>
		$(document).on('ready', function() {
			$('textarea').on('click keyup', function() {
				var offset = this.selectionStart,
					lines = $(this).val().split("\n");
				
				var line, col, colCnt = 0;
				for (var i = 0; i < lines.length; i++) {
					colCnt += lines[i].length;

					if (colCnt >= offset) {
						line = i + 1;
						col = offset - (colCnt - lines[i].length) + 1;
						break;
					}

					colCnt++;
				}

				$('.line-col').html('Line: ' + line + ' Col: ' + col);
			});
		});
	</script>
</head>
<body>
	<div class="container">
		<div class="row">
			<div class="col">
				<h1 class="text-center">TOML Parser</h1>
				<p>Parser mostly compliant with <a href="https://github.com/toml-lang/toml#example" target="_blank">TOML</a> spec 0.5.0</p>
				<h2>TOML Input</h2>
				<form method="POST">
					<div class="form-group">
						<textarea class="form-control" name="toml"><?= htmlentities($toml) ?></textarea>
						<span class="line-col">&nbsp;</span>
					</div>
					<button type="submit" class="btn btn-primary">Submit</button>
				</form>
			</div>
		</div>

		<?php if (isset($exception)) : ?>
		<div class="row">
			<div class="col">
				<h2>Parsing Error</h2>
				<?= $exception->getMessage() ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if (isset($tomlObj)) : ?>
		<div class="row">
			<div class="col">
				<h2>Parsed Output</h2>
				<?php
				if (!ini_get('xdebug.overload_var_dump')) {
					echo '<pre>';
				}

				var_dump($tomlObj->data);

				if (!ini_get('xdebug.overload_var_dump')) {
					echo '</pre>';
				}
				?>
			</div>
		</div>
		<?php endif; ?>
	</div>
</body>
</html>

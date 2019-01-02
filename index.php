<?php

require __DIR__ . '/parser.php';

$yamlSupport = function_exists('yaml_parse');

$toml = '';
$json = '';
$yaml = '';
$tab = 'toml';

if (isset($_POST['method'])) {
	try {
		if ($_POST['method'] == 'parse-toml') {
			$toml = $_POST['toml'];
			$data = Toml::parse($toml);
		}
		elseif ($_POST['method'] == 'parse-json') {
			$tab = 'json';
			$json = $_POST['json'];
			$data = json_decode($json, true);
		}
		elseif ($_POST['method'] == 'parse-yaml') {
			$tab = 'yaml';
			$yaml = $_POST['yaml'];
			$data = yaml_parse($yaml);
		}
	}
	catch (Exception $e) {
		$exception = $e;
	}
}

if ($toml == '' && (!isset($_POST['method']) || $_POST['method'] != 'parse-toml')) {
	$toml = file_get_contents(__DIR__ . '/sample.toml');
}

?><!DOCTYPE html>
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
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.2.1/js/bootstrap.min.js"></script>
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

				$(this)
					.parents('form')
					.find('.line-col')
					.html('Line: ' + line + ' Col: ' + col);
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
				
				<ul class="nav nav-tabs">
					<li class="nav-item">
						<a class="nav-link <?= $tab == 'toml' ? 'active' : '' ?>" data-toggle="tab" href="#tab-toml">Parse TOML</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= $tab == 'json' ? 'active' : '' ?>" data-toggle="tab" href="#tab-json">Parse JSON</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= $tab == 'yaml' ? 'active' : '' ?> <?= $yamlSupport ? '' : 'disabled' ?>" data-toggle="tab" href="#tab-yaml">Parse YAML</a>
					</li>
				</ul>
			</div>
		</div>

		<div class="row">
			<div class="col">
				<div class="tab-content">
					<div class="tab-pane <?= $tab == 'toml' ? 'active' : '' ?>" id="tab-toml">
						<h2>TOML Input</h2>
						<form method="POST">
							<input type="hidden" name="method" value="parse-toml">
							<div class="form-group">
								<textarea class="form-control" name="toml"><?= htmlentities($toml) ?></textarea>
								<span class="line-col">&nbsp;</span>
							</div>
							<button type="submit" class="btn btn-primary">Submit</button>
						</form>
					</div>
					<div class="tab-pane <?= $tab == 'json' ? 'active' : '' ?>" id="tab-json">
						<h2>JSON Input</h2>
						<form method="POST">
							<input type="hidden" name="method" value="parse-json">
							<div class="form-group">
								<textarea class="form-control" name="json"><?= htmlentities($json) ?></textarea>
								<span class="line-col">&nbsp;</span>
							</div>
							<button type="submit" class="btn btn-primary">Submit</button>
						</form>
					</div>
					<?php if ($yamlSupport) : ?>
					<div class="tab-pane <?= $tab == 'yaml' ? 'active' : '' ?>" id="tab-yaml">
						<h2>YAML Input</h2>
						<form method="POST">
							<input type="hidden" name="method" value="parse-yaml">
							<div class="form-group">
								<textarea class="form-control" name="yaml"><?= htmlentities($yaml) ?></textarea>
								<span class="line-col">&nbsp;</span>
							</div>
							<button type="submit" class="btn btn-primary">Submit</button>
						</form>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="row">
			<div class="col">
				<?php if (isset($exception)) : ?>

				<h2>Parsing Error</h2>
				<?= $exception->getMessage() ?>

				<?php elseif (isset($data)) : ?>

				<h2>Parsed Output</h2>
				<?php
				if (!ini_get('xdebug.overload_var_dump')) {
					echo '<pre>';
				}
				else {
					ini_set('xdebug.var_display_max_depth', '10');
				}

				var_dump($data);

				if (!ini_get('xdebug.overload_var_dump')) {
					echo '</pre>';
				}
				?>

				<h2>Converted</h2>
				<ul class="nav nav-tabs">
					<li class="nav-item">
						<a class="nav-link active" data-toggle="tab" href="#tab2-toml">TOML</a>
					</li>
					<li class="nav-item">
						<a class="nav-link" data-toggle="tab" href="#tab2-json">JSON</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= $yamlSupport ? '' : 'disabled' ?>" data-toggle="tab" href="#tab2-yaml">YAML</a>
					</li>
				</ul>
				<div class="tab-content">
					<div class="tab-pane active" id="tab2-toml">
						<pre><?= Toml::toToml($data) ?></pre>
					</div>
					<div class="tab-pane" id="tab2-json">
						<pre><?= json_encode($data, JSON_PRETTY_PRINT) ?></pre>
					</div>
					<?php if ($yamlSupport) : ?>
					<div class="tab-pane" id="tab2-yaml">
						<pre><?= yaml_emit($data) ?></pre>
					</div>
					<?php endif; ?>
				</div>

				<?php endif; ?>
			</div>
		</div>
	</div>
</body>
</html>
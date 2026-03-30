<?php
$input = $argv[1] ?? null;
$output = $argv[2] ?? null;

if (!$input || !$output) {
    echo "Usage: php minify-js.php input.js output.min.js\n";
    exit(1);
}

$content = file_get_contents($input);
if ($content === false) {
    echo "Error reading file: $input\n";
    exit(1);
}

// Remove multi-line comments
$content = preg_replace('!/\*[\s\S]*?\*/!', '', $content);

// Remove single-line comments (but not inside strings)
$content = preg_replace('!^\s*//.*$!m', '', $content);

// Remove newlines and extra whitespace
$content = preg_replace('/\s+/', ' ', $content);

// Minimize spaces around operators (careful with < and > in templates)
$content = preg_replace('/\s*([{};:,=+\-*\/&|!?()])\s*/', '$1', $content);

// Add back space after keywords
$content = preg_replace('/(class|const|let|var|return|new|if|else|async|await|function|throw|catch|try|static|typeof|instanceof|extends)([^\s\w])/', '$1 $2', $content);

// Add space before { after class/function names
$content = preg_replace('/(\w)\{/', '$1 {', $content);

// Fix arrow functions
$content = str_replace('= >', '=>', $content);

// Trim
$content = trim($content);

file_put_contents($output, $content);
echo "Minified: " . strlen($content) . " bytes\n";

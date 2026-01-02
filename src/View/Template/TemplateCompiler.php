<?php
declare(strict_types=1);

namespace Laas\View\Template;

final class TemplateCompiler
{
    private int $loopIndex = 0;
    /** @var array<int, array{name: string, prev: string, has: string, item: string}> */
    private array $loopStack = [];

    public function extractExtends(string $source): ?string
    {
        if (preg_match('/\{\%\s*extends\s+[\'"]([^\'"]+)[\'"]\s*\%\}/', $source, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function compile(string $source): string
    {
        $this->loopIndex = 0;
        $this->loopStack = [];

        $out = '';
        $offset = 0;

        preg_match_all('/\{\%\s*(.*?)\s*\%\}/s', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $index => $match) {
            $tag = trim($match[0]);
            $pos = $matches[0][$index][1];
            $len = strlen($matches[0][$index][0]);

            $out .= substr($source, $offset, $pos - $offset);
            $offset = $pos + $len;

            $out .= $this->compileTag($tag);
        }

        $out .= substr($source, $offset);

        return $out;
    }

    private function compileTag(string $tag): string
    {
        if (preg_match('/^extends\s+[\'"]([^\'"]+)[\'"]$/', $tag)) {
            return '';
        }

        if (preg_match('/^block\s+([A-Za-z_][A-Za-z0-9_]*)$/', $tag, $matches)) {
            $name = $matches[1];
            return "<?php \$this->block('{$name}', function() use (\$ctx, \$options) { ?>";
        }

        if ($tag === 'endblock') {
            return "<?php }, \$options); ?>";
        }

        if (preg_match('/^include\s+[\'"]([^\'"]+)[\'"]$/', $tag, $matches)) {
            $name = $matches[1];
            return "<?php echo \$this->includeTemplate('{$name}', \$ctx, \$options); ?>";
        }

        if (preg_match('/^foreach\s+([A-Za-z0-9_.]+)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/', $tag, $matches)) {
            $this->loopIndex++;
            $loopId = $this->loopIndex;
            $source = $matches[1];
            $varName = $matches[2];

            $prev = '$__prev_' . $loopId;
            $has = '$__has_' . $loopId;
            $item = '$__item_' . $loopId;

            $this->loopStack[] = [
                'name' => $varName,
                'prev' => $prev,
                'has' => $has,
                'item' => $item,
            ];

            return "<?php {$prev} = \$ctx['{$varName}'] ?? null; {$has} = array_key_exists('{$varName}', \$ctx); foreach ((array) \$this->value(\$ctx, '{$source}') as {$item}): \$ctx['{$varName}'] = {$item}; ?>";
        }

        if ($tag === 'endforeach') {
            $loop = array_pop($this->loopStack);
            if ($loop === null) {
                return '';
            }

            $name = $loop['name'];
            $prev = $loop['prev'];
            $has = $loop['has'];

            return "<?php endforeach; if ({$has}) { \$ctx['{$name}'] = {$prev}; } else { unset(\$ctx['{$name}']); } ?>";
        }

        if (preg_match('/^if\s+([A-Za-z0-9_.]+)$/', $tag, $matches)) {
            $key = $matches[1];
            return "<?php if (\$this->truthy(\$this->value(\$ctx, '{$key}'))): ?>";
        }

        if ($tag === 'else') {
            return "<?php else: ?>";
        }

        if ($tag === 'endif') {
            return "<?php endif; ?>";
        }

        if (preg_match('/^raw\s+([A-Za-z0-9_.]+)$/', $tag, $matches)) {
            $key = $matches[1];
            return "<?php echo \$this->raw(\$this->value(\$ctx, '{$key}')); ?>";
        }

        if ($tag === 'csrf') {
            return "<?php echo \$this->escape(\$this->helper('csrf', null, \$ctx)); ?>";
        }

        if (preg_match('/^t\s+([\'"])([^\'"]+)\\1(.*)$/', $tag, $matches)) {
            $key = $matches[2];
            $params = $this->buildParamsArray(trim($matches[3]));
            $arg = "['key' => '{$key}', 'params' => {$params}]";
            return "<?php echo \$this->escape(\$this->helper('t', {$arg}, \$ctx)); ?>";
        }

        if (preg_match('/^(url|asset|blocks|menu)\s+[\'"]([^\'"]+)[\'"]$/', $tag, $matches)) {
            $helper = $matches[1];
            $arg = $matches[2];
            if ($helper === 'menu') {
                return "<?php echo \$this->helper('{$helper}', '{$arg}', \$ctx); ?>";
            }
            return "<?php echo \$this->escape(\$this->helper('{$helper}', '{$arg}', \$ctx)); ?>";
        }

        if (preg_match('/^[A-Za-z0-9_.]+$/', $tag)) {
            return "<?php echo \$this->escape(\$this->value(\$ctx, '{$tag}')); ?>";
        }

        return '{% ' . $tag . ' %}';
    }

    private function buildParamsArray(string $raw): string
    {
        if ($raw === '') {
            return '[]';
        }

        $pairs = [];
        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\\s*=\\s*([A-Za-z0-9_.]+)/', $raw, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = $match[1];
            $value = $match[2];
            $pairs[] = "'{$name}' => \$this->value(\$ctx, '{$value}')";
        }

        if ($pairs === []) {
            return '[]';
        }

        return '[' . implode(', ', $pairs) . ']';
    }
}

<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring\Security;

use Symfony\Component\Yaml\Yaml;

/** A bounded YAML scalar lexer. Syntax is validated by Symfony; offsets come from exact source tokens. */
final class YamlSecretInspector
{
    public function __construct(private readonly SecretPolicy $policy) {}

    /** @return array{spans:list<array{start:int,end:int}>,incomplete:list<string>} */
    public function inspect(string $source): array
    {
        Yaml::parse($source); // No PARSE_OBJECT or custom tag execution.
        $tokens = $this->tokens($source);
        $anchors = $this->anchors($tokens);
        $spans = [];
        $incomplete = [];
        $count = count($tokens);
        $flowDepth = 0;
        for ($i = 0; $i + 2 < $count; $i++) {
            $key = $tokens[$i];
            if (in_array($key['text'], ['{', '['], true)) $flowDepth++;
            elseif (in_array($key['text'], ['}', ']'], true)) $flowDepth = max(0, $flowDepth - 1);
            if ($tokens[$i + 1]['text'] !== ':' || $key['type'] !== 'scalar') continue;
            try { $name = Yaml::parse($key['text']); } catch (\Throwable) { continue; }
            if (!is_string($name) || !$this->policy->sensitive($name)) continue;
            $valueIndex = $i + 2;
            while ($flowDepth > 0 && ($tokens[$valueIndex]['type'] ?? '') === 'newline') $valueIndex++;
            $value = $tokens[$valueIndex] ?? ['type' => 'newline', 'text' => '', 'start' => 0, 'end' => 0];
            if ($value['type'] === 'newline') {
                // Empty/null keys are safe; values expressed on later indented lines need explicit review.
                $next = $tokens[$i + 3] ?? null;
                if ($next !== null && $next['type'] === 'scalar' && $this->indent($source, $next['start']) > $this->indent($source, $key['start'])) $incomplete[] = 'Sensitive YAML value starts on a following line.';
                continue;
            }
            if ($value['type'] !== 'scalar') {
                if (in_array($value['text'], ['[', '{'], true)) $incomplete[] = 'Sensitive YAML value is a collection.';
                continue;
            }
            $text = $value['text'];
            $start = $value['start'];
            $end = $value['end'];
            if (str_starts_with($text, '!')) {
                $incomplete[] = 'Sensitive YAML value uses a tag.';
                continue;
            }
            if (str_starts_with($text, '*') || str_starts_with($text, '&')) {
                $name = substr($text, 1, strspn($text, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-', 1));
                $entry = null;
                foreach ($anchors[$name] ?? [] as $candidate) {
                    if ($candidate['start'] <= $start) $entry = $candidate;
                }
                if ($entry === null || !$entry['supported']) {
                    $incomplete[] = 'Sensitive YAML value uses a complex or unresolved anchor/alias.';
                    continue;
                }
                $decoded = $entry['value'];
            } elseif ($text !== '' && ($text[0] === '|' || $text[0] === '>')) {
                try { $decoded = Yaml::parse('credential: ' . $text)['credential'] ?? null; }
                catch (\Throwable) { $incomplete[] = 'Sensitive YAML block scalar could not be decoded.'; continue; }
            } else {
                // Plain scalars can continue on an indented following line; avoid partial-value claims.
                $next = $tokens[$i + 3] ?? null;
                if ($text[0] !== '"' && $text[0] !== "'" && $next !== null && $next['type'] === 'newline') {
                    $after = $tokens[$i + 4] ?? null;
                    if ($after !== null && $after['type'] === 'scalar' && $this->indent($source, $after['start']) > $this->indent($source, $key['start'])) {
                        $incomplete[] = 'Sensitive YAML plain scalar has continuation lines.';
                        continue;
                    }
                }
                try { $decoded = Yaml::parse($text); }
                catch (\Throwable) { $incomplete[] = 'Sensitive YAML scalar could not be decoded.'; continue; }
            }
            if ($this->policy->hardcoded($decoded)) $spans[$start . ':' . $end] = ['start' => $start, 'end' => $end];
        }
        return ['spans' => array_values($spans), 'incomplete' => array_values(array_unique($incomplete))];
    }

    private function anchors(array $tokens): array
    {
        $anchors = [];
        foreach ($tokens as $index => $token) {
            $text = $token['text'];
            if ($token['type'] !== 'scalar' || !str_starts_with($text, '&')) continue;
            $nameLength = strspn($text, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-', 1);
            if ($nameLength === 0) continue;
            $name = substr($text, 1, $nameLength);
            $scalar = ltrim(substr($text, $nameLength + 1));
            $supported = $scalar !== '' && !in_array($scalar[0], ['|', '>', '*', '!', '&'], true);
            $value = null;
            if ($supported) {
                try {
                    $value = Yaml::parse('credential: ' . $text)['credential'] ?? null;
                    $supported = !is_array($value) && !is_object($value);
                } catch (\Throwable) { $supported = false; }
            }
            $anchors[$name][] = ['start' => $token['start'], 'value' => $value, 'supported' => $supported];
        }
        return $anchors;
    }

    private function indent(string $source, int $offset): int
    {
        $last = strrpos(substr($source, 0, $offset), "\n");
        return strspn(substr($source, $last === false ? 0 : $last + 1), ' ');
    }

    /** @return list<array{type:string,text:string,start:int,end:int}> */
    private function tokens(string $source): array
    {
        $result = [];
        $length = strlen($source);
        for ($i = 0; $i < $length;) {
            $c = $source[$i];
            if ($c === ' ' || $c === "\t" || $c === "\r") { $i++; continue; }
            if ($c === '#') { while ($i < $length && $source[$i] !== "\n") $i++; continue; }
            $start = $i;
            if ($c === "\n") { $result[] = ['type' => 'newline', 'text' => "\n", 'start' => $i, 'end' => ++$i]; continue; }
            if (str_contains('{}[],', $c) || ($c === ':' && ($i + 1 === $length || ctype_space($source[$i + 1]) || str_contains('{}[],\"\'', $source[$i + 1])))) {
                $result[] = ['type' => 'punctuation', 'text' => $c, 'start' => $i, 'end' => ++$i]; continue;
            }
            if ($c === '-' && isset($source[$i + 1]) && ctype_space($source[$i + 1])) { $i++; continue; }
            if (($c === '|' || $c === '>') && (($result[count($result) - 1]['text'] ?? '') === ':')) {
                $baseIndent = $this->indent($source, $start);
                $lineEnd = strpos($source, "\n", $start);
                $i = $lineEnd === false ? $length : $lineEnd + 1;
                while ($i < $length) {
                    $nextEnd = strpos($source, "\n", $i);
                    $nextEnd = $nextEnd === false ? $length : $nextEnd + 1;
                    $line = substr($source, $i, $nextEnd - $i);
                    if (trim($line) !== '' && strspn($line, ' ') <= $baseIndent) break;
                    $i = $nextEnd;
                }
                $result[] = ['type' => 'scalar', 'text' => substr($source, $start, $i - $start), 'start' => $start, 'end' => $i];
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $i++;
                while ($i < $length) {
                    if ($quote === '"' && $source[$i] === '\\') { $i += 2; continue; }
                    if ($source[$i] === $quote) {
                        if ($quote === "'" && ($source[$i + 1] ?? '') === "'") { $i += 2; continue; }
                        $i++; break;
                    }
                    $i++;
                }
            } else {
                while ($i < $length) {
                    $char = $source[$i];
                    if ($char === "\n" || $char === "\r" || str_contains('{}[],', $char)) break;
                    if (($char === ':' || $char === '#') && ($char === ':' ? ($i + 1 === $length || ctype_space($source[$i + 1]) || str_contains('{}[],\"\'', $source[$i + 1])) : ($i === $start || ctype_space($source[$i - 1])))) break;
                    $i++;
                }
            }
            if ($i === $start) { $i++; continue; }
            $text = rtrim(substr($source, $start, $i - $start), " \t");
            $result[] = ['type' => 'scalar', 'text' => $text, 'start' => $start, 'end' => $start + strlen($text)];
        }
        return $result;
    }
}

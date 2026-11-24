<?php
/**
 * Formatting for product descriptions: a small, safe slice of Markdown.
 *
 * The description box used to print exactly what was typed, so the
 * formatting people reach for by habit -- **bold**, ### headings, - lists,
 * | tables | -- came out as raw asterisks, hashes and pipes. This turns that
 * familiar subset into HTML and nothing more.
 *
 * Safe by construction: every line is escaped with e() FIRST, and only then
 * are the markers turned into tags this file writes itself. Nothing typed
 * ever reaches the page as HTML, so a <script> in a description is printed
 * as text, the same as before. Links accept only http(s):// and site paths
 * (/blasters/...), never javascript:.
 *
 * What it understands:
 *   # / ## / ### Heading      a heading (all levels render as one size: the
 *                             page already has its h1 and h2, so these are h3)
 *   **bold**  *italic*
 *   - item   * item   1. item  lists
 *   | a | b |                  a table, when the second line is |---|---|
 *   [text](https://...)        a link
 *   ---                        a dividing line
 *   a blank line               a new paragraph
 *
 * Anything else is plain text, line breaks kept, exactly as before.
 */

function rt_inline(string $text): string
{
    $s = e($text);
    // Links before emphasis so a * inside a URL is left alone.
    $s = preg_replace_callback(
        '~\[([^\]]+)\]\((https?://[^\s)]+|/[^\s)]*)\)~',
        static function (array $m): string {
            $external = str_starts_with($m[2], 'http');
            return '<a href="' . $m[2] . '"' . ($external ? ' rel="noopener nofollow" target="_blank"' : '') . '>' . $m[1] . '</a>';
        },
        $s
    );
    $s = preg_replace('~\*\*(?=\S)(.+?)(?<=\S)\*\*~u', '<strong>$1</strong>', $s);
    $s = preg_replace('~(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])~u', '<em>$1</em>', $s);
    return $s;
}

/** A Markdown table row's cells, without the outer pipes. */
function rt_cells(string $line): array
{
    $line = trim($line);
    if (str_starts_with($line, '|')) {
        $line = substr($line, 1);
    }
    if (str_ends_with($line, '|')) {
        $line = substr($line, 0, -1);
    }
    return array_map('trim', explode('|', $line));
}

function rt_is_table_rule(string $line): bool
{
    return (bool) preg_match('~^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$~', $line);
}

function richtext(string $src): string
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $src));
    $out   = [];
    $para  = [];
    $list  = null;   // 'ul' | 'ol' | null
    $items = [];

    $flush_para = static function () use (&$para, &$out): void {
        if ($para) {
            $out[] = '<p>' . implode("<br>\n", array_map('rt_inline', $para)) . '</p>';
            $para  = [];
        }
    };
    $flush_list = static function () use (&$list, &$items, &$out): void {
        if ($list) {
            $out[] = '<' . $list . '>' . implode('', array_map(
                static fn(string $i): string => '<li>' . rt_inline($i) . '</li>', $items
            )) . '</' . $list . '>';
            $list  = null;
            $items = [];
        }
    };

    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        $t    = trim($line);

        if ($t === '') {
            $flush_para();
            $flush_list();
            continue;
        }

        if (preg_match('~^#{1,6}\s+(.+?)\s*#*$~', $t, $m)) {
            $flush_para();
            $flush_list();
            $out[] = '<h3>' . rt_inline($m[1]) . '</h3>';
            continue;
        }

        if (preg_match('~^(-{3,}|\*{3,}|_{3,})$~', $t)) {
            $flush_para();
            $flush_list();
            $out[] = '<hr>';
            continue;
        }

        // A table: a pipe row followed by a |---|---| rule.
        if (str_contains($t, '|') && $i + 1 < $n && rt_is_table_rule($lines[$i + 1])) {
            $flush_para();
            $flush_list();
            $head = rt_cells($t);
            $rows = [];
            $i += 2;
            while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                $rows[] = rt_cells($lines[$i]);
                $i++;
            }
            $i--;
            $html = '<div class="rt-table"><table class="table"><thead><tr>';
            foreach ($head as $h) {
                $html .= '<th scope="col">' . rt_inline($h) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>';
                foreach (array_pad(array_slice($r, 0, count($head)), count($head), '') as $c) {
                    $html .= '<td>' . rt_inline($c) . '</td>';
                }
                $html .= '</tr>';
            }
            $out[] = $html . '</tbody></table></div>';
            continue;
        }

        if (preg_match('~^[-*+]\s+(.+)$~', $t, $m) || preg_match('~^\d{1,3}[.)]\s+(.+)$~', $t, $m2)) {
            $kind = isset($m[1]) ? 'ul' : 'ol';
            $text = isset($m[1]) ? $m[1] : $m2[1];
            $flush_para();
            if ($list !== $kind) {
                $flush_list();
                $list = $kind;
            }
            $items[] = $text;
            unset($m, $m2);
            continue;
        }

        $flush_list();
        $para[] = $t;
    }
    $flush_para();
    $flush_list();

    return implode("\n", $out);
}

/**
 * The same text with the markers taken out, for places that want plain words:
 * the search-result snippet, the share card and the structured data. Without
 * this Google would be shown "### Key Features | Brand | TG |".
 */
function richtext_plain(string $src): string
{
    $keep = [];
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $src)) as $line) {
        $t = trim($line);
        if ($t === '' || rt_is_table_rule($t) || preg_match('~^(-{3,}|\*{3,}|_{3,})$~', $t)) {
            continue;
        }
        if (str_contains($t, '|')) {
            $t = implode(', ', array_filter(rt_cells($t), 'strlen'));
        }
        $t = preg_replace('~^#{1,6}\s+|^[-*+]\s+|^\d{1,3}[.)]\s+~', '', $t);
        $t = preg_replace('~\[([^\]]+)\]\([^)]*\)~', '$1', $t);
        $keep[] = str_replace(['**', '*'], '', $t);
    }
    return trim(preg_replace('~\s+~u', ' ', strip_tags(implode(' ', $keep))));
}

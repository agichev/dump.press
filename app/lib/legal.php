<?php
declare(strict_types=1);

/**
 * Юридические документы (Политика конфиденциальности, Правила).
 *
 * Хранятся как .md в /docs и рендерятся в HTML для красивой модалки чтения,
 * а также доступны по собственному URL (/legal/<slug>) для SEO и прямых ссылок.
 */

function legalDocs(): array {
    return [
        'privacy-policy' => [
            'file'  => __DIR__ . '/../../docs/privacy-policy.md',
            'title' => 'Политика конфиденциальности',
            'slug'  => 'privacy-policy',
        ],
        'rules' => [
            'file'  => __DIR__ . '/../../docs/rules.md',
            'title' => 'Правила сервиса',
            'slug'  => 'rules',
        ],
    ];
}

function getLegalDoc(string $slug): ?array {
    $docs = legalDocs();
    if (!isset($docs[$slug])) return null;
    $doc = $docs[$slug];
    if (!is_file($doc['file'])) return null;
    $md = (string)file_get_contents($doc['file']);
    return [
        'slug'  => $doc['slug'],
        'title' => $doc['title'],
        'md'    => $md,
        'html'  => renderLegalMarkdown($md),
    ];
}

/**
 * Минимальный, безопасный рендерер Markdown для доверенных .md файлов проекта.
 * Поддерживает: заголовки, жирный/курсив, ссылки, списки, цитаты, горизонтальные
 * линии, абзацы и переносы строк.
 */
function renderLegalMarkdown(string $md): string {
    $md = str_replace("\r\n", "\n", $md);
    $lines = explode("\n", $md);
    $html = '';
    $inUl = false;
    $inOl = false;

    $closeLists = function () use (&$inUl, &$inOl, &$html) {
        if ($inUl) { $html .= "</ul>\n"; $inUl = false; }
        if ($inOl) { $html .= "</ol>\n"; $inOl = false; }
    };

    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') { $closeLists(); continue; }

        // Горизонтальная линия
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $t)) { $closeLists(); $html .= "<hr>\n"; continue; }

        // Заголовки
        if (preg_match('/^(#{1,6})\s+(.*)$/', $t, $m)) {
            $closeLists();
            $level = strlen($m[1]);
            $html .= '<h' . $level . '>' . mdInline($m[2]) . '</h' . $level . ">\n";
            continue;
        }

        // Нумерованный список
        if (preg_match('/^\d+\.\s+(.*)$/', $t, $m)) {
            if (!$inOl) { $closeLists(); $html .= "<ol>\n"; $inOl = true; }
            $html .= '<li>' . mdInline($m[1]) . "</li>\n";
            continue;
        }

        // Маркированный список
        if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
            if (!$inUl) { $closeLists(); $html .= "<ul>\n"; $inUl = true; }
            $html .= '<li>' . mdInline($m[1]) . "</li>\n";
            continue;
        }

        // Цитата
        if (preg_match('/^>\s?(.*)$/', $t, $m)) {
            $closeLists();
            $html .= '<blockquote>' . mdInline($m[1]) . "</blockquote>\n";
            continue;
        }

        // Обычный абзац
        $closeLists();
        $html .= '<p>' . mdInline($t) . "</p>\n";
    }
    $closeLists();
    return $html;
}

/** Инлайн-форматирование + экранирование. */
function mdInline(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Ссылки [текст](url)
    $text = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', function ($m) {
        return '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $text);
    // Жирный
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    // Курсив
    $text = preg_replace('/(^|[^*])\*([^*]+)\*/', '$1<em>$2</em>', $text);
    // Инлайн-код
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    return $text;
}

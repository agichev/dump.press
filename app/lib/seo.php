<?php
declare(strict_types=1);

function generateSeoKeywords(string $content): string {
    $base = ['dump', 'dump.press', 'социальная сеть', 'фото', 'контент'];

    preg_match_all('/[А-Яа-яЁёA-Za-z]{3,}/u', $content, $matches);
    $words = !empty($matches[0]) ? array_unique(array_map('mb_strtolower', $matches[0])) : [];

    $synonyms = [
        'фото' => ['фото', 'фотография', 'снимок', 'изображение', 'картинка', 'фоточка'],
        'пост' => ['пост', 'публикация', 'запись', 'сообщение', 'заметка'],
        'контент' => ['контент', 'содержание', 'наполнение', 'материал'],
        'общение' => ['общение', 'чат', 'беседа', 'разговор', 'переписка'],
        'люди' => ['люди', 'пользователи', 'народ', 'сообщество', 'аудитория'],
        'крутой' => ['крутой', 'классный', 'отличный', 'интересный', 'прикольный', 'замечательный'],
        'новый' => ['новый', 'свежий', 'актуальный', 'последний'],
        'лучший' => ['лучший', 'топ', 'популярный', 'известный', 'знаменитый'],
        'смешной' => ['смешной', 'забавный', 'прикольный', 'веселый', 'юморной'],
        'красивый' => ['красивый', 'прекрасный', 'великолепный', 'шикарный', 'эффектный'],
        'творчество' => ['творчество', 'искусство', 'креатив', 'самовыражение'],
        'жизнь' => ['жизнь', 'лайфстайл', 'быт', 'повседневность'],
        'путешествия' => ['путешествия', 'поездки', 'приключения', 'туризм'],
        'еда' => ['еда', 'кулинария', 'блюда', 'рецепты', 'готовка'],
        'природа' => ['природа', 'пейзаж', 'ландшафт', 'натура'],
        'мода' => ['мода', 'стиль', 'одежда', 'образ', 'тренды'],
        'спорт' => ['спорт', 'тренировки', 'фитнес', 'активность', 'упражнения'],
        'музыка' => ['музыка', 'треки', 'песни', 'мелодии', 'исполнители'],
        'кино' => ['кино', 'фильмы', 'сериалы', 'кинематограф'],
        'игры' => ['игры', 'гейминг', 'видеоигры', 'развлечения'],
        'отношения' => ['отношения', 'любовь', 'дружба', 'знакомства', 'романтика'],
        'юмор' => ['юмор', 'мемы', 'шутки', 'приколы', 'смешное'],
        'новости' => ['новости', 'события', 'обновления', 'свежее'],
        'топ' => ['топ', 'лучшие', 'популярное', 'рекомендуемое', 'избранное'],
        'идеи' => ['идеи', 'вдохновение', 'креатив', 'фантазия', 'задумки'],
        'история' => ['история', 'рассказ', 'опыт', 'случай', 'байка'],
        'вопрос' => ['вопрос', 'тема', 'обсуждение', 'дискуссия', 'опрос'],
        'dump' => ['dump', 'дамп', 'дамппресс'],
    ];

    $keywords = $base;
    foreach ($words as $word) {
        $keywords[] = $word;
        if (isset($synonyms[$word])) {
            $keywords = array_merge($keywords, $synonyms[$word]);
        }
    }

    $keywords = array_unique($keywords);
    return implode(', ', array_slice($keywords, 0, 40));
}

function buildJsonLd(string $type, array $data = []): string {
    $base = app_base_url();
    $json = [];

    switch ($type) {
        case 'website':
            $json = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => 'Dump',
                'url' => $base . '/',
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => $base . '/?search={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
                'description' => 'Dump — это место, где ты можешь делиться фотографиями, мыслями и находить крутой контент от других людей.',
            ];
            break;
        case 'organization':
            $json = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => 'Dump',
                'url' => $base . '/',
                'logo' => $base . '/logo.png',
                'description' => 'Социальная платформа для обмена фотографиями и мыслями.',
            ];
            break;
        case 'article':
            $json = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $data['title'] ?? 'Dump',
                'description' => $data['description'] ?? '',
                'image' => $data['image'] ?? $base . '/watchindump.png',
                'author' => ['@type' => 'Person', 'name' => $data['author'] ?? ''],
                'datePublished' => $data['date'] ?? date('c'),
                'mainEntityOfPage' => $data['url'] ?? $base . '/',
                'keywords' => $data['keywords'] ?? '',
            ];
            break;
        case 'profile':
            $json = [
                '@context' => 'https://schema.org',
                '@type' => 'ProfilePage',
                'name' => $data['title'] ?? '',
                'description' => $data['description'] ?? '',
                'image' => $data['image'] ?? $base . '/watchindump.png',
                'mainEntity' => [
                    '@type' => 'Person',
                    'name' => $data['username'] ?? '',
                    'description' => $data['description'] ?? '',
                ],
            ];
            break;
        default:
            return '';
    }

    return '<script type="application/ld+json">' . json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . '</script>';
}

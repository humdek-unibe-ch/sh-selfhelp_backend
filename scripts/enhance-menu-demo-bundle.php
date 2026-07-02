<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

/**
 * One-shot maintainer script: enrich menu-demo.bundle.json with page title/description
 * translations (en-GB + de-CH), active routes, icons on web/mobile menus (footer stays
 * icon-free), and open_access=false so ACL/group selection matters on import.
 */

$paths = [
    dirname(__DIR__, 2) . '/sh-selfhelp_frontend/examples/navigation/menu-demo.bundle.json',
    dirname(__DIR__) . '/tests/fixtures/examples/menu-demo.bundle.json',
];

$source = null;
foreach ($paths as $path) {
    if (is_file($path)) {
        $source = $path;
        break;
    }
}

if ($source === null) {
    fwrite(STDERR, "menu-demo.bundle.json not found\n");
    exit(1);
}

/** @var array<string, array{en: string, de: string, desc_en: string, desc_de: string}> $pageMeta */
$pageMeta = [
    'demo-home' => ['en' => 'Home', 'de' => 'Startseite', 'desc_en' => 'Welcome to the SelfHelp navigation demo.', 'desc_de' => 'Willkommen zur SelfHelp-Navigationsdemo.'],
    'demo-about' => ['en' => 'About us', 'de' => 'Über uns', 'desc_en' => 'Learn who we are and what we do.', 'desc_de' => 'Erfahren Sie, wer wir sind und was wir tun.'],
    'demo-services' => ['en' => 'Services', 'de' => 'Dienstleistungen', 'desc_en' => 'Explore our service offerings.', 'desc_de' => 'Entdecken Sie unser Dienstleistungsangebot.'],
    'demo-services-consulting' => ['en' => 'Consulting', 'de' => 'Beratung', 'desc_en' => 'Expert consulting for digital health projects.', 'desc_de' => 'Expertenberatung für digitale Gesundheitsprojekte.'],
    'demo-services-training' => ['en' => 'Training', 'de' => 'Schulungen', 'desc_en' => 'Workshops and training for your team.', 'desc_de' => 'Workshops und Schulungen für Ihr Team.'],
    'demo-services-support' => ['en' => 'Support', 'de' => 'Support', 'desc_en' => 'Ongoing support when you need it.', 'desc_de' => 'Laufender Support, wenn Sie ihn brauchen.'],
    'demo-products' => ['en' => 'Products', 'de' => 'Produkte', 'desc_en' => 'Browse our product catalogue.', 'desc_de' => 'Durchstöbern Sie unser Produktangebot.'],
    'demo-products-a' => ['en' => 'Product A', 'de' => 'Produkt A', 'desc_en' => 'Details for Product A.', 'desc_de' => 'Details zu Produkt A.'],
    'demo-products-b' => ['en' => 'Product B', 'de' => 'Produkt B', 'desc_en' => 'Details for Product B.', 'desc_de' => 'Details zu Produkt B.'],
    'demo-news' => ['en' => 'News', 'de' => 'Neuigkeiten', 'desc_en' => 'Latest updates and announcements.', 'desc_de' => 'Aktuelle Updates und Ankündigungen.'],
    'demo-team' => ['en' => 'Team', 'de' => 'Team', 'desc_en' => 'Meet the people behind the project.', 'desc_de' => 'Lernen Sie die Menschen hinter dem Projekt kennen.'],
    'demo-faq' => ['en' => 'FAQ', 'de' => 'FAQ', 'desc_en' => 'Frequently asked questions answered.', 'desc_de' => 'Antworten auf häufig gestellte Fragen.'],
    'demo-contact' => ['en' => 'Contact', 'de' => 'Kontakt', 'desc_en' => 'Get in touch with us.', 'desc_de' => 'Nehmen Sie Kontakt mit uns auf.'],
    'demo-legal' => ['en' => 'Legal', 'de' => 'Rechtliches', 'desc_en' => 'Legal information overview.', 'desc_de' => 'Übersicht zu rechtlichen Informationen.'],
    'demo-privacy' => ['en' => 'Privacy', 'de' => 'Datenschutz', 'desc_en' => 'How we handle your data.', 'desc_de' => 'Wie wir mit Ihren Daten umgehen.'],
    'demo-imprint' => ['en' => 'Imprint', 'de' => 'Impressum', 'desc_en' => 'Publisher and contact details.', 'desc_de' => 'Herausgeber und Kontaktangaben.'],
    'demo-resources' => ['en' => 'Resources', 'de' => 'Ressourcen', 'desc_en' => 'Guides, downloads, and references.', 'desc_de' => 'Leitfäden, Downloads und Referenzen.'],
    'demo-blog' => ['en' => 'Blog', 'de' => 'Blog', 'desc_en' => 'Articles and insights from our team.', 'desc_de' => 'Artikel und Einblicke von unserem Team.'],
    'demo-careers' => ['en' => 'Careers', 'de' => 'Karriere', 'desc_en' => 'Open roles and how to apply.', 'desc_de' => 'Offene Stellen und Bewerbung.'],
    'demo-partners' => ['en' => 'Partners', 'de' => 'Partner', 'desc_en' => 'Organisations we collaborate with.', 'desc_de' => 'Organisationen, mit denen wir zusammenarbeiten.'],
];

/** @var array<string, array{icon?: string, mobile_icon?: string}> $menuIcons */
$menuIcons = [
    // Web header: Tabler React component names (SelectIconField / IconComponent).
    'wh-home' => ['icon' => 'IconHome'],
    'wh-about' => ['icon' => 'IconInfoCircle'],
    'wh-services' => ['icon' => 'IconBriefcase'],
    'wh-consulting' => ['icon' => 'IconStethoscope'],
    'wh-training' => ['icon' => 'IconSchool'],
    'wh-support' => ['icon' => 'IconLifebuoy'],
    'wh-products' => ['icon' => 'IconPackage'],
    'wh-product-a' => ['icon' => 'IconBox'],
    'wh-product-b' => ['icon' => 'IconBox'],
    'wh-news' => ['icon' => 'IconNews'],
    'wh-contact' => ['icon' => 'IconMail'],
    // Mobile menus: lucide PascalCase names from MOBILE_ICON_SET only.
    'md-home' => ['mobile_icon' => 'House'],
    'md-services' => ['mobile_icon' => 'Briefcase'],
    'md-consulting' => ['mobile_icon' => 'Users'],
    'md-news' => ['mobile_icon' => 'Bell'],
    'md-contact' => ['mobile_icon' => 'Mail'],
    'mb-home' => ['mobile_icon' => 'House'],
    'mb-services' => ['mobile_icon' => 'Briefcase'],
    'mb-news' => ['mobile_icon' => 'Bell'],
    'mb-faq' => ['mobile_icon' => 'CircleQuestionMark'],
    'mb-contact' => ['mobile_icon' => 'Mail'],
];

$raw = file_get_contents($source);
if ($raw === false) {
    fwrite(STDERR, "Failed to read {$source}\n");
    exit(1);
}

/** @var array<string, mixed> $bundle */
$bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

$bundle['import_hints'] = [
    'default_keyword_prefix' => 'qa-demo-',
    'default_route_prefix' => '/demo',
];

$menus = $bundle['menus'] ?? null;
if (!is_array($menus)) {
    fwrite(STDERR, "Invalid bundle: missing menus\n");
    exit(1);
}

foreach ($menus as $menuKey => $menuPayload) {
    if (!is_array($menuPayload)) {
        continue;
    }
    $items = $menuPayload['items'] ?? null;
    if (!is_array($items)) {
        continue;
    }
    foreach ($items as &$item) {
        if (!is_array($item)) {
            continue;
        }
        $ref = is_string($item['ref'] ?? null) ? $item['ref'] : '';
        unset($item['icon'], $item['mobile_icon']);
        if ($menuKey === 'web_footer') {
            continue;
        }
        if (!isset($menuIcons[$ref])) {
            continue;
        }
        if ($menuKey === 'web_header' && isset($menuIcons[$ref]['icon'])) {
            $item['icon'] = $menuIcons[$ref]['icon'];
        }
        if (in_array($menuKey, ['mobile_drawer', 'mobile_bottom_tabs'], true) && isset($menuIcons[$ref]['mobile_icon'])) {
            $item['mobile_icon'] = $menuIcons[$ref]['mobile_icon'];
        }
    }
    unset($item);
    $menuPayload['items'] = $items;
    $menus[$menuKey] = $menuPayload;
}
$bundle['menus'] = $menus;

// Footer group translations de-CH
$footerDe = [
    'wf-company' => ['label' => 'Unternehmen', 'description' => 'Über unsere Organisation'],
    'wf-legal' => ['label' => 'Rechtliches'],
    'wf-resources' => ['label' => 'Ressourcen'],
];
$footer = $menus['web_footer']['items'] ?? null;
if (is_array($footer)) {
    foreach ($footer as &$item) {
        if (!is_array($item)) {
            continue;
        }
        $ref = is_string($item['ref'] ?? null) ? $item['ref'] : '';
        if (!isset($footerDe[$ref])) {
            continue;
        }
        $translations = is_array($item['translations'] ?? null) ? $item['translations'] : [];
        /** @var array<string, array<string, mixed>> $translationsByLocale */
        $translationsByLocale = [];
        foreach ($translations as $translation) {
            if (!is_array($translation)) {
                continue;
            }
            $locale = is_string($translation['locale'] ?? null) ? $translation['locale'] : '';
            if ($locale === '') {
                continue;
            }
            $translationsByLocale[$locale] = $translation;
        }
        $translationsByLocale['de-CH'] = [
            'locale' => 'de-CH',
            'label' => $footerDe[$ref]['label'],
            'description' => $footerDe[$ref]['description'] ?? null,
        ];
        $item['translations'] = array_values($translationsByLocale);
    }
    unset($item);
    $menus['web_footer']['items'] = $footer;
    $bundle['menus'] = $menus;
}

$pages = $bundle['pages'] ?? null;
if (!is_array($pages)) {
    fwrite(STDERR, "Invalid bundle: missing pages\n");
    exit(1);
}

foreach ($pages as &$page) {
    if (!is_array($page)) {
        continue;
    }
    $keyword = is_string($page['keyword'] ?? null) ? $page['keyword'] : '';
    $meta = $pageMeta[$keyword] ?? null;
    if ($meta === null) {
        continue;
    }

    $page['open_access'] = false;
    $url = is_string($page['url'] ?? null) ? $page['url'] : '/' . $keyword;
    $page['routes'] = [
        [
            'path_pattern' => $url,
            'is_canonical' => true,
            'is_active' => true,
            'priority' => 0,
        ],
    ];
    $page['fields'] = [
        [
            'name' => 'title',
            'display' => true,
            'translations' => [
                ['language_code' => 'en-GB', 'content' => $meta['en']],
                ['language_code' => 'de-CH', 'content' => $meta['de']],
            ],
        ],
        [
            'name' => 'description',
            'display' => true,
            'translations' => [
                ['language_code' => 'en-GB', 'content' => $meta['desc_en']],
                ['language_code' => 'de-CH', 'content' => $meta['desc_de']],
            ],
        ],
    ];

    $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
    foreach ($sections as &$section) {
        if (!is_array($section)) {
            continue;
        }
        $style = is_string($section['style_name'] ?? null) ? $section['style_name'] : '';
        if ($style === 'title') {
            $section['fields'] = [
                'content' => [
                    'en-GB' => ['content' => $meta['en']],
                    'de-CH' => ['content' => $meta['de']],
                ],
            ];
        }
        if ($style === 'text') {
            $section['fields'] = [
                'text' => [
                    'en-GB' => ['content' => $meta['desc_en']],
                    'de-CH' => ['content' => $meta['desc_de']],
                ],
            ];
        }
    }
    unset($section);
    $page['sections'] = $sections;
}
unset($page);
$bundle['pages'] = $pages;

$encoded = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    fwrite(STDERR, "Failed to encode bundle\n");
    exit(1);
}
$encoded .= "\n";

foreach ($paths as $path) {
    if (!is_file($path) && $path !== $source) {
        continue;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create {$dir}\n");
        exit(1);
    }
    file_put_contents($path, $encoded);
    echo "Wrote {$path}\n";
}

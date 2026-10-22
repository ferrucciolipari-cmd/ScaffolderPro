<?php
// ===================================================================
// LINGUE DELL'INTERFACCIA
// I testi sono in lang/<codice>.json (stesse chiavi per PHP e JavaScript).
// Lingua scelta in data/config.json ("language"); predefinita: inglese.
// Una chiave che manca in una lingua usa il testo inglese.
// ===================================================================

/** codice => [nome nella lingua, nome in inglese (per Claude), bandiera] */
const LANGUAGES = [
    'en' => ['English', 'English', 'gb'],
    'it' => ['Italiano', 'Italian', 'it'],
    'es' => ['Español', 'Spanish', 'es'],
    'fr' => ['Français', 'French', 'fr'],
    'de' => ['Deutsch', 'German', 'de'],
    'pt' => ['Português', 'Portuguese', 'pt'],
    'nl' => ['Nederlands', 'Dutch', 'nl'],
    'pl' => ['Polski', 'Polish', 'pl'],
    'ro' => ['Română', 'Romanian', 'ro'],
    'cs' => ['Čeština', 'Czech', 'cz'],
    'sv' => ['Svenska', 'Swedish', 'se'],
    'da' => ['Dansk', 'Danish', 'dk'],
    'fi' => ['Suomi', 'Finnish', 'fi'],
    'el' => ['Ελληνικά', 'Greek', 'gr'],
    'tr' => ['Türkçe', 'Turkish', 'tr'],
    'ru' => ['Русский', 'Russian', 'ru'],
    'uk' => ['Українська', 'Ukrainian', 'ua'],
    'hi' => ['हिन्दी', 'Hindi', 'in'],
    'zh' => ['中文', 'Chinese (Simplified)', 'cn'],
    'ja' => ['日本語', 'Japanese', 'jp'],
    'ko' => ['한국어', 'Korean', 'kr'],
];

/** Lingua attuale: letta direttamente da data/config.json (serve già prima del database e della password). */
function uiLang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;
    $cfg = @json_decode((string)@file_get_contents(__DIR__ . '/data/config.json'), true);
    $lang = (is_array($cfg) && isset($cfg['language'], LANGUAGES[$cfg['language']])) ? $cfg['language'] : 'en';
    return $lang;
}

/** Dizionario della lingua attuale completato con l'inglese. */
function i18nDict(): array {
    static $dict = null;
    if ($dict !== null) return $dict;
    $load = function (string $code): array {
        $d = @json_decode((string)@file_get_contents(__DIR__ . '/lang/' . $code . '.json'), true);
        return is_array($d) ? $d : [];
    };
    $dict = uiLang() === 'en' ? $load('en') : array_merge($load('en'), $load(uiLang()));
    return $dict;
}

/** Testo tradotto; {nome} viene sostituito con $p['nome']. */
function t(string $key, array $p = []): string {
    $s = i18nDict()[$key] ?? $key;
    if ($p) {
        $r = [];
        foreach ($p as $k => $v) $r['{' . $k . '}'] = (string)$v;
        $s = strtr($s, $r);
    }
    return $s;
}

/** Bandiera semplificata (SVG 30×20) per il pulsante utente e il selettore della lingua. */
function flagSvg(string $code): string {
    $h = function ($colors) { // fasce orizzontali uguali
        $n = count($colors); $out = '';
        foreach ($colors as $i => $c) $out .= '<rect y="' . round(20 / $n * $i, 3) . '" width="30" height="' . round(20 / $n + 0.01, 3) . '" fill="' . $c . '"/>';
        return $out;
    };
    $v = function ($colors) { // fasce verticali uguali
        $n = count($colors); $out = '';
        foreach ($colors as $i => $c) $out .= '<rect x="' . round(30 / $n * $i, 3) . '" width="' . round(30 / $n + 0.01, 3) . '" height="20" fill="' . $c . '"/>';
        return $out;
    };
    $nordic = function ($bg, $cross, $inner = null) {
        $o = '<rect width="30" height="20" fill="' . $bg . '"/><rect x="8" width="5" height="20" fill="' . $cross . '"/><rect y="7.5" width="30" height="5" fill="' . $cross . '"/>';
        if ($inner) $o .= '<rect x="9.25" width="2.5" height="20" fill="' . $inner . '"/><rect y="8.75" width="30" height="2.5" fill="' . $inner . '"/>';
        return $o;
    };
    $star = function ($cx, $cy, $r, $fill) {
        $pts = [];
        for ($i = 0; $i < 10; $i++) {
            $a = -M_PI / 2 + $i * M_PI / 5;
            $rr = $i % 2 ? $r * 0.4 : $r;
            $pts[] = round($cx + cos($a) * $rr, 2) . ',' . round($cy + sin($a) * $rr, 2);
        }
        return '<polygon points="' . implode(' ', $pts) . '" fill="' . $fill . '"/>';
    };
    switch ($code) {
        case 'gb': $s = '<rect width="30" height="20" fill="#012169"/><path d="M0 0l30 20M30 0L0 20" stroke="#fff" stroke-width="4"/><path d="M0 0l30 20M30 0L0 20" stroke="#C8102E" stroke-width="1.6"/><path d="M15 0v20M0 10h30" stroke="#fff" stroke-width="6"/><path d="M15 0v20M0 10h30" stroke="#C8102E" stroke-width="3.6"/>'; break;
        case 'it': $s = $v(['#009246', '#fff', '#CE2B37']); break;
        case 'es': $s = '<rect width="30" height="20" fill="#AA151B"/><rect y="5" width="30" height="10" fill="#F1BF00"/>'; break;
        case 'fr': $s = $v(['#0055A4', '#fff', '#EF4135']); break;
        case 'de': $s = $h(['#000', '#DD0000', '#FFCE00']); break;
        case 'pt': $s = '<rect width="30" height="20" fill="#FF0000"/><rect width="12" height="20" fill="#006600"/><circle cx="12" cy="10" r="3.6" fill="#FFE000"/><circle cx="12" cy="10" r="2.2" fill="#FF0000"/>'; break;
        case 'nl': $s = $h(['#AE1C28', '#fff', '#21468B']); break;
        case 'pl': $s = $h(['#fff', '#DC143C']); break;
        case 'ro': $s = $v(['#002B7F', '#FCD116', '#CE1126']); break;
        case 'cz': $s = $h(['#fff', '#D7141A']) . '<path d="M0 0l15 10L0 20z" fill="#11457E"/>'; break;
        case 'se': $s = $nordic('#006AA7', '#FECC00'); break;
        case 'dk': $s = $nordic('#C8102E', '#fff'); break;
        case 'fi': $s = $nordic('#fff', '#002F6C'); break;
        case 'gr':
            $s = '';
            for ($i = 0; $i < 9; $i++) $s .= '<rect y="' . round($i * 20 / 9, 3) . '" width="30" height="2.23" fill="' . ($i % 2 ? '#fff' : '#0D5EAF') . '"/>';
            $s .= '<rect width="11.1" height="11.1" fill="#0D5EAF"/><rect x="4.45" width="2.2" height="11.1" fill="#fff"/><rect y="4.45" width="11.1" height="2.2" fill="#fff"/>';
            break;
        case 'tr': $s = '<rect width="30" height="20" fill="#E30A17"/><circle cx="11" cy="10" r="5" fill="#fff"/><circle cx="12.3" cy="10" r="4" fill="#E30A17"/>' . $star(18, 10, 2.6, '#fff'); break;
        case 'ru': $s = $h(['#fff', '#0039A6', '#D52B1E']); break;
        case 'ua': $s = $h(['#0057B7', '#FFD700']); break;
        case 'in': $s = $h(['#FF9933', '#fff', '#138808']) . '<circle cx="15" cy="10" r="2.6" fill="none" stroke="#000080" stroke-width="0.8"/>'; break;
        case 'cn': $s = '<rect width="30" height="20" fill="#DE2910"/>' . $star(6, 6, 3.4, '#FFDE00') . $star(11, 2.4, 1, '#FFDE00') . $star(13, 4.6, 1, '#FFDE00') . $star(13, 7.6, 1, '#FFDE00') . $star(11, 9.6, 1, '#FFDE00'); break;
        case 'jp': $s = '<rect width="30" height="20" fill="#fff"/><circle cx="15" cy="10" r="6" fill="#BC002D"/>'; break;
        case 'kr': $s = '<rect width="30" height="20" fill="#fff"/><path d="M9.8 10a5.2 5.2 0 0 1 10.4 0z" fill="#CD2E3A"/><path d="M9.8 10a5.2 5.2 0 0 0 10.4 0z" fill="#0047A0"/><circle cx="12.4" cy="10" r="2.6" fill="#CD2E3A"/><circle cx="17.6" cy="10" r="2.6" fill="#0047A0"/>'
                     . '<path d="M3 4.5l3-2M3.6 5.4l3-2M4.2 6.3l3-2M23 15.5l3-2M23.6 16.4l3-2M24.2 17.3l3-2M23 4.5l3 2M24.2 3.6l3 2M3 15.5l3 2M4.2 14.6l3 2" stroke="#000" stroke-width="0.6"/>'; break;
        default: $s = '<rect width="30" height="20" fill="#888"/>';
    }
    return '<svg class="flag" viewBox="0 0 30 20" aria-hidden="true">' . $s . '</svg>';
}

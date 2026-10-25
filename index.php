<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/core.php';

$csrf   = csrfToken();
$config = publicConfig();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
/** Testo tradotto, già pronto per l'HTML. */
function tx(string $key, array $p = []): string { return h(t($key, $p)); }
/** Testo tradotto che contiene HTML (i file in lang/ sono parte del codice, non input degli utenti). */
function th(string $key, array $p = []): string { return t($key, $p); }

/** Icona SVG dallo sprite in fondo alla pagina. */
function ic(string $name): string { return '<svg class="ic" aria-hidden="true"><use href="#i-' . $name . '"/></svg>'; }
?>
<!DOCTYPE html>
<html lang="<?= h(uiLang()) ?>" data-theme="<?= h($config['theme']) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Scaffolder Pro</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg?v=<?= filemtime(__DIR__ . '/favicon.svg') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>

<!-- Sprite delle icone (tratto 1.75, stile lineare) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <defs>
        <symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 2 4 14h8l-1 8 9-12h-8l1-8z"/></symbol>
        <symbol id="i-archive" viewBox="0 0 24 24"><rect x="2.5" y="3.5" width="19" height="5" rx="1"/><path d="M4.5 8.5V19a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8.5"/><path d="M10 12.5h4"/></symbol>
        <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
        <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2.5H6.5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8z"/><path d="M14 2.5V8h5.5"/></symbol>
        <symbol id="i-file-plus" viewBox="0 0 24 24"><path d="M14 2.5H6.5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8z"/><path d="M14 2.5V8h5.5"/><path d="M12 11.5v6M9 14.5h6"/></symbol>
        <symbol id="i-folder" viewBox="0 0 24 24"><path d="M20 19.5a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9l-.8-1.2a2 2 0 0 0-1.7-.9H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2z"/></symbol>
        <symbol id="i-folder-plus" viewBox="0 0 24 24"><path d="M20 19.5a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9l-.8-1.2a2 2 0 0 0-1.7-.9H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2z"/><path d="M12 10v6M9 13h6"/></symbol>
        <symbol id="i-upload" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></symbol>
        <symbol id="i-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></symbol>
        <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/></symbol>
        <symbol id="i-collapse" viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="17" height="17" rx="2"/><path d="M8 12h8"/></symbol>
        <symbol id="i-pen" viewBox="0 0 24 24"><path d="M17 3.5a2.1 2.1 0 0 1 3 3L8 18.5l-4 1 1-4z"/><path d="m15 5.5 3 3"/></symbol>
        <symbol id="i-external" viewBox="0 0 24 24"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></symbol>
        <symbol id="i-split" viewBox="0 0 24 24"><rect x="3" y="3.5" width="18" height="17" rx="2"/><path d="M12 3.5v17"/></symbol>
        <symbol id="i-save" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></symbol>
        <symbol id="i-trash" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6M14 11v6"/></symbol>
        <symbol id="i-history" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3.5 2"/></symbol>
        <symbol id="i-sparkles" viewBox="0 0 24 24"><path d="M11 3.5 12.8 8.2 17.5 10l-4.7 1.8L11 16.5l-1.8-4.7L4.5 10l4.7-1.8z"/><path d="M18.5 15.5l.8 1.7 1.7.8-1.7.8-.8 1.7-.8-1.7-1.7-.8 1.7-.8z"/></symbol>
        <symbol id="i-maximize" viewBox="0 0 24 24"><path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="m21 3-7 7"/><path d="m3 21 7-7"/></symbol>
        <symbol id="i-send" viewBox="0 0 24 24"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/></symbol>
        <symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="12.5" height="12.5" rx="2"/><path d="M5 15H4.5a2 2 0 0 1-2-2V4.5a2 2 0 0 1 2-2H13a2 2 0 0 1 2 2V5"/></symbol>
        <symbol id="i-undo" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 2.6-6.4L3 8"/><path d="M3 3v5h5"/></symbol>
        <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
        <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
        <symbol id="i-minus" viewBox="0 0 24 24"><path d="M5 12h14"/></symbol>
        <symbol id="i-chevron" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></symbol>
        <symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></symbol>
        <symbol id="i-unzip" viewBox="0 0 24 24"><rect x="2.5" y="3.5" width="19" height="5" rx="1"/><path d="M4.5 8.5V19a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8.5"/><path d="m9.5 15 2.5-2.5 2.5 2.5"/><path d="M12 12.5V18"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></symbol>
        <symbol id="i-api" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19"/><path d="M12 2.5a14.5 14.5 0 0 1 0 19 14.5 14.5 0 0 1 0-19z"/></symbol>
        <symbol id="i-play" viewBox="0 0 24 24"><path d="M7 4.5v15l12-7.5z"/></symbol>
        <symbol id="i-terminal" viewBox="0 0 24 24"><path d="m4 17 6-5-6-5"/><path d="M12 19h8"/></symbol>
        <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6.5h16M4 12h16M4 17.5h16"/></symbol>
        <symbol id="i-up" viewBox="0 0 24 24"><path d="m6 15 6-6 6 6"/></symbol>
        <symbol id="i-down" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
        <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
        <symbol id="i-replace" viewBox="0 0 24 24"><path d="M4 7h11"/><path d="m12 4 3 3-3 3"/><path d="M20 17H9"/><path d="m12 14-3 3 3 3"/></symbol>
        <symbol id="i-bug" viewBox="0 0 24 24"><rect x="7" y="7" width="10" height="13" rx="5"/><path d="M12 11v9M9 4.5l1.5 2.5M15 4.5 13.5 7M3.5 13H7M17 13h3.5M4.5 8.5 7.5 10M19.5 8.5 16.5 10M4.5 18.5l3-1.5M19.5 18.5l-3-1.5"/></symbol>
        <symbol id="i-arrow-left" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></symbol>
        <symbol id="i-arrow-right" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></symbol>
        <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/></symbol>
        <symbol id="i-device" viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16.5V20"/></symbol>
        <symbol id="i-wrap" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M3 12h15a3 3 0 0 1 0 6h-4"/><path d="m16 16-2 2 2 2"/><path d="M3 18h7"/></symbol>
        <symbol id="i-gotoline" viewBox="0 0 24 24"><path d="M4 7h3M4 12h3M4 17h3"/><path d="M11 12h9"/><path d="m16 8 4 4-4 4"/></symbol>
        <symbol id="i-format" viewBox="0 0 24 24"><path d="M4 6h16M4 10h10M4 14h16M4 18h10"/></symbol>
        <symbol id="i-list" viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/></symbol>
        <symbol id="i-minimap" viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="17" height="17" rx="2"/><path d="M14.5 3.5v17"/><path d="M6.5 8h5M6.5 11h4M6.5 14h5"/></symbol>
        <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></symbol>
        <symbol id="i-pause" viewBox="0 0 24 24"><path d="M8 5v14M16 5v14"/></symbol>
        <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 7h9M17 7h3"/><circle cx="15" cy="7" r="2"/><path d="M4 17h3M11 17h9"/><circle cx="9" cy="17" r="2"/></symbol>
        <symbol id="i-database" viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="8" ry="3"/><path d="M4 5.5v13c0 1.7 3.6 3 8 3s8-1.3 8-3v-13"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></symbol>
        <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></symbol>
    </defs>
</svg>

<header>
    <div class="brand"><?= ic('bolt') ?> <span class="brand-name"><span class="b-main">Scaffolder</span> <span class="b-pro">Pro</span></span></div>

    <!-- Navigazione principale: un solo pannello visibile alla volta (vedi initMainTabs in app.js) -->
    <nav class="main-tabs" id="mainTabs" role="tablist" aria-label="<?= tx('tab.nav') ?>">
        <button type="button" role="tab" id="tab-generator" class="main-tab" data-tab="generator" aria-controls="panel-generator" aria-selected="true"><?= ic('bolt') ?><span><?= tx('tab.generator') ?></span></button>
        <button type="button" role="tab" id="tab-config" class="main-tab" data-tab="config" aria-controls="panel-config" aria-selected="false" tabindex="-1"><?= ic('sliders') ?><span><?= tx('tab.config') ?></span></button>
        <button type="button" role="tab" id="tab-database" class="main-tab" data-tab="database" aria-controls="panel-database" aria-selected="false" tabindex="-1"><?= ic('database') ?><span><?= tx('tab.database') ?></span></button>
    </nav>

    <div class="toolbar">
        <!-- Selettore della root: cartelle dello spazio web con ricerca e permessi (sotto, un velo che lo chiude) -->
        <div class="root-overlay" id="rootOverlay" hidden></div>
        <div class="root-picker" id="rootPicker">
            <button type="button" class="root-trigger" id="rootTrigger" aria-haspopup="listbox" aria-expanded="false" title="<?= tx('hdr.root_title') ?>">
                <span class="root-label"><?= tx('hdr.root') ?></span>
                <span class="root-perm-dot" id="rootCurrentPerm"></span>
                <span class="root-current-name" id="rootCurrentLabel"><?= h(basename($rootDir) ?: $rootDir) ?></span>
                <svg class="chev"><use href="#i-chevron"/></svg>
            </button>
            <div class="root-dropdown" id="rootDropdown" hidden>
                <div class="root-search-bar">
                    <?= ic('search') ?>
                    <input type="text" id="rootSearchInput" placeholder="<?= tx('rp.search_ph') ?>" autocomplete="off" spellcheck="false">
                    <button type="button" id="rootSearchClear" class="link-btn" hidden><?= tx('common.clear') ?></button>
                    <button type="button" id="rootClose" class="icon-btn" title="<?= tx('common.close') ?> (Esc)"><?= ic('x') ?></button>
                </div>
                <div class="root-filters">
                    <div class="seg" id="rootFilterMode">
                        <button type="button" data-rf="all" class="on"><?= tx('common.all') ?></button>
                        <button type="button" data-rf="writable" title="<?= tx('rp.only_writable_title') ?>"><?= tx('rp.only_writable') ?></button>
                    </div>
                    <button type="button" class="icon-btn" id="rootReload" title="<?= tx('common.refresh') ?>"><?= ic('refresh') ?></button>
                    <span class="spacer"></span>
                    <button type="button" class="link-btn" id="rootCustomPathBtn" title="<?= tx('js.root_other_title') ?>"><?= tx('js.root_other') ?></button>
                </div>
                <div class="root-list" id="rootList" role="listbox"></div>
                <div class="root-legend muted">
                    <span><i class="p-dot rw"></i> <?= tx('rp.writable') ?></span>
                    <span><i class="p-dot ro"></i> <?= tx('rp.readonly') ?></span>
                    <span><i class="p-dot tool"></i> <?= tx('rp.tool') ?></span>
                </div>
            </div>
        </div>

        <button type="button" class="user-btn" id="settingsBtn" title="<?= tx('hdr.settings') ?>"><?= flagSvg(LANGUAGES[uiLang()][2]) ?><?= ic('user') ?></button>
        <?php if (AUTH_ENABLED): ?>
        <button type="button" class="user-btn logout-btn" id="logoutBtn" title="<?= tx('auth.logout_title', ['user' => $authUser]) ?>"><?= ic('logout') ?><span><?= tx('auth.logout') ?></span></button>
        <?php endif; ?>
    </div>
</header>

<?php if (!AUTH_ENABLED): ?>
<div id="authBanner" class="notice error"><?= ic('alert') ?> <span><?= th('auth.banner') ?></span>
    <button type="button" class="pbtn warn" id="setupAuthBtn"><?= ic('user') ?> <?= tx('auth.setup_btn') ?></button></div>
<?php endif; ?>
<div id="permBanner" class="notice error" hidden></div>

<div class="tab-panels" id="tabPanels">
<section class="tab-panel" id="panel-generator" data-panel="generator" role="tabpanel" aria-labelledby="tab-generator">
<div class="layout" id="layout">

    <!-- ============ COLONNA SINISTRA: EXPLORER ============ -->
    <aside id="leftCol">
        <div class="card explorer" id="explorerCard">
            <div class="card-head">
                <h3><?= tx('exp.title') ?></h3>
                <div class="icon-actions">
                    <button type="button" class="icon-btn" id="searchOpen" title="<?= tx('exp.search_files') ?>"><?= ic('search') ?></button>
                    <button type="button" class="icon-btn" id="explorerRefresh" title="<?= tx('common.refresh') ?>"><?= ic('refresh') ?></button>
                    <button type="button" class="icon-btn" id="explorerMenuBtn" title="<?= tx('common.actions') ?>" aria-haspopup="menu" aria-expanded="false"><?= ic('menu') ?></button>
                </div>
            </div>

            <div class="search-box-wrap">
                <input type="text" id="treeSearchInput" class="search-input" placeholder="<?= tx('exp.filter_placeholder') ?>" autocomplete="off">
                <button type="button" id="treeSearchClear" class="search-clear" title="<?= tx('common.clear') ?>"><?= ic('x') ?></button>
            </div>

            <div class="tree-root-row drop-zone" id="treeRootRow" data-target-dir="">
                <svg class="chev open"><use href="#i-chevron"/></svg>
                <span id="rootName"></span>
                <button type="button" class="row-pen" title="<?= tx('common.options') ?>"><?= ic('pen') ?></button>
            </div>

            <div class="tree-container" id="treeContainer" tabindex="0">
                <div id="tree"></div>
                <div class="marquee" id="marquee" hidden></div>
            </div>
            <div class="tree-hint muted"><?= tx('exp.hint') ?></div>
        </div>

        <!-- Cerca nei file (⌘⇧F): prende il posto dell'Explorer -->
        <div class="card explorer search-card" id="searchCard" hidden>
            <div class="card-head">
                <h3><?= ic('search') ?> <?= tx('sf.title') ?></h3>
                <div class="icon-actions">
                    <button type="button" class="icon-btn" id="sfRefresh" title="<?= tx('sf.repeat') ?>"><?= ic('refresh') ?></button>
                    <button type="button" class="icon-btn" id="sfCollapse" title="<?= tx('sf.collapse_all') ?>"><?= ic('collapse') ?></button>
                    <button type="button" class="icon-btn" id="sfClose" title="<?= tx('sf.back') ?>"><?= ic('x') ?></button>
                </div>
            </div>
            <div class="sf-form">
                <button type="button" class="sf-repl-toggle" id="sfReplToggle" title="<?= tx('sf.toggle_replace') ?>"><svg class="chev"><use href="#i-chevron"/></svg></button>
                <div class="sf-fields">
                    <div class="sf-input">
                        <input type="text" id="sfQuery" placeholder="<?= tx('sf.search') ?>" autocomplete="off" spellcheck="false">
                        <span class="sf-opts">
                            <button type="button" data-opt="case" title="<?= tx('sf.match_case') ?>">Aa</button>
                            <button type="button" data-opt="word" title="<?= tx('sf.whole_word') ?>"><u>ab</u></button>
                            <button type="button" data-opt="regex" title="<?= tx('sf.regex') ?>">.*</button>
                        </span>
                    </div>
                    <div class="sf-input" id="sfReplRow" hidden>
                        <input type="text" id="sfReplace" placeholder="<?= tx('sf.replace_placeholder') ?>" autocomplete="off" spellcheck="false">
                        <span class="sf-opts"><button type="button" id="sfReplaceAll" title="<?= tx('sf.replace_all') ?>"><?= ic('replace') ?></button></span>
                    </div>
                </div>
            </div>
            <button type="button" class="link-btn sf-more-toggle" id="sfMoreToggle"><?= tx('sf.file_filters') ?></button>
            <div class="sf-more" id="sfMore" hidden>
                <label><?= tx('sf.include') ?> <input type="text" id="sfInclude" placeholder="<?= tx('sf.include_ph') ?>" autocomplete="off" spellcheck="false"></label>
                <label><?= tx('sf.exclude') ?> <input type="text" id="sfExclude" placeholder="<?= tx('sf.exclude_ph') ?>" autocomplete="off" spellcheck="false"></label>
                <div class="muted"><?= tx('sf.always_excluded') ?></div>
            </div>
            <div class="sf-summary" id="sfSummary"></div>
            <div class="sf-results" id="sfResults"></div>
        </div>
    </aside>

    <div class="gutter" data-gutter="left" title="<?= tx('common.drag_resize') ?>"></div>

    <!-- ============ COLONNA CENTRALE: SOLO EDITOR, A TUTTA ALTEZZA ============ -->
    <main id="centerCol">
        <div class="editor-card">
            <div class="tab-bar" id="tabBar"></div>

            <div class="editor-topbar" id="editorTopbar" hidden>
                <div class="file-title-wrap">
                    <span class="file-title" id="fileTitle"></span>
                    <span class="file-ext-badge" id="fileBadge"></span>
                    <span id="saveStatus" class="save-status"></span>
                </div>
                <div class="editor-actions">
                    <span class="nav-controls">
                        <button type="button" class="tb-btn" id="navBack" disabled><?= ic('arrow-left') ?></button>
                        <button type="button" class="tb-btn" id="navForward" disabled><?= ic('arrow-right') ?></button>
                    </span>
                    <span class="tb-sep"></span>
                    <span class="zoom-controls" id="zoomControls">
                        <button type="button" class="tb-btn" data-zoom="-1" title="<?= tx('ed.zoom_out') ?>"><?= ic('minus') ?></button>
                        <span id="zoomLabel" title="<?= tx('ed.zoom_reset') ?>">14px</span>
                        <button type="button" class="tb-btn" data-zoom="1" title="<?= tx('ed.zoom_in') ?>"><?= ic('plus') ?></button>
                        <button type="button" class="tb-btn wrap-btn" id="wrapBtn" aria-pressed="false"><?= ic('wrap') ?></button>
                    </span>
                    <span class="tb-sep"></span>
                    <button type="button" class="tb-btn save" id="saveBtn" title="<?= tx('ed.save_title') ?>"><?= ic('save') ?><span><?= tx('common.save') ?></span></button>
                    <button type="button" class="tb-btn" id="editorMenuBtn" title="<?= tx('common.more_actions') ?>" aria-haspopup="menu" aria-expanded="false"><?= ic('menu') ?></button>
                </div>
            </div>

            <div class="workspace-grid" id="workspaceGrid">
                <div class="editor-wrap" id="editorWrap" hidden>
                    <div id="editor"></div>
                    <canvas id="minimap" title="<?= tx('ed.minimap') ?>"></canvas>
                </div>
                <div id="imageView" class="image-view" hidden>
                    <img id="imageEl" alt="">
                    <div class="image-meta muted" id="imageMeta"></div>
                </div>
                <div id="welcome" class="welcome">
                    <div class="welcome-logo"><?= ic('bolt') ?></div>
                    <h2>Scaffolder Pro</h2>
                    <p class="muted" id="welcomeRoot"></p>
                    <ul class="muted">
                        <li><?= tx('welcome.open') ?></li>
                        <li><?= tx('welcome.context') ?></li>
                        <li><?= th('welcome.keys') ?></li>
                    </ul>
                </div>
                <iframe id="previewFrame" title="<?= tx('ed.preview') ?>" hidden></iframe>
                <div id="diffView" class="diff-view" hidden>
                    <div class="diff-head">
                        <span class="diff-title" id="diffTitle"></span>
                        <span class="status-pill" id="diffState"></span>
                        <span class="spacer"></span>
                        <button type="button" class="sbtn" id="diffDiscard"><?= ic('x') ?> <?= tx('prop.discard') ?></button>
                        <button type="button" class="pbtn" id="diffApply"><?= ic('check') ?> <?= tx('prop.apply') ?></button>
                    </div>
                    <div class="diff-note muted" id="diffNote"></div>
                    <div class="diff-body" id="diffBody"></div>
                </div>
                <div id="httpView" class="http-view" hidden>
                    <div class="http-bar">
                        <input type="text" id="hName" class="http-name" placeholder="<?= tx('http.name_ph') ?>" autocomplete="off">
                        <label class="check" title="<?= tx('http.ctx_title') ?>"><input type="checkbox" id="hCtx"> <?= tx('http.in_context') ?></label>
                        <button type="button" class="tb-btn danger" id="hDelete" title="<?= tx('http.delete') ?>"><?= ic('trash') ?></button>
                    </div>
                    <div class="http-line">
                        <select id="hMethod"><option>GET</option><option>POST</option><option>PUT</option><option>PATCH</option><option>DELETE</option></select>
                        <input type="text" id="hUrl" placeholder="https://api.example.com/v1/data?page=1" autocomplete="off" spellcheck="false">
                        <button type="button" class="pbtn" id="hSend" title="<?= tx('http.send_title') ?>"><?= ic('play') ?> <?= tx('common.send') ?></button>
                    </div>
                    <label class="check http-insecure"><input type="checkbox" id="hInsecure"> <?= tx('http.insecure') ?></label>
                    <details class="http-sec" open>
                        <summary><?= tx('http.auth') ?></summary>
                        <div class="hrow">
                            <select id="hAuthType">
                                <option value="none"><?= tx('http.auth_none') ?></option>
                                <option value="header"><?= tx('http.auth_header') ?></option>
                                <option value="query"><?= tx('http.auth_query') ?></option>
                                <option value="bearer">Bearer token</option>
                            </select>
                            <input type="text" id="hAuthName" placeholder="X-API-Key" autocomplete="off">
                            <input type="password" id="hAuthValue" placeholder="<?= tx('http.key') ?>" autocomplete="new-password">
                        </div>
                        <div class="muted http-hint"><span id="hAuthHint"></span> <button type="button" class="link-btn" id="hAuthClear" hidden><?= tx('http.remove_key') ?></button></div>
                    </details>
                    <details class="http-sec">
                        <summary><?= tx('http.headers') ?> (<span id="hHeadCount">0</span>)</summary>
                        <div id="hHeaders"></div>
                        <button type="button" class="sbtn" id="hAddHeader"><?= ic('plus') ?> <?= tx('http.add_header') ?></button>
                    </details>
                    <details class="http-sec" id="hBodySec">
                        <summary><?= tx('http.body') ?></summary>
                        <textarea id="hBody" class="prompt" rows="5" spellcheck="false" placeholder='{"key": "value"}'></textarea>
                    </details>
                    <div class="http-resp">
                        <div class="http-resp-head">
                            <span id="hStatus" class="status-pill">—</span>
                            <span id="hMeta" class="muted"><?= tx('http.no_response') ?></span>
                            <span class="spacer"></span>
                            <div class="seg"><button type="button" data-hview="body" class="on"><?= tx('http.resp_body') ?></button><button type="button" data-hview="headers"><?= tx('http.headers') ?></button></div>
                            <button type="button" class="icon-btn" id="hCopy" title="<?= tx('http.copy_body') ?>"><?= ic('copy') ?></button>
                            <button type="button" class="icon-btn" id="hSaveFile" title="<?= tx('http.save_file') ?>"><?= ic('save') ?></button>
                        </div>
                        <pre id="hRespBody" class="json-view"></pre>
                        <div id="hRespHeaders" class="resp-headers" hidden></div>
                    </div>
                </div>
            </div>

            <!-- Avvisi del file (errori, sintassi PHP…): in basso, sopra l'editor, senza spingere l'area di lavoro -->
            <div id="fileNotice" class="notice error" hidden></div>
        </div>
    </main>

    <div class="gutter" data-gutter="right" title="<?= tx('common.drag_resize') ?>"></div>

    <!-- ============ COLONNA DESTRA: CLAUDE, RICHIESTE API, VERSIONI, ERRORI, REGISTRO, CESTINO ============ -->
    <aside id="rightCol">
        <div class="card" id="claudeCard">
            <div class="card-head">
                <h3><?= ic('sparkles') ?> Claude AI</h3>
                <button type="button" class="icon-btn" id="claudeExpand" title="<?= tx('claude.expand') ?>"><?= ic('maximize') ?></button>
            </div>
            <div id="claudePanel" class="claude-panel">
                <div class="claude-key" id="claudeKeyRow"></div>
                <div class="hrow claude-opts">
                    <select id="claudeModel" title="<?= tx('claude.model') ?>"></select>
                    <select id="claudeEffort" title="<?= tx('claude.effort_title') ?>">
                        <option value="low">effort: low</option>
                        <option value="medium">effort: medium</option>
                        <option value="high">effort: high</option>
                        <option value="xhigh">effort: xhigh</option>
                    </select>
                </div>
                <div class="claude-ctx">
                    <div class="muted"><?= tx('claude.context') ?></div>
                    <div class="chips" id="ctxChips"></div>
                    <label class="check"><input type="checkbox" id="ctxActive" checked> <?= tx('claude.active_tab') ?></label>
                    <label class="check"><input type="checkbox" id="ctxDocs"> <?= tx('claude.include_docs') ?></label>
                </div>
                <div class="claude-chat card-scroll" id="claudeChat"></div>
                <textarea id="claudeInput" class="prompt" rows="4" placeholder="<?= tx('claude.input_ph') ?>"></textarea>
                <div class="hrow">
                    <button type="button" class="pbtn" id="claudeSend"><?= ic('send') ?> <?= tx('common.send') ?></button>
                    <button type="button" class="icon-btn" id="claudeCopy" title="<?= tx('claude.copy_prompt') ?>"><?= ic('copy') ?></button>
                    <button type="button" class="icon-btn" id="claudeReset" title="<?= tx('claude.new_chat') ?>"><?= ic('undo') ?></button>
                </div>
            </div>
        </div>

        <div class="card" id="httpCard">
            <div class="card-head">
                <h3><?= ic('api') ?> <?= tx('http.title') ?></h3>
                <div class="icon-actions">
                    <button type="button" class="icon-btn" id="httpPaste" title="<?= tx('http.paste_curl') ?>"><?= ic('terminal') ?></button>
                    <button type="button" class="icon-btn" id="httpNew" title="<?= tx('http.new') ?>"><?= ic('plus') ?></button>
                </div>
            </div>
            <div id="httpList" class="http-list card-scroll"></div>
        </div>

        <div class="card" id="versionsCard" hidden>
            <div class="card-head">
                <h3><?= ic('history') ?> <?= tx('ver.title') ?> (<span id="versionsCount">0</span>)</h3>
            </div>
            <div class="bak-list card-scroll" id="versionsList"></div>
        </div>

        <div class="card" id="errorsCard">
            <div class="card-head">
                <h3><?= ic('bug') ?> <?= tx('err.title') ?></h3>
                <div class="icon-actions">
                    <button type="button" class="icon-btn" id="errRefresh" title="<?= tx('common.refresh') ?>"><?= ic('refresh') ?></button>
                    <button type="button" class="icon-btn" id="errMenuBtn" title="<?= tx('common.more_actions') ?>" aria-haspopup="menu"><?= ic('menu') ?></button>
                </div>
            </div>
            <div class="err-tools">
                <select id="errSource" title="<?= tx('err.source') ?>"></select>
                <div class="seg" id="errFilter">
                    <button type="button" data-kind="" class="on"><?= tx('common.all') ?></button>
                    <button type="button" data-kind="fatal">Fatal</button>
                    <button type="button" data-kind="warning">Warning</button>
                    <button type="button" data-kind="notice">Notice</button>
                    <button type="button" data-kind="deprecated">Deprec.</button>
                </div>
                <label class="check"><input type="checkbox" id="errAuto"> <?= tx('err.auto') ?></label>
            </div>
            <div class="err-list card-scroll" id="errList"></div>
        </div>

        <div class="card" id="logCard">
            <div class="card-head">
                <h3><?= tx('log.title') ?></h3>
                <div class="icon-actions">
                    <button type="button" class="link-btn" id="clearLogsBtn" title="<?= tx('log.clear_title') ?>"><?= tx('common.empty') ?></button>
                    <button type="button" class="icon-btn" id="logExpand" title="<?= tx('common.fullscreen') ?>"><?= ic('maximize') ?></button>
                </div>
            </div>
            <div class="log-list card-scroll" id="logList"></div>
        </div>

        <div class="card" id="trashCard">
            <div class="card-head">
                <h3><?= ic('trash') ?> <?= tx('trash.title') ?> (<span id="trashCount">0</span>)</h3>
                <button type="button" class="link-btn" id="emptyTrashBtn"><?= tx('common.empty') ?></button>
            </div>
            <div id="trashList" class="card-scroll"></div>
        </div>
    </aside>
</div>
</section>

<!-- ============ TAB 2: CONFIGURAZIONI ============ -->
<section class="tab-panel" id="panel-config" data-panel="config" role="tabpanel" aria-labelledby="tab-config" hidden>
    <div class="config-scroll">
    <form id="configForm" class="config-form" autocomplete="off">
        <div class="config-head">
            <h2><?= ic('sliders') ?> <?= tx('env.title') ?></h2>
            <p class="muted"><?= tx('env.intro') ?></p>
        </div>
        <div class="settings-grid">
            <fieldset>
                <legend><?= ic('database') ?> <?= tx('env.db') ?></legend>
                <p class="muted"><?= tx('env.db_hint') ?></p>
                <div class="env-row">
                    <label><?= tx('env.db_host') ?> <input type="text" name="db_host" placeholder="localhost" spellcheck="false"></label>
                    <label class="env-port"><?= tx('env.db_port') ?> <input type="text" name="db_port" placeholder="3306" inputmode="numeric" spellcheck="false"></label>
                </div>
                <label><?= tx('env.db_user') ?> <input type="text" name="db_user" placeholder="root" autocomplete="off" spellcheck="false"></label>
                <label><?= tx('env.db_pass') ?> <input type="password" name="db_pass" autocomplete="new-password"></label>
                <div class="muted" data-state="db_pass"></div>
                <label class="check"><input type="checkbox" name="remove_db_pass"> <?= tx('env.remove_saved') ?></label>
                <label><?= tx('env.db_name') ?> <input type="text" name="db_name" placeholder="<?= tx('env.db_name_ph') ?>" spellcheck="false"></label>
                <div class="hrow"><button type="button" class="sbtn" id="testDbBtn"><?= ic('database') ?> <?= tx('env.db_test') ?></button><span class="muted env-result" id="testDbMsg"></span></div>
            </fieldset>

            <fieldset>
                <legend><?= ic('external') ?> <?= tx('env.github') ?></legend>
                <p class="muted"><?= tx('env.github_hint') ?></p>
                <label><?= tx('env.gh_user') ?> <input type="text" name="github_user" spellcheck="false"></label>
                <label><?= tx('env.gh_token') ?> <input type="password" name="github_token" autocomplete="new-password" placeholder="ghp_… / github_pat_…"></label>
                <div class="muted" data-state="github_token"></div>
                <label class="check"><input type="checkbox" name="remove_github_token"> <?= tx('env.remove_saved') ?></label>
                <div class="hrow"><button type="button" class="sbtn" id="testGhBtn"><?= ic('check') ?> <?= tx('env.gh_test') ?></button><span class="muted env-result" id="testGhMsg"></span></div>
            </fieldset>

            <fieldset class="wide">
                <legend><?= ic('terminal') ?> <?= tx('env.mcp') ?></legend>
                <p class="muted"><?= tx('env.mcp_hint') ?></p>
                <div id="mcpList" class="mcp-list"></div>
                <div class="hrow"><button type="button" class="sbtn" id="mcpAdd"><?= ic('plus') ?> <?= tx('env.mcp_add') ?></button></div>
            </fieldset>

            <fieldset class="wide">
                <legend><?= ic('format') ?> <?= tx('env.gen') ?></legend>
                <p class="muted"><?= tx('env.gen_hint') ?></p>
                <div class="hrow"><button type="button" class="sbtn" id="openSettingsFromCfg"><?= ic('user') ?> <?= tx('env.gen_open') ?></button></div>
            </fieldset>
        </div>
        <div class="hrow config-foot"><span class="muted" id="configMsg"></span><span class="spacer"></span><button type="submit" class="pbtn"><?= ic('save') ?> <?= tx('env.save') ?></button></div>
    </form>
    </div>
</section>

<!-- ============ TAB 3: DATABASE MANAGER (Adminer in un iframe a tutta area) ============ -->
<section class="tab-panel" id="panel-database" data-panel="database" role="tabpanel" aria-labelledby="tab-database" hidden>
    <div class="dbm-frame-wrap">
        <div class="dbm-loading muted" id="dbmLoading"><?= tx('dbm.loading') ?></div>
        <iframe id="dbmFrame" title="<?= tx('dbm.title') ?>" data-src="adminer/" referrerpolicy="same-origin"></iframe>
    </div>
</section>
</div>

<!-- "Scarica tutto (ZIP)": risponde direttamente con il file -->
<form id="downloadAllForm" method="POST" action="api.php" hidden>
    <input type="hidden" name="action" value="backup_zip">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
</form>

<!-- Velo scuro sotto ogni finestra modale aperta (un solo elemento, mostrato da initModalOverlay) -->
<div class="modal-overlay" id="modalOverlay" hidden></div>

<!-- Velo sotto i menu a comparsa (☰ dell'Explorer, tasto destro, ☰ dell'editor, errori PHP): un clic lo chiude -->
<div class="menu-overlay" id="menuOverlay" hidden></div>

<!-- Menu azioni dell'Explorer (pulsante hamburger) -->
<div id="explorerMenu" class="ctx-menu dropdown" role="menu" hidden>
    <button type="button" class="ctx-item" data-act="new-file" role="menuitem"><?= ic('file-plus') ?> <?= tx('menu.new_file') ?></button>
    <button type="button" class="ctx-item" data-act="new-folder" role="menuitem"><?= ic('folder-plus') ?> <?= tx('menu.new_folder') ?></button>
    <button type="button" class="ctx-item" data-act="upload" role="menuitem"><?= ic('upload') ?> <?= tx('menu.upload') ?></button>
    <button type="button" class="ctx-item" data-act="zip" role="menuitem"><?= ic('archive') ?> <?= tx('menu.zip_selection') ?></button>
    <button type="button" class="ctx-item" data-act="github" role="menuitem"><?= ic('external') ?> <?= tx('gh.menu') ?></button>
    <div class="ctx-sep"></div>
    <button type="button" class="ctx-item" data-act="quick-open" role="menuitem"><?= ic('search') ?> <?= tx('qo.title') ?> <kbd>⌘P</kbd></button>
    <button type="button" class="ctx-item" data-act="collapse" role="menuitem"><?= ic('collapse') ?> <?= tx('menu.collapse_all') ?></button>
</div>

<!-- Menu ☰ della barra dell'editor: azioni sul file aperto -->
<div id="editorMenu" class="ctx-menu dropdown" role="menu" hidden>
    <button type="button" class="ctx-item" data-ed="format" id="formatBtn" role="menuitem"><?= ic('format') ?><span><?= tx('ed.format') ?></span><kbd>⇧⌥F</kbd></button>
    <button type="button" class="ctx-item" data-ed="gotoline" id="gotoLineBtn" role="menuitem"><?= ic('gotoline') ?><span><?= tx('ed.goto_line') ?></span><kbd>⌃G</kbd></button>
    <button type="button" class="ctx-item" data-ed="symbols" id="symbolsBtn" role="menuitem"><?= ic('list') ?><span><?= tx('ed.symbols') ?></span><kbd>⇧⌘O</kbd></button>
    <button type="button" class="ctx-item" data-ed="minimap" id="minimapBtn" role="menuitemcheckbox" aria-checked="false"><?= ic('minimap') ?><span><?= tx('ed.minimap') ?></span><span class="ctx-check"><?= ic('check') ?></span></button>
    <div class="ctx-sep"></div>
    <button type="button" class="ctx-item" data-ed="preview" id="previewBtn" role="menuitem"><?= ic('external') ?><span><?= tx('ed.preview_window') ?></span></button>
    <button type="button" class="ctx-item" data-ed="split" id="splitBtn" role="menuitemcheckbox" aria-checked="false"><?= ic('split') ?><span><?= tx('ed.preview_split') ?></span><span class="ctx-check"><?= ic('check') ?></span></button>
    <button type="button" class="ctx-item" data-ed="download" id="downloadBtn" role="menuitem"><?= ic('download') ?><span><?= tx('ed.download_file') ?></span></button>
    <div class="ctx-sep"></div>
    <button type="button" class="ctx-item danger" data-ed="delete" id="deleteBtn" role="menuitem"><?= ic('trash') ?><span><?= tx('ed.delete_file') ?></span></button>
</div>

<!-- Menu della card Errori PHP -->
<div id="errMenu" class="ctx-menu dropdown" role="menu" hidden>
    <button type="button" class="ctx-item" data-err="clear" role="menuitem"><?= ic('trash') ?><span class="ctx-text"><?= tx('err.clear') ?><small><?= tx('err.clear_hint') ?></small></span></button>
    <button type="button" class="ctx-item" data-err="site" role="menuitem"><?= ic('bug') ?><span class="ctx-text" id="errSiteLabel"></span></button>
    <button type="button" class="ctx-item" data-err="path" role="menuitem"><?= ic('pen') ?><span class="ctx-text"><?= tx('err.other_log') ?><small><?= tx('err.other_log_hint') ?></small></span></button>
</div>

<!-- Menu tasto destro dell'Explorer -->
<div id="contextMenu" class="ctx-menu" hidden>
    <div class="ctx-header" id="ctxHeader"></div>
    <button type="button" class="ctx-item" data-ctx="new-file"><?= ic('file-plus') ?> <?= tx('menu.new_file') ?></button>
    <button type="button" class="ctx-item" data-ctx="new-folder"><?= ic('folder-plus') ?> <?= tx('menu.new_folder') ?></button>
    <button type="button" class="ctx-item" data-ctx="upload"><?= ic('upload') ?> <?= tx('menu.upload_here') ?></button>
    <button type="button" class="ctx-item" data-ctx="download-all" data-only="root"><?= ic('download') ?> <?= tx('menu.download_all') ?></button>
    <div class="ctx-sep"></div>
    <button type="button" class="ctx-item" data-ctx="preview" data-only="file"><?= ic('external') ?> <?= tx('menu.preview') ?></button>
    <button type="button" class="ctx-item" data-ctx="download" data-only="item"><?= ic('download') ?> <?= tx('menu.download') ?></button>
    <button type="button" class="ctx-item" data-ctx="zip" data-only="item"><?= ic('archive') ?> <?= tx('menu.zip') ?></button>
    <button type="button" class="ctx-item" data-ctx="unzip" data-only="zip"><?= ic('unzip') ?> <?= tx('menu.unzip') ?></button>
    <button type="button" class="ctx-item" data-ctx="claude" data-only="file"><?= ic('sparkles') ?> <?= tx('menu.claude_ctx') ?></button>
    <button type="button" class="ctx-item" data-ctx="copy-path"><?= ic('copy') ?> <?= tx('menu.copy_path') ?></button>
    <div class="ctx-sep" data-only="item"></div>
    <button type="button" class="ctx-item" data-ctx="duplicate" data-only="item"><?= ic('copy') ?> <?= tx('menu.duplicate') ?></button>
    <button type="button" class="ctx-item" data-ctx="rename" data-only="single"><?= ic('pen') ?> <?= tx('menu.rename') ?> <kbd>F2</kbd></button>
    <button type="button" class="ctx-item danger" data-ctx="delete" data-only="item"><?= ic('trash') ?> <?= tx('common.delete') ?></button>
</div>

<!-- Impostazioni generali -->
<dialog id="settingsDialog" class="modal framed">
    <form id="settingsForm" method="dialog">
        <div class="modal-head"><h3><?= ic('user') ?> <?= tx('set.title') ?></h3><button type="button" class="icon-btn" data-close title="<?= tx('common.close') ?>"><?= ic('x') ?></button></div>
        <div class="modal-body">
            <div class="settings-grid">
                <fieldset>
                    <legend><?= tx('set.profile') ?></legend>
                    <label><?= tx('set.language') ?>
                        <span class="lang-pick"><span id="langFlag"><?= flagSvg(LANGUAGES[uiLang()][2]) ?></span>
                        <select name="language"><?php foreach (LANGUAGES as $code => $l): ?><option value="<?= h($code) ?>"<?= $code === uiLang() ? ' selected' : '' ?>><?= h($l[0]) ?><?= $code !== 'en' ? ' — ' . h($l[1]) : '' ?></option><?php endforeach; ?></select></span>
                    </label>
                    <label><?= tx('set.name') ?> <input type="text" name="dev_name"></label>
                    <label><?= tx('set.environment') ?> <input type="text" name="environment"></label>
                    <label><?= tx('set.default_root') ?> <input type="text" name="default_root" placeholder="<?= h($defaultRoot) ?>"></label>
                    <div class="reset-box">
                        <button type="button" class="sbtn danger-btn" id="resetAllBtn"><?= ic('undo') ?> <?= tx('set.reset_all') ?></button>
                        <span class="muted"><?= tx('set.reset_hint') ?></span>
                    </div>
                </fieldset>
                <fieldset>
                    <legend>Claude / Anthropic</legend>
                    <label>API key <input type="password" name="api_key" autocomplete="off" placeholder="sk-ant-…"></label>
                    <div class="muted" id="apiKeyState"></div>
                    <label class="check"><input type="checkbox" name="remove_api_key"> <?= tx('set.remove_key') ?></label>
                    <label><?= tx('set.default_model') ?> <select name="claude_model"></select></label>
                    <label><?= tx('set.default_effort') ?>
                        <select name="claude_effort"><option>low</option><option>medium</option><option>high</option><option>xhigh</option><option>max</option></select>
                    </label>
                    <p class="muted"><?= th('set.key_note') ?></p>
                </fieldset>
                <fieldset>
                    <legend><?= tx('set.interface') ?></legend>
                    <label><?= tx('set.font_size') ?> <input type="number" name="editor_font" min="10" max="32"></label>
                    <label class="check"><input type="checkbox" name="editor_wrap"> <?= tx('set.wrap') ?></label>
                    <label class="check"><input type="checkbox" name="show_right"> <?= tx('set.show_right') ?></label>
                <label><?= tx('set.theme') ?>
                    <select name="theme"><option value="dark"><?= tx('set.theme_dark') ?></option><option value="light"><?= tx('set.theme_light') ?></option><option value="auto"><?= tx('set.theme_auto') ?></option></select>
                </label>
                <label class="check"><input type="checkbox" name="minimap"> <?= tx('set.minimap') ?></label>
                <label class="check"><input type="checkbox" name="format_on_save"> <?= tx('set.format_on_save') ?></label>
                <label class="check"><input type="checkbox" name="emmet"> <?= tx('set.emmet') ?></label>
                    <button type="button" class="sbtn" id="resetLayoutBtn"><?= tx('set.reset_columns') ?></button>
                </fieldset>
                <fieldset>
                    <legend><?= tx('set.php_claude') ?></legend>
                    <label class="check"><input type="checkbox" name="php_lint"> <?= tx('set.php_lint') ?></label>
                    <label class="check"><input type="checkbox" name="php_lint_live"> <?= tx('set.php_lint_live') ?></label>
                    <div class="muted" id="lintInfo"></div>
                    <label class="check"><input type="checkbox" name="claude_apply_save"> <?= tx('set.claude_apply_save') ?></label>
                    <label><?= tx('set.error_log_path') ?> <input type="text" name="error_log_path" placeholder="/var/www/vhosts/example.com/logs/error_log" autocomplete="off" spellcheck="false"></label>
                </fieldset>
                <fieldset class="wide" id="securityBox">
                    <legend><?= ic('lock') ?> <?= tx('sec.title') ?></legend>
                    <div id="secContent" class="sec-content"><div class="muted"><?= tx('rp.loading') ?></div></div>
                </fieldset>
                <fieldset>
                    <legend><?= tx('set.download_server') ?></legend>
                    <label><?= tx('set.backup_skip') ?> <input type="text" name="backup_skip"></label>
                    <label><?= tx('set.upload_max') ?> <input type="number" name="upload_max_gb" min="0.1" max="1024" step="0.1"></label>
                    <div class="limits" id="limitsBox"></div>
                </fieldset>
            </div>
        </div>
        <div class="hrow end modal-foot"><span class="muted" id="settingsMsg"></span><button type="button" class="sbtn" data-close><?= tx('common.cancel') ?></button><button type="submit" class="pbtn"><?= tx('set.save') ?></button></div>
    </form>
</dialog>

<!-- Carica file / ZIP: scelta dei file (il caricamento vero avviene nella finestra di trasferimento) -->
<dialog id="uploadDialog" class="modal small framed">
    <div class="modal-head"><h3><?= ic('upload') ?> <?= tx('up.title') ?> <span id="uploadTarget"></span></h3><button type="button" class="icon-btn" data-close title="<?= tx('common.close') ?>"><?= ic('x') ?></button></div>
    <div class="modal-body">
        <div class="limits" id="uploadLimits"></div>
        <label class="drop-area" id="dropArea">
            <input type="file" id="uploadInput" multiple hidden>
            <input type="file" id="uploadFolderInput" webkitdirectory multiple hidden>
            <?= ic('upload') ?>
            <span><?= th('up.drop') ?> · <a href="#" id="pickFolder"><?= tx('up.pick_folder') ?></a></span>
            <small class="muted" id="uploadChosen"></small>
        </label>
        <label class="check"><input type="checkbox" id="uploadExtract" checked> <?= tx('up.extract') ?></label>
        <label class="check"><input type="checkbox" id="uploadOverwrite"> <?= tx('up.overwrite') ?></label>
        <p class="muted"><?= tx('up.options_note') ?></p>
    </div>
    <div class="hrow end modal-foot"><button type="button" class="sbtn" data-close><?= tx('common.cancel') ?></button><button type="button" class="pbtn" id="uploadStart" disabled><?= tx('up.start') ?></button></div>
</dialog>

<!-- Trasferimento in corso: avanzamento ed esito di ogni elemento -->
<dialog id="transferDialog" class="modal transfer framed">
    <div class="modal-head"><h3><?= ic('upload') ?> <span id="trTitle"><?= tx('tr.uploading') ?></span></h3></div>
    <div class="modal-body">
        <div class="tr-summary"><span id="trCount"></span></div>
        <progress id="trProgress" max="100" value="0"></progress>
        <div class="muted tr-opts" id="trOpts"></div>
        <div class="tr-conflict" id="trConflict" hidden>
            <div class="tr-conflict-head"><?= ic('alert') ?><span id="trConflictText"></span></div>
            <div class="tr-conflict-list" id="trConflictList"></div>
            <div class="hrow end"><button type="button" class="sbtn" id="trConflictNo"><?= tx('tr.dont_overwrite') ?></button><button type="button" class="pbtn warn" id="trConflictYes"><?= tx('tr.overwrite') ?></button></div>
        </div>
        <div class="tr-alert" id="trAlert" hidden><?= ic('alert') ?><span id="trAlertText"></span></div>
        <div class="tr-list" id="trList"></div>
    </div>
    <div class="hrow end modal-foot">
        <button type="button" class="sbtn" id="trPauseAll" hidden><?= tx('tr.pause_all') ?></button>
        <button type="button" class="sbtn" id="trCancel"><?= tx('tr.stop') ?></button>
        <button type="button" class="pbtn" id="trAck" hidden><?= ic('check') ?> <?= tx('tr.ack') ?></button>
    </div>
</dialog>

<!-- Pubblica la cartella di lavoro su GitHub (un commit, via API) -->
<dialog id="githubDialog" class="modal small framed">
    <form id="githubForm" method="dialog">
        <div class="modal-head"><h3><?= ic('external') ?> <?= tx('gh.title') ?></h3><button type="button" class="icon-btn" data-close title="<?= tx('common.close') ?>"><?= ic('x') ?></button></div>
        <div class="modal-body">
            <p class="muted"><?= tx('gh.intro') ?></p>
            <div class="notice error" id="ghNoToken" hidden><span><?= tx('gh.no_token') ?></span> <button type="button" class="sbtn" id="ghOpenConfig"><?= tx('gh.open_config') ?></button></div>
            <div class="settings-grid one">
                <label><?= tx('gh.repo') ?> <input type="text" name="repo" required maxlength="100" spellcheck="false" autocomplete="off"></label>
                <div class="muted" id="ghOwner"></div>
                <label><?= tx('gh.visibility') ?> <select name="private"><option value="1"><?= tx('gh.private') ?></option><option value="0"><?= tx('gh.public') ?></option></select></label>
                <label><?= tx('gh.branch') ?> <input type="text" name="branch" maxlength="100" placeholder="<?= tx('gh.branch_ph') ?>" spellcheck="false" autocomplete="off"></label>
                <label><?= tx('gh.message') ?> <input type="text" name="message" maxlength="500" autocomplete="off"></label>
            </div>
            <div class="gh-summary" id="ghSummary"></div>
            <p class="muted"><?= tx('gh.warn') ?></p>
            <div class="notice ok" id="ghDone" hidden></div>
        </div>
        <div class="hrow end modal-foot"><span class="muted" id="ghMsg"></span><button type="button" class="sbtn" data-close><?= tx('common.close') ?></button><button type="submit" class="pbtn" id="ghPublish"><?= ic('upload') ?> <?= tx('gh.publish') ?></button></div>
    </form>
</dialog>

<!-- Importa una cURL incollata -->
<dialog id="curlDialog" class="modal framed">
    <div class="modal-head"><h3><?= ic('terminal') ?> <?= tx('curl.title') ?></h3><button type="button" class="icon-btn" data-close title="<?= tx('common.close') ?>"><?= ic('x') ?></button></div>
    <div class="modal-body">
        <p class="muted"><?= th('curl.help') ?></p>
        <textarea id="curlInput" class="prompt" rows="12" spellcheck="false" placeholder="curl -H 'X-Api-Key: …' 'https://api.example.com/v1/data'"></textarea>
        <div id="curlPreview" class="curl-preview" hidden></div>
        <label class="check"><input type="checkbox" id="curlRun" checked> <?= tx('curl.run_after') ?></label>
    </div>
    <div class="hrow end modal-foot"><button type="button" class="sbtn" data-close><?= tx('common.cancel') ?></button><button type="button" class="pbtn" id="curlImport" disabled><?= tx('curl.import') ?></button></div>
</dialog>

<!-- Finestra grande per Claude -->
<dialog id="claudeDialog" class="modal large">
    <div class="modal-head"><h3><?= ic('sparkles') ?> Claude AI</h3><button type="button" class="icon-btn" data-close title="<?= tx('common.close') ?>"><?= ic('x') ?></button></div>
    <div id="claudeDialogBody"></div>
</dialog>

<!-- Registro movimenti a tutto schermo -->
<dialog id="logDialog" class="modal large">
    <div class="modal-head"><h3><?= tx('log.title') ?></h3><button type="button" class="icon-btn" data-close title="<?= tx('common.close') ?>"><?= ic('x') ?></button></div>
    <div class="log-tools">
        <div class="log-search"><?= ic('search') ?><input type="text" id="logFilterText" placeholder="<?= tx('log.filter_ph') ?>" autocomplete="off"></div>
        <div class="seg" id="logFilterType">
            <button type="button" data-type="" class="on"><?= tx('common.all') ?></button>
            <button type="button" data-type="save"><?= tx('log.f_save') ?></button>
            <button type="button" data-type="create"><?= tx('log.f_create') ?></button>
            <button type="button" data-type="move"><?= tx('log.f_move') ?></button>
            <button type="button" data-type="delete"><?= tx('log.f_delete') ?></button>
            <button type="button" data-type="error"><?= tx('log.f_error') ?></button>
        </div>
        <span class="muted" id="logFilterCount"></span>
    </div>
    <div id="logDialogBody"></div>
</dialog>

<!-- Visualizzazione versione: differenza con l'editor (o il testo completo) -->
<dialog id="versionDialog" class="modal large">
    <div class="modal-head"><h3 id="versionTitle"><?= tx('ver.version') ?></h3><button type="button" class="icon-btn" data-close title="<?= tx('common.close') ?>"><?= ic('x') ?></button></div>
    <div class="ver-tools">
        <div class="seg" id="versionMode">
            <button type="button" data-vmode="diff" class="on"><?= tx('ver.mode_diff') ?></button>
            <button type="button" data-vmode="full"><?= tx('ver.mode_full') ?></button>
        </div>
        <span class="muted" id="versionLegend"></span>
        <span class="spacer"></span>
        <button type="button" class="sbtn" id="versionRestore"><?= ic('undo') ?> <?= tx('js.restore_version') ?></button>
    </div>
    <div class="diff-body ver-diff" id="versionDiff"></div>
    <pre id="versionContent" hidden></pre>
</dialog>

<!-- Vai al file (⌘P / Ctrl+P) -->
<dialog id="quickOpenDialog" class="modal small quick-open">
    <div class="qo-input"><?= ic('search') ?><input type="text" id="qoInput" placeholder="<?= tx('qo.placeholder') ?>" autocomplete="off" spellcheck="false"></div>
    <div id="qoList" class="qo-list"></div>
    <div class="qo-foot muted"><span><kbd>↑</kbd><kbd>↓</kbd> <?= tx('qo.move') ?></span><span><kbd>↵</kbd> <?= tx('qo.open') ?></span><span><kbd>Esc</kbd> <?= tx('common.close') ?></span></div>
</dialog>

<!-- Vai alla riga (⌃G): "120" oppure "120:5" (riga:colonna) -->
<dialog id="gotoLineDialog" class="modal small quick-open">
    <div class="qo-input"><?= ic('gotoline') ?><input type="text" id="gotoLineInput" inputmode="numeric" autocomplete="off" spellcheck="false"></div>
    <div class="qo-foot muted"><span id="gotoLineHint"></span><span><kbd>↵</kbd> <?= tx('qo.open') ?></span><span><kbd>Esc</kbd> <?= tx('common.close') ?></span></div>
</dialog>

<!-- Vai al simbolo (⇧⌘O / Ctrl+Shift+O): funzioni, classi, selettori, titoli del file aperto -->
<dialog id="symbolDialog" class="modal small quick-open">
    <div class="qo-input"><?= ic('list') ?><input type="text" id="symInput" placeholder="<?= tx('sym.placeholder') ?>" autocomplete="off" spellcheck="false"></div>
    <div id="symList" class="qo-list"></div>
    <div class="qo-foot muted"><span><kbd>↑</kbd><kbd>↓</kbd> <?= tx('qo.move') ?></span><span><kbd>↵</kbd> <?= tx('qo.open') ?></span><span><kbd>Esc</kbd> <?= tx('common.close') ?></span></div>
</dialog>

<!-- Primo avvio: password di accesso creata dall'interfaccia -->
<dialog id="setupAuthDialog" class="modal small framed">
    <form id="setupAuthForm" method="dialog">
        <div class="modal-head"><h3><?= ic('user') ?> <?= tx('auth.setup_title') ?></h3><button type="button" class="icon-btn" data-close title="<?= tx('common.close') ?>"><?= ic('x') ?></button></div>
        <div class="modal-body">
            <p class="muted"><?= tx('auth.setup_intro') ?></p>
            <div class="settings-grid one">
                <label><?= tx('auth.user') ?> <input type="text" name="user" value="admin" autocomplete="username" required maxlength="64"></label>
                <label><?= tx('auth.pass') ?> <input type="password" name="pass" autocomplete="new-password" required minlength="8"></label>
                <label><?= tx('auth.pass2') ?> <input type="password" name="pass2" autocomplete="new-password" required minlength="8"></label>
            </div>
            <p class="muted"><?= tx('auth.setup_note') ?></p>
        </div>
        <div class="hrow end modal-foot"><span class="muted" id="setupAuthMsg"></span><button type="button" class="sbtn" data-close><?= tx('common.cancel') ?></button><button type="submit" class="pbtn"><?= tx('auth.setup_save') ?></button></div>
    </form>
</dialog>

<div id="toasts" class="toasts"></div>

<script>
    window.APP_DATA = <?= json_encode([
        'csrf'        => $csrf,
        'rootDir'     => $rootDir,
        'defaultRoot' => $defaultRoot,
        'publicUrl'   => rootPublicUrl(),
        'config'      => $config,
        'models'      => CLAUDE_MODELS,
        'limits'      => serverLimits(),
        'php'         => ['server' => PHP_VERSION, 'cli' => phpCli()['version'] ?? null],
        'authEnabled' => AUTH_ENABLED,
        'daemonUser'  => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'daemon') : 'daemon',
        'lang'        => uiLang(),
        'i18n'        => i18nDict(),
        'flags'       => array_map(function ($l) { return flagSvg($l[2]); }, LANGUAGES),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
</script>
<!-- Librerie incluse nel progetto (lib/): nessuna dipendenza esterna -->
<script src="lib/ace/ace.js"></script>
<script src="lib/ace/ext-language_tools.js"></script>
<script src="lib/gsap.min.js"></script>
<script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
</body>
</html>

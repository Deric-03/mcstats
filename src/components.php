<?php
/**
 * Composants HTML réutilisables.
 */

const GAMEMODES = [0 => 'Survie', 1 => 'Créatif', 2 => 'Aventure', 3 => 'Spectateur'];

/** Enchantements qui n'affichent pas de niveau en jeu (niveau max 1). */
const SINGLE_LEVEL_ENCHANTS = ['mending', 'silk_touch', 'infinity', 'aqua_affinity', 'channeling', 'flame', 'multishot', 'binding_curse', 'vanishing_curse'];

function rank_badge(int $rank): string
{
    return '<span class="rank-badge ' . rank_class($rank) . '">' . $rank . '</span>';
}

/** Pastille verte "en ligne" (mise à jour ensuite en direct par le JavaScript). */
function online_dot(array $p): string
{
    $on = !empty($p['online']);
    return '<span class="online-dot' . ($on ? ' is-on' : '') . '" data-online-uuid="' . h($p['uuid']) . '"' . ($on ? ' title="En ligne"' : '') . '></span>';
}

/** "En ligne" ou "il y a 3 h". */
function seen_html(array $p, string $prefix = ''): string
{
    if (!empty($p['online'])) {
        return '<span class="text-online">En ligne</span>';
    }
    return '<span class="muted">' . h($prefix . fmt_ago($p['last_seen'])) . '</span>';
}

function head_img(string $uuid, int $size = 32, string $cls = 'head'): string
{
    return '<img class="' . h($cls) . '" src="' . h(head_url($uuid, $size * 2)) . '" width="' . $size . '" height="' . $size . '" alt="" loading="lazy">';
}

/** Tuile de statistique avec rang. */
function stat_tile(string $cat, $value, int $rank, int $total): string
{
    $c = Stats::CATEGORIES[$cat];
    $ranked = (float) $value > 0;
    if (!$ranked) {
        $rankHtml = '<span class="rank-badge">–</span><span class="muted">non classé</span>';
    } else {
        $rankText = $total >= 20 ? 'Top ' . max(1, (int) ceil($rank / $total * 100)) . NNBSP . '%' : 'sur ' . $total;
        $rankHtml = rank_badge($rank) . '<span class="muted">' . h($rankText) . '</span>';
    }
    $desc = isset($c['desc']) ? ' title="' . h($c['desc']) . '"' : '';
    return '<a class="tile' . ($rank === 1 && $ranked ? ' tile--first' : '') . '" href="leaderboard.php?cat=' . h($cat) . '"' . $desc . '>'
        . '<span class="tile__head">' . mc_icon($c['icon']) . '<span class="tile__label">' . h($c['label']) . '</span></span>'
        . '<span class="tile__value">' . h(fmt_cat($cat, $value)) . '</span>'
        . '<span class="tile__rank">' . $rankHtml . '</span>'
        . '</a>';
}

/** Petite tuile sans rang (valeur libre). */
function mini_tile(string $label, string $value, ?string $icon = null, string $hint = ''): string
{
    return '<div class="tile tile--mini"' . ($hint !== '' ? ' title="' . h($hint) . '"' : '') . '>'
        . '<span class="tile__head">' . ($icon ? mc_icon($icon) : '') . '<span class="tile__label">' . h($label) . '</span></span>'
        . '<span class="tile__value">' . h($value) . '</span></div>';
}

/**
 * Liste de barres horizontales triées (une seule teinte, valeurs lisibles).
 * @param array    $items  id => valeur
 * @param callable $name   id => libellé
 * @param callable $icon   id => identifiant d'icône
 */
function bar_list(array $items, callable $name, callable $icon, string $fmt = 'int', int $limit = 10, string $empty = 'Aucune donnée', int $cap = 60): string
{
    $items = array_filter($items, function ($v) {
        return $v > 0;
    });
    if (!$items) {
        return '<p class="muted empty-note">' . h($empty) . '</p>';
    }
    arsort($items);
    $hidden = max(0, count($items) - $cap);
    $items = array_slice($items, 0, $cap, true);
    $max = max($items);
    $row = function ($id, $v) use ($name, $icon, $fmt, $max) {
        $val = $fmt === 'cm' ? fmt_cm($v) : ($fmt === 'ticks' ? fmt_ticks($v) : fmt_int($v));
        $w = max(1.5, $v / $max * 100);
        return '<li class="bar-row"><span class="bar-row__icon">' . $icon($id) . '</span>'
            . '<span class="bar-row__name">' . h($name($id)) . '</span>'
            . '<span class="bar-row__value">' . h($val) . '</span>'
            . '<span class="bar-row__track"><span class="bar-row__fill" style="width:' . round($w, 1) . '%"></span></span></li>';
    };
    $html = '<ol class="bar-list">';
    $i = 0;
    $rest = '';
    foreach ($items as $id => $v) {
        if ($i++ < $limit) {
            $html .= $row($id, $v);
        } else {
            $rest .= $row($id, $v);
        }
    }
    $html .= '</ol>';
    if ($rest !== '') {
        $html .= '<details class="more"><summary>Afficher les ' . (count($items) - $limit) . ' suivants</summary><ol class="bar-list">' . $rest . '</ol>'
            . ($hidden ? '<p class="muted empty-note">… et ' . $hidden . ' autre' . ($hidden > 1 ? 's' : '') . ' avec des valeurs plus faibles.</p>' : '')
            . '</details>';
    }
    return $html;
}

/** Contenu HTML de l'infobulle d'un objet. */
function item_tip(array $it): string
{
    $id = $it['id'];
    $base = !empty($it['potion']) ? (Mc::potionName($id, $it['potion']) ?? Mc::itemName($id)) : Mc::itemName($id);
    $ench = ($it['ench'] ?? []) + ($it['stored'] ?? []);
    $custom = $it['name'] ?? '';
    $cls = 'tip__title' . ($custom !== '' ? ' tip__title--custom' : '') . ($ench ? ' tip__title--ench' : '');
    $html = '<div class="' . $cls . '">' . h($custom !== '' ? $custom : $base) . ($it['count'] > 1 ? ' <span class="tip__count">×' . (int) $it['count'] . '</span>' : '') . '</div>';
    if ($custom !== '') {
        $html .= '<div class="tip__line tip__muted">' . h($base) . '</div>';
    }
    foreach ($ench as $eid => $lvl) {
        $label = Mc::enchantName($eid);
        if (!in_array($eid, SINGLE_LEVEL_ENCHANTS, true) || $lvl > 1) {
            $label .= ' ' . roman((int) $lvl);
        }
        $html .= '<div class="tip__line ' . (strpos($eid, 'curse') !== false ? 'tip__curse' : 'tip__ench') . '">' . h($label) . '</div>';
    }
    if (!empty($it['trim'])) {
        $pattern = Mc::t('trim_pattern.minecraft.' . $it['trim'][1], Mc::pretty($it['trim'][1]));
        $material = Mc::t('trim_material.minecraft.' . $it['trim'][0], Mc::pretty($it['trim'][0]));
        $html .= '<div class="tip__line tip__muted">' . h($pattern . ' · ' . $material) . '</div>';
    }
    $max = $it['max_damage'] ?? max_durability($id);
    if ($max > 0 && !empty($it['damage'])) {
        $html .= '<div class="tip__line tip__muted">Durabilité : ' . max(0, $max - (int) $it['damage']) . ' / ' . $max . '</div>';
    }
    if (!empty($it['contents'])) {
        $html .= '<div class="tip__contents">';
        foreach (array_slice($it['contents'], 0, 12) as $sub) {
            $html .= '<div class="tip__line">' . h(($sub['name'] ?? '') !== '' ? $sub['name'] : Mc::itemName($sub['id'])) . ' <span class="tip__muted">×' . (int) $sub['count'] . '</span></div>';
        }
        if (count($it['contents']) > 12) {
            $html .= '<div class="tip__line tip__muted">et ' . (count($it['contents']) - 12) . ' autre(s)…</div>';
        }
        $html .= '</div>';
    }
    return $html;
}

/** Case d'inventaire. */
function item_slot(?array $it, string $cls = '', string $placeholder = ''): string
{
    if (!$it || ($it['id'] ?? 'air') === 'air') {
        return '<div class="slot slot--empty ' . h($cls) . '"' . ($placeholder !== '' ? ' title="' . h($placeholder) . '"' : '') . '></div>';
    }
    $ench = !empty($it['ench']) || !empty($it['stored']);
    $html = '<div class="slot ' . h($cls) . ($ench ? ' slot--ench' : '') . '" tabindex="0" data-tip="' . h(item_tip($it)) . '">' . mc_icon($it['id']);
    if ($it['count'] > 1) {
        $html .= '<span class="slot__count">' . (int) $it['count'] . '</span>';
    }
    $max = $it['max_damage'] ?? max_durability($it['id']);
    if ($max > 0 && !empty($it['damage'])) {
        $left = max(0, 1 - $it['damage'] / $max);
        $state = $left > 0.5 ? 'ok' : ($left > 0.2 ? 'mid' : 'low');
        $html .= '<span class="slot__dura slot__dura--' . $state . '"><span style="width:' . round($left * 100) . '%"></span></span>';
    }
    return $html . '</div>';
}

/** Grille d'inventaire : $items indexés par slot. */
function item_grid(array $items, int $from, int $count, int $selected = -1): string
{
    $bySlot = [];
    foreach ($items as $it) {
        if (isset($it['slot'])) {
            $bySlot[(int) $it['slot']] = $it;
        }
    }
    $html = '<div class="inv-grid">';
    for ($s = $from; $s < $from + $count; $s++) {
        $html .= item_slot($bySlot[$s] ?? null, $s === $selected ? 'slot--selected' : '');
    }
    return $html . '</div>';
}

/** Carte de succès. */
function adv_card(string $id, ?array $meta, ?array $state): string
{
    $done = !empty($state[0]);
    $date = (int) ($state[1] ?? 0);
    $crit = (int) ($state[2] ?? 0);
    $frame = $meta['frame'] ?? 'task';
    $locked = !empty($meta['hidden']) && !$done;
    $title = $locked ? '???' : Mc::advTitle($id, $meta);
    $desc = $locked ? 'Succès secret' : Mc::advDescription($id, $meta);
    $sub = '';
    if ($done) {
        $sub = fmt_date($date);
    } elseif (($meta['req'] ?? 0) > 1 && $crit > 0) {
        $sub = $crit . ' / ' . $meta['req'];
    }
    $frames = ['task' => 'Progrès', 'goal' => 'Objectif', 'challenge' => 'Défi'];
    $tip = '<div class="tip__title' . ($frame === 'challenge' ? ' tip__title--challenge' : '') . '">' . h($title) . '</div>'
        . ($desc !== '' ? '<div class="tip__line">' . h($desc) . '</div>' : '')
        . '<div class="tip__line tip__muted">' . h($frames[$frame] ?? 'Progrès')
        . ($done ? ' · obtenu le ' . h(fmt_datetime($date)) : ($sub !== '' ? ' · ' . h($sub) : '')) . '</div>';
    $progress = '';
    if (!$done && ($meta['req'] ?? 0) > 1 && $crit > 0) {
        $progress = '<span class="adv__progress"><span style="width:' . round(min(1, $crit / $meta['req']) * 100) . '%"></span></span>';
    }
    return '<div class="adv adv--' . h($frame) . ($done ? ' is-done' : '') . ($locked ? ' is-locked' : '') . '" tabindex="0" data-tip="' . h($tip) . '">'
        . '<span class="adv__icon">' . ($locked ? '<span class="mc-icon mc-icon--ph">?</span>' : mc_icon($meta['icon'] ?? 'stone')) . '</span>'
        . '<span class="adv__body"><span class="adv__title">' . h($title) . '</span>'
        . ($sub !== '' ? '<span class="adv__sub">' . h($sub) . '</span>' : '') . $progress . '</span></div>';
}

function coords(?array $pos): string
{
    if (!$pos || count($pos) < 3) {
        return '—';
    }
    return 'X ' . (int) $pos[0] . ' · Y ' . (int) $pos[1] . ' · Z ' . (int) $pos[2];
}

/** Encadré affiché à la place d'un contenu réservé aux joueurs connectés. */
function locked_card(string $title, string $return = ''): string
{
    $return = safe_return($return);
    return '<div class="card locked">'
        . mc_icon('barrier', '', 'mc-icon--lg')
        . '<h2>' . h($title) . '</h2>'
        . '<p class="muted">La carte, les positions et les inventaires sont réservés aux joueurs du serveur. '
        . 'Demande la whitelist pour rejoindre le serveur et créer ton compte, ou connecte-toi si tu en as déjà un.</p>'
        . '<div class="locked__actions">'
        . '<a class="btn btn--primary" href="demande.php">Demander la whitelist</a>'
        . '<a class="btn btn--ghost" href="connexion.php' . ($return !== '' ? '?retour=' . h(rawurlencode($return)) : '') . '">Se connecter</a>'
        . '</div></div>';
}

/** Étiquette de statut d'un compte. */
function account_status_tag(string $status): string
{
    $cls = ['active' => 'tag--ok', 'pending' => 'tag--warn', 'refused' => 'tag--danger', 'disabled' => 'tag--muted'][$status] ?? '';
    return '<span class="tag ' . $cls . '">' . h(Whitelist::STATUS_LABELS[$status] ?? $status) . '</span>';
}

function pagination(int $page, int $pages, callable $url): string
{
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="Pages">';
    $html .= $page > 1 ? '<a class="btn btn--ghost" href="' . h($url($page - 1)) . '">← Précédent</a>' : '<span></span>';
    $html .= '<span class="muted">Page ' . $page . ' / ' . $pages . '</span>';
    $html .= $page < $pages ? '<a class="btn btn--ghost" href="' . h($url($page + 1)) . '">Suivant →</a>' : '<span></span>';
    return $html . '</nav>';
}

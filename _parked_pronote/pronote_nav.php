<?php
/**
 * 🧭 (2026-10-07) تنظيم كالمرجع (Pronote): ترويسة + شريط تبويبات أفقي بقوائم منسدلة + شريط صفحة — بدل القائمة الجانبية.
 * لا يلمس أي رابط ولا أي شرط صلاحية: يأخذ HTML القائمة الجانبية كما يُولَّد (بشروطه وشاراته) ويعيد ترتيبه فقط.
 * الرسوم والأيقونات تبقى لنا (Font Awesome)؛ الألوان مشابهة للمرجع (رمادي غامق + أخضر مزرقّ)، لا منسوخة.
 */
function msaPronoteNav(string $sidebarHtml): string {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="__root">' . $sidebarHtml . '</div>');
    $xp = new DOMXPath($doc);
    $inner = function (DOMNode $n) use ($doc): string { $h = ''; foreach ($n->childNodes as $c) $h .= $doc->saveHTML($c); return $h; };
    // ترويسة: الشعار واسم المدرسة
    $brand = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " sidebar-brand ")]')->item(0);
    $brandH2 = $brand ? trim($xp->query('.//h2', $brand)->item(0)->textContent ?? '') : 'MSA Payroll';
    $brandP = $brand ? trim($xp->query('.//p', $brand)->item(0)->textContent ?? '') : '';
    // القائمة: البيت ثم مجموعات (nav-section) بما يليها من روابط
    $nav = $xp->query('//nav[contains(concat(" ", normalize-space(@class), " "), " sidebar-nav ")]')->item(0);
    $groups = []; $home = null;
    if ($nav) foreach ($nav->childNodes as $n) {
        if (!($n instanceof DOMElement)) continue;
        $cls = ' ' . $n->getAttribute('class') . ' ';
        if ($n->tagName === 'div' && strpos($cls, ' nav-section ') !== false) { $g = ['label' => trim($n->textContent), 'color' => '', 'items' => []]; if (preg_match('/--ns:\s*(#[0-9a-fA-F]{3,6})/', $n->getAttribute('style'), $m)) $g['color'] = $m[1]; $groups[] = $g; continue; }
        if ($n->tagName === 'a') {
            $href = $n->getAttribute('href'); $active = strpos($cls, ' active ') !== false;
            $icon = ''; $ic = $xp->query('./i', $n)->item(0); if ($ic) $icon = $ic->getAttribute('class');
            $label = ''; $badges = '';
            foreach ($xp->query('./span', $n) as $sp) { if ($label === '' && !$sp->hasAttribute('style')) $label = trim($sp->textContent); else $badges .= $doc->saveHTML($sp); }
            if ($label === '') $label = trim($n->textContent);
            $it = ['href' => $href, 'active' => $active, 'icon' => $icon, 'label' => $label, 'badges' => $badges];
            if ($home === null && !$groups) { $home = $it; continue; }
            if ($groups) $groups[count($groups) - 1]['items'][] = $it;
        }
    }
    $split = function (string $label): array { $p = array_map('trim', explode(' / ', $label)); $fr = []; $ar = []; foreach ($p as $x) { if ($x === '') continue; if (preg_match('/\p{Arabic}/u', $x)) $ar[] = $x; else $fr[] = $x; } return [implode(' / ', $fr), implode(' / ', $ar)]; };
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $h = '<header class="pn-top no-print"><div class="pn-brand"><span class="pn-logo"><i class="fas fa-graduation-cap"></i></span><div><b>' . $e($brandH2) . '</b><span>' . $e($brandP) . '</span></div></div><div class="pn-top-r" id="pnTopRight"></div></header>';
    $h .= '<nav class="pn-menu no-print"><button type="button" class="pn-burger" onclick="document.body.classList.toggle(\'nav-open\')" title="Menu / القائمة"><i class="fas fa-bars"></i></button>';
    if ($home) $h .= '<a class="pn-home' . ($home['active'] ? ' on' : '') . '" href="' . $e($home['href']) . '" title="' . $e($home['label']) . '"><i class="fas fa-house"></i></a>';
    foreach ($groups as $g) {
        if (!$g['items']) continue;
        $on = false; foreach ($g['items'] as $it) if ($it['active']) $on = true;
        [$gf, $ga] = $split($g['label']);
        $h .= '<div class="pn-dd' . ($on ? ' on' : '') . '"><button type="button" onclick="this.parentNode.classList.toggle(\'open\')"><span class="pn-fr">' . $e($gf) . '</span><span class="pn-ar">' . $e($ga) . '</span></button><div class="pn-ddm">';
        foreach ($g['items'] as $it) { [$f, $a] = $split($it['label']);
            $h .= '<a href="' . $e($it['href']) . '" class="' . ($it['active'] ? 'active' : '') . '"><i class="' . $e($it['icon']) . '"></i><span class="pn-txt"><span class="pn-fr">' . $e($f) . '</span><span class="pn-ar">' . $e($a) . '</span></span>' . $it['badges'] . '</a>'; }
        $h .= '</div></div>';
    }
    $h .= '<div class="pn-right" id="pnMenuRight"></div></nav>';
    return $h;
}

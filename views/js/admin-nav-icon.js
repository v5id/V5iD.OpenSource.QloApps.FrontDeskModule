/**
 * Gives the "Front Desk" entry in the back-office left nav an icon.
 *
 * Loaded on every admin page via the displayBackOfficeHeader hook (see
 * v5idfrontdesk.php::hookDisplayBackOfficeHeader()) since the left nav
 * itself is shared chrome, not something this module's own controller
 * renders.
 *
 * QloApps' theme (admin/themes/default/template/nav.tpl) renders a
 * top-level tab's icon as <i class="icon-{ControllerClassName}">. That
 * class only shows a glyph if a matching CSS rule with actual glyph
 * content exists (see admin/themes/default/sass/partials/_icons.sass) —
 * core controllers get one there; a module's own tab (icon-AdminV5idFrontDesk)
 * has nothing to extend and renders empty. Rather than touching that core
 * theme file, this just adds a second, already-defined Font Awesome class
 * alongside it so the existing rule supplies the glyph.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var icon = document.querySelector('#maintab-AdminV5idFrontDesk > a > i');
        if (icon) {
            icon.classList.add('icon-bell');
        }
    });
})();

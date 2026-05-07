(function () {
    var resizeFrame = null;

    function positionHeaderSwitcher() {
        var headerInner = document.querySelector('.site-header__inner');
        var slot = document.querySelector('.mpt-header-language-slot');
        var logo = headerInner ? headerInner.querySelector('.custom-logo-link, .site-logo') : null;
        var toggle = headerInner ? headerInner.querySelector('.site-header__menu-toggle') : null;
        var flag = slot ? slot.querySelector('.mpt-language-current .mpt-flag') : null;

        if (!headerInner || !slot || !logo || !toggle || !flag) {
            return;
        }

        if (!window.matchMedia('(max-width: 991px)').matches) {
            slot.style.removeProperty('--mpt-header-switcher-left');
            return;
        }

        var headerRect = headerInner.getBoundingClientRect();
        var logoRect = logo.getBoundingClientRect();
        var toggleRect = toggle.getBoundingClientRect();
        var slotRect = slot.getBoundingClientRect();
        var gap = 10;
        var left = toggleRect.left - headerRect.left - slotRect.width - gap;
        var minimumLeft = logoRect.right - headerRect.left + gap;

        if (left < minimumLeft) {
            left = minimumLeft;
        }

        slot.style.setProperty('--mpt-header-switcher-left', left + 'px');
    }

    function requestHeaderSwitcherPosition() {
        if (resizeFrame) {
            window.cancelAnimationFrame(resizeFrame);
        }

        resizeFrame = window.requestAnimationFrame(function () {
            resizeFrame = null;
            positionHeaderSwitcher();
        });
    }

    function createHeaderSwitcher() {
        var headerInner = document.querySelector('.site-header__inner');
        var source = document.querySelector('.site-nav .mpt-language-switcher');

        if (!headerInner || !source || headerInner.querySelector('.mpt-header-language-slot')) {
            return;
        }

        var slot = document.createElement('div');
        slot.className = 'mpt-header-language-slot';

        var clone = source.cloneNode(true);
        clone.classList.add('mpt-language-switcher--header');
        slot.appendChild(clone);

        var toggle = headerInner.querySelector('.site-header__menu-toggle');
        headerInner.insertBefore(slot, toggle || null);
        requestHeaderSwitcherPosition();
    }

    function normalizeUrl(url) {
        return String(url || '').replace(/\/+$/, '') + '/';
    }

    function localizeLogoHomeLinks() {
        var config = window.mptLanguage || {};
        if (!config.current || !config.default || config.current === config.default || !config.homeUrl || !config.rootUrl) {
            return;
        }

        var rootUrl = normalizeUrl(config.rootUrl);
        var homeUrl = normalizeUrl(config.homeUrl);

        document.querySelectorAll('a.custom-logo-link, .custom-logo-link, .site-logo a, .site-branding a, a[rel="home"], header a[href]').forEach(function (link) {
            var href = link.getAttribute('href');
            if (normalizeUrl(href) === rootUrl) {
                link.setAttribute('href', homeUrl);
            }
        });
    }

    function fixDefaultHomeSwitcherLinks() {
        var config = window.mptLanguage || {};
        var defaultLang = config.default || 'pt';
        var path = window.location.pathname.replace(/^\/+|\/+$/g, '');

        if (!path || path.indexOf('/') !== -1 || path === defaultLang) {
            return;
        }

        var rootUrl = normalizeUrl(config.rootUrl || (window.location.origin + '/'));
        document.querySelectorAll('[data-mpt-switcher] a[data-mpt-language="' + defaultLang + '"], [data-mpt-switcher] a[hreflang="' + defaultLang + '"]').forEach(function (link) {
            link.setAttribute('href', rootUrl);
        });
    }

    function closeAll(except) {
        document.querySelectorAll('[data-mpt-switcher].is-open').forEach(function (switcher) {
            if (switcher !== except) {
                switcher.classList.remove('is-open');
                var button = switcher.querySelector('.mpt-language-current');
                if (button) {
                    button.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.mpt-language-current');
        if (!button) {
            closeAll(null);
            return;
        }

        var switcher = button.closest('[data-mpt-switcher]');
        if (!switcher) {
            return;
        }

        var isOpen = switcher.classList.toggle('is-open');
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        closeAll(switcher);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll(null);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            createHeaderSwitcher();
            localizeLogoHomeLinks();
            fixDefaultHomeSwitcherLinks();
        });
    } else {
        createHeaderSwitcher();
        localizeLogoHomeLinks();
        fixDefaultHomeSwitcherLinks();
    }

    window.addEventListener('load', requestHeaderSwitcherPosition);
    window.addEventListener('load', localizeLogoHomeLinks);
    window.addEventListener('load', fixDefaultHomeSwitcherLinks);
    window.addEventListener('resize', requestHeaderSwitcherPosition);

    if ('MutationObserver' in window) {
        var logoObserver = new MutationObserver(function () {
            localizeLogoHomeLinks();
            fixDefaultHomeSwitcherLinks();
        });
        if (document.body) {
            logoObserver.observe(document.body, { childList: true, subtree: true });
        }
    }

    if ('ResizeObserver' in window) {
        var observer = new ResizeObserver(requestHeaderSwitcherPosition);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                var headerInner = document.querySelector('.site-header__inner');
                if (headerInner) {
                    observer.observe(headerInner);
                }
            });
        } else {
            var headerInner = document.querySelector('.site-header__inner');
            if (headerInner) {
                observer.observe(headerInner);
            }
        }
    }
})();

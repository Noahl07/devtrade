// dev.trade : script global du nouveau layout (design system).
// Aucun script inline : tout passe par ce fichier (compatible avec une CSP stricte).
import './styles/ds.css';
import './styles/ds/layout.css';
import './styles/ds/home.css';

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

// ── En-tête : se compacte après un léger défilement ─────────────────────────
const header = document.querySelector('[data-site-header]');
if (header) {
    let ticking = false;
    const update = () => {
        header.classList.toggle('is-scrolled', window.scrollY > 24);
        ticking = false;
    };
    window.addEventListener(
        'scroll',
        () => {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(update);
            }
        },
        { passive: true },
    );
    update();
}

// ── Menu mobile plein écran (<dialog> natif : focus piégé, Échap, fond inerte) ─
const menu = document.querySelector('[data-site-menu]');
if (menu && typeof menu.showModal === 'function') {
    document.querySelectorAll('[data-menu-open]').forEach((button) => {
        button.addEventListener('click', () => {
            menu.showModal();
            button.setAttribute('aria-expanded', 'true');
        });
    });
    menu.addEventListener('close', () => {
        document.querySelectorAll('[data-menu-open]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
    });
    menu.querySelectorAll('[data-menu-close], a').forEach((el) => {
        el.addEventListener('click', () => menu.close());
    });
    // Clic sur le fond (hors du contenu) : fermeture
    menu.addEventListener('click', (event) => {
        if (event.target === menu) {
            menu.close();
        }
    });
}

// ── Messages flash ──────────────────────────────────────────────────────────
document.querySelectorAll('[data-flash-close]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.flash')?.remove());
});

// ── Révélations au défilement (discrètes, coupées si mouvement réduit) ─────
// Sans JavaScript, tout reste visible : l'état masqué n'est posé qu'ici,
// et seulement sur les éléments situés sous la ligne de flottaison.
const revealables = document.querySelectorAll('[data-reveal]');
if (revealables.length && !reducedMotion.matches && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    observer.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -8% 0px' },
    );
    revealables.forEach((el) => {
        if (el.getBoundingClientRect().top > window.innerHeight) {
            el.classList.add('is-pending');
            observer.observe(el);
        }
    });
}

// ── Scène 3D du héros (chargée à la demande) ────────────────────────────────
function canUseWebGL() {
    try {
        const canvas = document.createElement('canvas');
        return Boolean(canvas.getContext('webgl2') || canvas.getContext('webgl'));
    } catch {
        return false;
    }
}

function isLowPowerDevice() {
    const connection = navigator.connection;
    if (connection?.saveData) {
        return true;
    }
    if (navigator.deviceMemory && navigator.deviceMemory < 4) {
        return true;
    }
    const smallScreen = window.matchMedia('(max-width: 760px)').matches;
    return smallScreen && (navigator.hardwareConcurrency || 8) <= 4;
}

const heroArt = document.querySelector('[data-hero-scene]');
if (heroArt && !reducedMotion.matches && !isLowPowerDevice() && canUseWebGL()) {
    let dispose = null;

    const observer = new IntersectionObserver(async ([entry]) => {
        if (!entry.isIntersecting || dispose) {
            return;
        }
        observer.disconnect();
        try {
            const { mountHeroScene } = await import(/* webpackChunkName: "hero-scene" */ './js/hero-scene.js');
            dispose = mountHeroScene(heroArt, {
                onReady: () => heroArt.classList.add('is-3d'),
            });
        } catch (error) {
            // L'illustration SVG reste affichée : aucune fonctionnalité perdue.
            console.warn('Scène 3D indisponible :', error);
        }
    });
    observer.observe(heroArt);

    // Libère le contexte WebGL quand on quitte la page
    // (retour arrière via le cache du navigateur : l'illustration SVG reprend le relais)
    window.addEventListener('pagehide', () => {
        dispose?.();
        dispose = null;
        heroArt.classList.remove('is-3d');
    });
}

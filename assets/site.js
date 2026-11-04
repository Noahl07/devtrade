// dev.trade : script global du nouveau layout (design system).
// Aucun script inline : tout passe par ce fichier (compatible avec une CSP stricte).
import './styles/ds.css';
import './styles/ds/layout.css';
import './styles/ds/home.css';
import './styles/ds/pages.css';
import { allow3D, lazyScene } from './js/device.js';

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

// ── Champ mot de passe : afficher / masquer ───────────────────────────────
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = button.parentElement.querySelector('input');
    button.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    });
});

// ── Lecture : sommaire généré depuis les titres + barre de progression ─────
const readingBody = document.querySelector('[data-reading-body]');
if (readingBody) {
    const toc = document.querySelector('[data-toc]');
    const headings = [...readingBody.querySelectorAll('h2, h3')];
    const slug = (text) => text.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'section';
    const links = new Map();

    if (toc && headings.length > 1) {
        headings.forEach((heading, i) => {
            heading.id ||= `${slug(heading.textContent)}-${i + 1}`;
            const li = document.createElement('li');
            if (heading.tagName === 'H3') {
                li.className = 'toc__sub';
            }
            const a = document.createElement('a');
            a.href = `#${heading.id}`;
            a.textContent = heading.textContent;
            li.appendChild(a);
            toc.appendChild(li);
            links.set(heading, a);
        });
        toc.closest('[data-toc-wrap]')?.removeAttribute('hidden');

        const spy = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    links.forEach((a) => a.removeAttribute('aria-current'));
                    links.get(entry.target)?.setAttribute('aria-current', 'true');
                }
            });
        }, { rootMargin: '-90px 0px -70% 0px' });
        headings.forEach((h) => spy.observe(h));
    }

    const bar = document.querySelector('[data-read-progress]');
    if (bar) {
        let frame = 0;
        const update = () => {
            const rect = readingBody.getBoundingClientRect();
            const total = rect.height - window.innerHeight * 0.6;
            const progress = Math.min(1, Math.max(0, -rect.top / Math.max(total, 1)));
            bar.style.transform = `scaleX(${progress})`;
            frame = 0;
        };
        window.addEventListener('scroll', () => { frame ||= requestAnimationFrame(update); }, { passive: true });
        update();
    }
}

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

// ── Scène 3D du héros (chargée à la demande, voir assets/js/device.js) ─────
const heroArt = document.querySelector('[data-hero-scene]');
if (heroArt && allow3D()) {
    lazyScene(heroArt, async () => {
        const { mountHeroScene } = await import(/* webpackChunkName: "hero-scene" */ './js/hero-scene.js');
        return { mount: mountHeroScene };
    });
}

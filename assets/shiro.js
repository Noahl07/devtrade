// Page produit Shiro (/trading) : onglets de période, compteurs, frise, graphiques, liste d'attente, scène 3D.
// Amélioration progressive : sans JavaScript, tout le contenu reste lisible (panneaux empilés, tableau de données).
import './styles/ds/shiro.css';
import { allow3D, lazyScene, reducedMotion } from './js/device.js';
import { createChart, fr } from './js/charts.js';

// Les données publiques sont mensuelles (« 2021-04 ») : aucune date de trade n'est publiée.
const frMonth = (ym) => new Date(`${ym}-01T00:00:00Z`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric', timeZone: 'UTC' });

// ── Compteurs animés ────────────────────────────────────────────────────────
function animateCounters(scope) {
    scope.querySelectorAll('[data-count]').forEach((el) => {
        if (el.dataset.counted) {
            return;
        }
        el.dataset.counted = '1';
        if (reducedMotion()) {
            return; // la valeur finale est déjà dans le HTML
        }
        const target = Number(el.dataset.count);
        const decimals = Number(el.dataset.decimals || 0);
        const prefix = el.dataset.prefix || '';
        const suffix = el.dataset.suffix || '';
        const final = el.textContent;
        const duration = 1400;
        const t0 = performance.now();
        const step = (now) => {
            const p = Math.min(1, (now - t0) / duration);
            const eased = 1 - (1 - p) ** 3;
            el.textContent = p < 1 ? `${prefix}${fr(target * eased, decimals)}${suffix}` : final;
            if (p < 1) {
                requestAnimationFrame(step);
            }
        };
        requestAnimationFrame(step);
    });
}

const onceVisible = (el, callback, rootMargin = '0px 0px -10% 0px') => {
    const observer = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
            observer.disconnect();
            callback();
        }
    }, { rootMargin });
    observer.observe(el);
};

// ── Onglets de période (rôle ARIA tablist, flèches gauche/droite) ──────────
document.querySelectorAll('[data-tabs]').forEach((root) => {
    const tablist = root.querySelector('[data-tablist]');
    const tabs = [...root.querySelectorAll('[role="tab"]')];
    const panels = [...root.querySelectorAll('[data-panel]')];
    if (!tablist || tabs.length < 2) {
        return;
    }
    tablist.hidden = false;
    root.classList.add('is-enhanced');

    const select = (index, focus = false) => {
        tabs.forEach((tab, i) => {
            const active = i === index;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            panels[i].hidden = !active;
        });
        if (focus) {
            tabs[index].focus();
        }
        animateCounters(panels[index]);
    };

    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => select(i));
        tab.addEventListener('keydown', (event) => {
            const delta = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            if (delta) {
                event.preventDefault();
                select((i + delta + tabs.length) % tabs.length, true);
            }
        });
    });

    panels.forEach((panel, i) => {
        panel.hidden = i !== 0;
    });
    onceVisible(root, () => animateCounters(panels[0]));
});

// ── Frise du principe : la ligne dorée se dessine à l'apparition ───────────
document.querySelectorAll('[data-steps]').forEach((steps) => onceVisible(steps, () => steps.classList.add('is-active')));

// ── Graphiques ─────────────────────────────────────────────────────────────
const signed = (v, d) => `${v > 0 ? '+' : ''}${fr(v, d)}`;

const CHARTS = {
    'r-cumule': (data) => ({
        xType: 'date',
        label: `Gain cumulé en R, ${data.meta.trades} trades, points mensuels`,
        zero: true,
        series: [{ type: 'line', className: 'ch-line--gold', points: data.points }],
        markers: data.meta.breaks.map((d) => ({ x: d, label: `Rupture · ${frMonth(d)}` })),
        yFormat: (v) => `${fr(v, 0)} R`,
        tooltip: (i) => ({
            title: `Fin ${frMonth(data.points[i][0])}`,
            rows: [['Gain cumulé', `${signed(data.points[i][1], 1)} R`]],
        }),
    }),
    avantage: (data) => ({
        xType: 'date',
        label: 'Avantage estimé par trade, filtre de Kalman, intervalle à 95 %',
        zero: true,
        series: [
            { type: 'band', className: 'ch-band--positive', points: data.points.map((p) => [p[0], p[2], p[3]]) },
            { type: 'line', className: 'ch-line--positive', points: data.points.map((p) => [p[0], p[1]]) },
        ],
        yFormat: (v) => `${signed(v, 2)} R`,
        tooltip: (i) => {
            const p = data.points[i];
            return {
                title: `Fin ${frMonth(p[0])}`,
                rows: [['Avantage', `${signed(p[1], 3)} R`], ['Intervalle 95 %', `${signed(p[2], 2)} à ${signed(p[3], 2)} R`]],
            };
        },
        legend: [
            { label: 'Avantage estimé', className: 'ch-line--positive' },
            { label: 'Intervalle à 95 %', className: 'ch-band--positive' },
        ],
    }),
    'monte-carlo': (data) => ({
        xType: 'number',
        label: 'Éventail de rendements simulés sur un an',
        zero: true,
        series: [
            { type: 'band', className: 'ch-band--soft', points: data.bands.map((b) => [b[0], b[1], b[5]]) },
            { type: 'band', className: 'ch-band--strong', points: data.bands.map((b) => [b[0], b[2], b[4]]) },
            { type: 'line', className: 'ch-line--gold', points: data.bands.map((b) => [b[0], b[3]]) },
        ],
        xFormat: (v) => `${fr(v)}`,
        yFormat: (v) => `${signed(v, 0)} %`,
        tooltip: (i) => {
            const b = data.bands[i];
            return {
                title: `Trade ${b[0]} (simulation)`,
                rows: [['Médiane', `${signed(b[3], 1)} %`], ['50 % des cas', `${signed(b[2], 1)} à ${signed(b[4], 1)} %`], ['90 % des cas', `${signed(b[1], 1)} à ${signed(b[5], 1)} %`]],
            };
        },
        legend: [
            { label: 'Médiane', className: 'ch-line--gold' },
            { label: '50 % des trajectoires', className: 'ch-band--strong' },
            { label: '90 % des trajectoires', className: 'ch-band--soft' },
        ],
    }),
    kelly: (data) => ({
        xType: 'number',
        label: 'Croissance théorique selon le risque par trade',
        zero: true,
        series: [
            { type: 'line', className: 'ch-line--gold', points: data.f.map((f, i) => [f, data.recent[i]]) },
            { type: 'line', className: 'ch-line--light', points: data.f.map((f, i) => [f, data.prudent[i]]) },
            { type: 'line', className: 'ch-line--negative', points: data.f.map((f, i) => [f, data.degrade[i]]) },
        ],
        xFormat: (v) => `${fr(v)} %`,
        yFormat: (v) => `${signed(v, 0)} %`,
        tooltip: (i) => ({
            title: `Risque de ${fr(data.f[i], 1)} % par trade`,
            rows: [['Récent', `${signed(data.recent[i], 2)} %`], ['Prudent', `${signed(data.prudent[i], 2)} %`], ['Dégradé', `${signed(data.degrade[i], 2)} %`]],
        }),
        legend: [
            { label: 'Scénario récent', className: 'ch-line--gold' },
            { label: 'Scénario prudent', className: 'ch-line--light' },
            { label: 'Scénario dégradé (+0,05 R)', className: 'ch-line--negative' },
        ],
    }),
};

async function mountChart(plot) {
    if (plot.dataset.mounted) {
        return;
    }
    plot.dataset.mounted = '1';
    try {
        const response = await fetch(plot.dataset.src, { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            throw new Error(String(response.status));
        }
        const chart = createChart(plot, CHARTS[plot.dataset.chart](await response.json()));
        // L'élément conteneur garde sa description pour les lecteurs d'écran ; le SVG porte la sienne.
        plot.removeAttribute('role');
        onceVisible(plot, () => chart.reveal(), '0px 0px -15% 0px');
    } catch (error) {
        plot.dataset.mounted = '';
        const message = document.createElement('p');
        message.className = 'muted small';
        message.textContent = 'Graphique indisponible pour le moment. Les données annuelles restent consultables ci-dessous.';
        plot.replaceChildren(message);
        console.warn('Graphique indisponible :', error);
    }
}

document.querySelectorAll('[data-chart]').forEach((plot) => {
    const details = plot.closest('details');
    if (details) {
        details.addEventListener('toggle', () => details.open && mountChart(plot));
    } else {
        onceVisible(plot, () => mountChart(plot), '0px 0px 300px 0px');
    }
});

// ── Liste d'attente ────────────────────────────────────────────────────────
document.querySelectorAll('[data-waitlist]').forEach((form) => {
    const status = form.querySelector('[data-waitlist-status]');
    const button = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        status.className = 'sh-form__status';

        if (!form.email.value.trim() || !form.email.checkValidity()) {
            status.classList.add('is-error');
            status.textContent = 'Indiquez une adresse e-mail valide.';
            form.email.focus();
            return;
        }
        if (!form.consent.checked) {
            status.classList.add('is-error');
            status.textContent = 'Merci de cocher la case de consentement.';
            form.consent.focus();
            return;
        }

        button.disabled = true;
        status.textContent = 'Envoi…';
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
            const result = await response.json().catch(() => ({}));
            if (response.ok && result.success) {
                status.classList.add('is-success');
                status.textContent = result.message;
                form.reset();
            } else {
                status.classList.add('is-error');
                status.textContent = result.error || 'Une erreur est survenue. Réessayez dans un instant.';
            }
        } catch {
            status.classList.add('is-error');
            status.textContent = 'Connexion impossible. Vérifiez votre réseau puis réessayez.';
        } finally {
            button.disabled = false;
        }
    });
});

// ── Scène 3D du héros ──────────────────────────────────────────────────────
const sceneContainer = document.querySelector('[data-shiro-scene]');
if (sceneContainer && allow3D()) {
    lazyScene(sceneContainer, async () => {
        const { mountShiroScene } = await import(/* webpackChunkName: "shiro-scene" */ './js/shiro-scene.js');
        return { mount: mountShiroScene };
    });
}

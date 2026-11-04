// Graphiques SVG légers (sans bibliothèque) : lignes, bandes, repères, curseur au survol et au clavier.
// Toutes les couleurs viennent du CSS (classes .ch-*), donc des tokens du design system.

const NS = 'http://www.w3.org/2000/svg';
const MARGIN = { top: 16, right: 16, bottom: 30, left: 52 };
const HEIGHT = 300;

export const fr = (value, decimals = 0) =>
    new Intl.NumberFormat('fr-FR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value);

function el(name, attrs = {}, parent = null) {
    const node = document.createElementNS(NS, name);
    Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, String(v)));
    if (parent) {
        parent.appendChild(node);
    }
    return node;
}

function niceStep(range, target) {
    const raw = range / Math.max(target, 1);
    const pow = 10 ** Math.floor(Math.log10(raw));
    const n = raw / pow;
    return (n < 1.5 ? 1 : n < 3 ? 2 : n < 7 ? 5 : 10) * pow;
}

function nearestIndex(xs, x) {
    let lo = 0;
    let hi = xs.length - 1;
    while (hi - lo > 1) {
        const mid = (lo + hi) >> 1;
        if (xs[mid] < x) {
            lo = mid;
        } else {
            hi = mid;
        }
    }
    return x - xs[lo] < xs[hi] - x ? lo : hi;
}

/**
 * @param {HTMLElement} container
 * @param {{
 *   xType: 'date'|'number',
 *   series: Array<{type: 'line'|'band', className: string, points: Array<Array<number|string>>}>,
 *   yFormat: (v: number) => string,
 *   xFormat?: (v: number) => string,
 *   zero?: boolean,
 *   markers?: Array<{x: number|string, label: string}>,
 *   tooltip: (i: number) => {title: string, rows: Array<[string, string]>},
 *   legend?: Array<{label: string, className: string}>,
 *   label: string,
 * }} spec
 */
export function createChart(container, spec) {
    const toX = (v) => (spec.xType === 'date' ? Date.parse(v) : Number(v));
    const series = spec.series.map((s) => ({
        ...s,
        points: s.points.map((p) => [toX(p[0]), ...p.slice(1).map(Number)]),
    }));
    const lead = series.find((s) => s.type === 'line');
    const xs = lead.points.map((p) => p[0]);

    let xmin = Infinity;
    let xmax = -Infinity;
    let ymin = spec.zero ? 0 : Infinity;
    let ymax = spec.zero ? 0 : -Infinity;
    series.forEach((s) =>
        s.points.forEach((p) => {
            xmin = Math.min(xmin, p[0]);
            xmax = Math.max(xmax, p[0]);
            p.slice(1).forEach((v) => {
                ymin = Math.min(ymin, v);
                ymax = Math.max(ymax, v);
            });
        }),
    );
    const pad = (ymax - ymin) * 0.08 || 1;
    ymin -= pad;
    ymax += pad;

    container.replaceChildren();
    const svg = el('svg', { class: 'ch is-pending', role: 'img', tabindex: 0, 'aria-label': spec.label });
    container.appendChild(svg);
    const tooltip = document.createElement('div');
    tooltip.className = 'ch-tooltip';
    tooltip.hidden = true;
    container.appendChild(tooltip);

    if (spec.legend) {
        const legend = document.createElement('div');
        legend.className = 'ch-legend';
        spec.legend.forEach((item) => {
            const span = document.createElement('span');
            const swatch = document.createElement('i');
            swatch.className = item.className;
            span.append(swatch, item.label);
            legend.appendChild(span);
        });
        container.appendChild(legend);
    }

    const hint = document.createElement('p');
    hint.className = 'ch-hint';
    hint.textContent = window.matchMedia('(hover: hover)').matches
        ? 'Survolez la courbe pour lire les valeurs (ou flèches du clavier).'
        : 'Touchez la courbe pour lire les valeurs.';
    container.appendChild(hint);

    let revealed = false;
    let cursorIndex = null;
    let scale = null;
    let cursorGroup = null;

    function draw() {
        const width = Math.max(container.clientWidth, 280);
        svg.setAttribute('viewBox', `0 0 ${width} ${HEIGHT}`);
        svg.replaceChildren();
        const iw = width - MARGIN.left - MARGIN.right;
        const ih = HEIGHT - MARGIN.top - MARGIN.bottom;
        const sx = (x) => MARGIN.left + ((x - xmin) / (xmax - xmin || 1)) * iw;
        const sy = (y) => MARGIN.top + (1 - (y - ymin) / (ymax - ymin)) * ih;
        scale = { sx, sy, iw, ih, width };

        // Grille et axe Y
        const grid = el('g', { class: 'ch-grid' }, svg);
        const axis = el('g', { class: 'ch-axis' }, svg);
        const step = niceStep(ymax - ymin, 5);
        for (let v = Math.ceil(ymin / step) * step; v <= ymax; v += step) {
            el('line', { x1: MARGIN.left, x2: width - MARGIN.right, y1: sy(v), y2: sy(v) }, grid);
            const t = el('text', { x: MARGIN.left - 8, y: sy(v) + 4, 'text-anchor': 'end' }, axis);
            t.textContent = spec.yFormat(Math.abs(v) < step / 1e6 ? 0 : v);
        }

        // Axe X
        if (spec.xType === 'date' && xmax - xmin < 2 * 365.25 * 864e5) {
            // Période courte : repères mensuels
            const start = new Date(xmin);
            const months = Math.round((xmax - xmin) / (30.44 * 864e5));
            const every = Math.max(1, Math.ceil(months / Math.max(1, Math.floor(iw / 56))));
            for (let k = 0; k <= months; k += every) {
                const date = Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + k, 1);
                const t = el('text', { x: sx(date), y: HEIGHT - 8, 'text-anchor': 'middle' }, axis);
                t.textContent = new Date(date).toLocaleDateString('fr-FR', { month: 'short', timeZone: 'UTC' }).replace('.', '');
            }
        } else if (spec.xType === 'date') {
            const first = new Date(xmin).getUTCFullYear() + 1;
            const last = new Date(xmax).getUTCFullYear();
            const every = Math.ceil((last - first + 1) / Math.max(1, Math.floor(iw / 64)));
            for (let year = first; year <= last; year += every) {
                const x = sx(Date.UTC(year, 0, 1));
                const t = el('text', { x, y: HEIGHT - 8, 'text-anchor': 'middle' }, axis);
                t.textContent = String(year);
            }
        } else {
            const xstep = niceStep(xmax - xmin, Math.max(2, Math.floor(iw / 80)));
            for (let v = Math.ceil(xmin / xstep) * xstep; v <= xmax; v += xstep) {
                const t = el('text', { x: sx(v), y: HEIGHT - 8, 'text-anchor': 'middle' }, axis);
                t.textContent = spec.xFormat ? spec.xFormat(v) : fr(v);
            }
        }

        if (spec.zero && ymin < 0 && ymax > 0) {
            el('line', { class: 'ch-zero', x1: MARGIN.left, x2: width - MARGIN.right, y1: sy(0), y2: sy(0) }, svg);
        }

        // Séries : bandes d'abord, lignes ensuite
        const decimate = (pts) => {
            const stride = Math.max(1, Math.floor(pts.length / (iw * 1.5)));
            return stride === 1 ? pts : pts.filter((_, i) => i % stride === 0 || i === pts.length - 1);
        };
        series.filter((s) => s.type === 'band').forEach((s) => {
            const pts = decimate(s.points);
            const top = pts.map((p) => `${sx(p[0]).toFixed(1)},${sy(p[2]).toFixed(1)}`);
            const bottom = pts.slice().reverse().map((p) => `${sx(p[0]).toFixed(1)},${sy(p[1]).toFixed(1)}`);
            el('path', { class: `ch-band ${s.className}`, d: `M${top.join('L')}L${bottom.join('L')}Z` }, svg);
        });
        series.filter((s) => s.type === 'line').forEach((s) => {
            const pts = decimate(s.points);
            const d = 'M' + pts.map((p) => `${sx(p[0]).toFixed(1)},${sy(p[1]).toFixed(1)}`).join('L');
            const path = el('path', { class: `ch-line ${s.className}`, d, 'data-draw': '' }, svg);
            if (!revealed) {
                const length = path.getTotalLength();
                path.style.strokeDasharray = `${length}`;
                path.style.strokeDashoffset = `${length}`;
            }
        });

        // Repères verticaux (ex. rupture)
        (spec.markers || []).forEach((m) => {
            const x = sx(toX(m.x));
            const g = el('g', { class: 'ch-marker' }, svg);
            el('line', { x1: x, x2: x, y1: MARGIN.top, y2: HEIGHT - MARGIN.bottom }, g);
            const t = el('text', { x: x + 6, y: MARGIN.top + 12 }, g);
            t.textContent = m.label;
        });

        cursorGroup = el('g', { class: 'ch-cursor', visibility: 'hidden' }, svg);
        el('line', { y1: MARGIN.top, y2: HEIGHT - MARGIN.bottom }, cursorGroup);
        series.filter((s) => s.type === 'line').forEach(() => el('circle', { r: 3.5 }, cursorGroup));

        const hit = el('rect', {
            x: MARGIN.left, y: MARGIN.top, width: iw, height: ih, fill: 'transparent',
        }, svg);
        hit.addEventListener('pointermove', (event) => {
            const rect = svg.getBoundingClientRect();
            const px = ((event.clientX - rect.left) / rect.width) * width;
            const x = xmin + ((px - MARGIN.left) / iw) * (xmax - xmin);
            showCursor(nearestIndex(xs, x));
        });
        // Au doigt : un appui affiche la valeur, glisser horizontalement la fait défiler.
        hit.addEventListener('pointerdown', (event) => {
            const rect = svg.getBoundingClientRect();
            const px = ((event.clientX - rect.left) / rect.width) * width;
            showCursor(nearestIndex(xs, xmin + ((px - MARGIN.left) / iw) * (xmax - xmin)));
        });
        hit.addEventListener('pointerleave', (event) => {
            if (event.pointerType === 'mouse') {
                hideCursor();
            }
        });

        if (revealed) {
            svg.classList.remove('is-pending');
        }
        if (cursorIndex !== null) {
            showCursor(cursorIndex);
        }
    }

    function showCursor(i) {
        cursorIndex = i;
        const { sx, sy, width } = scale;
        const x = sx(xs[i]);
        cursorGroup.setAttribute('visibility', 'visible');
        const [line, ...dots] = cursorGroup.children;
        line.setAttribute('x1', x);
        line.setAttribute('x2', x);
        series.filter((s) => s.type === 'line').forEach((s, k) => {
            const p = s.points[Math.min(i, s.points.length - 1)];
            dots[k].setAttribute('cx', sx(p[0]));
            dots[k].setAttribute('cy', sy(p[1]));
        });

        const content = spec.tooltip(i);
        tooltip.replaceChildren();
        const title = document.createElement('strong');
        title.textContent = content.title;
        tooltip.appendChild(title);
        content.rows.forEach(([label, value]) => {
            const row = document.createElement('div');
            const l = document.createElement('span');
            l.textContent = `${label} `;
            row.append(l, value);
            tooltip.appendChild(row);
        });
        const rect = svg.getBoundingClientRect();
        const ratio = rect.width / width;
        const leadPoint = lead.points[i];
        tooltip.style.left = `${Math.min(Math.max(x * ratio, 80), rect.width - 80)}px`;
        tooltip.style.top = `${sy(leadPoint[1]) * ratio}px`;
        tooltip.hidden = false;
    }

    function hideCursor() {
        cursorIndex = null;
        cursorGroup?.setAttribute('visibility', 'hidden');
        tooltip.hidden = true;
    }

    // Clavier : flèches pour parcourir les points, Échap pour masquer
    svg.addEventListener('keydown', (event) => {
        const last = xs.length - 1;
        const jump = event.shiftKey ? 10 : 1;
        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            const start = cursorIndex ?? (event.key === 'ArrowRight' ? -1 : last + 1);
            showCursor(Math.min(last, Math.max(0, start + (event.key === 'ArrowRight' ? jump : -jump))));
        } else if (event.key === 'Escape') {
            hideCursor();
        }
    });
    svg.addEventListener('blur', hideCursor);

    let resizeFrame = 0;
    const observer = new ResizeObserver(() => {
        cancelAnimationFrame(resizeFrame);
        resizeFrame = requestAnimationFrame(draw);
    });
    observer.observe(container);
    draw();

    return {
        reveal() {
            if (revealed) {
                return;
            }
            revealed = true;
            requestAnimationFrame(() => {
                svg.classList.remove('is-pending');
                svg.querySelectorAll('.ch-line[data-draw]').forEach((path) => {
                    path.style.strokeDashoffset = '0';
                });
            });
        },
        destroy() {
            observer.disconnect();
        },
    };
}

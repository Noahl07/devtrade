// Surfaces 3D interactives des articles de documentation.
//
// Chaque <figure class="doc-3d"> contient un schéma statique (SVG + libellés HTML) et, dans
// <div class="doc-3d-data">, les données de la surface sous forme de tableaux HTML ordinaires
// (autorisés par le nettoyeur de contenu). Ce module lit ces tableaux, dessine la surface dans un
// <canvas> et permet de la faire pivoter (souris, doigt, flèches du clavier). Sans JavaScript, le
// schéma statique reste affiché. Les libellés restent du texte HTML (traduisible).

const PALETTE = [
    [30, 58, 110],
    [40, 110, 150],
    [79, 157, 126],
    [200, 164, 92],
    [226, 120, 80],
];
const DEG = Math.PI / 180;
const EL_MIN = 5;
const EL_MAX = 80;

function couleur(t, lum) {
    const u = Math.min(Math.max(t, 0), 1) * (PALETTE.length - 1);
    const i = Math.min(Math.floor(u), PALETTE.length - 2);
    const f = u - i;
    const a = PALETTE[i];
    const b = PALETTE[i + 1];
    const c = [0, 1, 2].map((k) => Math.min(255, Math.round((a[k] + (b[k] - a[k]) * f) * lum)));
    return `rgb(${c[0]},${c[1]},${c[2]})`;
}

/** Lit les deux tableaux de données de la figure. Renvoie null si les données sont incomplètes. */
function lireDonnees(figure) {
    const bloc = figure.querySelector('.doc-3d-data');
    const meta = bloc?.querySelector('.doc-3d-meta');
    const data = bloc?.querySelector('table:not(.doc-3d-meta)');
    if (!meta || !data) {
        return null;
    }
    const m = { line: [] };
    meta.querySelectorAll('tr').forEach((tr) => {
        const cle = tr.querySelector('th')?.textContent.trim();
        const valeurs = [...tr.querySelectorAll('td')].map((td) => td.textContent.trim());
        if (cle === 'line') {
            m.line.push({
                couleur: valeurs[0],
                points: valeurs.slice(1).map((p) => p.split(' ').map(Number)),
            });
        } else if (cle) {
            m[cle] = valeurs;
        }
    });
    const xs = [...data.querySelectorAll('thead th')].slice(1).map((th) => Number(th.textContent));
    const ys = [];
    const Z = [];
    data.querySelectorAll('tbody tr').forEach((tr) => {
        ys.push(Number(tr.querySelector('th').textContent));
        Z.push([...tr.querySelectorAll('td')].map((td) => Number(td.textContent)));
    });
    if (xs.length < 2 || ys.length < 2) {
        return null;
    }
    const nombres = (cle) => (m[cle] || []).map(Number);
    const ticks = (axe) => nombres(`${axe}ticks`).map((v, i) => ({ v, label: (m[`${axe}ticklabels`] || [])[i] ?? String(v) }));
    return {
        xs,
        ys,
        Z,
        titre: m.titre?.[0] ?? '',
        xlab: m.xlab?.[0] ?? '',
        ylab: m.ylab?.[0] ?? '',
        zlab: m.zlab?.[0] ?? '',
        zmin: Number(m.zmin?.[0] ?? 0),
        zmax: Number(m.zmax?.[0] ?? 1),
        az: Number(m.az?.[0] ?? -25),
        el: Number(m.el?.[0] ?? 25),
        xticks: ticks('x'),
        yticks: ticks('y'),
        zticks: ticks('z'),
        lignes: m.line,
    };
}

class Surface3D {
    constructor(figure, d) {
        this.figure = figure;
        this.d = d;
        this.az = d.az;
        this.el = d.el;
        this.zone = figure.querySelector('.doc-diagram__canvas');
        const svg = this.zone.querySelector('svg');
        const vb = svg?.viewBox?.baseVal;
        this.ratio = vb && vb.width ? vb.height / vb.width : 0.625;

        this.canvas = document.createElement('canvas');
        this.canvas.className = 'doc-3d__canvas';
        this.canvas.tabIndex = 0;
        this.canvas.setAttribute('role', 'img');
        this.canvas.setAttribute('aria-label', `${d.titre} : surface en trois dimensions, orientable avec les flèches du clavier`);
        this.couche = document.createElement('div');
        this.couche.className = 'doc-3d__labels';
        this.zone.append(this.canvas, this.couche);

        this.barre = document.createElement('div');
        this.barre.className = 'doc-3d__bar';
        const aide = document.createElement('span');
        aide.textContent = 'Faites glisser pour faire pivoter la surface';
        this.bouton = document.createElement('button');
        this.bouton.type = 'button';
        this.bouton.className = 'doc-3d__reset';
        this.bouton.textContent = 'Vue initiale';
        this.barre.append(aide, this.bouton);
        this.zone.after(this.barre);

        this.libelles = new Map();
        this.prepare();
        this.ecoute();
        figure.classList.add('is-live');
        this.redimensionne();
    }

    /** Coordonnées normalisées des sommets et lignes, calculées une seule fois. */
    prepare() {
        const { xs, ys, Z, zmin, zmax } = this.d;
        const x0 = xs[0];
        const x1 = xs[xs.length - 1];
        const y0 = ys[0];
        const y1 = ys[ys.length - 1];
        this.norm = (x, y, z) => [(x - x0) / (x1 - x0) - 0.5, (y - y0) / (y1 - y0) - 0.5, (Math.min(Math.max(z, zmin), zmax) - zmin) / (zmax - zmin)];
        this.bornes = { x0, x1, y0, y1 };
        this.facettes = [];
        for (let j = 0; j < ys.length - 1; j++) {
            for (let i = 0; i < xs.length - 1; i++) {
                const pts = [
                    this.norm(xs[i], ys[j], Z[j][i]),
                    this.norm(xs[i + 1], ys[j], Z[j][i + 1]),
                    this.norm(xs[i + 1], ys[j + 1], Z[j + 1][i + 1]),
                    this.norm(xs[i], ys[j + 1], Z[j + 1][i]),
                ];
                const zm = (pts[0][2] + pts[1][2] + pts[2][2] + pts[3][2]) / 4;
                this.facettes.push({ pts, zm, normale: this.normale(pts) });
            }
        }
    }

    normale(p) {
        const u = [p[1][0] - p[0][0], p[1][1] - p[0][1], p[1][2] - p[0][2]];
        const v = [p[3][0] - p[0][0], p[3][1] - p[0][1], p[3][2] - p[0][2]];
        let n = [u[1] * v[2] - u[2] * v[1], u[2] * v[0] - u[0] * v[2], u[0] * v[1] - u[1] * v[0]];
        const l = Math.hypot(...n) || 1;
        n = n.map((c) => c / l);
        return n[2] < 0 ? n.map((c) => -c) : n;
    }

    ecoute() {
        let depart = null;
        this.canvas.addEventListener('pointerdown', (e) => {
            depart = { x: e.clientX, y: e.clientY, az: this.az, el: this.el, type: e.pointerType };
            this.canvas.setPointerCapture(e.pointerId);
        });
        this.canvas.addEventListener('pointermove', (e) => {
            if (!depart) {
                return;
            }
            this.az = depart.az - (e.clientX - depart.x) * 0.45;
            // Au doigt, le geste vertical sert au défilement de la page (touch-action: pan-y).
            if (depart.type !== 'touch') {
                this.el = Math.min(EL_MAX, Math.max(EL_MIN, depart.el + (e.clientY - depart.y) * 0.3));
            }
            this.demande();
        });
        const fin = () => {
            depart = null;
        };
        this.canvas.addEventListener('pointerup', fin);
        this.canvas.addEventListener('pointercancel', fin);
        this.canvas.addEventListener('keydown', (e) => {
            const pas = e.shiftKey ? 15 : 5;
            const actions = {
                ArrowLeft: () => { this.az += pas; },
                ArrowRight: () => { this.az -= pas; },
                ArrowUp: () => { this.el = Math.min(EL_MAX, this.el + pas); },
                ArrowDown: () => { this.el = Math.max(EL_MIN, this.el - pas); },
            };
            if (actions[e.key]) {
                e.preventDefault();
                actions[e.key]();
                this.demande();
            }
        });
        this.bouton.addEventListener('click', () => {
            this.az = this.d.az;
            this.el = this.d.el;
            this.demande();
        });
        new ResizeObserver(() => this.redimensionne()).observe(this.zone);
    }

    redimensionne() {
        const largeur = this.zone.clientWidth;
        if (!largeur) {
            return;
        }
        const hauteur = Math.round(largeur * this.ratio);
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        this.canvas.width = Math.round(largeur * dpr);
        this.canvas.height = Math.round(hauteur * dpr);
        this.canvas.style.height = `${hauteur}px`;
        this.W = largeur;
        this.H = hauteur;
        this.dpr = dpr;
        this.dessine();
    }

    demande() {
        if (!this.attente) {
            this.attente = true;
            requestAnimationFrame(() => {
                this.attente = false;
                this.dessine();
            });
        }
    }

    /** Projection orthographique ; même convention que le générateur des schémas statiques. */
    projecteur() {
        const a = this.az * DEG;
        const e = this.el * DEG;
        const ca = Math.cos(a);
        const sa = Math.sin(a);
        const ce = Math.cos(e);
        const se = Math.sin(e);
        // Échelle fixe (indépendante de l'angle) pour que la surface ne « zoome » pas en tournant.
        const marge = 0.09;
        const largeurUtile = this.W * (1 - 2 * marge);
        const hauteurUtile = this.H * (1 - 2 * marge - 0.06);
        const s = Math.min(largeurUtile / Math.SQRT2, hauteurUtile / (0.9 * ce + Math.SQRT2 * se));
        // Centre vertical : le milieu de la hauteur de la surface tombe un peu sous le milieu du cadre.
        const cy = this.H * 0.53 + s * 0.45 * ce;
        return ([u, v, w]) => {
            const X = u * ca - v * sa;
            const D = u * sa + v * ca;
            const Y = w * 0.9 * ce + D * se;
            return [this.W / 2 + s * X, cy - s * Y, D];
        };
    }

    dessine() {
        if (!this.W) {
            return;
        }
        const P = this.projecteur();
        const ctx = this.canvas.getContext('2d');
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.clearRect(0, 0, this.W, this.H);
        const { x0, x1, y0, y1 } = this.bornes;
        const { zmin, zmax } = this.d;
        const N = (x, y, z) => P(this.norm(x, y, z));

        // Murs du fond et plancher : le mur choisi est celui qui est le plus loin de l'observateur.
        const loinX = N(x0, (y0 + y1) / 2, zmin)[2] > N(x1, (y0 + y1) / 2, zmin)[2] ? x0 : x1;
        const loinY = N((x0 + x1) / 2, y0, zmin)[2] > N((x0 + x1) / 2, y1, zmin)[2] ? y0 : y1;
        const presX = loinX === x0 ? x1 : x0;
        const presY = loinY === y0 ? y1 : y0;
        ctx.lineWidth = 1;
        ctx.strokeStyle = 'rgba(168,179,174,0.32)';
        ctx.setLineDash([2, 3]);
        const segment = (p, q) => {
            ctx.beginPath();
            ctx.moveTo(p[0], p[1]);
            ctx.lineTo(q[0], q[1]);
            ctx.stroke();
        };
        this.d.zticks.forEach(({ v }) => {
            segment(N(x0, loinY, v), N(x1, loinY, v));
            segment(N(loinX, y0, v), N(loinX, y1, v));
        });
        this.d.xticks.forEach(({ v }) => segment(N(v, y0, zmin), N(v, y1, zmin)));
        this.d.yticks.forEach(({ v }) => segment(N(x0, v, zmin), N(x1, v, zmin)));
        ctx.setLineDash([]);
        ctx.strokeStyle = 'rgba(168,179,174,0.55)';
        segment(N(loinX, loinY, zmin), N(loinX, loinY, zmax));
        // Axe vertical gradué : sur le coin du plancher le plus à gauche de l'écran.
        const coins = [[x0, y0], [x1, y0], [x0, y1], [x1, y1]];
        const coinZ = coins.reduce((m, c) => (N(c[0], c[1], zmin)[0] < N(m[0], m[1], zmin)[0] ? c : m));
        segment(N(coinZ[0], coinZ[1], zmin), N(coinZ[0], coinZ[1], zmax));

        // Facettes de la surface, de la plus lointaine à la plus proche (algorithme du peintre).
        const lumiere = [-0.5, -0.4, 0.77];
        const proj = this.facettes.map((f) => {
            const pp = f.pts.map(P);
            const prof = (pp[0][2] + pp[1][2] + pp[2][2] + pp[3][2]) / 4;
            const n = f.normale;
            const lum = 0.62 + 0.5 * Math.max(0, n[0] * lumiere[0] + n[1] * lumiere[1] + n[2] * lumiere[2]);
            return { pp, prof, couleur: couleur(f.zm, lum) };
        });
        proj.sort((p, q) => q.prof - p.prof);
        ctx.lineWidth = 0.6;
        ctx.strokeStyle = 'rgba(10,14,12,0.55)';
        for (const f of proj) {
            ctx.beginPath();
            ctx.moveTo(f.pp[0][0], f.pp[0][1]);
            for (let k = 1; k < 4; k++) {
                ctx.lineTo(f.pp[k][0], f.pp[k][1]);
            }
            ctx.closePath();
            ctx.fillStyle = f.couleur;
            ctx.fill();
            ctx.stroke();
        }

        // Courbes superposées (ligne de Kelly, seuils…).
        this.d.lignes.forEach((l) => {
            ctx.strokeStyle = l.couleur;
            ctx.lineWidth = 2;
            ctx.setLineDash(l.points.length === 2 ? [5, 4] : []);
            ctx.beginPath();
            l.points.forEach((p, i) => {
                const q = N(p[0], p[1], p[2]);
                if (i === 0) {
                    ctx.moveTo(q[0], q[1]);
                } else {
                    ctx.lineTo(q[0], q[1]);
                }
            });
            ctx.stroke();
            ctx.setLineDash([]);
        });

        this.placeLibelles(N, { presX, presY, coinZ });
    }

    libelle(cle, texte, classe) {
        let span = this.libelles.get(cle);
        if (!span) {
            span = document.createElement('span');
            span.className = `doc-label ${classe}`;
            span.textContent = texte;
            if (cle === 'titre') {
                span.classList.add('doc-3d__title');
            }
            this.couche.append(span);
            this.libelles.set(cle, span);
        }
        return span;
    }

    pose(span, x, y) {
        span.style.left = `${((x / this.W) * 100).toFixed(2)}%`;
        span.style.top = `${((y / this.H) * 100).toFixed(2)}%`;
    }

    placeLibelles(N, { presX, presY, coinZ }) {
        const { zmin, zmax } = this.d;
        const { x0, x1, y0, y1 } = this.bornes;
        const milieu = this.W / 2;
        const centre = N((x0 + x1) / 2, (y0 + y1) / 2, zmin);
        // Décale un point vers l'extérieur de la surface (direction centre → point, à l'écran).
        const ecarte = (q, dist) => {
            const dx = q[0] - centre[0];
            const dy = q[1] - centre[1];
            const l = Math.hypot(dx, dy) || 1;
            return [q[0] + (dx / l) * dist, q[1] + (dy / l) * dist];
        };
        this.d.xticks.forEach(({ v, label }, i) => {
            this.pose(this.libelle(`x${i}`, label, 'is-muted'), ...ecarte(N(v, presY, zmin), 20));
        });
        this.d.yticks.forEach(({ v, label }, i) => {
            this.pose(this.libelle(`y${i}`, label, 'is-muted'), ...ecarte(N(presX, v, zmin), 22));
        });
        this.d.zticks.forEach(({ v, label }, i) => {
            const q = N(coinZ[0], coinZ[1], v);
            const span = this.libelle(`z${i}`, label, 'is-muted doc-label--end');
            this.pose(span, q[0] - 12, q[1]);
        });
        this.pose(this.libelle('xlab', this.d.xlab, ''), ...ecarte(N((x0 + x1) / 2, presY, zmin), 58));
        this.pose(this.libelle('ylab', this.d.ylab, ''), ...ecarte(N(presX, (y0 + y1) / 2, zmin), 62));
        const haut = N(coinZ[0], coinZ[1], zmax);
        this.pose(this.libelle('zlab', this.d.zlab, 'doc-label--start'), haut[0] - 8, haut[1] - 18);
        this.pose(this.libelle('titre', this.d.titre, 'is-muted'), milieu, this.H * 0.05);
        this.evitements();
    }

    /** Masque les libellés qui en recouvrent un plus prioritaire (titres, puis axe vertical, puis x, puis y). */
    evitements() {
        const rang = (cle) => {
            if (!/^[xyz]\d/.test(cle)) {
                return 0;
            }
            return { z: 1, x: 2, y: 3 }[cle[0]];
        };
        const ordre = [...this.libelles.entries()].sort((a, b) => rang(a[0]) - rang(b[0]));
        const cadre = this.couche.getBoundingClientRect();
        const gardes = [];
        for (const [, span] of ordre) {
            span.style.visibility = '';
            span.style.translate = '';
            let r = span.getBoundingClientRect();
            // Ramène dans le cadre un libellé qui déborderait à gauche ou à droite.
            const dx = r.left < cadre.left + 4 ? cadre.left + 4 - r.left : r.right > cadre.right - 4 ? cadre.right - 4 - r.right : 0;
            if (dx) {
                span.style.translate = `${dx}px 0`;
                r = span.getBoundingClientRect();
            }
            const touche = gardes.some((g) => r.left < g.right + 2 && r.right > g.left - 2 && r.top < g.bottom && r.bottom > g.top);
            if (touche) {
                span.style.visibility = 'hidden';
            } else {
                gardes.push(r);
            }
        }
    }
}

export function initSurfaces(racine = document) {
    racine.querySelectorAll('figure.doc-3d:not(.is-live)').forEach((figure) => {
        const d = lireDonnees(figure);
        if (d && figure.querySelector('.doc-diagram__canvas') && typeof ResizeObserver === 'function') {
            new Surface3D(figure, d);
        }
    });
}

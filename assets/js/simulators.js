// Simulateurs interactifs des articles : <div class="doc-sim doc-sim--TYPE"> … </div>.
// Le contenu d'origine du bloc (un exemple chiffré statique) sert de repli sans JavaScript ; ce module
// le remplace par des curseurs, des résultats et, selon le type, un graphique ou un bouton « Relancer ».

const nf = (v, d = 0) => v.toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d });
const euros = (v) => `${nf(v)} €`;
const pct = (v, d = 1) => `${nf(v * 100, d)} %`;
const signe = (v, d) => `${v >= 0 ? '+' : ''}${nf(v, d)}`;

/** Fonction d'erreur (approximation d'Abramowitz et Stegun, erreur < 1,5e-7). */
function erf(x) {
    const t = 1 / (1 + 0.3275911 * Math.abs(x));
    const y = 1 - (((((1.061405429 * t - 1.453152027) * t) + 1.421413741) * t - 0.284496736) * t + 0.254829592) * t * Math.exp(-x * x);
    return x >= 0 ? y : -y;
}

/** Générateur pseudo-aléatoire reproductible (mulberry32) et tirage gaussien. */
function aleatoire(graine) {
    let a = graine >>> 0;
    const u = () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
    const gauss = () => Math.sqrt(-2 * Math.log(1 - u())) * Math.cos(2 * Math.PI * u());
    return { u, gauss };
}

/** Graphique en lignes minimal sur canvas : séries [{ points: [[x, y]], couleur, epaisseur }]. */
function trace(canvas, series, { ymin, ymax, ligneZero = null, etiquette = (v) => nf(v) } = {}) {
    const largeur = canvas.clientWidth;
    const hauteur = Math.round(largeur * 0.42);
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = largeur * dpr;
    canvas.height = hauteur * dpr;
    canvas.style.height = `${hauteur}px`;
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, largeur, hauteur);
    const tous = series.flatMap((s) => s.points);
    const xmin = Math.min(...tous.map((p) => p[0]));
    const xmax = Math.max(...tous.map((p) => p[0]));
    const y0 = ymin ?? Math.min(...tous.map((p) => p[1]));
    const y1 = ymax ?? Math.max(...tous.map((p) => p[1]));
    const g = 56;
    const d = 12;
    const h = 12;
    const b = 22;
    const X = (x) => g + ((x - xmin) / (xmax - xmin || 1)) * (largeur - g - d);
    const Y = (y) => hauteur - b - ((y - y0) / (y1 - y0 || 1)) * (hauteur - h - b);
    ctx.font = '11px system-ui, sans-serif';
    ctx.fillStyle = '#A8B3AE';
    ctx.strokeStyle = 'rgba(168,179,174,0.18)';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const v = y0 + ((y1 - y0) * i) / 4;
        ctx.beginPath();
        ctx.moveTo(g, Y(v));
        ctx.lineTo(largeur - d, Y(v));
        ctx.stroke();
        ctx.textAlign = 'right';
        ctx.fillText(etiquette(v), g - 6, Y(v) + 4);
    }
    if (ligneZero !== null) {
        ctx.strokeStyle = 'rgba(200,106,92,0.8)';
        ctx.setLineDash([5, 4]);
        ctx.beginPath();
        ctx.moveTo(g, Y(ligneZero));
        ctx.lineTo(largeur - d, Y(ligneZero));
        ctx.stroke();
        ctx.setLineDash([]);
    }
    series.forEach((s) => {
        ctx.strokeStyle = s.couleur;
        ctx.lineWidth = s.epaisseur || 1.5;
        ctx.beginPath();
        s.points.forEach(([x, y], i) => (i ? ctx.lineTo(X(x), Y(y)) : ctx.moveTo(X(x), Y(y))));
        ctx.stroke();
    });
}

// ── Définition des simulateurs ────────────────────────────────────────────────
// curseurs : [clé, libellé, min, max, pas, défaut, format, saisieMax?]
// La valeur peut aussi être tapée (bouton sur la valeur affichée), entre min et saisieMax (par défaut max).
const SIMULATEURS = {
    'taille-position': {
        titre: 'Calculateur de taille de position',
        curseurs: [
            ['capital', 'Capital du compte', 1000, 100000, 500, 10000, euros],
            ['risque', 'Risque par trade', 0.0025, 0.03, 0.0025, 0.01, (v) => pct(v, 2)],
            ['stop', 'Distance du stop (en points)', 5, 200, 1, 40, (v) => `${nf(v)} points`],
            ['valeur', 'Valeur d\'un point pour 1 unité', 0.1, 50, 0.1, 1, (v) => `${nf(v, 1)} €`],
            ['glissement', 'Glissement possible au stop', 0, 50, 1, 2, (v) => `${nf(v)} points`],
            ['positions', 'Positions ouvertes en même temps', 1, 5, 1, 1, (v) => nf(v)],
            ['lot', 'Arrondi de la quantité', 'choix', [[0.01, '0,01'], [0.1, '0,1'], [1, '1']], 0.01],
        ],
        calcule: ({ capital, risque, stop, valeur, glissement, positions, lot }) => {
            const montant = capital * risque;
            const brute = montant / (stop * valeur);
            const quantite = Math.floor(brute / lot) * lot;
            const perteReelle = quantite * (stop + glissement) * valeur;
            const lignes = [
                ['Montant visé si le stop est touché', euros(montant)],
                ['Quantité à prendre (arrondie vers le bas)', quantite > 0 ? `${nf(quantite, lot < 1 ? (lot < 0.1 ? 2 : 1) : 0)} unités` : 'trop petite : réduisez le stop ou changez d\'instrument'],
                ['Perte réelle avec le glissement', `${euros(perteReelle)} (${pct(perteReelle / capital, 2)})`],
                ['Risque total si toutes les positions perdent', `${euros(perteReelle * positions)} (${pct((perteReelle * positions) / capital, 1)})`],
                ['Pertes consécutives pour perdre 20 % du compte', nf(Math.ceil(Math.log(0.8) / Math.log(1 - risque)))],
            ];
            return lignes;
        },
    },
    'seuil-rentabilite': {
        titre: 'Ratio gain/perte, taux de réussite et espérance',
        curseurs: [
            ['ratio', 'Gain moyen / perte moyenne (R)', 0.5, 5, 0.1, 2, (v) => `${nf(v, 1)} R`],
            ['reussite', 'Taux de réussite', 0.1, 0.8, 0.01, 0.4, (v) => pct(v, 0)],
            ['couts', 'Coûts par trade (en R)', 0, 0.3, 0.01, 0.05, (v) => `${nf(v, 2)} R`],
            ['trades', 'Nombre de trades', 10, 1000, 10, 100, (v) => nf(v), 5000],
        ],
        calcule: ({ ratio, reussite, couts, trades }) => {
            const esperance = reussite * ratio - (1 - reussite) - couts;
            const seuil = (1 + couts) / (1 + ratio);
            const ecart = Math.sqrt(reussite * (ratio + 1) ** 2 * (1 - reussite));
            const z = (esperance * trades) / (ecart * Math.sqrt(trades));
            const enPerte = 0.5 * (1 + erf(-z / Math.SQRT2));
            return [
                ['Taux de réussite minimal pour être rentable', pct(seuil)],
                ['Espérance par trade', `${signe(esperance, 2)} R`],
                [`Résultat moyen sur ${nf(trades)} trades`, `${signe(esperance * trades, 0)} R`],
                [`Probabilité d'être en perte après ${nf(trades)} trades`, pct(enPerte, 0)],
            ];
        },
        legende: 'Probabilité calculée avec l\'approximation normale : indicative pour de petits nombres de trades.',
    },
    ruine: {
        titre: 'Simulateur de drawdown (500 comptes simulés)',
        relance: true,
        graphique: true,
        curseurs: [
            ['reussite', 'Taux de réussite', 0.2, 0.7, 0.01, 0.4, (v) => pct(v, 0)],
            ['ratio', 'Gain moyen (R)', 0.5, 4, 0.1, 2, (v) => `${nf(v, 1)} R`],
            ['risque', 'Risque par trade', 0.0025, 0.1, 0.0025, 0.01, (v) => pct(v, 2)],
            ['trades', 'Nombre de trades', 50, 1000, 10, 200, (v) => nf(v), 5000],
            ['seuil', 'Baisse jugée critique', 0.1, 0.6, 0.05, 0.3, (v) => pct(v, 0)],
            ['couts', 'Coûts par trade (en R)', 0, 0.3, 0.01, 0, (v) => `${nf(v, 2)} R`],
            ['mode', 'Taille du risque', 'choix', [['pct', '% du capital actuel'], ['fixe', 'montant fixe']], 'pct'],
            ['arret', 'Arrêt après la baisse critique', 'choix', [['non', 'non'], ['oui', 'oui']], 'non'],
        ],
        calcule: ({ reussite, ratio, risque, trades, seuil, couts, mode, arret }, { graine, canvas }) => {
            const r = aleatoire(graine);
            const N = 500;
            let touche = 0;
            let arretes = 0;
            const finaux = [];
            const exemples = [];
            for (let k = 0; k < N; k++) {
                let c = 1;
                let pic = 1;
                let pire = 0;
                let stoppe = false;
                const pts = [[0, 100]];
                for (let t = 1; t <= trades; t++) {
                    if (!stoppe) {
                        const mise = mode === 'pct' ? c * risque : risque;
                        c += r.u() < reussite ? mise * (ratio - couts) : -mise * (1 + couts);
                        c = Math.max(c, 0);
                        pic = Math.max(pic, c);
                        pire = Math.min(pire, c / pic - 1);
                        if (arret === 'oui' && pire <= -seuil) {
                            stoppe = true;
                        }
                    }
                    if (k < 12) {
                        pts.push([t, c * 100]);
                    }
                }
                touche += pire <= -seuil;
                arretes += stoppe;
                finaux.push(c);
                if (k < 12) {
                    exemples.push(pts);
                }
            }
            finaux.sort((a, b) => a - b);
            trace(canvas, exemples.map((p) => ({ points: p, couleur: 'rgba(185,198,230,0.55)' })), { ligneZero: 100 });
            const lignes = [
                [`Comptes passés par une baisse de ${pct(seuil, 0)} ou plus`, pct(touche / N, 0)],
                ['Capital final médian (départ 100)', nf(finaux[N / 2] * 100)],
                ['Capital final : 10 % des comptes font moins de', nf(finaux[Math.floor(N * 0.1)] * 100)],
                ['Comptes finissant en perte', pct(finaux.filter((v) => v < 1).length / N, 0)],
            ];
            if (arret === 'oui') {
                lignes.push(['Comptes arrêtés par la règle', pct(arretes / N, 0)]);
            }
            return lignes;
        },
        legende: 'En montant fixe, chaque trade risque le même montant qu\'au départ, quel que soit le capital restant.',
    },
    'interets-composes': {
        titre: 'Simulateur d\'intérêts composés',
        graphique: true,
        curseurs: [
            ['initial', 'Capital de départ', 0, 100000, 1000, 0, euros],
            ['versement', 'Versement mensuel', 0, 2000, 25, 200, euros],
            ['rendement', 'Rendement annuel brut', 0, 0.1, 0.0025, 0.06, (v) => pct(v)],
            ['frais', 'Frais annuels', 0, 0.025, 0.001, 0.002, (v) => pct(v)],
            ['inflation', 'Inflation annuelle', 0, 0.06, 0.0025, 0.02, (v) => pct(v)],
            ['annees', 'Durée', 1, 45, 1, 25, (v) => `${nf(v)} ans`],
            ['fiscalite', 'Imposition des gains à la sortie', 'choix', [[0, 'aucune'], [0.186, '18,6 % (PEA)'], [0.314, '31,4 % (compte-titres)']], 0.186],
        ],
        calcule: ({ initial, versement, rendement, frais, inflation, annees, fiscalite }, { canvas }) => {
            const courbe = (taux) => {
                const pts = [[0, initial]];
                let c = initial;
                for (let m = 1; m <= annees * 12; m++) {
                    c = c * (1 + taux) ** (1 / 12) + versement;
                    if (m % 12 === 0) {
                        pts.push([m / 12, c]);
                    }
                }
                return pts;
            };
            const net = courbe(rendement - frais);
            const verse = net.map(([x]) => [x, initial + versement * 12 * x]);
            const sansFrais = courbe(rendement);
            trace(canvas, [
                { points: verse, couleur: '#A8B3AE' },
                { points: sansFrais, couleur: 'rgba(79,157,126,0.5)' },
                { points: net, couleur: '#E2CB91', epaisseur: 2.2 },
            ], { ymin: 0, etiquette: (v) => `${nf(v / 1000)} k€` });
            const fin = net[net.length - 1][1];
            const total = initial + versement * 12 * annees;
            const impot = Math.max(fin - total, 0) * fiscalite;
            const apresImpot = fin - impot;
            return [
                ['Total versé', euros(total)],
                ['Capital final (après frais)', euros(fin)],
                ['Coût des frais sur la période', euros(sansFrais[sansFrais.length - 1][1] - fin)],
                ['Impôt sur les gains à la sortie', euros(impot)],
                ['Net après impôt', euros(apresImpot)],
                ['Pouvoir d\'achat en euros d\'aujourd\'hui', euros(apresImpot / (1 + inflation) ** annees)],
            ];
        },
        legende: 'Gris : versements cumulés. Vert : sans frais. Or : après frais. Fiscalité simplifiée, prélevée en une fois à la sortie.',
    },
    kelly: {
        titre: 'Croissance selon la fraction risquée (critère de Kelly)',
        graphique: true,
        curseurs: [
            ['reussite', 'Taux de réussite', 0.2, 0.8, 0.01, 0.4, (v) => pct(v, 0)],
            ['ratio', 'Gain moyen (R)', 0.5, 5, 0.1, 2, (v) => `${nf(v, 1)} R`],
            ['choisie', 'Fraction que vous risquez', 0.0025, 0.4, 0.0025, 0.02, (v) => pct(v, 2)],
            ['erreur', 'Surestimation de votre taux de réussite', 0, 0.15, 0.01, 0, (v) => `${nf(v * 100, 0)} points`],
        ],
        calcule: ({ reussite, ratio, choisie, erreur }, { canvas }) => {
            // Le taux affiché est celui que vous croyez avoir ; le vrai est inférieur de « erreur ».
            const vrai = Math.max(reussite - erreur, 0.01);
            const g = (p, f) => p * Math.log(1 + ratio * f) + (1 - p) * Math.log(1 - f);
            const kellyCru = (reussite * (ratio + 1) - 1) / ratio;
            const kellyVrai = (vrai * (ratio + 1) - 1) / ratio;
            const pts = [];
            const ptsVrai = [];
            for (let f = 0; f <= 0.6; f += 0.005) {
                pts.push([f * 100, Math.max(g(reussite, f) * 100, -5)]);
                ptsVrai.push([f * 100, Math.max(g(vrai, f) * 100, -5)]);
            }
            const series = [{ points: pts, couleur: '#E2CB91', epaisseur: 2.2 }];
            if (erreur > 0) {
                series.push({ points: ptsVrai, couleur: '#E89A90', epaisseur: 2 });
            }
            const haut = Math.max(...pts.map((q) => q[1]));
            series.push({ points: [[choisie * 100, -5], [choisie * 100, haut]], couleur: 'rgba(185,198,230,0.8)' });
            trace(canvas, series, { ligneZero: 0, etiquette: (v) => `${nf(v, 1)} %` });
            return [
                ['Fraction de Kelly selon votre estimation', kellyCru > 0 ? pct(kellyCru) : 'aucune (espérance négative)'],
                ['Fraction de Kelly réelle', kellyVrai > 0 ? pct(kellyVrai) : 'aucune (espérance négative)'],
                ['Croissance réelle par trade à votre fraction', pct(g(vrai, choisie), 3)],
                ['Votre fraction en multiple du Kelly réel', kellyVrai > 0 ? `${nf(choisie / kellyVrai, 2)} ×` : '—'],
            ];
        },
        legende: 'Or : croissance si votre estimation est juste. Rouge : croissance réelle avec la surestimation choisie. Trait bleu : votre fraction.',
    },
    'marche-aleatoire': {
        titre: 'Trajectoires de pur hasard',
        relance: true,
        graphique: true,
        curseurs: [
            ['nombre', 'Nombre de trajectoires', 1, 30, 1, 10, (v) => nf(v)],
            ['vol', 'Volatilité annuelle', 0.05, 0.6, 0.01, 0.2, (v) => pct(v, 0)],
            ['tendance', 'Rendement moyen annuel', -0.1, 0.15, 0.01, 0.07, (v) => pct(v, 0)],
            ['annees', 'Durée', 1, 10, 1, 3, (v) => `${nf(v)} ans`],
            ['loi', 'Variations quotidiennes', 'choix', [['normale', 'loi normale'], ['epaisse', 'queues épaisses']], 'normale'],
        ],
        calcule: ({ nombre, vol, tendance, annees, loi }, { graine, canvas }) => {
            const r = aleatoire(graine);
            // Student à 3 degrés de liberté, ramenée à une variance de 1.
            const choc = () => {
                if (loi === 'normale') {
                    return r.gauss();
                }
                const z = r.gauss();
                const c = (r.gauss() ** 2 + r.gauss() ** 2 + r.gauss() ** 2) / 3;
                return z / Math.sqrt(c) / Math.sqrt(3);
            };
            const jours = annees * 252;
            const series = [];
            let plusBas = 0;
            let pireJour = 0;
            for (let k = 0; k < nombre; k++) {
                let p = 100;
                const pts = [[0, p]];
                for (let t = 1; t <= jours; t++) {
                    const x = choc();
                    pireJour = Math.min(pireJour, x);
                    p *= Math.exp((tendance - vol * vol / 2) / 252 + (vol / Math.sqrt(252)) * x);
                    pts.push([t / 252, p]);
                }
                plusBas += p < 100;
                series.push({ points: pts, couleur: `hsla(${30 + k * 37}, 45%, 70%, 0.75)` });
            }
            trace(canvas, series, { ligneZero: 100 });
            return [
                ['Trajectoires finissant sous leur point de départ', `${plusBas} sur ${nombre}`],
                ['Pire journée du tirage', `${nf(pireJour, 1)} écarts-types`],
                ['À retenir', 'tendances, sommets et « supports » apparaissent ici sans aucune cause'],
            ];
        },
    },
    'series-pertes': {
        titre: 'Séries de pertes d\'une stratégie gagnante',
        relance: true,
        graphique: true,
        curseurs: [
            ['reussite', 'Taux de réussite', 0.25, 0.75, 0.01, 0.4, (v) => pct(v, 0)],
            ['ratio', 'Gain moyen (R)', 0.5, 4, 0.1, 2, (v) => `${nf(v, 1)} R`],
            ['trades', 'Nombre de trades', 50, 1000, 10, 200, (v) => nf(v), 5000],
            ['couts', 'Coûts par trade (en R)', 0, 0.3, 0.01, 0, (v) => `${nf(v, 2)} R`],
        ],
        calcule: ({ reussite, ratio, trades, couts }, { graine, canvas }) => {
            const r = aleatoire(graine);
            const tirage = (garder) => {
                let cumul = 0;
                let serie = 0;
                let pire = 0;
                const pts = [[0, 0]];
                for (let t = 1; t <= trades; t++) {
                    if (r.u() < reussite) {
                        cumul += ratio - couts;
                        serie = 0;
                    } else {
                        cumul -= 1 + couts;
                        serie += 1;
                        pire = Math.max(pire, serie);
                    }
                    if (garder) {
                        pts.push([t, cumul]);
                    }
                }
                return { cumul, pire, pts };
            };
            const affiche = tirage(true);
            const series = [];
            for (let k = 0; k < 1000; k++) {
                series.push(tirage(false).pire);
            }
            series.sort((a, b) => a - b);
            trace(canvas, [{ points: affiche.pts, couleur: '#E2CB91', epaisseur: 2 }], { ligneZero: 0, etiquette: (v) => `${nf(v)} R` });
            const esperance = reussite * ratio - (1 - reussite) - couts;
            return [
                ['Plus longue série de pertes de ce tirage', `${affiche.pire} d'affilée`],
                ['Sur 1 000 tirages : série médiane', `${series[500]} d'affilée`],
                ['Sur 1 000 tirages : atteinte une fois sur dix', `${series[900]} ou plus`],
                ['Résultat de ce tirage', `${signe(affiche.cumul, 1)} R`],
                ['Résultat attendu en moyenne', `${signe(esperance * trades, 0)} R`],
            ];
        },
    },
    comparateur: {
        titre: 'Comparateur de deux stratégies (500 simulations chacune)',
        relance: true,
        graphique: true,
        curseurs: [
            ['reussiteA', 'Stratégie A : taux de réussite', 0.2, 0.8, 0.01, 0.6, (v) => pct(v, 0)],
            ['ratioA', 'Stratégie A : gain moyen (R)', 0.3, 4, 0.1, 0.8, (v) => `${nf(v, 1)} R`],
            ['reussiteB', 'Stratégie B : taux de réussite', 0.2, 0.8, 0.01, 0.35, (v) => pct(v, 0)],
            ['ratioB', 'Stratégie B : gain moyen (R)', 0.3, 4, 0.1, 2.5, (v) => `${nf(v, 1)} R`],
            ['risque', 'Risque par trade (les deux)', 0.0025, 0.05, 0.0025, 0.01, (v) => pct(v, 2)],
            ['trades', 'Nombre de trades', 50, 1000, 10, 200, (v) => nf(v), 5000],
        ],
        calcule: ({ reussiteA, ratioA, reussiteB, ratioB, risque, trades }, { graine, canvas }) => {
            const r = aleatoire(graine);
            const N = 500;
            const simule = (p, R, garder) => {
                let c = 1;
                let pic = 1;
                let pire = 0;
                const pts = [[0, 100]];
                for (let t = 1; t <= trades; t++) {
                    c *= r.u() < p ? 1 + risque * R : 1 - risque;
                    pic = Math.max(pic, c);
                    pire = Math.min(pire, c / pic - 1);
                    if (garder) {
                        pts.push([t, c * 100]);
                    }
                }
                return { c, pire, pts };
            };
            const A = [];
            const B = [];
            let aDevant = 0;
            for (let k = 0; k < N; k++) {
                const a = simule(reussiteA, ratioA, k === 0);
                const b = simule(reussiteB, ratioB, k === 0);
                A.push(a);
                B.push(b);
                aDevant += a.c > b.c;
            }
            const mediane = (liste, f) => {
                const v = liste.map(f).sort((x, y) => x - y);
                return v[Math.floor(v.length / 2)];
            };
            trace(canvas, [
                { points: A[0].pts, couleur: '#B9C6E6', epaisseur: 2 },
                { points: B[0].pts, couleur: '#E2CB91', epaisseur: 2 },
            ], { ligneZero: 100 });
            const espA = reussiteA * ratioA - (1 - reussiteA);
            const espB = reussiteB * ratioB - (1 - reussiteB);
            return [
                ['Espérance par trade : A / B', `${signe(espA, 2)} R / ${signe(espB, 2)} R`],
                ['Capital final médian : A / B (départ 100)', `${nf(mediane(A, (x) => x.c * 100))} / ${nf(mediane(B, (x) => x.c * 100))}`],
                ['Pire baisse médiane : A / B', `${pct(-mediane(A, (x) => x.pire), 0)} / ${pct(-mediane(B, (x) => x.pire), 0)}`],
                ['Simulations où A finit devant B', pct(aDevant / N, 0)],
            ];
        },
        legende: 'Bleu : un tirage de la stratégie A. Or : un tirage de la stratégie B. Les résultats chiffrés portent sur 500 tirages de chacune. Par défaut, A gagne souvent mais peu, B rarement mais beaucoup.',
    },
};

class Simulateur {
    constructor(bloc, type, def) {
        this.def = def;
        this.valeurs = {};
        this.defauts = [];
        this.graine = 1;
        bloc.replaceChildren();
        bloc.classList.add('is-live');

        const titre = document.createElement('p');
        titre.className = 'doc-sim__title';
        titre.textContent = def.titre;
        const grille = document.createElement('div');
        grille.className = 'doc-sim__controls';
        def.curseurs.forEach((ctrl) => {
            if (ctrl[2] === 'choix') {
                grille.append(this.choix(ctrl));
                return;
            }
            grille.append(this.curseur(type, ctrl));
        });
        this.resultats = document.createElement('dl');
        this.resultats.className = 'doc-sim__results';
        this.resultats.setAttribute('aria-live', 'polite');
        bloc.append(titre, grille, this.resultats);
        if (def.graphique) {
            this.canvas = document.createElement('canvas');
            this.canvas.className = 'doc-sim__chart';
            this.canvas.setAttribute('role', 'img');
            this.canvas.setAttribute('aria-label', `Graphique : ${def.titre}`);
            bloc.append(this.canvas);
            new ResizeObserver(() => this.demande()).observe(this.canvas);
        }
        if (def.legende) {
            const l = document.createElement('p');
            l.className = 'doc-sim__legend';
            l.textContent = def.legende;
            bloc.append(l);
        }
        const actions = document.createElement('div');
        actions.className = 'doc-sim__actions';
        const raz = document.createElement('button');
        raz.type = 'button';
        raz.className = 'doc-pas__btn';
        raz.textContent = 'Valeurs par défaut';
        raz.addEventListener('click', () => {
            this.defauts.forEach((f) => f());
            this.demande();
        });
        actions.append(raz);
        if (def.relance) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'doc-pas__btn';
            b.textContent = 'Relancer le tirage';
            b.addEventListener('click', () => {
                this.graine += 1;
                this.demande();
            });
            actions.prepend(b);
        }
        bloc.append(actions);
        this.demande();
    }

    /**
     * Curseur + valeur affichée. La valeur est un bouton : un clic ouvre un champ pour taper
     * une valeur précise (y compris au-delà du curseur, jusqu'à saisieMax).
     */
    curseur(type, [cle, libelle, min, max, pas, defaut, format, saisieMax = max]) {
        const id = `sim-${type}-${cle}-${Math.random().toString(36).slice(2, 7)}`;
        const ligne = document.createElement('div');
        ligne.className = 'doc-sim__control';
        const label = document.createElement('label');
        label.htmlFor = id;
        label.textContent = libelle;
        const sortie = document.createElement('button');
        sortie.type = 'button';
        sortie.className = 'doc-sim__value';
        sortie.title = 'Saisir une valeur précise';
        const champ = document.createElement('input');
        Object.assign(champ, { type: 'number', min, max: saisieMax, step: 'any', className: 'doc-sim__input', hidden: true });
        champ.setAttribute('aria-label', `${libelle} : valeur précise (de ${format(min)} à ${format(saisieMax)})`);
        const curseur = document.createElement('input');
        Object.assign(curseur, { type: 'range', id, min, max, step: pas, value: defaut });
        this.valeurs[cle] = defaut;
        this.defauts.push(() => fixe(defaut));

        const fixe = (v) => {
            this.valeurs[cle] = v;
            curseur.value = String(Math.min(v, max));
            sortie.textContent = format(v);
            sortie.setAttribute('aria-label', `${libelle} : ${format(v)}. Modifier la valeur`);
            this.demande();
        };
        const ouvre = () => {
            champ.value = String(this.valeurs[cle]);
            sortie.hidden = true;
            champ.hidden = false;
            champ.focus();
            champ.select();
        };
        const ferme = (valider) => {
            if (champ.hidden) {
                return;
            }
            const v = Number(champ.value.replace(',', '.'));
            if (valider && champ.value !== '' && Number.isFinite(v)) {
                fixe(Math.min(saisieMax, Math.max(min, v)));
            }
            champ.hidden = true;
            sortie.hidden = false;
            sortie.focus();
        };
        sortie.addEventListener('click', ouvre);
        champ.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                ferme(true);
            } else if (e.key === 'Escape') {
                ferme(false);
            }
        });
        champ.addEventListener('blur', () => ferme(true));
        curseur.addEventListener('input', () => fixe(Number(curseur.value)));
        sortie.textContent = format(defaut);
        sortie.setAttribute('aria-label', `${libelle} : ${format(defaut)}. Modifier la valeur`);
        ligne.append(label, sortie, champ, curseur);
        return ligne;
    }

    /** Groupe de boutons à choix unique : [clé, libellé, 'choix', [[valeur, texte], …], défaut]. */
    choix([cle, libelle, , options, defaut]) {
        const groupe = document.createElement('div');
        groupe.className = 'doc-sim__control doc-sim__choice';
        groupe.setAttribute('role', 'radiogroup');
        const titre = document.createElement('span');
        titre.className = 'doc-sim__choice-label';
        titre.textContent = libelle;
        groupe.setAttribute('aria-label', libelle);
        const boutons = document.createElement('div');
        boutons.className = 'doc-sim__choice-options';
        this.valeurs[cle] = defaut;
        const majBoutons = () => boutons.querySelectorAll('button').forEach((b) => {
            const actif = b.dataset.valeur === String(this.valeurs[cle]);
            b.setAttribute('aria-checked', String(actif));
            b.classList.toggle('is-active', actif);
        });
        options.forEach(([valeur, texte]) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'radio');
            b.dataset.valeur = String(valeur);
            b.textContent = texte;
            b.addEventListener('click', () => {
                this.valeurs[cle] = valeur;
                majBoutons();
                this.demande();
            });
            boutons.append(b);
        });
        majBoutons();
        this.defauts.push(() => {
            this.valeurs[cle] = defaut;
            majBoutons();
        });
        groupe.append(titre, boutons);
        return groupe;
    }

    demande() {
        if (this.attente) {
            return;
        }
        this.attente = true;
        requestAnimationFrame(() => {
            this.attente = false;
            const lignes = this.def.calcule(this.valeurs, { graine: this.graine * 7919, canvas: this.canvas });
            this.resultats.replaceChildren(
                ...lignes.flatMap(([cle, valeur]) => {
                    const dt = document.createElement('dt');
                    dt.textContent = cle;
                    const dd = document.createElement('dd');
                    dd.textContent = valeur;
                    return [dt, dd];
                }),
            );
        });
    }
}

export function initSimulateurs(racine = document) {
    racine.querySelectorAll('.doc-sim:not(.is-live)').forEach((bloc) => {
        const type = [...bloc.classList].find((c) => c.startsWith('doc-sim--'))?.slice(9);
        if (type && SIMULATEURS[type] && typeof ResizeObserver === 'function') {
            new Simulateur(bloc, type, SIMULATEURS[type]);
        }
    });
}

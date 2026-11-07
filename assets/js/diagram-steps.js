// Schémas « pas à pas » des articles de documentation.
//
// Une <figure class="doc-pas"> contient un schéma dont les éléments (formes SVG et libellés HTML)
// portent une classe pas-1, pas-2… et une liste <ol class="doc-pas-texte"> qui décrit chaque étape.
// Ce module ajoute des boutons pour révéler le schéma étape par étape. Sans JavaScript, le schéma
// est affiché en entier et la liste des étapes reste lisible sous la figure.

function etapeDe(el) {
    const m = (el.getAttribute('class') || '').match(/(?:^|\s)pas-(\d+)(?:\s|$)/);
    return m ? Number(m[1]) : 0;
}

class SchemaPasAPas {
    constructor(figure) {
        this.figure = figure;
        this.elements = [...figure.querySelectorAll('[class*="pas-"]')].filter((el) => etapeDe(el) > 0);
        this.textes = [...figure.querySelectorAll('.doc-pas-texte > li')].map((li) => li.textContent.trim());
        this.total = Math.max(this.textes.length, ...this.elements.map(etapeDe));
        this.etape = 1;

        const barre = document.createElement('div');
        barre.className = 'doc-pas__bar';
        this.precedent = this.bouton('Précédent', () => this.va(this.etape - 1));
        this.suivant = this.bouton('Étape suivante', () => this.va(this.etape + 1));
        this.compteur = document.createElement('span');
        this.compteur.className = 'doc-pas__count';
        this.tout = this.bouton('Tout afficher', () => this.va(this.total));
        barre.append(this.precedent, this.compteur, this.suivant, this.tout);

        this.texte = document.createElement('p');
        this.texte.className = 'doc-pas__text';
        this.texte.setAttribute('aria-live', 'polite');

        const zone = figure.querySelector('.doc-diagram__canvas');
        zone.after(barre, this.texte);
        figure.classList.add('is-live');
        this.va(1);
    }

    bouton(libelle, action) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'doc-pas__btn';
        b.textContent = libelle;
        b.addEventListener('click', action);
        return b;
    }

    va(n) {
        this.etape = Math.min(Math.max(n, 1), this.total);
        this.elements.forEach((el) => {
            el.classList.toggle('is-hidden-step', etapeDe(el) > this.etape);
            el.classList.toggle('is-current-step', etapeDe(el) === this.etape);
        });
        this.compteur.textContent = `Étape ${this.etape} / ${this.total}`;
        this.texte.textContent = this.textes[this.etape - 1] || '';
        this.precedent.disabled = this.etape === 1;
        this.suivant.disabled = this.etape === this.total;
        this.tout.disabled = this.etape === this.total;
    }
}

export function initSchemas(racine = document) {
    racine.querySelectorAll('figure.doc-pas:not(.is-live)').forEach((figure) => {
        if (figure.querySelector('.doc-diagram__canvas')) {
            new SchemaPasAPas(figure);
        }
    });
}

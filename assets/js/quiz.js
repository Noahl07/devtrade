// Quiz interactifs des articles.
// Structure dans l'article :
// <div class="doc-quiz"><ol>
//   <li><p>Question</p><ul><li>Option</li><li class="is-correct">Bonne option</li></ul>
//       <p class="doc-quiz__explication">Explication</p></li>
// </ol></div>
// Sans JavaScript, questions, options et explications restent lisibles.

class Quiz {
    constructor(bloc) {
        this.bloc = bloc;
        this.questions = [...bloc.querySelectorAll(':scope > ol > li')].map((li) => ({
            enonce: li.querySelector(':scope > p:not(.doc-quiz__explication)')?.textContent.trim() || '',
            options: [...li.querySelectorAll(':scope > ul > li')].map((o) => ({ texte: o.textContent.trim(), juste: o.classList.contains('is-correct') })),
            explication: li.querySelector('.doc-quiz__explication')?.textContent.trim() || '',
        })).filter((q) => q.options.some((o) => o.juste));
        if (!this.questions.length) {
            return;
        }
        this.construit();
    }

    construit() {
        this.bloc.replaceChildren();
        this.bloc.classList.add('is-live');
        this.score = 0;
        this.repondues = 0;
        this.manquees = [];
        const titre = document.createElement('p');
        titre.className = 'doc-quiz__title';
        titre.textContent = `Quiz : ${this.questions.length} questions`;
        this.bloc.append(titre);
        // Options mélangées à chaque affichage : la bonne réponse ne doit pas avoir de place fixe.
        this.questions.forEach((q) => {
            for (let k = q.options.length - 1; k > 0; k--) {
                const j = Math.floor(Math.random() * (k + 1));
                [q.options[k], q.options[j]] = [q.options[j], q.options[k]];
            }
        });
        this.questions.forEach((q, i) => {
            const carte = document.createElement('fieldset');
            carte.className = 'doc-quiz__question';
            const legende = document.createElement('legend');
            legende.textContent = `${i + 1}. ${q.enonce}`;
            const options = document.createElement('div');
            options.className = 'doc-quiz__options';
            const retour = document.createElement('p');
            retour.className = 'doc-quiz__feedback';
            retour.setAttribute('aria-live', 'polite');
            q.options.forEach((o) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'doc-quiz__option';
                b.textContent = o.texte;
                b.addEventListener('click', () => {
                    options.querySelectorAll('button').forEach((x, k) => {
                        x.disabled = true;
                        if (q.options[k].juste) {
                            x.classList.add('is-right');
                        }
                    });
                    if (!o.juste) {
                        b.classList.add('is-wrong');
                    }
                    this.score += o.juste ? 1 : 0;
                    this.repondues += 1;
                    if (!o.juste) {
                        this.manquees.push({ numero: i + 1, q });
                    }
                    retour.textContent = `${o.juste ? 'Exact.' : 'Non.'} ${q.explication}`;
                    retour.classList.toggle('is-right', o.juste);
                    this.bilan();
                });
                options.append(b);
            });
            carte.append(legende, options, retour);
            this.bloc.append(carte);
        });
        this.resultat = document.createElement('div');
        this.resultat.className = 'doc-quiz__result';
        this.resultat.setAttribute('aria-live', 'polite');
        this.bloc.append(this.resultat);
    }

    bilan() {
        if (this.repondues < this.questions.length) {
            return;
        }
        const n = this.questions.length;
        const message = this.score === n
            ? 'Parfait.'
            : this.score >= n * 0.6
                ? 'Bien : relisez les explications des questions manquées.'
                : 'Relisez l\'article, puis réessayez.';
        const texte = document.createElement('p');
        texte.textContent = `Score : ${this.score} / ${n}. ${message}`;
        const elements = [texte];
        // Récapitulatif : les points à revoir, avec la bonne réponse et son explication.
        if (this.manquees.length) {
            const titre = document.createElement('p');
            titre.className = 'doc-quiz__recap-title';
            titre.textContent = 'À revoir';
            const liste = document.createElement('ul');
            liste.className = 'doc-quiz__recap';
            this.manquees.forEach(({ numero, q }) => {
                const li = document.createElement('li');
                const enonce = document.createElement('strong');
                enonce.textContent = `${numero}. ${q.enonce}`;
                const reponse = document.createElement('span');
                reponse.textContent = ` Réponse : ${q.options.find((o) => o.juste).texte}. ${q.explication}`;
                li.append(enonce, reponse);
                liste.append(li);
            });
            elements.push(titre, liste);
        }
        const recommencer = document.createElement('button');
        recommencer.type = 'button';
        recommencer.className = 'doc-pas__btn';
        recommencer.textContent = 'Recommencer le quiz';
        recommencer.addEventListener('click', () => this.construit());
        elements.push(recommencer);
        this.resultat.replaceChildren(...elements);
        // L'article enregistre le meilleur score (membres connectés).
        this.bloc.dispatchEvent(new CustomEvent('doc-quiz:termine', { bubbles: true, detail: { score: this.score, total: n } }));
    }
}

export function initQuiz(racine = document) {
    racine.querySelectorAll('.doc-quiz:not(.is-live)').forEach((bloc) => new Quiz(bloc));
}

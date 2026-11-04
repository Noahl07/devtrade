# Refonte devtrade.fr : rapport final

Branche `refonte-premium`, état au 4 novembre 2026. Rien n'a été poussé.

## Ce qui a été livré

| Phase | Contenu |
|---|---|
| 0. Audit | Sécurité, conformité des chiffres, dette technique |
| 1. Design system | Palette « Obsidienne & Or », Fraunces + Inter + JetBrains Mono auto-hébergées, tokens, composants Twig (`components/ui/_ui.html.twig`), `/styleguide` (dev uniquement) |
| 2. Layout et accueil | En-tête, menu mobile `<dialog>`, pied de page avec avertissement de risque, accueil avec scène 3D (three.js, chargée à la demande) |
| 3. Page Shiro | `/trading` : 3 backtests MT5 contextualisés, graphiques interactifs (données mensuelles uniquement), liste d'attente avec consentement RGPD |
| 4. Pages secondaires | Développement, documentation (lecture avec sommaire), contact, connexion / inscription, accès Premium, pages légales |
| 5. Finitions | Espace membre, CSP, SEO, performance, accessibilité, audits de dépendances |

## Garde-fous en place

**Véracité (règles du prompt)**
- Chaque chiffre affiche sa période, son nombre de trades, sa nature (backtest), le risque par trade et les coûts non inclus.
- Aucun témoignage, compteur ni urgence artificielle, et aucun « le plus populaire ».
- Aucun identifiant légal inventé : les champs vides s'affichent « À compléter ».
- Les tests échouent si l'un de ces éléments réapparaît.

**Protection de l'algorithme**
- Aucun détail du mécanisme publié (horaires, calendrier, règles de dates, références des études).
- Données des graphiques agrégées par mois.
- Liste de termes interdits vérifiée par les tests.

**Sécurité**
- CSRF partout et limiteurs de débit (connexion, mot de passe oublié, contact, liste d'attente).
- Pièges à robots et erreurs d'authentification génériques.
- Contenu des articles nettoyé (`|sanitize_html`).
- CSP stricte sur le site public : `script-src 'self'`, `object-src 'none'`, `frame-ancestors 'self'`.
- En-têtes Caddy (HSTS, nosniff, Referrer-Policy, Permissions-Policy).
- `php.ini` de production, et `.dockerignore` qui exclut `.git`, dump SQL, archives et `.env.local`.

## Mesures

**Web Vitals.** Mobile simulé (412 px, 4G lente 1,6 Mbit/s, latence 150 ms, CPU ralenti ×4), serveur local.

| Page | LCP | CLS |
|---|---|---|
| Accueil | 1,9 s | 0,034 |
| Shiro | 2,4 s | 0 |
| Documentation | 1,6 s | 0 |
| Contact | 1,5 s | 0 |

Seuils « bons » de Google : LCP < 2,5 s, CLS < 0,1.

**Poids.** JS + CSS initiaux : environ 54 Ko (site) et 45 Ko de plus sur la page Shiro (minifiés, non compressés). three.js (≈ 140 Ko compressés) n'est chargé que sur les appareils capables, quand le navigateur est inactif, et jamais en 2G/3G ou en mode économie de données.

**Accessibilité.** Audit automatique dans Chrome, en largeurs bureau et mobile, sur 11 pages publiques, sans aucun problème détecté. Points vérifiés :
- noms accessibles, libellés de champs, `alt` ;
- ids uniques, hiérarchie des titres, `lang` ;
- contraste AA du texte.

Le mouvement réduit est respecté et tout est utilisable au clavier, graphiques compris (flèches pour lire les valeurs).

**Dépendances.** `composer audit` et `npm audit` : 0 vulnérabilité.

**Tests.** 98 tests, 295 assertions, tous verts. `lint:container`, `lint:twig` et `lint:yaml` OK.

## Reste à faire

| Sujet | Qui | Détail |
|---|---|---|
| Identité légale | Éditeur | `config/packages/legal.yaml`, puis `legal.validated: true` après relecture juridique |
| Réalisations | Éditeur | À saisir dans l'admin ; la page Développement les affiche automatiquement |
| Admin | Dev | Encore en ancien style, scripts en ligne et Sortable chargé depuis cdnjs ; la CSP y est assouplie en attendant |
| Secrets | Éditeur | Faire tourner les secrets présents dans l'historique git (`.env`, `DOC/devtrade.sql`) |
| Historique de la branche | Éditeur | Les premiers commits de la branche contiennent encore les anciens textes détaillés sur l'algorithme : à nettoyer avant le premier push |
| Traduction | Plus tard | Remplacer la traduction automatique abandonnée par des fichiers de traduction |
| Image Docker | Dev | Tester `docker build` (non testable sur le poste de développement) ; Node 20, utilisé seulement au build, n'est plus maintenu |

## Lancer en local

```
symfony composer install
npm ci && npm run dev
symfony console doctrine:migrations:migrate
symfony serve
```

Graphiques Shiro : `python tools/shiro_charts.py` régénère `public/data/shiro/*.json` depuis le journal MT5.

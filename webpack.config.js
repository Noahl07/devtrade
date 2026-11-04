const Encore = require('@symfony/webpack-encore');

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'development');
}

Encore
    .addEntry('custom-select', './assets/js/custom-select.js')
    .setOutputPath('public/build/')
    .setPublicPath('/build')

    // Entry point principal
    // Layout et design system de la refonte : chargé sur toutes les pages
    .addEntry('site', './assets/site.js')
    // Page produit Shiro (/trading) : graphiques, onglets, scène 3D
    .addEntry('shiro', './assets/shiro.js')
    // Ancien CSS, seulement pour les pages pas encore refondues (supprimé en Phase 4)
    .addEntry('app', './assets/app.js')
    // Design system (refonte) : styleguide de dev, servi uniquement par la route app_styleguide
    .addEntry('styleguide', './assets/styleguide.js')
    // Back-office (refonte) : éditeur, réordonnancement, confirmations ; aucun script en ligne
    .addEntry('admin', './assets/admin.js')

    .splitEntryChunks()
    .enableSingleRuntimeChunk()

    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())
    // Versionnage aussi en dev : le nom du fichier change à chaque build, le navigateur ne peut pas garder une ancienne version.
    .enableVersioning()

    // PostCSS / Autoprefixer
    .enablePostCssLoader()

    // Support des imports CSS dans JS
    .configureCssLoader(() => {})
;

module.exports = Encore.getWebpackConfig();

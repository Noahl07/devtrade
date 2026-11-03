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
    // Ancien CSS, seulement pour les pages pas encore refondues (supprimé en Phase 4)
    .addEntry('app', './assets/app.js')
    // Design system (refonte) : styleguide de dev, servi uniquement par la route app_styleguide
    .addEntry('styleguide', './assets/styleguide.js')

    .splitEntryChunks()
    .enableSingleRuntimeChunk()

    .cleanupOutputBeforeBuild()
    .enableBuildNotifications()
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())

    // PostCSS / Autoprefixer
    .enablePostCssLoader()

    // Support des imports CSS dans JS
    .configureCssLoader(() => {})
;

module.exports = Encore.getWebpackConfig();

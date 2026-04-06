const Encore = require('@symfony/webpack-encore');

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'development');
}

Encore
    .addEntry('custom-select', './assets/js/custom-select.js')
    .setOutputPath('public/build/')
    .setPublicPath('/build')

    // Entry point principal
    .addEntry('app', './assets/app.js')

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

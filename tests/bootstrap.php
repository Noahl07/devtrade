<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Base de test remise à zéro à chaque lancement : sans cela, les données créées par les tests
// s'accumulent (modules, articles) et les pages finissent par devenir énormes.
// Garde : uniquement l'environnement de test, et une base dont le nom finit par « _test ».
if (($_SERVER['APP_ENV'] ?? null) === 'test') {
    $kernel = new App\Kernel('test', false);
    $kernel->boot();
    /** @var Doctrine\DBAL\Connection $connexion */
    $connexion = $kernel->getContainer()->get('doctrine')->getConnection();
    $base = (string) $connexion->getDatabase();
    if (!str_ends_with($base, '_test') && !preg_match('/_test\d*$/', $base)) {
        throw new RuntimeException("Refus de vider la base « $base » : ce n'est pas une base de test.");
    }
    $tables = array_filter(
        $connexion->createSchemaManager()->listTableNames(),
        static fn (string $t): bool => $t !== 'doctrine_migration_versions',
    );
    if ($tables !== []) {
        $connexion->executeStatement('TRUNCATE ' . implode(', ', array_map(static fn (string $t): string => '"' . trim($t, '"') . '"', $tables)) . ' RESTART IDENTITY CASCADE');
    }
    $kernel->shutdown();
}

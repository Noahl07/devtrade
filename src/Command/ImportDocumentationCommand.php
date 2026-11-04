<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DocumentationSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Importe dans la base de travail les modules et articles qui n'existent que dans une autre base locale.
 * Comparaison sur le slug. Rien n'est écrasé ni supprimé. Réservé à l'environnement dev.
 */
#[AsCommand(name: 'app:documentation:import', description: 'Importe les modules et articles absents depuis une autre base locale (comparaison sur le slug, sans écrasement)')]
final class ImportDocumentationCommand extends Command
{
    public function __construct(
        private readonly DocumentationSync $sync,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Base source (même serveur, mêmes identifiants)', 'devtrade_live')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait ajouté et les conflits, sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = (string) $input->getOption('source');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($this->environment !== 'dev') {
            $io->error('Commande réservée à l\'environnement dev (elle écrit dans la base configurée).');

            return Command::FAILURE;
        }
        if ($source === $this->sync->targetDatabase()) {
            $io->error('La base source et la base de travail sont identiques.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Documentation : %s → %s%s', $source, $this->sync->targetDatabase(), $dryRun ? ' (simulation)' : ''));
        $plan = $this->sync->diff($this->sync->openSource($source));

        $io->section(sprintf('Modules à ajouter (%d)', count($plan['newCategories'])));
        $io->listing(array_map(static fn (array $c): string => sprintf('%s  [%s] %s', $c['slug'], $c['access_level'], $c['name']), $plan['newCategories']) ?: ['aucun']);

        $io->section(sprintf('Articles à ajouter (%d)', count($plan['newDocuments'])));
        if ($plan['newDocuments'] !== []) {
            $io->table(['Slug', 'Module', 'Accès', 'Publié', 'Titre'], array_map(static fn (array $d): array => [
                $d['slug'], $d['category_slug'], $d['access_level'], $d['is_published'] ? 'oui' : 'non', mb_strimwidth((string) $d['title'], 0, 50, '…'),
            ], $plan['newDocuments']));
        } else {
            $io->listing(['aucun']);
        }

        $io->section(sprintf('Conflits : même slug, contenu différent (%d modules, %d articles) : laissés tels quels', count($plan['categoryConflicts']), count($plan['documentConflicts'])));
        $conflicts = array_merge(
            array_map(static fn (array $c): array => ['module', $c['slug'], implode(', ', $c['fields'])], $plan['categoryConflicts']),
            array_map(static fn (array $c): array => ['article', $c['slug'], implode(', ', $c['fields'])], $plan['documentConflicts']),
        );
        $conflicts !== [] ? $io->table(['Type', 'Slug', 'Champs différents'], $conflicts) : $io->listing(['aucun']);

        $io->text(sprintf('Identiques dans les deux bases : %d modules, %d articles.', $plan['identical']['categories'], $plan['identical']['documents']));

        if ($dryRun) {
            $io->note('Simulation : rien n\'a été écrit. Relancez sans --dry-run pour importer.');

            return Command::SUCCESS;
        }

        $result = $this->sync->import($plan);
        $io->success(sprintf('%d modules et %d articles ajoutés.', $result['categories'], $result['documents']));
        if ($result['authorsUnmatched'] > 0) {
            $io->warning(sprintf('%d article(s) sans auteur : leur auteur n\'existe pas dans la base de travail.', $result['authorsUnmatched']));
        }

        return Command::SUCCESS;
    }
}

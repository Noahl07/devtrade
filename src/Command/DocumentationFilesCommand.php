<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DocumentationBundle;
use App\Service\DocumentationFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * app:documentation:files export  : écrit les articles de la base locale dans content/documentation et content/premium.
 * app:documentation:files apply   : met à jour la base locale depuis ces fichiers (--dry-run pour simuler).
 * app:documentation:files bundle  : produit le lot d'import (.json.gz) à envoyer dans l'admin en production.
 * Réservé à l'environnement dev. Rien n'est jamais supprimé.
 */
#[AsCommand(name: 'app:documentation:files', description: 'Exporte les articles en fichiers, les applique à la base locale, ou prépare le lot d\'import pour la production')]
final class DocumentationFilesCommand extends Command
{
    public function __construct(
        private readonly DocumentationFiles $files,
        private readonly DocumentationBundle $bundle,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'export, apply ou bundle')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'apply : affiche les changements sans rien écrire')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'bundle : fichier produit', 'var/documentation-bundle.json.gz');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment !== 'dev') {
            $io->error('Commande réservée à l\'environnement dev. En production, utilisez l\'import de l\'admin.');

            return Command::FAILURE;
        }

        return match ($input->getArgument('action')) {
            'export' => $this->export($io),
            'apply' => $this->apply($io, (bool) $input->getOption('dry-run')),
            'bundle' => $this->makeBundle($io, (string) $input->getOption('output')),
            default => $this->unknown($io),
        };
    }

    private function export(SymfonyStyle $io): int
    {
        $io->success(sprintf('%d articles écrits dans %s (gratuits : documentation/, Premium : premium/).', $this->files->export(), $this->files->directory()));

        return Command::SUCCESS;
    }

    private function apply(SymfonyStyle $io, bool $dryRun): int
    {
        $bundle = $this->bundle->validate($this->files->bundle());
        $plan = $this->bundle->plan($bundle);
        self::renderPlan($io, $plan);
        if ($dryRun) {
            $io->note('Simulation : rien n\'a été écrit.');

            return Command::SUCCESS;
        }
        $this->bundle->apply($bundle);
        $io->success('Base locale mise à jour.');

        return Command::SUCCESS;
    }

    private function makeBundle(SymfonyStyle $io, string $output): int
    {
        $bundle = $this->files->bundle();
        $this->bundle->validate($bundle);
        $path = str_starts_with($output, '/') || preg_match('/^[A-Za-z]:/', $output) ? $output : $this->projectDir . '/' . $output;
        (new Filesystem())->dumpFile($path, $this->bundle->encode($bundle));
        $io->success(sprintf('%d modules et %d articles dans %s (%d Ko). Envoyez ce fichier dans Admin > Documents > Importer.',
            count($bundle['modules']), count($bundle['articles']), $path, (int) ceil(filesize($path) / 1024)));
        $io->warning('Ce fichier contient les articles Premium : ne le partagez pas et ne le versionnez pas.');

        return Command::SUCCESS;
    }

    private function unknown(SymfonyStyle $io): int
    {
        $io->error('Action inconnue : export, apply ou bundle.');

        return Command::INVALID;
    }

    /** @param array<string, mixed> $plan */
    public static function renderPlan(SymfonyStyle $io, array $plan): void
    {
        $io->section(sprintf('Modules : %d nouveau(x), %d déplacé(s)', count($plan['modulesNew']), count($plan['modulesMoved'])));
        foreach ($plan['modulesNew'] as $m) {
            $io->writeln(sprintf(' + %s (%s)', $m['name'], $m['slug']));
        }
        foreach ($plan['modulesMoved'] as $m) {
            $io->writeln(sprintf(' ↕ %s : %d → %d', $m['name'], $m['from'], $m['to']));
        }
        $io->section(sprintf('Articles : %d nouveau(x), %d modifié(s), %d inchangé(s)', count($plan['articlesNew']), count($plan['articlesUpdated']), $plan['unchanged']));
        foreach ($plan['articlesNew'] as $a) {
            $io->writeln(sprintf(' + %s', $a['slug']));
        }
        foreach ($plan['articlesUpdated'] as $u) {
            $io->writeln(sprintf(' ~ %s : %s', $u['slug'], implode(', ', array_keys($u['fields']))));
        }
        if ($plan['onlyInDatabase'] !== []) {
            $io->note(sprintf('%d article(s) en base absents des fichiers (conservés) : %s', count($plan['onlyInDatabase']), implode(', ', array_column($plan['onlyInDatabase'], 'slug'))));
        }
    }
}

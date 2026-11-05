<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DocumentationFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * app:documentation:files export  : écrit les articles de la base dans content/documentation/
 * app:documentation:files apply   : met à jour la base depuis ces fichiers (--dry-run pour simuler).
 * Réservé à l'environnement dev. Aucun article n'est créé ni supprimé.
 */
#[AsCommand(name: 'app:documentation:files', description: 'Exporte les articles en fichiers versionnés, ou applique les fichiers modifiés à la base')]
final class DocumentationFilesCommand extends Command
{
    public function __construct(
        private readonly DocumentationFiles $files,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'export ou apply')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'apply : affiche les changements sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment !== 'dev') {
            $io->error('Commande réservée à l\'environnement dev.');

            return Command::FAILURE;
        }

        if ($input->getArgument('action') === 'export') {
            $count = $this->files->export();
            $io->success(sprintf('%d articles écrits dans %s', $count, $this->files->directory()));

            return Command::SUCCESS;
        }
        if ($input->getArgument('action') !== 'apply') {
            $io->error('Action inconnue : export ou apply.');

            return Command::INVALID;
        }

        $modules = $this->files->moduleChanges();
        $io->section(sprintf('Modules déplacés (%d)', count($modules)));
        $io->listing($modules === [] ? ['aucun'] : array_map(static fn (array $m): string => sprintf('%s : %d → %d', $m['slug'], $m['from'], $m['to']), $modules));

        $changes = $this->files->diff();
        $missing = array_filter($changes, static fn (array $c): bool => $c['missing'] ?? false);
        $updates = array_values(array_filter($changes, static fn (array $c): bool => !($c['missing'] ?? false)));

        $io->section(sprintf('Articles modifiés (%d)', count($updates)));
        if ($updates !== []) {
            $io->table(['Fichier', 'Champs', 'Contenu (caractères)'], array_map(static function (array $c): array {
                $content = $c['fields']['content'] ?? null;

                $fields = array_map(static fn (string $f): string => $f === 'position' ? sprintf('position %s → %s', $c['fields'][$f][0], $c['fields'][$f][1]) : $f, array_keys($c['fields']));

                return [
                    $c['file'],
                    implode(', ', $fields),
                    $content ? sprintf('%d → %d', mb_strlen((string) $content[0]), mb_strlen((string) $content[1])) : '—',
                ];
            }, $updates));
        } else {
            $io->listing(['aucun']);
        }
        if ($missing !== []) {
            $io->warning('Fichiers sans article correspondant en base (ignorés) : ' . implode(', ', array_column($missing, 'file')));
        }

        if ($input->getOption('dry-run')) {
            $io->note('Simulation : rien n\'a été écrit.');

            return Command::SUCCESS;
        }
        $io->success(sprintf('%d article(s) et %d module(s) mis à jour.', $this->files->apply($updates, $modules), count($modules)));

        return Command::SUCCESS;
    }
}

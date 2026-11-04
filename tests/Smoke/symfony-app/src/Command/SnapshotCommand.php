<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Category;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
use function implode;
use function json_encode;
use function max;
use function sort;
use function substr_count;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Prints the stored users, orders and category paths as JSON, independent of
 * the column names, so the smoke run can compare the data across migrations.
 */
#[AsCommand(name: 'app:smoke:snapshot', description: 'Print the stored data as JSON')]
final class SnapshotCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'check-tree',
            null,
            InputOption::VALUE_NONE,
            'Also check that the category tree has three levels and that its foreign key rejects an unknown parent',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = array_map(
            static fn (User $user): array => [
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'orders' => array_map(
                    static fn (Order $order): array => [$order->getReference(), $order->getAmountCents()],
                    $user->getOrders()->toArray(),
                ),
            ],
            $this->entityManager->getRepository(User::class)->findBy([], ['email' => 'ASC']),
        );

        $paths = array_map(
            static function (Category $category): string {
                $names = [];

                for ($node = $category; $node !== null; $node = $node->getParent()) {
                    $names[] = $node->getName();
                }

                return implode(' < ', $names);
            },
            $this->entityManager->getRepository(Category::class)->findAll(),
        );

        sort($paths);

        if ($input->getOption('check-tree')) {
            $this->checkTree($paths);
        }

        $output->writeln(json_encode(
            ['users' => $users, 'categories' => $paths],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return Command::SUCCESS;
    }

    /** @param list<string> $paths */
    private function checkTree(array $paths): void
    {
        $levels = 0;

        foreach ($paths as $path) {
            $levels = max($levels, substr_count($path, ' < ') + 1);
        }

        SmokeFailed::unlessSame(3, $levels, 'Levels of the category tree');

        $metadata   = $this->entityManager->getClassMetadata(Category::class);
        $connection = $this->entityManager->getConnection();

        try {
            $connection->insert($metadata->getTableName(), [
                $metadata->getSingleIdentifierColumnName() => -1,
                $metadata->getColumnName('name') => 'Orphan',
                $metadata->getSingleAssociationJoinColumnName('parent') => -42,
            ]);
        } catch (ForeignKeyConstraintViolationException) {
            return;
        }

        throw new SmokeFailed('The category table accepted a row whose parent does not exist.');
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Category;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
use function is_int;
use function sprintf;

/**
 * Creates, reads, updates and removes users, orders and a category tree
 * through the ORM, checking each step's result.
 */
#[AsCommand(name: 'app:smoke:crud', description: 'Persist, query, update and remove entities through the ORM')]
final class CrudCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->create();
        $output->writeln('Created 3 users, 3 orders and 7 categories.');

        $this->read();
        $output->writeln('Queried users, order counts and the category tree.');

        $this->update();
        $output->writeln('Updated a user, an order and two categories.');

        $this->remove();
        $output->writeln('Removed an order, a user and a category.');

        return Command::SUCCESS;
    }

    private function create(): void
    {
        foreach ([User::class, Order::class, Category::class] as $class) {
            SmokeFailed::unlessSame(
                true,
                $this->entityManager->getClassMetadata($class)->isIdGeneratorSequence(),
                sprintf('%s generates its default ID from a sequence', $class),
            );
        }

        $alice = new User('alice@example.com', 'Alice');
        $bob   = new User('bob@example.com', 'Bob');
        $carol = new User('carol@example.com', 'Carol');

        $catalog = new Category('Catalog');
        $books   = new Category('Books', $catalog);
        $music   = new Category('Music', $catalog);

        $entities = [
            $alice,
            $bob,
            $carol,
            new Order($alice, 'A-1', 1500),
            new Order($alice, 'A-2', 2500),
            new Order($bob, 'B-1', 990),
            $catalog,
            $books,
            $music,
            new Category('Fiction', $books),
            new Category('Science', $books),
            new Category('Jazz', $music),
            new Category('Obsolete', $music),
        ];

        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        foreach ($entities as $entity) {
            SmokeFailed::unlessSame(true, is_int($entity->getId()), sprintf('%s received an ID', $entity::class));
        }

        $this->entityManager->clear();
    }

    private function read(): void
    {
        $alice = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'alice@example.com']);

        SmokeFailed::unlessSame('Alice', $alice?->getName(), 'User found by its unique email');

        $orderCounts = $this->entityManager->createQuery(
            'SELECT u.email, COUNT(o.id) AS orders FROM App\Entity\User u LEFT JOIN u.orders o GROUP BY u.email ORDER BY u.email',
        )->getArrayResult();

        SmokeFailed::unlessSame(
            [
                ['email' => 'alice@example.com', 'orders' => 2],
                ['email' => 'bob@example.com', 'orders' => 1],
                ['email' => 'carol@example.com', 'orders' => 0],
            ],
            $orderCounts,
            'Orders per user',
        );

        $books = $this->entityManager->getRepository(Category::class)->findOneBy(['name' => 'Books']);

        if ($books === null) {
            throw new SmokeFailed('The Books category was not found.');
        }

        SmokeFailed::unlessSame('Catalog', $books->getParent()?->getName(), 'Parent of Books');
        SmokeFailed::unlessSame(
            ['Fiction', 'Science'],
            array_map(static fn (Category $child): string => $child->getName(), $books->getChildren()->toArray()),
            'Children of Books',
        );

        $this->entityManager->clear();
    }

    private function update(): void
    {
        $users      = $this->entityManager->getRepository(User::class);
        $orders     = $this->entityManager->getRepository(Order::class);
        $categories = $this->entityManager->getRepository(Category::class);

        $users->findOneBy(['email' => 'bob@example.com'])?->rename('Robert');
        $orders->findOneBy(['reference' => 'B-1'])?->changeAmount(1990);
        $categories->findOneBy(['name' => 'Science'])?->rename('Science & Nature');
        $categories->findOneBy(['name' => 'Jazz'])?->moveTo($categories->findOneBy(['name' => 'Books']));

        $this->entityManager->flush();
        $this->entityManager->clear();

        SmokeFailed::unlessSame('Robert', $users->findOneBy(['email' => 'bob@example.com'])?->getName(), 'Renamed user');
        SmokeFailed::unlessSame(1990, $orders->findOneBy(['reference' => 'B-1'])?->getAmountCents(), 'Changed order amount');
        SmokeFailed::unlessSame(
            'Books',
            $categories->findOneBy(['name' => 'Jazz'])?->getParent()?->getName(),
            'Parent of the moved category',
        );
        SmokeFailed::unlessSame(
            'Books',
            $categories->findOneBy(['name' => 'Science & Nature'])?->getParent()?->getName(),
            'Parent of the renamed category',
        );

        $this->entityManager->clear();
    }

    private function remove(): void
    {
        $users      = $this->entityManager->getRepository(User::class);
        $orders     = $this->entityManager->getRepository(Order::class);
        $categories = $this->entityManager->getRepository(Category::class);

        foreach (
            [
                $orders->findOneBy(['reference' => 'A-2']),
                $users->findOneBy(['email' => 'carol@example.com']),
                $categories->findOneBy(['name' => 'Obsolete']),
            ] as $entity
        ) {
            if ($entity === null) {
                throw new SmokeFailed('An entity to remove was not found.');
            }

            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        SmokeFailed::unlessSame(2, $users->count(), 'Users left');
        SmokeFailed::unlessSame(2, $orders->count(), 'Orders left');
        SmokeFailed::unlessSame(6, $categories->count(), 'Categories left');
    }
}

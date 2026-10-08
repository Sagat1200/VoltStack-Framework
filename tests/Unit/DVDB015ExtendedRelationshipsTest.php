<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\JoinTable;
use Quantum\Database\ORM\Attributes\ManyToOne;
use Quantum\Database\ORM\Attributes\ManyToMany;
use Quantum\Database\ORM\Attributes\OneToMany;
use Quantum\Database\ORM\Attributes\OneToOne;
use Quantum\Database\ORM\Contracts\Cascade;
use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;

final class DVDB015ExtendedRelationshipsTest extends TestCase
{
    public function test_association_metadata_supports_one_to_one_and_many_to_many_kinds(): void
    {
        $reflection = new \ReflectionProperty(UnitStudent::class, 'courses');

        $oneToOneOwning = new EntityAssociationMetadata(
            name: 'profile',
            kind: EntityAssociationMetadata::KIND_ONE_TO_ONE,
            targetEntity: UnitProfile::class,
            property: new \ReflectionProperty(UnitProfile::class, 'account'),
            sourceField: 'account',
            sourceColumn: 'account_id',
            targetField: 'id',
            targetColumn: 'id',
        );
        self::assertTrue($oneToOneOwning->isOneToOne());
        self::assertTrue($oneToOneOwning->isToOne());
        self::assertTrue($oneToOneOwning->isOwningSide());

        $manyToManyOwning = new EntityAssociationMetadata(
            name: 'courses',
            kind: EntityAssociationMetadata::KIND_MANY_TO_MANY,
            targetEntity: UnitCourse::class,
            property: $reflection,
            cascade: [Cascade::PERSIST],
            joinTable: 'unit_student_courses',
            joinTableSourceColumn: 'student_id',
            joinTableTargetColumn: 'course_id',
        );
        self::assertTrue($manyToManyOwning->isManyToMany());
        self::assertTrue($manyToManyOwning->isToMany());
        self::assertTrue($manyToManyOwning->isOwningSide());
        self::assertTrue($manyToManyOwning->usesJoinTable());
        self::assertTrue($manyToManyOwning->cascadesPersist());
        self::assertFalse($manyToManyOwning->cascadesRemove());
    }

    public function test_registry_maps_owning_one_to_one_with_join_column_details(): void
    {
        $registry = new EntityMetadataRegistry();
        $metadata = $registry->for(UnitProfile::class);
        $association = $metadata->association('account');

        self::assertSame(EntityAssociationMetadata::KIND_ONE_TO_ONE, $association->kind);
        self::assertTrue($association->isOwningSide());
        self::assertSame('account_id', $association->sourceColumn);
        self::assertSame('id', $association->targetColumn);
        self::assertSame('profile', $association->inversedBy);
    }

    public function test_registry_maps_inverse_one_to_one_via_mapped_by(): void
    {
        $registry = new EntityMetadataRegistry();
        $metadata = $registry->for(UnitAccount::class);
        $association = $metadata->association('profile');

        self::assertSame(EntityAssociationMetadata::KIND_ONE_TO_ONE, $association->kind);
        self::assertTrue($association->isInverseSide());
        self::assertSame('account', $association->mappedBy);
        self::assertSame('account_id', $association->targetColumn);
    }

    public function test_registry_maps_owning_many_to_many_join_table_details(): void
    {
        $registry = new EntityMetadataRegistry();
        $metadata = $registry->for(UnitStudent::class);
        $association = $metadata->association('courses');

        self::assertSame(EntityAssociationMetadata::KIND_MANY_TO_MANY, $association->kind);
        self::assertTrue($association->isOwningSide());
        self::assertSame('unit_student_courses', $association->joinTable);
        self::assertSame('student_id', $association->joinTableSourceColumn);
        self::assertSame('course_id', $association->joinTableTargetColumn);
        self::assertSame('students', $association->inversedBy);
    }

    public function test_registry_maps_inverse_many_to_many_via_mapped_by_even_during_two_phase_build(): void
    {
        $registry = new EntityMetadataRegistry();
        $metadata = $registry->for(UnitCourse::class);
        $association = $metadata->association('students');

        self::assertSame(EntityAssociationMetadata::KIND_MANY_TO_MANY, $association->kind);
        self::assertTrue($association->isInverseSide());
        self::assertSame('courses', $association->mappedBy);
        self::assertSame('unit_student_courses', $association->joinTable);
        self::assertSame('course_id', $association->joinTableSourceColumn);
        self::assertSame('student_id', $association->joinTableTargetColumn);
    }

    public function test_registry_maps_fetch_strategy_and_defaults_to_lazy(): void
    {
        $registry = new EntityMetadataRegistry();

        $bookMetadata = $registry->for(UnitBook::class);
        $authorAssociation = $bookMetadata->association('author');
        self::assertSame(EntityAssociationMetadata::FETCH_EAGER, $authorAssociation->fetch);
        self::assertTrue($authorAssociation->isEager());
        self::assertSame(['author'], $bookMetadata->eagerAssociationNames());

        $studentMetadata = $registry->for(UnitStudent::class);
        $coursesAssociation = $studentMetadata->association('courses');
        self::assertSame(EntityAssociationMetadata::FETCH_LAZY, $coursesAssociation->fetch);
        self::assertTrue($coursesAssociation->isLazy());
    }
}

#[Entity]
final class UnitAccount
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[OneToOne(targetEntity: UnitProfile::class, mappedBy: 'account')]
    public ?UnitProfile $profile = null;
}

#[Entity]
final class UnitProfile
{
    #[Id]
    public ?int $id = null;

    #[Column(name: 'account_id')]
    public ?int $accountId = null;

    #[OneToOne(targetEntity: UnitAccount::class, inversedBy: 'profile')]
    public ?UnitAccount $account = null;
}

#[Entity]
final class UnitStudent
{
    #[Id]
    public ?int $id = null;

    /** @var list<UnitCourse> */
    #[ManyToMany(
        targetEntity: UnitCourse::class,
        inversedBy: 'students',
        cascade: [Cascade::PERSIST],
    )]
    #[JoinTable(
        name: 'unit_student_courses',
        joinColumns: ['student_id'],
        inverseJoinColumns: ['course_id'],
    )]
    public array $courses = [];
}

#[Entity]
final class UnitCourse
{
    #[Id]
    public ?int $id = null;

    /** @var list<UnitStudent> */
    #[ManyToMany(targetEntity: UnitStudent::class, mappedBy: 'courses')]
    public array $students = [];
}

#[Entity]
final class UnitAuthor
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;
}

#[Entity]
final class UnitBook
{
    #[Id]
    public ?int $id = null;

    #[Column(name: 'author_id')]
    public int $authorId;

    #[Column]
    public string $title;

    #[ManyToOne(targetEntity: UnitAuthor::class, fetch: 'eager')]
    public ?UnitAuthor $author = null;
}

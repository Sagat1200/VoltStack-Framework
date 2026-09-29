<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\ManyToOne;
use Quantum\Database\ORM\Attributes\OneToMany;
use Quantum\Database\ORM\Attributes\PostLoad;
use Quantum\Database\ORM\Attributes\PostPersist;
use Quantum\Database\ORM\Attributes\PostRemove;
use Quantum\Database\ORM\Attributes\PostUpdate;
use Quantum\Database\ORM\Attributes\PrePersist;
use Quantum\Database\ORM\Attributes\PreRemove;
use Quantum\Database\ORM\Attributes\PreUpdate;
use Quantum\Database\ORM\Attributes\Table;
use Quantum\Database\ORM\Contracts\AbstractEntityLifecycleListener;
use Quantum\Database\ORM\Contracts\Cascade;
use Quantum\Database\ORM\Contracts\EntityLifecycleListenerInterface;
use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use RuntimeException;
use stdClass;

final class DVDB014LifecycleCascadeOrphanTest extends TestCase
{
    /* =====================================================================
     * 1. EntityMetadata lifecycleCallbacks default shape y validación keys.
     * ===================================================================== */
    public function test_entity_metadata_defaults_lifecycle_callbacks_to_seven_empty_arrays(): void
    {
        $metadata = $this->minimalMetadata();
        $callbacks = $metadata->lifecycleCallbacks;

        self::assertCount(7, $callbacks);
        foreach (EntityMetadata::KNOWN_LIFECYCLE_EVENTS as $event) {
            self::assertArrayHasKey($event, $callbacks);
            self::assertSame([], $callbacks[$event]);
            self::assertFalse($metadata->hasCallbacks($event));
            self::assertSame([], $metadata->callbacksFor($event));
        }
    }

    public function test_entity_metadata_rejects_unknown_lifecycle_event_keys(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown lifecycle events');

        $this->minimalMetadata(lifecycleCallbacks: [
            'prePersist' => [],
            'mysteryEvent' => [static fn () => null],
        ]);
    }

    public function test_callbacks_for_rejects_unknown_event_name(): void
    {
        $metadata = $this->minimalMetadata();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown lifecycle event');

        $metadata->callbacksFor('bogusEvent');
    }

    public function test_has_callbacks_and_callbacks_for_when_populated(): void
    {
        $noop = static fn () => null;
        $metadata = $this->minimalMetadata(lifecycleCallbacks: [
            'prePersist' => [$noop],
            'postLoad'    => [$noop, $noop],
        ]);

        self::assertTrue($metadata->hasCallbacks('prePersist'));
        self::assertTrue($metadata->hasCallbacks('postLoad'));
        self::assertFalse($metadata->hasCallbacks('postPersist'));
        self::assertFalse($metadata->hasCallbacks('postUpdate'));
        self::assertCount(1, $metadata->callbacksFor('prePersist'));
        self::assertCount(2, $metadata->callbacksFor('postLoad'));
    }

    /* =====================================================================
     * 2. Cascade / orphanRemoval en EntityAssociationMetadata + Attributes.
     * ===================================================================== */
    public function test_association_metadata_cascade_and_orphan_defaults_to_safe_values(): void
    {
        $assoc = $this->fakeAssociation(kind: EntityAssociationMetadata::KIND_ONE_TO_MANY);

        self::assertSame([], $assoc->cascade);
        self::assertFalse($assoc->orphanRemoval);
        self::assertFalse($assoc->cascadesPersist());
        self::assertFalse($assoc->cascadesRemove());
    }

    public function test_association_metadata_cascade_all_flags_both_persist_and_remove(): void
    {
        $assoc = $this->fakeAssociation(
            kind: EntityAssociationMetadata::KIND_MANY_TO_ONE,
            cascade: Cascade::ALL,
        );

        self::assertTrue($assoc->cascadesPersist());
        self::assertTrue($assoc->cascadesRemove());
        self::assertSame(Cascade::ALL, $assoc->cascade);
    }

    public function test_association_metadata_single_cascade_value_matches_only_one_operation(): void
    {
        $persistOnly = $this->fakeAssociation(
            kind: EntityAssociationMetadata::KIND_MANY_TO_ONE,
            cascade: [Cascade::PERSIST],
        );
        self::assertTrue($persistOnly->cascadesPersist());
        self::assertFalse($persistOnly->cascadesRemove());

        $removeOnly = $this->fakeAssociation(
            kind: EntityAssociationMetadata::KIND_ONE_TO_MANY,
            cascade: [Cascade::REMOVE],
            orphanRemoval: true,
        );
        self::assertFalse($removeOnly->cascadesPersist());
        self::assertTrue($removeOnly->cascadesRemove());
        self::assertTrue($removeOnly->orphanRemoval);
    }

    /* =====================================================================
     * 3. EntityMetadataRegistry buildLifecycleCallbacks discovery (Unit).
     *    Creo clases fixture con atributos method/class listener.
     * ===================================================================== */
    public function test_registry_discovers_seven_method_level_attributes_into_correct_event_buckets(): void
    {
        $registry = new EntityMetadataRegistry();
        $metadata = $registry->for(SampleEntityWithAllHooks::class);

        foreach (EntityMetadata::KNOWN_LIFECYCLE_EVENTS as $event) {
            self::assertTrue(
                $metadata->hasCallbacks($event),
                "Expected at least 1 method-level callback for event [$event].",
            );
            self::assertCount(1, $metadata->callbacksFor($event));
        }
    }

    public function test_registry_dispatches_method_level_attribute_closure_against_entity_instance(): void
    {
        $registry = new EntityMetadataRegistry();
        $metadata = $registry->for(SampleEntityWithAllHooks::class);
        $entity = new SampleEntityWithAllHooks();

        $fakeEm = new stdClass();
        foreach ($metadata->callbacksFor('prePersist') as $cb) {
            $cb($entity, $fakeEm);
        }

        self::assertSame('prePersist-called', $entity->lastHook);
        self::assertSame(1, $entity->prePersistCount);
    }

    public function test_registry_rejects_class_listener_declaration_that_does_not_exist(): void
    {
        $registry = new EntityMetadataRegistry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $registry->for(SampleEntityWithMissingListenerClass::class);
    }

    public function test_registry_rejects_class_listener_that_does_not_implement_interface(): void
    {
        $registry = new EntityMetadataRegistry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not implement');

        $registry->for(SampleEntityWithInvalidListenerClass::class);
    }

    public function test_registry_registers_class_listener_for_all_seven_events(): void
    {
        $registry = new EntityMetadataRegistry();
        $metadata = $registry->for(SampleEntityWithValidListenerClass::class);

        // 1 method-level prePersist + 1 class-level listener per bucket = 2 prePersist,
        // 1 per other event from class listener only.
        self::assertCount(2, $metadata->callbacksFor('prePersist'));
        self::assertCount(1, $metadata->callbacksFor('postPersist'));
        self::assertCount(1, $metadata->callbacksFor('postLoad'));
    }

    public function test_registry_dispatches_class_listener_callbacks_correctly(): void
    {
        $registry = new EntityMetadataRegistry();
        $metadata = $registry->for(SampleEntityWithValidListenerClass::class);
        $entity = new SampleEntityWithValidListenerClass();
        $fakeEm = new stdClass();
        SampleValidListener::reset();

        foreach (EntityMetadata::KNOWN_LIFECYCLE_EVENTS as $event) {
            foreach ($metadata->callbacksFor($event) as $cb) {
                $cb($entity, $fakeEm, ['ping' => true]);
            }
        }

        $calls = SampleValidListener::$calls;
        self::assertCount(7, $calls);
        foreach (EntityMetadata::KNOWN_LIFECYCLE_EVENTS as $event) {
            self::assertArrayHasKey($event, $calls);
            self::assertSame($entity, $calls[$event]['entity']);
            self::assertSame($fakeEm, $calls[$event]['em']);
            self::assertSame(['ping' => true], $calls[$event]['context']);
        }
    }

    /* =====================================================================
     * 4. Backward compat: entity sin atributos nuevos = 0 callbacks,
     *    asociaciones sin cascade/orphan no tienen nada.
     * ===================================================================== */
    public function test_plain_entity_without_new_attributes_exposes_zero_callbacks_and_default_association_shape(): void
    {
        $registry = new EntityMetadataRegistry();
        $metadata = $registry->for(BareSampleEntity::class);

        foreach (EntityMetadata::KNOWN_LIFECYCLE_EVENTS as $event) {
            self::assertFalse($metadata->hasCallbacks($event));
            self::assertSame([], $metadata->callbacksFor($event));
        }

        // Bare Sample tiene ManyToOne bare sin cascade
        $assoc = $metadata->association('bare');
        self::assertFalse($assoc->cascadesPersist());
        self::assertFalse($assoc->cascadesRemove());
    }

    /* =====================================================================
     * Helpers
     * ===================================================================== */

    /**
     * @param array<string, list<callable>>|null $lifecycleCallbacks If null, use constructor default (7 empty buckets).
     */
    private function minimalMetadata(?array $lifecycleCallbacks = null): EntityMetadata
    {
        require_once __DIR__ . '/../Feature/DatabaseOrmFeatureTest.php';
        $fixtureClass = \VoltStack\Test\Feature\OrmUser::class;
        $registry = new EntityMetadataRegistry();
        $base = $registry->for($fixtureClass);

        if ($lifecycleCallbacks === null) {
            return new EntityMetadata(
                className: $fixtureClass,
                table: $base->table,
                fields: $base->fields,
                identifier: $base->identifier,
                associations: $base->associations,
                embeddeds: $base->embeddeds,
                repositoryClass: $base->repositoryClass,
            );
        }

        return new EntityMetadata(
            className: $fixtureClass,
            table: $base->table,
            fields: $base->fields,
            identifier: $base->identifier,
            associations: $base->associations,
            embeddeds: $base->embeddeds,
            repositoryClass: $base->repositoryClass,
            lifecycleCallbacks: $lifecycleCallbacks,
        );
    }

    /**
     * @param string       $kind    KIND_* constant
     * @param list<string> $cascade
     */
    private function fakeAssociation(
        string $kind,
        array $cascade = [],
        bool $orphanRemoval = false,
    ): EntityAssociationMetadata {
        $holder = new class {
            public ?object $dummy = null;
        };

        return new EntityAssociationMetadata(
            name: 'dummy',
            kind: $kind,
            targetEntity: BareSampleEntity::class,
            property: new \ReflectionProperty($holder, 'dummy'),
            sourceField: null,
            sourceColumn: null,
            targetField: 'id',
            targetColumn: 'id',
            cascade: $cascade,
            orphanRemoval: $orphanRemoval,
        );
    }
}

/* -------------------------------------------------------------------------
 * Fixture classes usadas por este Unit test (no requieren BD).
 * ------------------------------------------------------------------------- */

#[Entity]
#[Table(name: 'sample_all_hooks')]
final class SampleEntityWithAllHooks
{
    #[Id, Column]
    public ?int $id = null;

    #[Column]
    public string $lastHook = '';

    public int $prePersistCount = 0;

    #[PrePersist]
    public function onPrePersist(): void { $this->lastHook = 'prePersist-called'; $this->prePersistCount++; }
    #[PostPersist]
    public function onPostPersist(): void { $this->lastHook = 'postPersist-called'; }
    #[PreUpdate]
    public function onPreUpdate(): void { $this->lastHook = 'preUpdate-called'; }
    #[PostUpdate]
    public function onPostUpdate(): void { $this->lastHook = 'postUpdate-called'; }
    #[PreRemove]
    public function onPreRemove(): void { $this->lastHook = 'preRemove-called'; }
    #[PostRemove]
    public function onPostRemove(): void { $this->lastHook = 'postRemove-called'; }
    #[PostLoad]
    public function onPostLoad(): void { $this->lastHook = 'postLoad-called'; }
}

#[Entity(lifecycleListeners: ['NonExistent\\ListenerClassXYZ'])]
#[Table(name: 'sample_missing_listener')]
final class SampleEntityWithMissingListenerClass
{
    #[Id, Column]
    public ?int $id = null;
}

#[Entity(lifecycleListeners: [BareSampleEntity::class])]
#[Table(name: 'sample_invalid_listener')]
final class SampleEntityWithInvalidListenerClass
{
    #[Id, Column]
    public ?int $id = null;
}

#[Entity(lifecycleListeners: [SampleValidListener::class])]
#[Table(name: 'sample_valid_listener')]
final class SampleEntityWithValidListenerClass
{
    #[Id, Column]
    public ?int $id = null;

    #[PrePersist]
    public function onPrePersist(): void
    {
    }
}

final class SampleValidListener extends AbstractEntityLifecycleListener
{
    /**
     * @var array<string, array{entity:object, em:object, context:array<string, mixed>}>
     */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    /** @inheritDoc */
    public function prePersist(object $entity, object $entityManager, array $context = []): void
    {
        self::$calls['prePersist'] = ['entity' => $entity, 'em' => $entityManager, 'context' => $context];
    }

    /** @inheritDoc */
    public function postPersist(object $entity, object $entityManager, array $context = []): void
    {
        self::$calls['postPersist'] = ['entity' => $entity, 'em' => $entityManager, 'context' => $context];
    }

    /** @inheritDoc */
    public function preUpdate(object $entity, object $entityManager, array $context = []): void
    {
        self::$calls['preUpdate'] = ['entity' => $entity, 'em' => $entityManager, 'context' => $context];
    }

    /** @inheritDoc */
    public function postUpdate(object $entity, object $entityManager, array $context = []): void
    {
        self::$calls['postUpdate'] = ['entity' => $entity, 'em' => $entityManager, 'context' => $context];
    }

    /** @inheritDoc */
    public function preRemove(object $entity, object $entityManager, array $context = []): void
    {
        self::$calls['preRemove'] = ['entity' => $entity, 'em' => $entityManager, 'context' => $context];
    }

    /** @inheritDoc */
    public function postRemove(object $entity, object $entityManager, array $context = []): void
    {
        self::$calls['postRemove'] = ['entity' => $entity, 'em' => $entityManager, 'context' => $context];
    }

    /** @inheritDoc */
    public function postLoad(object $entity, object $entityManager, array $context = []): void
    {
        self::$calls['postLoad'] = ['entity' => $entity, 'em' => $entityManager, 'context' => $context];
    }
}

#[Entity]
#[Table(name: 'bare_sample_entity')]
final class BareSampleEntity
{
    #[Id, Column]
    public ?int $id = null;

    #[ManyToOne(targetEntity: BareSampleEntity::class)]
    public ?BareSampleEntity $bare = null;
}

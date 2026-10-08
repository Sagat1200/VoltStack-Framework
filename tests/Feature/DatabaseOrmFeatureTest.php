<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Embedded;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\JoinTable;
use Quantum\Database\ORM\Attributes\ManyToOne;
use Quantum\Database\ORM\Attributes\ManyToMany;
use Quantum\Database\ORM\Attributes\OneToMany;
use Quantum\Database\ORM\Attributes\OneToOne;
use Quantum\Database\ORM\Attributes\PostLoad;
use Quantum\Database\ORM\Attributes\PostPersist;
use Quantum\Database\ORM\Attributes\PrePersist;
use Quantum\Database\ORM\Attributes\PreUpdate;
use Quantum\Database\ORM\Attributes\RepositoryFor;
use Quantum\Database\ORM\Attributes\Table;
use Quantum\Database\ORM\Contracts\AbstractEntityLifecycleListener;
use Quantum\Database\ORM\Contracts\Cascade;
use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\ORM\Contracts\RepositoryFactoryInterface;
use Quantum\Database\ORM\CustomRepositoryRegistry;
use Quantum\Database\ORM\EntityRepository;
use Quantum\Database\ORM\EntityState;
use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\ORM\Model;
use Quantum\Database\Schema\Builder\TableBlueprint;
use Quantum\Http\Request;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class DatabaseOrmFeatureTest extends TestCase
{
    private string $basePath;
    private string $databasePath;
    private ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-orm-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(ConnectionManagerInterface::class)->disconnectAll();
            $this->app = null;
        }

        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_entity_manager_repository_and_metadata_work_together_inside_one_scope(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/entity-manager', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active');
            }, true);

            $metadata = $app->make(EntityMetadataRegistry::class);
            self::assertTrue($metadata->has(OrmUser::class));
            self::assertTrue($metadata->has(OrmPost::class));
            self::assertFalse($metadata->has(\stdClass::class));

            $userMetadata = $metadata->for(OrmUser::class);
            self::assertSame('orm_users', $userMetadata->table);
            self::assertSame('id', $userMetadata->identifier->column);
            self::assertSame(OrmUserRepository::class, $userMetadata->repositoryClass);
            self::assertSame('string', $userMetadata->field('name')->type);
            self::assertSame('bool', $userMetadata->field('active')->type);

            $postMetadata = $metadata->for(OrmPost::class);
            self::assertSame('orm_posts', $postMetadata->table);

            $manager = $database->entityManager();
            $repository = $database->repository(OrmUser::class);

            self::assertInstanceOf(OrmUserRepository::class, $repository);
            self::assertInstanceOf(EntityRepositoryInterface::class, $repository);

            $volt = new OrmUser();
            $volt->name = 'VoltStack';
            $volt->active = true;

            $quantum = new OrmUser();
            $quantum->name = 'Quantum';
            $quantum->active = false;

            $manager->persist($volt);
            $manager->persist($quantum);
            $manager->flush();

            self::assertIsInt($volt->id);
            self::assertIsInt($quantum->id);
            self::assertSame(EntityState::Managed, $manager->state($volt));
            self::assertTrue($manager->contains($volt));
            self::assertSame($volt, $manager->find(OrmUser::class, $volt->id));

            $volt->name = 'VoltStack Updated';
            $manager->flush();

            $byCriteria = $repository->findOneBy(['name' => 'VoltStack Updated']);
            self::assertSame($volt, $byCriteria);

            $activeNames = $repository->activeNames();
            self::assertSame(['VoltStack Updated'], $activeNames);

            $manager->clear();

            $reloaded = $manager->find(OrmUser::class, $volt->id);
            self::assertInstanceOf(OrmUser::class, $reloaded);
            self::assertNotSame($volt, $reloaded);
            self::assertSame('VoltStack Updated', $reloaded->name);
            self::assertTrue($reloaded->active);

            $all = $database->repository(OrmUser::class)->findBy([], ['name' => 'asc']);
            self::assertCount(2, $all);
            self::assertSame(['Quantum', 'VoltStack Updated'], array_map(
                static fn(OrmUser $user): string => $user->name,
                $all,
            ));
            self::assertSame(
                $reloaded,
                $database->repository(OrmUser::class)->find($reloaded->id),
            );
        } finally {
            $scope->end();
        }
    }

    public function test_model_api_uses_the_same_scoped_entity_manager_for_crud_operations(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/model', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);

            $manager = $database->entityManager();

            $first = OrmPost::create([
                'title' => 'First Post',
                'published' => true,
            ]);
            $second = OrmPost::create([
                'title' => 'Draft Post',
                'published' => false,
            ]);

            self::assertIsInt($first->id);
            self::assertIsInt($second->id);
            self::assertSame($first, OrmPost::find($first->id));

            $first->title = 'Updated Post';
            self::assertTrue($first->save());

            $published = OrmPost::query()
                ->where('published', true)
                ->orderBy('title')
                ->get();

            self::assertCount(1, $published);
            self::assertInstanceOf(OrmPost::class, $published[0]);
            self::assertSame($first, $published[0]);
            self::assertSame('Updated Post', $published[0]->title);

            self::assertTrue($first->delete());
            self::assertSame(EntityState::Detached, $manager->state($first));

            $manager->clear();

            self::assertNull(OrmPost::find($first->id));
            self::assertInstanceOf(OrmPost::class, OrmPost::find($second->id));
        } finally {
            $scope->end();
        }
    }

    public function test_orm_types_round_trip_datetime_enum_and_json_values(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/types', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_events', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('status');
                $table->string('occurred_at');
                $table->string('payload');
            }, true);

            $metadata = $app->make(EntityMetadataRegistry::class)->for(OrmEvent::class);
            self::assertSame('enum', $metadata->field('status')->type);
            self::assertSame(OrmEventStatus::class, $metadata->field('status')->enumClass);
            self::assertSame('datetime_immutable', $metadata->field('occurredAt')->type);
            self::assertSame('json', $metadata->field('payload')->type);

            $createdAt = new DateTimeImmutable('2026-09-24T10:30:15+00:00');
            $event = OrmEvent::create([
                'name' => 'build.finished',
                'status' => OrmEventStatus::Published,
                'occurredAt' => $createdAt,
                'payload' => ['ok' => true, 'version' => 8],
            ]);

            self::assertIsInt($event->id);
            self::assertSame(OrmEventStatus::Published, $event->status);
            self::assertSame($createdAt->format(DATE_ATOM), $event->occurredAt->format(DATE_ATOM));
            self::assertSame(['ok' => true, 'version' => 8], $event->payload);

            $rawRow = $database->table('orm_events')->where('id', $event->id)->first();
            self::assertSame('published', $rawRow['status'] ?? null);
            self::assertSame($createdAt->format(DATE_ATOM), $rawRow['occurred_at'] ?? null);
            self::assertSame('{"ok":true,"version":8}', $rawRow['payload'] ?? null);

            $manager = $database->entityManager();
            $manager->clear();

            $reloaded = OrmEvent::query()
                ->where('status', OrmEventStatus::Published)
                ->first();

            self::assertInstanceOf(OrmEvent::class, $reloaded);
            self::assertSame(OrmEventStatus::Published, $reloaded->status);
            self::assertInstanceOf(DateTimeImmutable::class, $reloaded->occurredAt);
            self::assertSame($createdAt->format(DATE_ATOM), $reloaded->occurredAt->format(DATE_ATOM));
            self::assertSame(['ok' => true, 'version' => 8], $reloaded->payload);

            $reloaded->status = OrmEventStatus::Draft;
            $reloaded->payload = ['ok' => false, 'version' => 9];
            self::assertTrue($reloaded->save());

            $manager->clear();

            $updated = OrmEvent::find($event->id);
            self::assertInstanceOf(OrmEvent::class, $updated);
            self::assertSame(OrmEventStatus::Draft, $updated->status);
            self::assertSame(['ok' => false, 'version' => 9], $updated->payload);
        } finally {
            $scope->end();
        }
    }

    public function test_bidirectional_many_to_one_and_one_to_many_load_and_query_over_sqlite(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/relationships', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);

            $registry = $app->make(EntityMetadataRegistry::class);
            $postMeta = $registry->for(OrmBlogPost::class);
            $commentMeta = $registry->for(OrmBlogComment::class);

            self::assertTrue($postMeta->hasAssociation('comments'));
            self::assertSame(EntityAssociationMetadata::KIND_ONE_TO_MANY, $postMeta->association('comments')->kind);
            self::assertSame(OrmBlogComment::class, $postMeta->association('comments')->targetEntity);
            self::assertSame('post', $postMeta->association('comments')->mappedBy);
            self::assertTrue($postMeta->association('comments')->isInverseSide());

            self::assertTrue($commentMeta->hasAssociation('post'));
            self::assertSame(EntityAssociationMetadata::KIND_MANY_TO_ONE, $commentMeta->association('post')->kind);
            self::assertSame(OrmBlogPost::class, $commentMeta->association('post')->targetEntity);
            self::assertSame('comments', $commentMeta->association('post')->inversedBy);
            self::assertSame('post_id', $commentMeta->association('post')->sourceColumn);
            self::assertSame('id', $commentMeta->association('post')->targetColumn);
            self::assertTrue($commentMeta->association('post')->isOwningSide());

            $manager = $database->entityManager();

            $post = new OrmBlogPost();
            $post->title = 'Relaciones en VoltStack ORM';
            $post->published = true;
            $manager->persist($post);
            $manager->flush();
            self::assertIsInt($post->id);

            $firstComment = new OrmBlogComment();
            $firstComment->postId = $post->id;
            $firstComment->body = 'Primer comentario';
            $manager->persist($firstComment);

            $secondComment = new OrmBlogComment();
            $secondComment->postId = $post->id;
            $secondComment->body = 'Segundo comentario';
            $manager->persist($secondComment);
            $manager->flush();

            self::assertIsInt($firstComment->id);
            self::assertIsInt($secondComment->id);

            $rawComments = $database->table('orm_blog_comments')
                ->where('post_id', $post->id)
                ->orderBy('id')
                ->get();
            self::assertCount(2, $rawComments->rows());
            self::assertSame('Primer comentario', $rawComments->rows()[0]['body'] ?? null);
            self::assertSame('Segundo comentario', $rawComments->rows()[1]['body'] ?? null);

            $reloadedComment = $manager->find(OrmBlogComment::class, $firstComment->id);
            self::assertInstanceOf(OrmBlogComment::class, $reloadedComment);
            self::assertSame($firstComment, $reloadedComment);
            self::assertSame($post->id, $reloadedComment->postId);

            $loadedPost = $manager->loadToOne($reloadedComment, 'post');
            self::assertSame($post, $loadedPost);
            self::assertSame($post, $reloadedComment->post);
            self::assertSame('Relaciones en VoltStack ORM', $reloadedComment->post->title);

            $byPostObject = $database->repository(OrmBlogComment::class)
                ->findBy(['post' => $post], ['id' => 'asc']);
            self::assertCount(2, $byPostObject);
            self::assertSame($firstComment, $byPostObject[0]);
            self::assertSame($secondComment, $byPostObject[1]);

            $byPostId = $manager->query(OrmBlogComment::class)
                ->where('post', $post->id)
                ->orderBy('body')
                ->get();
            self::assertCount(2, $byPostId);
            self::assertSame($firstComment, $byPostId[0]);
            self::assertSame($secondComment, $byPostId[1]);

            $manager->clear();
            $clearedPost = $manager->find(OrmBlogPost::class, $post->id);
            self::assertNotSame($post, $clearedPost);

            $loadedComments = $manager->loadToMany($clearedPost, 'comments');
            self::assertCount(2, $loadedComments);
            self::assertSame($loadedComments, $clearedPost->comments);
            usort($loadedComments, static fn(OrmBlogComment $a, OrmBlogComment $b): int => $a->id <=> $b->id);
            self::assertSame('Primer comentario', $loadedComments[0]->body);
            self::assertSame('Segundo comentario', $loadedComments[1]->body);

            $sameComments = $manager->loadToMany($clearedPost, 'comments');
            self::assertSame($loadedComments[0], $sameComments[0]);
            self::assertSame($loadedComments[1], $sameComments[1]);

            $commentBackRef = $loadedComments[0]->post;
            self::assertNull($commentBackRef);
            $manager->loadToOne($loadedComments[0], 'post');
            self::assertSame($clearedPost, $loadedComments[0]->post);
        } finally {
            $scope->end();
        }
    }

    public function test_one_to_one_and_many_to_many_relationships_over_sqlite(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/relationships-v2', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_account_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_user_profiles', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('user_id');
                $table->string('bio');
            }, true);
            $database->schema()->create('orm_students', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_courses', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
            }, true);
            $database->schema()->create('orm_student_courses', function (TableBlueprint $table): void {
                $table->integer('student_id');
                $table->integer('course_id');
            }, true);

            $registry = $app->make(EntityMetadataRegistry::class);

            $userMeta = $registry->for(OrmAccountUser::class);
            $profileMeta = $registry->for(OrmUserProfile::class);
            $studentMeta = $registry->for(OrmStudent::class);
            $courseMeta = $registry->for(OrmCourse::class);

            self::assertTrue($userMeta->association('profile')->isOneToOne());
            self::assertTrue($userMeta->association('profile')->isInverseSide());
            self::assertSame('user', $userMeta->association('profile')->mappedBy);
            self::assertSame('user_id', $userMeta->association('profile')->targetColumn);

            self::assertTrue($profileMeta->association('user')->isOneToOne());
            self::assertTrue($profileMeta->association('user')->isOwningSide());
            self::assertSame('profile', $profileMeta->association('user')->inversedBy);
            self::assertSame('user_id', $profileMeta->association('user')->sourceColumn);

            self::assertTrue($studentMeta->association('courses')->isManyToMany());
            self::assertTrue($studentMeta->association('courses')->isOwningSide());
            self::assertSame('orm_student_courses', $studentMeta->association('courses')->joinTable);
            self::assertSame('student_id', $studentMeta->association('courses')->joinTableSourceColumn);
            self::assertSame('course_id', $studentMeta->association('courses')->joinTableTargetColumn);

            self::assertTrue($courseMeta->association('students')->isManyToMany());
            self::assertTrue($courseMeta->association('students')->isInverseSide());
            self::assertSame('course_id', $courseMeta->association('students')->joinTableSourceColumn);
            self::assertSame('student_id', $courseMeta->association('students')->joinTableTargetColumn);

            $manager = $database->entityManager();

            $user = new OrmAccountUser();
            $user->name = 'Ada';

            $profile = new OrmUserProfile();
            $profile->bio = 'VoltStack architect';
            $profile->user = $user;
            $user->profile = $profile;

            $manager->persist($user);
            $manager->persist($profile);
            $manager->flush();

            self::assertIsInt($user->id);
            self::assertIsInt($profile->id);
            self::assertSame($user->id, $profile->userId, 'OneToOne owning FK auto-synchronized from PHP reference');

            $profileRow = $database->table('orm_user_profiles')->where('id', $profile->id)->first();
            self::assertSame((string) $user->id, (string) ($profileRow['user_id'] ?? ''), 'Profile row stores FK to user');

            $profileByUser = $manager->query(OrmUserProfile::class)->where('user', $user)->first();
            self::assertSame($profile, $profileByUser, 'Owning OneToOne query by association works');

            $manager->clear();

            $loadedUser = $manager->find(OrmAccountUser::class, $user->id);
            self::assertInstanceOf(OrmAccountUser::class, $loadedUser);
            $loadedProfile = $manager->loadToOne($loadedUser, 'profile');
            self::assertInstanceOf(OrmUserProfile::class, $loadedProfile);
            self::assertSame('VoltStack architect', $loadedProfile->bio);
            self::assertSame($loadedProfile, $loadedUser->profile, 'Inverse OneToOne load populates property');

            $reloadedProfile = $manager->find(OrmUserProfile::class, $profile->id);
            self::assertInstanceOf(OrmUserProfile::class, $reloadedProfile);
            $owner = $manager->loadToOne($reloadedProfile, 'user');
            self::assertSame($loadedUser, $owner, 'Owning OneToOne load resolves the same managed user');
            self::assertSame($loadedUser, $reloadedProfile->user);

            $student = new OrmStudent();
            $student->name = 'Linus';

            $math = new OrmCourse();
            $math->title = 'Math';

            $history = new OrmCourse();
            $history->title = 'History';

            $student->courses = [$math, $history];

            $manager->persist($student);
            $manager->flush();

            self::assertIsInt($student->id);
            self::assertIsInt($math->id);
            self::assertIsInt($history->id);

            $membershipRows = $database->table('orm_student_courses')
                ->where('student_id', $student->id)
                ->get()
                ->rows();
            self::assertCount(2, $membershipRows, 'Owning ManyToMany persists 2 join rows');

            $manager->clear();

            $loadedStudent = $manager->find(OrmStudent::class, $student->id);
            self::assertInstanceOf(OrmStudent::class, $loadedStudent);

            $loadedCourses = $manager->loadToMany($loadedStudent, 'courses');
            self::assertCount(2, $loadedCourses);
            self::assertSame($loadedCourses, $loadedStudent->courses, 'ManyToMany load populates owning collection');
            usort($loadedCourses, static fn(OrmCourse $a, OrmCourse $b): int => strcmp($a->title, $b->title));
            self::assertSame(['History', 'Math'], array_map(
                static fn(OrmCourse $course): string => $course->title,
                $loadedCourses,
            ));

            $loadedMath = $manager->find(OrmCourse::class, $math->id);
            self::assertInstanceOf(OrmCourse::class, $loadedMath);
            $loadedStudents = $manager->loadToMany($loadedMath, 'students');
            self::assertCount(1, $loadedStudents);
            self::assertSame($loadedStudent->id, $loadedStudents[0]->id, 'Inverse ManyToMany resolves owning entity through join table');

            $science = new OrmCourse();
            $science->title = 'Science';
            $loadedStudent->courses = [$loadedCourses[0], $science];
            $manager->flush();

            self::assertIsInt($science->id, 'Cascade persist inserts new ManyToMany target before join rows');

            $membershipRowsAfter = $database->table('orm_student_courses')
                ->where('student_id', $loadedStudent->id)
                ->get()
                ->rows();
            self::assertCount(2, $membershipRowsAfter, 'Join table stays in sync after remove+add on owning collection');

            $mathMembership = $database->table('orm_student_courses')
                ->where('student_id', $loadedStudent->id)
                ->where('course_id', $math->id)
                ->first();
            self::assertNull($mathMembership, 'Removed ManyToMany membership deletes join row');

            $scienceMembership = $database->table('orm_student_courses')
                ->where('student_id', $loadedStudent->id)
                ->where('course_id', $science->id)
                ->first();
            self::assertNotNull($scienceMembership, 'Added ManyToMany membership inserts join row');

            $manager->remove($loadedStudent);
            $manager->flush();

            $remainingMemberships = $database->table('orm_student_courses')
                ->where('student_id', $student->id)
                ->get()
                ->rows();
            self::assertCount(0, $remainingMemberships, 'Deleting owning entity removes join-table memberships');

            $courseCount = $database->table('orm_courses')->count();
            self::assertSame(3, $courseCount, 'Deleting owning entity does not delete target entities without cascade REMOVE');
        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_with_preloads_batch_loads_supported_associations(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/preloads', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);
            $database->schema()->create('orm_account_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_user_profiles', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('user_id');
                $table->string('bio');
            }, true);
            $database->schema()->create('orm_students', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_courses', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
            }, true);
            $database->schema()->create('orm_student_courses', function (TableBlueprint $table): void {
                $table->integer('student_id');
                $table->integer('course_id');
            }, true);

            $em = $database->entityManager();

            $alpha = new OrmBlogPost();
            $alpha->title = 'Alpha';
            $alpha->published = true;

            $beta = new OrmBlogPost();
            $beta->title = 'Beta';
            $beta->published = true;

            $em->persist($alpha);
            $em->persist($beta);
            $em->flush();

            $commentA1 = new OrmBlogComment();
            $commentA1->body = 'Alpha #1';
            $commentA1->post = $alpha;

            $commentA2 = new OrmBlogComment();
            $commentA2->body = 'Alpha #2';
            $commentA2->post = $alpha;

            $commentB1 = new OrmBlogComment();
            $commentB1->body = 'Beta #1';
            $commentB1->post = $beta;

            $em->persist($commentA1);
            $em->persist($commentA2);
            $em->persist($commentB1);

            $ada = new OrmAccountUser();
            $ada->name = 'Ada';

            $adaProfile = new OrmUserProfile();
            $adaProfile->bio = 'Architect';
            $adaProfile->user = $ada;
            $ada->profile = $adaProfile;

            $grace = new OrmAccountUser();
            $grace->name = 'Grace';

            $graceProfile = new OrmUserProfile();
            $graceProfile->bio = 'Compiler pioneer';
            $graceProfile->user = $grace;
            $grace->profile = $graceProfile;

            $em->persist($ada);
            $em->persist($adaProfile);
            $em->persist($grace);
            $em->persist($graceProfile);

            $math = new OrmCourse();
            $math->title = 'Math';

            $history = new OrmCourse();
            $history->title = 'History';

            $science = new OrmCourse();
            $science->title = 'Science';

            $linus = new OrmStudent();
            $linus->name = 'Linus';
            $linus->courses = [$math, $history];

            $margaret = new OrmStudent();
            $margaret->name = 'Margaret';
            $margaret->courses = [$history, $science];

            $em->persist($linus);
            $em->persist($margaret);
            $em->flush();

            $em->clear();

            $posts = $em->query(OrmBlogPost::class)
                ->with('comments')
                ->orderBy('title')
                ->get();
            self::assertCount(2, $posts);
            self::assertSame('Alpha', $posts[0]->title);
            self::assertSame('Beta', $posts[1]->title);
            self::assertCount(2, $posts[0]->comments);
            self::assertCount(1, $posts[1]->comments);

            usort($posts[0]->comments, static fn(OrmBlogComment $a, OrmBlogComment $b): int => strcmp($a->body, $b->body));
            self::assertSame('Alpha #1', $posts[0]->comments[0]->body);
            self::assertSame('Alpha #2', $posts[0]->comments[1]->body);

            $comments = $em->query(OrmBlogComment::class)
                ->with('post')
                ->orderBy('body')
                ->get();
            self::assertCount(3, $comments);
            self::assertSame('Alpha', $comments[0]->post?->title);
            self::assertSame('Alpha', $comments[1]->post?->title);
            self::assertSame('Beta', $comments[2]->post?->title);
            self::assertSame($comments[0]->post, $comments[1]->post, 'Owning to-one preload reuses the same managed target');

            $loadedAda = $em->query(OrmAccountUser::class)
                ->with('profile')
                ->where('name', 'Ada')
                ->first();
            self::assertInstanceOf(OrmAccountUser::class, $loadedAda);
            self::assertInstanceOf(OrmUserProfile::class, $loadedAda->profile);
            self::assertSame('Architect', $loadedAda->profile->bio);

            $courses = $em->query(OrmCourse::class)
                ->with('students')
                ->orderBy('title')
                ->get();
            self::assertCount(3, $courses);

            $studentsByCourse = [];
            foreach ($courses as $course) {
                $studentsByCourse[$course->title] = array_map(
                    static fn(OrmStudent $student): string => $student->name,
                    $course->students,
                );
                sort($studentsByCourse[$course->title]);
            }

            self::assertSame(['Linus', 'Margaret'], $studentsByCourse['History']);
            self::assertSame(['Linus'], $studentsByCourse['Math']);
            self::assertSame(['Margaret'], $studentsByCourse['Science']);

            $students = $em->query(OrmStudent::class)
                ->with('courses')
                ->orderBy('name')
                ->get();
            self::assertCount(2, $students);
            self::assertCount(2, $students[0]->courses);
            self::assertCount(2, $students[1]->courses);

            usort($students[0]->courses, static fn(OrmCourse $a, OrmCourse $b): int => strcmp($a->title, $b->title));
            self::assertSame(['History', 'Math'], array_map(
                static fn(OrmCourse $course): string => $course->title,
                $students[0]->courses,
            ));

            $students[0]->courses = array_values(array_filter(
                $students[0]->courses,
                static fn(OrmCourse $course): bool => $course->title !== 'Math',
            ));
            $em->flush();

            $remainingMemberships = $database->table('orm_student_courses')
                ->where('student_id', $students[0]->id)
                ->get()
                ->rows();
            self::assertCount(1, $remainingMemberships, 'Preloaded ManyToMany collection stays flushable after mutation');
        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_supports_nested_preload_paths_for_root_and_joined_associations(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/preloads-nested', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);
            $database->schema()->create('orm_account_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_user_profiles', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('user_id')->nullable();
                $table->string('bio');
            }, true);

            $em = $database->entityManager();

            $alpha = new OrmBlogPost();
            $alpha->title = 'Alpha';
            $alpha->published = true;

            $beta = new OrmBlogPost();
            $beta->title = 'Beta';
            $beta->published = true;

            $em->persist($alpha);
            $em->persist($beta);
            $em->flush();

            $commentA1 = new OrmBlogComment();
            $commentA1->body = 'Alpha #1';
            $commentA1->post = $alpha;

            $commentA2 = new OrmBlogComment();
            $commentA2->body = 'Alpha #2';
            $commentA2->post = $alpha;

            $commentB1 = new OrmBlogComment();
            $commentB1->body = 'Beta #1';
            $commentB1->post = $beta;

            $em->persist($commentA1);
            $em->persist($commentA2);
            $em->persist($commentB1);

            $ada = new OrmAccountUser();
            $ada->name = 'Ada';

            $grace = new OrmAccountUser();
            $grace->name = 'Grace';

            $em->persist($ada);
            $em->persist($grace);
            $em->flush();

            $adaProfile = new OrmUserProfile();
            $adaProfile->bio = 'Architect';
            $adaProfile->user = $ada;
            $ada->profile = $adaProfile;

            $em->persist($adaProfile);
            $em->flush();
            $em->clear();

            $posts = $em->query(OrmBlogPost::class)
                ->with('comments.post')
                ->orderBy('title')
                ->get();

            self::assertCount(2, $posts);
            self::assertSame('Alpha', $posts[0]->title);
            self::assertSame('Beta', $posts[1]->title);
            self::assertCount(2, $posts[0]->comments);
            self::assertCount(1, $posts[1]->comments);
            usort($posts[0]->comments, static fn(OrmBlogComment $a, OrmBlogComment $b): int => strcmp($a->body, $b->body));
            self::assertSame($posts[0], $posts[0]->comments[0]->post);
            self::assertSame($posts[0], $posts[0]->comments[1]->post);
            self::assertSame($posts[1], $posts[1]->comments[0]->post);

            $em->clear();

            $users = $em->query(OrmAccountUser::class)
                ->leftJoin('profile', 'pr')
                ->with('profile.user')
                ->orderBy('name')
                ->get();

            self::assertCount(2, $users);
            self::assertSame('Ada', $users[0]->name);
            self::assertInstanceOf(OrmUserProfile::class, $users[0]->profile);
            self::assertSame($users[0], $users[0]->profile->user);
            self::assertSame('Grace', $users[1]->name);
            self::assertNull($users[1]->profile);
        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_can_join_to_one_associations_via_metadata_for_filters_and_projections(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/joined-query', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);
            $database->schema()->create('orm_account_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_user_profiles', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('user_id')->nullable();
                $table->string('bio');
            }, true);

            $em = $database->entityManager();

            $alpha = new OrmBlogPost();
            $alpha->title = 'Alpha';
            $alpha->published = true;

            $beta = new OrmBlogPost();
            $beta->title = 'Beta';
            $beta->published = true;

            $em->persist($alpha);
            $em->persist($beta);
            $em->flush();

            $commentA = new OrmBlogComment();
            $commentA->body = 'Alpha comment';
            $commentA->post = $alpha;

            $commentB = new OrmBlogComment();
            $commentB->body = 'Beta comment';
            $commentB->post = $beta;

            $em->persist($commentA);
            $em->persist($commentB);

            $ada = new OrmAccountUser();
            $ada->name = 'Ada';

            $grace = new OrmAccountUser();
            $grace->name = 'Grace';

            $em->persist($ada);
            $em->persist($grace);
            $em->flush();

            $profile = new OrmUserProfile();
            $profile->bio = 'Platform engineer';
            $profile->user = $ada;
            $em->persist($profile);
            $em->flush();
            $em->clear();

            $commentRows = $em->query(OrmBlogComment::class)
                ->join('post', 'p')
                ->select('body', 'post.title')
                ->orderBy('post.title')
                ->rows();
            self::assertSame([
                ['body' => 'Alpha comment', 'post.title' => 'Alpha'],
                ['body' => 'Beta comment', 'post.title' => 'Beta'],
            ], $commentRows);

            self::assertSame('Alpha', $em->query(OrmBlogComment::class)
                ->join('post', 'p')
                ->where('body', 'Alpha comment')
                ->value('post.title'));

            $filteredComments = $em->query(OrmBlogComment::class)
                ->join('post', 'p')
                ->where('post.title', 'Alpha')
                ->get();
            self::assertCount(1, $filteredComments);
            self::assertSame('Alpha comment', $filteredComments[0]->body);

            $userRows = $em->query(OrmAccountUser::class)
                ->leftJoin('profile', 'pr')
                ->select('name', 'profile.bio')
                ->orderBy('name')
                ->rows();
            self::assertSame([
                ['name' => 'Ada', 'profile.bio' => 'Platform engineer'],
                ['name' => 'Grace', 'profile.bio' => null],
            ], $userRows);

            $filteredUsers = $em->query(OrmAccountUser::class)
                ->leftJoin('profile', 'pr')
                ->where('profile.bio', 'Platform engineer')
                ->get();
            self::assertCount(1, $filteredUsers);
            self::assertSame('Ada', $filteredUsers[0]->name);

        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_joined_to_one_associations_are_hydrated_on_entity_results(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/joined-hydration', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);
            $database->schema()->create('orm_account_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_user_profiles', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('user_id')->nullable();
                $table->string('bio');
            }, true);

            $em = $database->entityManager();

            $post = new OrmBlogPost();
            $post->title = 'Joined post';
            $post->published = true;
            $em->persist($post);
            $em->flush();

            $comment = new OrmBlogComment();
            $comment->body = 'Joined comment';
            $comment->post = $post;
            $em->persist($comment);

            $ada = new OrmAccountUser();
            $ada->name = 'Ada';

            $grace = new OrmAccountUser();
            $grace->name = 'Grace';

            $em->persist($ada);
            $em->persist($grace);
            $em->flush();

            $profile = new OrmUserProfile();
            $profile->bio = 'Joined profile';
            $profile->user = $ada;
            $em->persist($profile);
            $em->flush();
            $em->clear();

            $loadedComment = $em->query(OrmBlogComment::class)
                ->join('post', 'p')
                ->where('body', 'Joined comment')
                ->first();
            self::assertInstanceOf(OrmBlogComment::class, $loadedComment);
            self::assertInstanceOf(OrmBlogPost::class, $loadedComment->post);
            self::assertSame('Joined post', $loadedComment->post->title);
            self::assertSame($loadedComment->post, $em->find(OrmBlogPost::class, $loadedComment->postId));

            $profiles = $em->query(OrmUserProfile::class)
                ->join('user', 'u')
                ->get();
            self::assertCount(1, $profiles);
            self::assertInstanceOf(OrmAccountUser::class, $profiles[0]->user);
            self::assertSame('Ada', $profiles[0]->user->name);
            self::assertSame($profiles[0], $profiles[0]->user->profile);

            $users = $em->query(OrmAccountUser::class)
                ->leftJoin('profile', 'pr')
                ->orderBy('name')
                ->get();
            self::assertCount(2, $users);
            self::assertSame('Joined profile', $users[0]->profile?->bio);
            self::assertNull($users[1]->profile);
        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_can_join_to_many_associations_with_root_deduplication_collection_hydration_and_root_safe_windowing(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/joined-to-many', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);
            $database->schema()->create('orm_students', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_courses', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
            }, true);
            $database->schema()->create('orm_student_courses', function (TableBlueprint $table): void {
                $table->integer('student_id');
                $table->integer('course_id');
            }, true);

            $em = $database->entityManager();

            $alpha = new OrmBlogPost();
            $alpha->title = 'Alpha';
            $alpha->published = true;

            $beta = new OrmBlogPost();
            $beta->title = 'Beta';
            $beta->published = true;

            $gamma = new OrmBlogPost();
            $gamma->title = 'Gamma';
            $gamma->published = true;

            $em->persist($alpha);
            $em->persist($beta);
            $em->persist($gamma);
            $em->flush();

            $alpha1 = new OrmBlogComment();
            $alpha1->body = 'Alpha #1';
            $alpha1->post = $alpha;

            $alpha2 = new OrmBlogComment();
            $alpha2->body = 'Alpha #2';
            $alpha2->post = $alpha;

            $beta1 = new OrmBlogComment();
            $beta1->body = 'Beta #1';
            $beta1->post = $beta;

            $em->persist($alpha1);
            $em->persist($alpha2);
            $em->persist($beta1);

            $math = new OrmCourse();
            $math->title = 'Math';

            $history = new OrmCourse();
            $history->title = 'History';

            $science = new OrmCourse();
            $science->title = 'Science';

            $linus = new OrmStudent();
            $linus->name = 'Linus';
            $linus->courses = [$math, $history];

            $margaret = new OrmStudent();
            $margaret->name = 'Margaret';
            $margaret->courses = [$history];

            $ada = new OrmStudent();
            $ada->name = 'Ada';
            $ada->courses = [];

            $em->persist($linus);
            $em->persist($margaret);
            $em->persist($ada);
            $em->flush();
            $em->clear();

            $commentRows = $em->query(OrmBlogPost::class)
                ->join('comments', 'c')
                ->select('title', 'comments.body')
                ->orderBy('comments.body')
                ->rows();
            self::assertSame([
                ['title' => 'Alpha', 'comments.body' => 'Alpha #1'],
                ['title' => 'Alpha', 'comments.body' => 'Alpha #2'],
                ['title' => 'Beta', 'comments.body' => 'Beta #1'],
            ], $commentRows);

            $posts = $em->query(OrmBlogPost::class)
                ->leftJoin('comments', 'c')
                ->orderBy('title')
                ->get();
            self::assertCount(3, $posts);
            self::assertSame('Alpha', $posts[0]->title);
            self::assertCount(2, $posts[0]->comments);
            self::assertSame($posts[0], $posts[0]->comments[0]->post);
            self::assertSame($posts[0], $posts[0]->comments[1]->post);
            self::assertCount(1, $posts[1]->comments);
            self::assertSame([], $posts[2]->comments);
            self::assertSame(3, $em->query(OrmBlogPost::class)->leftJoin('comments', 'c')->count());

            $windowedPost = $em->query(OrmBlogPost::class)
                ->leftJoin('comments', 'c')
                ->orderBy('title')
                ->limit(1)
                ->get();
            self::assertCount(1, $windowedPost);
            self::assertSame('Alpha', $windowedPost[0]->title);
            self::assertCount(2, $windowedPost[0]->comments);

            $commentOrderedWindow = $em->query(OrmBlogPost::class)
                ->join('comments', 'c')
                ->orderBy('comments.body')
                ->limit(1)
                ->get();
            self::assertCount(1, $commentOrderedWindow);
            self::assertSame('Alpha', $commentOrderedWindow[0]->title);
            self::assertCount(2, $commentOrderedWindow[0]->comments);

            $offsetPost = $em->query(OrmBlogPost::class)
                ->join('comments', 'c')
                ->orderBy('title')
                ->offset(1)
                ->first();
            self::assertInstanceOf(OrmBlogPost::class, $offsetPost);
            self::assertSame('Beta', $offsetPost->title);
            self::assertCount(1, $offsetPost->comments);

            $commentOrderedOffsetPost = $em->query(OrmBlogPost::class)
                ->join('comments', 'c')
                ->orderBy('comments.body')
                ->offset(1)
                ->first();
            self::assertInstanceOf(OrmBlogPost::class, $commentOrderedOffsetPost);
            self::assertSame('Beta', $commentOrderedOffsetPost->title);
            self::assertCount(1, $commentOrderedOffsetPost->comments);

            $students = $em->query(OrmStudent::class)
                ->leftJoin('courses', 'co')
                ->orderBy('name')
                ->get();
            self::assertCount(3, $students);
            self::assertSame('Ada', $students[0]->name);
            self::assertSame([], $students[0]->courses);
            self::assertCount(2, $students[1]->courses);
            usort($students[1]->courses, static fn(OrmCourse $a, OrmCourse $b): int => strcmp($a->title, $b->title));
            self::assertSame(['History', 'Math'], array_map(
                static fn(OrmCourse $course): string => $course->title,
                $students[1]->courses,
            ));
            self::assertCount(1, $students[2]->courses);

            $windowedStudent = $em->query(OrmStudent::class)
                ->leftJoin('courses', 'co')
                ->orderBy('name')
                ->limit(1)
                ->offset(1)
                ->first();
            self::assertInstanceOf(OrmStudent::class, $windowedStudent);
            self::assertSame('Linus', $windowedStudent->name);
            self::assertCount(2, $windowedStudent->courses);
        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_select_rows_firstrow_pluck_and_value_support_projection_mode(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/projections', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_events', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('status');
                $table->timestamp('occurred_at');
                $table->string('payload');
            }, true);
            $database->schema()->create('orm_products', function (TableBlueprint $table): void {
                $table->id();
                $table->string('sku');
                $table->integer('price_amount')->nullable();
                $table->string('price_currency', 3)->nullable();
                $table->string('dim_width')->nullable();
                $table->string('dim_height')->nullable();
                $table->string('dim_depth')->nullable();
            }, true);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);

            $em = $database->entityManager();

            $event = new OrmEvent();
            $event->name = 'Launch';
            $event->status = OrmEventStatus::Published;
            $event->occurredAt = new DateTimeImmutable('2026-10-02T10:15:00+00:00');
            $event->payload = ['channel' => 'web', 'attempts' => 3];
            $em->persist($event);

            $product = new OrmProduct();
            $product->sku = 'CHAIR-001';
            $product->price = new OrmMoney();
            $product->price->amount = 2599;
            $product->price->currency = 'USD';
            $product->dimensions = new OrmDimensions();
            $product->dimensions->width = '44';
            $product->dimensions->height = '91';
            $product->dimensions->depth = '52';
            $em->persist($product);

            $post = new OrmBlogPost();
            $post->title = 'Alpha';
            $post->published = true;
            $em->persist($post);
            $em->flush();

            $commentA = new OrmBlogComment();
            $commentA->body = 'Alpha #1';
            $commentA->post = $post;

            $commentB = new OrmBlogComment();
            $commentB->body = 'Alpha #2';
            $commentB->post = $post;

            $em->persist($commentA);
            $em->persist($commentB);
            $em->flush();
            $em->clear();

            $eventRows = $em->query(OrmEvent::class)
                ->select('name', 'status', 'occurredAt', 'payload')
                ->rows();
            self::assertCount(1, $eventRows);
            self::assertSame('Launch', $eventRows[0]['name']);
            self::assertSame(OrmEventStatus::Published, $eventRows[0]['status']);
            self::assertInstanceOf(DateTimeImmutable::class, $eventRows[0]['occurredAt']);
            self::assertSame('2026-10-02T10:15:00+00:00', $eventRows[0]['occurredAt']->format(DATE_ATOM));
            self::assertSame(['channel' => 'web', 'attempts' => 3], $eventRows[0]['payload']);

            $productRow = $em->query(OrmProduct::class)
                ->select('sku', 'price.amount', 'price.currency', 'dimensions.depth')
                ->where('sku', 'CHAIR-001')
                ->firstRow();
            self::assertNotNull($productRow);
            self::assertSame('CHAIR-001', $productRow['sku']);
            self::assertSame(2599, $productRow['price.amount']);
            self::assertSame('USD', $productRow['price.currency']);
            self::assertSame('52', $productRow['dimensions.depth']);

            $commentRows = $em->query(OrmBlogComment::class)
                ->select('body', 'post')
                ->orderBy('body')
                ->rows();
            self::assertCount(2, $commentRows);
            self::assertSame('Alpha #1', $commentRows[0]['body']);
            self::assertSame($post->id, $commentRows[0]['post']);
            self::assertSame($post->id, $commentRows[1]['post']);

            self::assertSame(['Alpha #1', 'Alpha #2'], $em->query(OrmBlogComment::class)
                ->orderBy('body')
                ->pluck('body'));
            self::assertSame($post->id, $em->query(OrmBlogComment::class)
                ->where('body', 'Alpha #1')
                ->value('post'));

            try {
                $em->query(OrmEvent::class)
                    ->select('name')
                    ->get();
                self::fail('Projection queries must not hydrate partial entities via get().');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('rows()/firstRow()/pluck()/value()', $exception->getMessage());
            }
        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_partial_hydration_returns_detached_entities_until_refresh(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/partial-hydration', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_events', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('status');
                $table->timestamp('occurred_at');
                $table->string('payload');
            }, true);
            $database->schema()->create('orm_products', function (TableBlueprint $table): void {
                $table->id();
                $table->string('sku');
                $table->integer('price_amount')->nullable();
                $table->string('price_currency', 3)->nullable();
                $table->string('dim_width')->nullable();
                $table->string('dim_height')->nullable();
                $table->string('dim_depth')->nullable();
            }, true);

            $em = $database->entityManager();

            $event = new OrmEvent();
            $event->name = 'Launch';
            $event->status = OrmEventStatus::Published;
            $event->occurredAt = new DateTimeImmutable('2026-10-02T11:00:00+00:00');
            $event->payload = ['channel' => 'api', 'ok' => true];
            $em->persist($event);

            $product = new OrmProduct();
            $product->sku = 'LAMP-001';
            $product->price = new OrmMoney();
            $product->price->amount = 7999;
            $product->price->currency = 'USD';
            $product->dimensions = new OrmDimensions();
            $product->dimensions->width = '20';
            $product->dimensions->height = '45';
            $product->dimensions->depth = '20';
            $em->persist($product);
            $em->flush();
            $em->clear();

            $partialEvent = $em->query(OrmEvent::class)
                ->partial('name', 'status')
                ->firstPartial();
            self::assertInstanceOf(OrmEvent::class, $partialEvent);
            self::assertSame(EntityState::Detached, $em->state($partialEvent));
            self::assertFalse($em->contains($partialEvent));
            self::assertSame('Launch', $partialEvent->name);
            self::assertSame(OrmEventStatus::Published, $partialEvent->status);
            self::assertTrue($this->isPropertyInitialized($partialEvent, 'id'));
            self::assertFalse($this->isPropertyInitialized($partialEvent, 'occurredAt'));
            self::assertSame([], $partialEvent->payload);

            try {
                $em->persist($partialEvent);
                self::fail('Partial entities must not be persisted directly.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Cannot persist partial entity', $exception->getMessage());
            }

            $em->refresh($partialEvent);
            self::assertSame(EntityState::Managed, $em->state($partialEvent));
            self::assertTrue($em->contains($partialEvent));
            self::assertTrue($this->isPropertyInitialized($partialEvent, 'occurredAt'));
            self::assertSame('2026-10-02T11:00:00+00:00', $partialEvent->occurredAt->format(DATE_ATOM));
            self::assertSame(['channel' => 'api', 'ok' => true], $partialEvent->payload);

            $partialProduct = $em->query(OrmProduct::class)
                ->partial('sku', 'price.amount', 'dimensions.depth')
                ->where('sku', 'LAMP-001')
                ->firstPartial();
            self::assertInstanceOf(OrmProduct::class, $partialProduct);
            self::assertSame('LAMP-001', $partialProduct->sku);
            self::assertInstanceOf(OrmMoney::class, $partialProduct->price);
            self::assertInstanceOf(OrmDimensions::class, $partialProduct->dimensions);
            self::assertTrue($this->isPropertyInitialized($partialProduct->price, 'amount'));
            self::assertFalse($this->isPropertyInitialized($partialProduct->price, 'currency'));
            self::assertTrue($this->isPropertyInitialized($partialProduct->dimensions, 'depth'));
            self::assertFalse($this->isPropertyInitialized($partialProduct->dimensions, 'width'));

            try {
                $em->query(OrmEvent::class)
                    ->partial('name')
                    ->get();
                self::fail('Partial queries must not hydrate full entities via get().');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('getPartial()/firstPartial()', $exception->getMessage());
            }
        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_partial_managed_hydration_tracks_only_loaded_fields_and_upgrades_on_find(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/partial-managed-hydration', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_events', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('status');
                $table->timestamp('occurred_at');
                $table->string('payload');
            }, true);
            $database->schema()->create('orm_products', function (TableBlueprint $table): void {
                $table->id();
                $table->string('sku');
                $table->integer('price_amount')->nullable();
                $table->string('price_currency', 3)->nullable();
                $table->string('dim_width')->nullable();
                $table->string('dim_height')->nullable();
                $table->string('dim_depth')->nullable();
            }, true);

            $em = $database->entityManager();

            $event = new OrmEvent();
            $event->name = 'Launch';
            $event->status = OrmEventStatus::Published;
            $event->occurredAt = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
            $event->payload = ['channel' => 'queue', 'ok' => true];
            $em->persist($event);

            $product = new OrmProduct();
            $product->sku = 'DESK-001';
            $product->price = new OrmMoney();
            $product->price->amount = 15999;
            $product->price->currency = 'USD';
            $product->dimensions = new OrmDimensions();
            $product->dimensions->width = '120';
            $product->dimensions->height = '75';
            $product->dimensions->depth = '60';
            $em->persist($product);
            $em->flush();
            $em->clear();

            $managedPartialEvent = $em->query(OrmEvent::class)
                ->partialManaged('name', 'status')
                ->firstPartialManaged();
            self::assertInstanceOf(OrmEvent::class, $managedPartialEvent);
            self::assertSame(EntityState::Managed, $em->state($managedPartialEvent));
            self::assertTrue($em->contains($managedPartialEvent));
            self::assertSame('Launch', $managedPartialEvent->name);
            self::assertSame(OrmEventStatus::Published, $managedPartialEvent->status);
            self::assertFalse($this->isPropertyInitialized($managedPartialEvent, 'occurredAt'));

            $managedPartialEvent->name = 'Launch Updated';
            $em->flush();

            $rawEvent = $database->table('orm_events')
                ->where('id', $managedPartialEvent->id)
                ->first();
            self::assertNotNull($rawEvent);
            self::assertSame('Launch Updated', $rawEvent['name'] ?? null);
            self::assertSame('published', $rawEvent['status'] ?? null);
            self::assertSame('2026-10-02T12:00:00+00:00', $rawEvent['occurred_at'] ?? null);
            self::assertIsString($rawEvent['payload'] ?? null);
            self::assertStringContainsString('"channel":"queue"', $rawEvent['payload'] ?? '');

            try {
                $em->remove($managedPartialEvent);
                self::fail('Managed partial entities must not be removed directly.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Cannot remove managed partial entity', $exception->getMessage());
            }

            $foundEvent = $em->find(OrmEvent::class, $managedPartialEvent->id);
            self::assertSame($managedPartialEvent, $foundEvent, 'find() upgrades the existing managed partial instance');
            self::assertTrue($this->isPropertyInitialized($managedPartialEvent, 'occurredAt'));
            self::assertSame('2026-10-02T12:00:00+00:00', $managedPartialEvent->occurredAt->format(DATE_ATOM));
            self::assertSame(['channel' => 'queue', 'ok' => true], $managedPartialEvent->payload);

            $em->clear();

            $managedPartialProduct = $em->query(OrmProduct::class)
                ->partialManaged('sku', 'price.amount')
                ->where('sku', 'DESK-001')
                ->firstPartialManaged();
            self::assertInstanceOf(OrmProduct::class, $managedPartialProduct);
            self::assertSame(EntityState::Managed, $em->state($managedPartialProduct));
            self::assertInstanceOf(OrmMoney::class, $managedPartialProduct->price);
            self::assertTrue($this->isPropertyInitialized($managedPartialProduct->price, 'amount'));
            self::assertFalse($this->isPropertyInitialized($managedPartialProduct->price, 'currency'));

            $managedPartialProduct->price->amount = 16999;
            $em->flush();

            $rawProduct = $database->table('orm_products')
                ->where('id', $managedPartialProduct->id)
                ->first();
            self::assertNotNull($rawProduct);
            self::assertSame(16999, $rawProduct['price_amount'] ?? null);
            self::assertSame('USD', $rawProduct['price_currency'] ?? null);
            self::assertSame('120', $rawProduct['dim_width'] ?? null);

            try {
                $em->query(OrmEvent::class)
                    ->partialManaged('name')
                    ->get();
                self::fail('Managed partial queries must not hydrate full entities via get().');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('getPartialManaged()/firstPartialManaged()', $exception->getMessage());
            }
        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_partial_relational_hydration_supports_joined_to_one_associations(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/partial-relational-hydration', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);
            $database->schema()->create('orm_account_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_user_profiles', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('user_id')->nullable();
                $table->string('bio');
            }, true);

            $em = $database->entityManager();

            $post = new OrmBlogPost();
            $post->title = 'Alpha';
            $post->published = true;
            $em->persist($post);
            $em->flush();

            $comment = new OrmBlogComment();
            $comment->body = 'Alpha #1';
            $comment->post = $post;
            $em->persist($comment);

            $userWithProfile = new OrmAccountUser();
            $userWithProfile->name = 'Ada';

            $userWithoutProfile = new OrmAccountUser();
            $userWithoutProfile->name = 'Linus';

            $profile = new OrmUserProfile();
            $profile->bio = 'Joined profile';
            $profile->user = $userWithProfile;

            $em->persist($userWithProfile);
            $em->persist($userWithoutProfile);
            $em->persist($profile);
            $em->flush();
            $em->clear();

            $partialComment = $em->query(OrmBlogComment::class)
                ->join('post', 'p')
                ->partial('body', 'post.title')
                ->where('body', 'Alpha #1')
                ->firstPartial();
            self::assertInstanceOf(OrmBlogComment::class, $partialComment);
            self::assertSame(EntityState::Detached, $em->state($partialComment));
            self::assertFalse($em->contains($partialComment));
            self::assertSame('Alpha #1', $partialComment->body);
            self::assertInstanceOf(OrmBlogPost::class, $partialComment->post);
            self::assertSame('Alpha', $partialComment->post->title);
            self::assertTrue($this->isPropertyInitialized($partialComment->post, 'id'));
            self::assertFalse($this->isPropertyInitialized($partialComment->post, 'published'));

            $partialUsers = $em->query(OrmAccountUser::class)
                ->leftJoin('profile', 'pr')
                ->partial('name', 'profile.bio')
                ->orderBy('name')
                ->getPartial();
            self::assertCount(2, $partialUsers);
            self::assertSame('Ada', $partialUsers[0]->name);
            self::assertInstanceOf(OrmUserProfile::class, $partialUsers[0]->profile);
            self::assertSame('Joined profile', $partialUsers[0]->profile->bio);
            self::assertSame($partialUsers[0], $partialUsers[0]->profile->user);
            self::assertSame('Linus', $partialUsers[1]->name);
            self::assertNull($partialUsers[1]->profile);

            $em->clear();

            $managedPartialComment = $em->query(OrmBlogComment::class)
                ->join('post', 'p')
                ->partialManaged('body', 'post.title')
                ->where('body', 'Alpha #1')
                ->firstPartialManaged();
            self::assertInstanceOf(OrmBlogComment::class, $managedPartialComment);
            self::assertSame(EntityState::Managed, $em->state($managedPartialComment));
            self::assertTrue($em->contains($managedPartialComment));
            self::assertInstanceOf(OrmBlogPost::class, $managedPartialComment->post);
            self::assertTrue($em->contains($managedPartialComment->post));
            self::assertFalse($this->isPropertyInitialized($managedPartialComment->post, 'published'));

            $managedPartialComment->body = 'Alpha #1 updated';
            $managedPartialComment->post->title = 'Alpha updated';
            $em->flush();

            $rawComment = $database->table('orm_blog_comments')
                ->where('id', $managedPartialComment->id)
                ->first();
            self::assertNotNull($rawComment);
            self::assertSame('Alpha #1 updated', $rawComment['body'] ?? null);

            $rawPost = $database->table('orm_blog_posts')
                ->where('id', $managedPartialComment->post->id)
                ->first();
            self::assertNotNull($rawPost);
            self::assertSame('Alpha updated', $rawPost['title'] ?? null);
            self::assertSame(1, $rawPost['published'] ?? null);

            $foundPost = $em->find(OrmBlogPost::class, $managedPartialComment->post->id);
            self::assertSame($managedPartialComment->post, $foundPost);
            self::assertTrue($this->isPropertyInitialized($managedPartialComment->post, 'published'));
            self::assertTrue($managedPartialComment->post->published);

        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_partial_relational_hydration_supports_joined_to_many_associations_in_detached_mode(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/partial-relational-hydration-to-many', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);
            $database->schema()->create('orm_students', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_courses', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
            }, true);
            $database->schema()->create('orm_student_courses', function (TableBlueprint $table): void {
                $table->integer('student_id');
                $table->integer('course_id');
            }, true);

            $em = $database->entityManager();

            $alpha = new OrmBlogPost();
            $alpha->title = 'Alpha';
            $alpha->published = true;

            $beta = new OrmBlogPost();
            $beta->title = 'Beta';
            $beta->published = false;

            $gamma = new OrmBlogPost();
            $gamma->title = 'Gamma';
            $gamma->published = true;

            $em->persist($alpha);
            $em->persist($beta);
            $em->persist($gamma);
            $em->flush();

            $commentA = new OrmBlogComment();
            $commentA->body = 'A-1';
            $commentA->post = $alpha;

            $commentB = new OrmBlogComment();
            $commentB->body = 'A-2';
            $commentB->post = $alpha;

            $commentC = new OrmBlogComment();
            $commentC->body = 'B-1';
            $commentC->post = $beta;

            $student = new OrmStudent();
            $student->name = 'Ada';

            $courseA = new OrmCourse();
            $courseA->title = 'Compilers';

            $courseB = new OrmCourse();
            $courseB->title = 'Runtime Systems';

            $student->courses = [$courseA, $courseB];
            $courseA->students = [$student];
            $courseB->students = [$student];

            $em->persist($commentA);
            $em->persist($commentB);
            $em->persist($commentC);
            $em->persist($student);
            $em->persist($courseA);
            $em->persist($courseB);
            $em->flush();
            $em->clear();

            $partialPosts = $em->query(OrmBlogPost::class)
                ->leftJoin('comments', 'c')
                ->partial('title', 'comments.body')
                ->orderBy('title')
                ->getPartial();

            self::assertCount(3, $partialPosts);
            self::assertSame(EntityState::Detached, $em->state($partialPosts[0]));
            self::assertFalse($em->contains($partialPosts[0]));
            self::assertSame('Alpha', $partialPosts[0]->title);
            self::assertCount(2, $partialPosts[0]->comments);
            self::assertSame(['A-1', 'A-2'], array_map(
                static fn(OrmBlogComment $comment): string => $comment->body,
                $partialPosts[0]->comments,
            ));
            self::assertSame($partialPosts[0], $partialPosts[0]->comments[0]->post);
            self::assertFalse($this->isPropertyInitialized($partialPosts[0]->comments[0], 'postId'));
            self::assertSame('Beta', $partialPosts[1]->title);
            self::assertCount(1, $partialPosts[1]->comments);
            self::assertSame('Gamma', $partialPosts[2]->title);
            self::assertSame([], $partialPosts[2]->comments);

            $windowedPost = $em->query(OrmBlogPost::class)
                ->join('comments', 'c')
                ->partial('title', 'comments.body')
                ->orderBy('title')
                ->limit(1)
                ->getPartial();
            self::assertCount(1, $windowedPost);
            self::assertSame('Alpha', $windowedPost[0]->title);
            self::assertCount(2, $windowedPost[0]->comments);

            $offsetPost = $em->query(OrmBlogPost::class)
                ->join('comments', 'c')
                ->partial('title', 'comments.body')
                ->orderBy('title')
                ->offset(1)
                ->firstPartial();
            self::assertInstanceOf(OrmBlogPost::class, $offsetPost);
            self::assertSame('Beta', $offsetPost->title);
            self::assertCount(1, $offsetPost->comments);
            self::assertSame('B-1', $offsetPost->comments[0]->body);

            $partialStudents = $em->query(OrmStudent::class)
                ->join('courses', 'c')
                ->partial('name', 'courses.title')
                ->getPartial();
            self::assertCount(1, $partialStudents);
            self::assertSame('Ada', $partialStudents[0]->name);
            self::assertCount(2, $partialStudents[0]->courses);
            self::assertSame(['Compilers', 'Runtime Systems'], array_map(
                static fn(OrmCourse $course): string => $course->title,
                $partialStudents[0]->courses,
            ));
            self::assertSame([], $partialStudents[0]->courses[0]->students);
        } finally {
            $scope->end();
        }
    }

    public function test_entity_query_partial_relational_hydration_supports_joined_to_many_associations_in_managed_mode(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/partial-relational-hydration-to-many-managed', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);
            $database->schema()->create('orm_students', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_courses', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
            }, true);
            $database->schema()->create('orm_student_courses', function (TableBlueprint $table): void {
                $table->integer('student_id');
                $table->integer('course_id');
            }, true);

            $em = $database->entityManager();

            $alpha = new OrmBlogPost();
            $alpha->title = 'Alpha';
            $alpha->published = true;

            $beta = new OrmBlogPost();
            $beta->title = 'Beta';
            $beta->published = false;

            $gamma = new OrmBlogPost();
            $gamma->title = 'Gamma';
            $gamma->published = true;

            $em->persist($alpha);
            $em->persist($beta);
            $em->persist($gamma);
            $em->flush();

            $commentA = new OrmBlogComment();
            $commentA->body = 'A-1';
            $commentA->post = $alpha;

            $commentB = new OrmBlogComment();
            $commentB->body = 'A-2';
            $commentB->post = $alpha;

            $commentC = new OrmBlogComment();
            $commentC->body = 'B-1';
            $commentC->post = $beta;

            $student = new OrmStudent();
            $student->name = 'Ada';

            $courseA = new OrmCourse();
            $courseA->title = 'Compilers';

            $courseB = new OrmCourse();
            $courseB->title = 'Runtime Systems';

            $student->courses = [$courseA, $courseB];
            $courseA->students = [$student];
            $courseB->students = [$student];

            $em->persist($commentA);
            $em->persist($commentB);
            $em->persist($commentC);
            $em->persist($student);
            $em->persist($courseA);
            $em->persist($courseB);
            $em->flush();
            $em->clear();

            $managedPosts = $em->query(OrmBlogPost::class)
                ->leftJoin('comments', 'c')
                ->partialManaged('title', 'comments.body')
                ->orderBy('title')
                ->getPartialManaged();

            self::assertCount(3, $managedPosts);
            self::assertSame(EntityState::Managed, $em->state($managedPosts[0]));
            self::assertTrue($em->contains($managedPosts[0]));
            self::assertSame('Alpha', $managedPosts[0]->title);
            self::assertCount(2, $managedPosts[0]->comments);
            self::assertTrue($em->contains($managedPosts[0]->comments[0]));
            self::assertSame($managedPosts[0], $managedPosts[0]->comments[0]->post);
            self::assertFalse($this->isPropertyInitialized($managedPosts[0], 'published'));
            self::assertFalse($this->isPropertyInitialized($managedPosts[0]->comments[0], 'postId'));
            self::assertSame([], $managedPosts[2]->comments);

            $offsetPost = $em->query(OrmBlogPost::class)
                ->join('comments', 'c')
                ->partialManaged('title', 'comments.body')
                ->orderBy('title')
                ->offset(1)
                ->firstPartialManaged();
            self::assertInstanceOf(OrmBlogPost::class, $offsetPost);
            self::assertSame($managedPosts[1], $offsetPost);
            self::assertSame('Beta', $offsetPost->title);
            self::assertCount(1, $offsetPost->comments);
            self::assertSame('B-1', $offsetPost->comments[0]->body);

            $managedPosts[0]->title = 'Alpha managed';
            $managedPosts[0]->comments[0]->body = 'A-1 managed';
            $em->flush();

            $rawAlpha = $database->table('orm_blog_posts')
                ->where('id', $managedPosts[0]->id)
                ->first();
            self::assertNotNull($rawAlpha);
            self::assertSame('Alpha managed', $rawAlpha['title'] ?? null);
            self::assertSame(1, $rawAlpha['published'] ?? null);

            $rawComment = $database->table('orm_blog_comments')
                ->where('id', $managedPosts[0]->comments[0]->id)
                ->first();
            self::assertNotNull($rawComment);
            self::assertSame('A-1 managed', $rawComment['body'] ?? null);

            $managedStudents = $em->query(OrmStudent::class)
                ->join('courses', 'c')
                ->partialManaged('name', 'courses.title')
                ->getPartialManaged();
            self::assertCount(1, $managedStudents);
            self::assertSame(EntityState::Managed, $em->state($managedStudents[0]));
            self::assertTrue($em->contains($managedStudents[0]));
            self::assertCount(2, $managedStudents[0]->courses);
            self::assertTrue($em->contains($managedStudents[0]->courses[0]));
            self::assertSame(['Compilers', 'Runtime Systems'], array_map(
                static fn(OrmCourse $course): string => $course->title,
                $managedStudents[0]->courses,
            ));

            $managedStudents[0]->courses[0]->title = 'Compilers managed';
            $em->flush();

            $rawCourse = $database->table('orm_courses')
                ->where('id', $managedStudents[0]->courses[0]->id)
                ->first();
            self::assertNotNull($rawCourse);
            self::assertSame('Compilers managed', $rawCourse['title'] ?? null);

            $removedCourse = array_shift($managedStudents[0]->courses);
            self::assertInstanceOf(OrmCourse::class, $removedCourse);

            $courseC = new OrmCourse();
            $courseC->title = 'Databases';
            $courseC->students = [$managedStudents[0]];
            $managedStudents[0]->courses[] = $courseC;

            $em->flush();

            $memberships = $database->table('orm_student_courses')
                ->where('student_id', $managedStudents[0]->id)
                ->get()
                ->rows();
            self::assertCount(2, $memberships);

            $removedMembership = $database->table('orm_student_courses')
                ->where('student_id', $managedStudents[0]->id)
                ->where('course_id', $removedCourse->id)
                ->first();
            self::assertNull($removedMembership);

            $newCourseRow = $database->table('orm_courses')
                ->where('title', 'Databases')
                ->first();
            self::assertNotNull($newCourseRow);

            $newMembership = $database->table('orm_student_courses')
                ->where('student_id', $managedStudents[0]->id)
                ->where('course_id', $newCourseRow['id'] ?? null)
                ->first();
            self::assertNotNull($newMembership);
        } finally {
            $scope->end();
        }
    }

    public function test_partial_managed_one_to_many_collections_support_structural_membership_when_runtime_can_sync_them(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/partial-managed-one-to-many-membership', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_cascade_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
                $table->string('created_by')->nullable();
                $table->string('created_at')->nullable();
                $table->string('updated_at')->nullable();
            }, true);
            $database->schema()->create('orm_cascade_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
                $table->string('created_at')->nullable();
            }, true);

            $em = $database->entityManager();

            $post = new OrmCascadePost();
            $post->title = 'Partial Post';
            $post->published = true;

            $commentA = new OrmCascadeComment();
            $commentA->body = 'Comment A';
            $commentA->post = $post;

            $commentB = new OrmCascadeComment();
            $commentB->body = 'Comment B';
            $commentB->post = $post;

            $post->comments = [$commentA, $commentB];

            $em->persist($post);
            $em->flush();
            $em->clear();

            $managedPost = $em->query(OrmCascadePost::class)
                ->join('comments', 'c')
                ->partialManaged('title', 'comments.body')
                ->firstPartialManaged();

            self::assertInstanceOf(OrmCascadePost::class, $managedPost);
            self::assertCount(2, $managedPost->comments);

            $removedComment = array_pop($managedPost->comments);
            self::assertInstanceOf(OrmCascadeComment::class, $removedComment);

            $commentC = new OrmCascadeComment();
            $commentC->body = 'Comment C';
            $commentC->post = $managedPost;
            $managedPost->comments[] = $commentC;

            $em->flush();

            $removedRow = $database->table('orm_cascade_comments')
                ->where('id', $removedComment->id)
                ->first();
            self::assertNull($removedRow);

            $addedRow = $database->table('orm_cascade_comments')
                ->where('body', 'Comment C')
                ->first();
            self::assertNotNull($addedRow);
            self::assertSame((string) $managedPost->id, (string) ($addedRow['post_id'] ?? ''));

            $managedPost->comments = array_values($managedPost->comments);
            $em->flush();

            $allComments = $database->table('orm_cascade_comments')
                ->where('post_id', $managedPost->id)
                ->get()
                ->rows();
            self::assertCount(2, $allComments);
        } finally {
            $scope->end();
        }
    }

    public function test_entity_manager_and_entity_query_honor_declarative_eager_fetch_strategies(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/fetch-strategies', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_fetch_authors', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_fetch_publishers', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);
            $database->schema()->create('orm_fetch_books', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('author_id');
                $table->integer('publisher_id');
                $table->string('title');
            }, true);

            $em = $database->entityManager();

            $author = new OrmFetchAuthor();
            $author->name = 'Ada';

            $publisher = new OrmFetchPublisher();
            $publisher->name = 'VoltStack Press';

            $bookA = new OrmFetchBook();
            $bookA->title = 'Compiler Construction';
            $bookA->author = $author;
            $bookA->publisher = $publisher;

            $bookB = new OrmFetchBook();
            $bookB->title = 'Runtime Notes';
            $bookB->author = $author;
            $bookB->publisher = $publisher;

            $author->books = [$bookA, $bookB];

            $em->persist($author);
            $em->persist($publisher);
            $em->persist($bookA);
            $em->persist($bookB);
            $em->flush();
            $em->clear();

            $loadedBook = $em->find(OrmFetchBook::class, $bookA->id);
            self::assertInstanceOf(OrmFetchBook::class, $loadedBook);
            self::assertInstanceOf(OrmFetchAuthor::class, $loadedBook->author);
            self::assertSame('Ada', $loadedBook->author->name);

            $em->clear();

            $loadedAuthor = $em->find(OrmFetchAuthor::class, $author->id);
            self::assertInstanceOf(OrmFetchAuthor::class, $loadedAuthor);
            self::assertCount(2, $loadedAuthor->books);
            usort($loadedAuthor->books, static fn(OrmFetchBook $a, OrmFetchBook $b): int => strcmp($a->title, $b->title));
            self::assertSame(['Compiler Construction', 'Runtime Notes'], array_map(
                static fn(OrmFetchBook $book): string => $book->title,
                $loadedAuthor->books,
            ));
            self::assertSame($loadedAuthor, $loadedAuthor->books[0]->author);
            self::assertSame($loadedAuthor, $loadedAuthor->books[1]->author);
            self::assertInstanceOf(OrmFetchPublisher::class, $loadedAuthor->books[0]->publisher);
            self::assertSame('VoltStack Press', $loadedAuthor->books[0]->publisher->name);
            self::assertSame($loadedAuthor->books[0]->publisher, $loadedAuthor->books[1]->publisher);

            $em->clear();

            $books = $em->query(OrmFetchBook::class)
                ->orderBy('title')
                ->get();
            self::assertCount(2, $books);
            self::assertInstanceOf(OrmFetchAuthor::class, $books[0]->author);
            self::assertSame('Ada', $books[0]->author->name);
            self::assertSame($books[0]->author, $books[1]->author);
            self::assertInstanceOf(OrmFetchPublisher::class, $books[0]->publisher);
            self::assertSame('VoltStack Press', $books[0]->publisher->name);

            $em->clear();

            $queriedAuthor = $em->query(OrmFetchAuthor::class)
                ->where('name', 'Ada')
                ->first();
            self::assertInstanceOf(OrmFetchAuthor::class, $queriedAuthor);
            self::assertCount(2, $queriedAuthor->books);
            self::assertInstanceOf(OrmFetchPublisher::class, $queriedAuthor->books[0]->publisher);
        } finally {
            $scope->end();
        }
    }

    private function makeApp(): Application
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'default');
        $config->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        return $this->app = $app;
    }

    public function test_embedded_value_objects_multicolumn_round_trip_and_query_over_sqlite(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/embedded', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_products', function (TableBlueprint $table): void {
                $table->id();
                $table->string('sku');
                $table->integer('price_amount');
                $table->string('price_currency', 3);
                $table->string('dim_width')->nullable();
                $table->string('dim_height')->nullable();
                $table->string('dim_depth')->nullable();
            }, true);

            $registry = $app->make(EntityMetadataRegistry::class);
            $productMeta = $registry->for(OrmProduct::class);

            self::assertTrue($productMeta->hasEmbedded('price'));
            self::assertTrue($productMeta->hasEmbedded('dimensions'));

            $priceEmbedded = $productMeta->embedded('price');
            self::assertSame(OrmMoney::class, $priceEmbedded->embeddableClass);
            self::assertSame('price_', $priceEmbedded->columnPrefix);
            self::assertTrue($priceEmbedded->hasInnerField('amount'));
            self::assertTrue($priceEmbedded->hasInnerField('currency'));
            self::assertSame('price_amount', $priceEmbedded->innerField('amount')->column);
            self::assertSame('price_currency', $priceEmbedded->innerField('currency')->column);

            $dimEmbedded = $productMeta->embedded('dimensions');
            self::assertSame(OrmDimensions::class, $dimEmbedded->embeddableClass);
            self::assertSame('dim_', $dimEmbedded->columnPrefix);
            self::assertSame('dim_width', $dimEmbedded->innerField('width')->column);

            $manager = $database->entityManager();

            $chair = new OrmProduct();
            $chair->sku = 'CHAIR-001';
            $chair->price = new OrmMoney();
            $chair->price->amount = 12999;
            $chair->price->currency = 'USD';
            $chair->dimensions = new OrmDimensions();
            $chair->dimensions->width = '50';
            $chair->dimensions->height = '90';
            $chair->dimensions->depth = '50';
            $manager->persist($chair);

            $desk = new OrmProduct();
            $desk->sku = 'DESK-001';
            $desk->price = new OrmMoney();
            $desk->price->amount = 24999;
            $desk->price->currency = 'USD';
            $manager->persist($desk);

            $freeSticker = new OrmProduct();
            $freeSticker->sku = 'STICKER-FREE';
            $freeSticker->price = new OrmMoney();
            $freeSticker->price->amount = 0;
            $freeSticker->price->currency = 'EUR';
            $manager->persist($freeSticker);
            $manager->flush();

            self::assertIsInt($chair->id);
            self::assertIsInt($desk->id);
            self::assertIsInt($freeSticker->id);

            $rawRow = $database->table('orm_products')
                ->where('sku', 'CHAIR-001')
                ->first();
            self::assertNotNull($rawRow);
            self::assertSame(12999, $rawRow['price_amount'] ?? null);
            self::assertSame('USD', $rawRow['price_currency'] ?? null);
            self::assertSame('50', $rawRow['dim_width'] ?? null);
            self::assertSame('90', $rawRow['dim_height'] ?? null);
            self::assertSame('50', $rawRow['dim_depth'] ?? null);

            $reloadedChair = $manager->find(OrmProduct::class, $chair->id);
            self::assertSame($chair, $reloadedChair);
            self::assertInstanceOf(OrmMoney::class, $reloadedChair->price);
            self::assertSame(12999, $reloadedChair->price->amount);
            self::assertSame('USD', $reloadedChair->price->currency);
            self::assertInstanceOf(OrmDimensions::class, $reloadedChair->dimensions);
            self::assertSame('50', $reloadedChair->dimensions->width);
            self::assertSame('90', $reloadedChair->dimensions->height);
            self::assertSame('50', $reloadedChair->dimensions->depth);

            $reloadedDesk = $manager->find(OrmProduct::class, $desk->id);
            self::assertNotNull($reloadedDesk);
            self::assertNull($reloadedDesk->dimensions);
            self::assertSame(24999, $reloadedDesk->price->amount);

            $byPriceCurrency = $manager->query(OrmProduct::class)
                ->where('price.currency', 'EUR')
                ->get();
            self::assertCount(1, $byPriceCurrency);
            self::assertSame($freeSticker, $byPriceCurrency[0]);

            $byPriceAmountLt = $manager->query(OrmProduct::class)
                ->where('price.amount', '<', 15000)
                ->orderBy('price.amount')
                ->get();
            self::assertCount(2, $byPriceAmountLt);
            self::assertSame($freeSticker, $byPriceAmountLt[0]);
            self::assertSame($chair, $byPriceAmountLt[1]);

            $byDimensionsDepth = $manager->query(OrmProduct::class)
                ->where('dimensions.depth', '50')
                ->orderBy('sku')
                ->get();
            self::assertCount(1, $byDimensionsDepth);
            self::assertSame($chair, $byDimensionsDepth[0]);

            $manager->clear();
            $clearedChair = $manager->find(OrmProduct::class, $chair->id);
            self::assertNotSame($chair, $clearedChair);
            self::assertSame(12999, $clearedChair->price->amount);
            self::assertSame('USD', $clearedChair->price->currency);
            self::assertSame('50', $clearedChair->dimensions->width);

            $clearedChair->price->amount = 13999;
            $clearedChair->dimensions = null;
            $manager->flush();

            $updatedRaw = $database->table('orm_products')
                ->where('id', $chair->id)
                ->first();
            self::assertNotNull($updatedRaw);
            self::assertSame(13999, $updatedRaw['price_amount'] ?? null);
            self::assertNull($updatedRaw['dim_width'] ?? null);
            self::assertNull($updatedRaw['dim_height'] ?? null);
            self::assertNull($updatedRaw['dim_depth'] ?? null);

            $reloadedUpdated = $manager->find(OrmProduct::class, $chair->id);
            self::assertSame($clearedChair, $reloadedUpdated);
            self::assertNull($reloadedUpdated->dimensions);
            self::assertSame(13999, $reloadedUpdated->price->amount);
        } finally {
            $scope->end();
        }
    }

    public function test_repository_factory_with_di_typed_and_ergonomic_helpers(): void
    {
        $app = $this->makeApp();

        $customRepoRegistry = $app->make(CustomRepositoryRegistry::class);
        $customRepoRegistry->register(OrmProductRepository::class);
        self::assertSame(OrmProductRepository::class, $customRepoRegistry->repositoryFor(OrmProduct::class));

        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/repository-factory', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_tags', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug');
                $table->boolean('visible');
            }, true);

            $registry = $app->make(EntityMetadataRegistry::class);
            $productMeta = $registry->for(OrmProduct::class);
            self::assertSame(OrmProductRepository::class, $productMeta->repositoryClass);

            $factory = $app->make(RepositoryFactoryInterface::class);
            $userRepo = $factory->repositoryFor(OrmUser::class);
            self::assertInstanceOf(OrmUserRepository::class, $userRepo);
            self::assertSame(OrmUser::class, $userRepo->getEntityClass());
            self::assertSame($database->entityManager(), $userRepo->getEntityManager());

            $dbRepoFactory = $database->repositoryFactory();
            self::assertSame($factory, $dbRepoFactory);

            $tagRepo = $factory->repositoryFor(OrmTag::class);
            self::assertInstanceOf(EntityRepository::class, $tagRepo);

            $php = new OrmTag();
            $php->name = 'PHP';
            $php->slug = 'php';
            $php->visible = true;

            $framework = new OrmTag();
            $framework->name = 'Framework';
            $framework->slug = 'framework';
            $framework->visible = true;

            $hidden = new OrmTag();
            $hidden->name = 'Hidden Draft';
            $hidden->slug = 'hidden-draft';
            $hidden->visible = false;

            $tagRepo->save($php);
            $tagRepo->save($framework);
            $tagRepo->save($hidden, flush: true);
            self::assertNotNull($php->id);
            self::assertNotNull($framework->id);
            self::assertNotNull($hidden->id);

            $rawCount = $database->table('orm_tags')->count();
            self::assertSame(3, $rawCount);

            $allViaCount = $tagRepo->count();
            self::assertSame(3, $allViaCount);

            self::assertTrue($tagRepo->exists(['slug' => 'php']));
            self::assertFalse($tagRepo->exists(['slug' => 'missing']));
            self::assertSame(2, $tagRepo->count(['visible' => true]));
            self::assertSame(1, $tagRepo->count(['visible' => false]));

            $query = $database->entityManager()->query(OrmTag::class);
            self::assertSame(2, $query->where('visible', true)->count());

            $reloadedPhp = $tagRepo->findOneBy(['slug' => 'php']);
            self::assertInstanceOf(OrmTag::class, $reloadedPhp);
            self::assertSame('PHP', $reloadedPhp->name);

            $tagRepo->delete($hidden, flush: true);
            self::assertSame(2, $tagRepo->count());
            self::assertFalse($tagRepo->exists(['slug' => 'hidden-draft']));

            $byName = $tagRepo->findBy([], ['name' => 'asc']);
            self::assertCount(2, $byName);
            self::assertSame('Framework', $byName[0]->name);
            self::assertSame('PHP', $byName[1]->name);

            $customProductRepo = $factory->repositoryFor(OrmProduct::class);
            self::assertInstanceOf(OrmProductRepository::class, $customProductRepo);
            self::assertSame('sku-based-lookup', $customProductRepo->label());
        } finally {
            $scope->end();
        }
    }

    public function test_lifecycle_callbacks_cascade_and_orphan_removal_over_sqlite(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/lifecycle-cascade-orphan', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $em = $database->entityManager();

            $database->schema()->create('orm_cascade_posts', function (TableBlueprint $t): void {
                $t->id();
                $t->string('title');
                $t->boolean('published');
                $t->string('created_by')->nullable();
                $t->string('created_at')->nullable();
                $t->string('updated_at')->nullable();
            }, true);

            $database->schema()->create('orm_cascade_comments', function (TableBlueprint $t): void {
                $t->id();
                $t->integer('post_id');
                $t->string('body');
                $t->string('created_at')->nullable();
            }, true);

            $database->schema()->create('orm_timestamped_posts', function (TableBlueprint $t): void {
                $t->id();
                $t->string('title');
                $t->string('updated_by')->nullable();
                $t->string('created_at')->nullable();
                $t->string('updated_at')->nullable();
            }, true);

            OrmLifecyclePostListener::reset();

            // ---- Metadata sanity checks BEFORE write (DV-DB-014 attrs wiring) ---------
            $registry = $app->make(EntityMetadataRegistry::class);
            $postMeta = $registry->for(OrmCascadePost::class);
            $commentMeta = $registry->for(OrmCascadeComment::class);
            $timestampedMeta = $registry->for(OrmLifecyclePost::class);

            // Inverse OneToMany comments association on the post side.
            $commentsAssoc = $postMeta->association('comments');
            self::assertSame(EntityAssociationMetadata::KIND_ONE_TO_MANY, $commentsAssoc->kind, 'comments assoc is inverse OneToMany');
            self::assertTrue($commentsAssoc->isOneToMany(), 'comments isOneToMany() === true');
            self::assertTrue($commentsAssoc->isInverseSide(), 'comments isInverseSide() === true');
            self::assertTrue($commentsAssoc->cascadesPersist(), 'comments cascade PERSIST flag on');
            self::assertTrue($commentsAssoc->cascadesRemove(), 'comments cascade REMOVE flag on');
            self::assertTrue($commentsAssoc->orphanRemoval, 'comments orphanRemoval on inverse side');

            // Owning ManyToOne post association on the comment side.
            $postAssoc = $commentMeta->association('post');
            self::assertSame(EntityAssociationMetadata::KIND_MANY_TO_ONE, $postAssoc->kind, 'post assoc is owning ManyToOne');
            self::assertTrue($postAssoc->isManyToOne(), 'post isManyToOne() === true');
            self::assertTrue($postAssoc->isOwningSide(), 'post isOwningSide() === true');
            self::assertTrue($postAssoc->cascadesPersist(), 'post cascade PERSIST on owning side');
            self::assertFalse($postAssoc->cascadesRemove(), 'post does NOT cascade REMOVE (configured persist-only)');
            self::assertFalse($postAssoc->orphanRemoval, 'orphanRemoval never applies to ManyToOne owning');

            // Listener wiring: OrmLifecyclePost has ONLY #[PrePersist] method-level; class listener registers all 7.
            self::assertCount(2, $timestampedMeta->callbacksFor('prePersist'), 'prePersist = 1 method-level + 1 class listener = 2');
            self::assertCount(1, $timestampedMeta->callbacksFor('postPersist'), 'postPersist = class listener only (no method-level)');
            self::assertCount(1, $timestampedMeta->callbacksFor('preUpdate'), 'preUpdate = class listener only');
            self::assertCount(1, $timestampedMeta->callbacksFor('postUpdate'), 'postUpdate = class listener only');
            self::assertCount(1, $timestampedMeta->callbacksFor('preRemove'), 'preRemove = class listener only');
            self::assertCount(1, $timestampedMeta->callbacksFor('postRemove'), 'postRemove = class listener only');
            self::assertCount(1, $timestampedMeta->callbacksFor('postLoad'), 'postLoad = class listener only (unused method-level)');
            self::assertTrue($timestampedMeta->hasCallbacks('prePersist'), 'hasCallbacks true for event with callbacks');
            self::assertFalse($timestampedMeta->hasCallbacks('unknownEvent'), 'hasCallbacks false for unknown event');
            self::assertFalse($timestampedMeta->hasCallbacks('notAnEvent'), 'hasCallbacks false for any non-event string');

            // ---- 1. CASCADE PERSIST + method-level PrePersist hooks --------------------
            $post = new OrmCascadePost();
            $post->title = 'My First Post';
            $post->published = true;

            $c1 = new OrmCascadeComment();
            $c1->body = 'Comment #1';
            $c1->post = $post;

            $c2 = new OrmCascadeComment();
            $c2->body = 'Comment #2';
            $c2->post = $post;

            $c3 = new OrmCascadeComment();
            $c3->body = 'Comment #3';
            $c3->post = $post;

            $post->comments = [$c1, $c2, $c3];

            // Persist ONLY the post; cascade=[PERSIST] on inverse OneToMany should
            // auto-persist the 3 child comments.
            $em->persist($post);
            $em->flush();

            self::assertNotNull($post->id, 'Post id assigned after insert');
            self::assertIsInt($post->id, 'Post id type is int (auto increment)');
            self::assertNotNull($c1->id, 'Comment 1 id assigned via cascade persist');
            self::assertNotNull($c2->id, 'Comment 2 id assigned via cascade persist');
            self::assertNotNull($c3->id, 'Comment 3 id assigned via cascade persist');
            self::assertSame($post->id, $c1->postId, 'Comment 1 postId synchronized from object reference');
            self::assertSame($post->id, $c2->postId, 'Comment 2 postId synchronized from object reference');
            self::assertSame($post->id, $c3->postId, 'Comment 3 postId synchronized from object reference');
            self::assertSame($post, $c1->post, 'Comment 1 back-reference to post kept intact');
            self::assertSame($post, $c2->post, 'Comment 2 back-reference to post kept intact');
            self::assertSame($post, $c3->post, 'Comment 3 back-reference to post kept intact');
            self::assertNotNull($post->createdAt, 'method-level PrePersist on post sets createdAt');
            self::assertInstanceOf(DateTimeImmutable::class, $post->createdAt, 'post createdAt is DateTimeImmutable instance');
            self::assertNotNull($c1->createdAt, 'method-level PrePersist on comment 1 sets createdAt');
            self::assertNotNull($c2->createdAt, 'method-level PrePersist on comment 2 sets createdAt');
            self::assertNotNull($c3->createdAt, 'method-level PrePersist on comment 3 sets createdAt');

            $rawPosts = $database->table('orm_cascade_posts')->count();
            $rawComments = $database->table('orm_cascade_comments')->count();
            self::assertSame(1, $rawPosts, '1 post inserted via explicit persist');
            self::assertSame(3, $rawComments, '3 comments auto-inserted via cascade persist');
            $postRow = $database->table('orm_cascade_posts')->where('id', $post->id)->first();
            self::assertNotNull($postRow, 'Post row retrievable by id');
            self::assertSame('My First Post', $postRow['title'] ?? null);
            self::assertSame(1, $postRow['published'] ?? null);
            self::assertNotNull($postRow['created_at'] ?? null, 'created_at column stored for post');

            // ---- 2. ORPHAN REMOVAL: remove one item from the inverse collection --------
            $post->comments = [$c1, $c3];
            $em->flush();

            $commentsAfter = $database->table('orm_cascade_comments')->count();
            self::assertSame(2, $commentsAfter, '2 comments remain after orphan removal of one item');
            $orphanRow = $database->table('orm_cascade_comments')->where('body', 'Comment #2')->first();
            self::assertNull($orphanRow, 'Removed comment #2 no longer exists on DB (orphanRemoval=true)');
            $comment1Row = $database->table('orm_cascade_comments')->where('id', $c1->id)->first();
            $comment3Row = $database->table('orm_cascade_comments')->where('id', $c3->id)->first();
            self::assertNotNull($comment1Row, 'Comment #1 still persisted');
            self::assertNotNull($comment3Row, 'Comment #3 still persisted');
            self::assertSame('Comment #1', $comment1Row['body'] ?? null);
            self::assertSame('Comment #3', $comment3Row['body'] ?? null);
            self::assertSame((string) $post->id, (string) ($comment1Row['post_id'] ?? ''), 'Kept comment 1 still references post');
            self::assertSame((string) $post->id, (string) ($comment3Row['post_id'] ?? ''), 'Kept comment 3 still references post');

            // ---- 3. LIFECYCLE method-level + class-level listeners on a timestamped post ----
            $tPost = new OrmLifecyclePost();
            $tPost->title = 'Callbacks!';
            $em->persist($tPost);
            $em->flush();

            self::assertNotNull($tPost->id, 'Timestamped post id assigned');
            self::assertIsInt($tPost->id);
            self::assertNotNull($tPost->createdAt, 'method-level PrePersist sets createdAt');
            self::assertNotNull($tPost->updatedAt, 'class-level listener sets updatedAt');
            self::assertSame('listener@example.test', $tPost->updatedBy, 'class-level listener fills updatedBy');
            self::assertInstanceOf(DateTimeImmutable::class, $tPost->createdAt);
            self::assertInstanceOf(DateTimeImmutable::class, $tPost->updatedAt);

            self::assertArrayHasKey('prePersist', OrmLifecyclePostListener::$calls, 'listener.prePersist called');
            self::assertArrayHasKey('postPersist', OrmLifecyclePostListener::$calls, 'listener.postPersist called');
            self::assertSame($tPost, OrmLifecyclePostListener::$calls['prePersist']['entity'], 'prePersist listener receives the right entity');
            self::assertSame($tPost, OrmLifecyclePostListener::$calls['postPersist']['entity'], 'postPersist listener receives the right entity');
            self::assertIsArray(OrmLifecyclePostListener::$calls['prePersist']['context'], 'listener context always array');
            self::assertSame($em, OrmLifecyclePostListener::$calls['postPersist']['em'], 'listener receives the entity manager');

            // ---- 4. PRE/POST UPDATE with changes context -------------------------------
            $beforeUpdateAt = $tPost->updatedAt;
            $tPost->title = 'Callbacks v2';
            $em->flush();

            self::assertNotNull($tPost->updatedAt, 'preUpdate listener should keep updatedAt set');
            self::assertGreaterThanOrEqual($beforeUpdateAt, $tPost->updatedAt);
            self::assertArrayHasKey('preUpdate', OrmLifecyclePostListener::$calls, 'listener.preUpdate called');
            self::assertArrayHasKey('postUpdate', OrmLifecyclePostListener::$calls, 'listener.postUpdate called');
            $preUpdateCtx = OrmLifecyclePostListener::$calls['preUpdate']['context'];
            self::assertArrayHasKey('changes', $preUpdateCtx, 'preUpdate context contains changes key');
            self::assertArrayHasKey('title', $preUpdateCtx['changes'], 'preUpdate changes includes title column');
            self::assertSame('Callbacks v2', $preUpdateCtx['changes']['title']);
            self::assertArrayHasKey('currentValues', $preUpdateCtx, 'preUpdate context includes currentValues field map');
            self::assertSame('Callbacks v2', $preUpdateCtx['currentValues']['title'] ?? null);
            self::assertArrayHasKey('originalSnapshot', $preUpdateCtx, 'preUpdate context includes originalSnapshot');
            self::assertSame('Callbacks!', $preUpdateCtx['originalSnapshot']['title'] ?? null);

            // ---- 5. CASCADE REMOVE: removing post deletes all remaining comments via cascade REMOVE ----
            $em->remove($post);
            $em->flush();

            $rowsPost = $database->table('orm_cascade_posts')->count();
            $rowsComments = $database->table('orm_cascade_comments')->count();
            self::assertSame(0, $rowsPost, 'post removed via explicit em.remove');
            self::assertSame(0, $rowsComments, 'comments removed via cascade REMOVE');
            self::assertFalse($em->contains($post), 'post no longer in UoW after remove+detach');
            self::assertFalse($em->contains($c1), 'comment1 no longer in UoW after cascade remove');
            self::assertFalse($em->contains($c3), 'comment3 no longer in UoW after cascade remove');

            // ---- 6. POSTLOAD after hydration through find: clear identity map to force a real DB load ----
            $em->clear();
            $tPostReload = $em->find(OrmLifecyclePost::class, $tPost->id);
            self::assertInstanceOf(OrmLifecyclePost::class, $tPostReload);
            self::assertNotSame($tPost, $tPostReload, 'clear forced a fresh hydration through find, not cached instance');
            self::assertSame($tPost->id, $tPostReload->id, 'newly hydrated post retains the same id');
            self::assertSame('Callbacks v2', $tPostReload->title, 'hydrated post retains the updated title');
            self::assertNotNull($tPostReload->createdAt, 'hydrated post keeps createdAt from DB');
            self::assertNotNull($tPostReload->updatedAt, 'hydrated post keeps updatedAt from DB');
            self::assertSame('listener@example.test', $tPostReload->updatedBy, 'hydrated post keeps updatedBy from DB');
            self::assertArrayHasKey('postLoad', OrmLifecyclePostListener::$calls, 'class-level listener postLoad invoked on find/hydrate after clear');
            self::assertSame($tPostReload, OrmLifecyclePostListener::$calls['postLoad']['entity'], 'postLoad listener receives the newly hydrated entity');

            // ---- 7. Backward compat: plain entities without DV-DB-014 attrs remain 100% unchanged ----
            $bareTagMeta = $registry->for(OrmTag::class);
            $tagAllCallbacks = 0;
            foreach (\Quantum\Database\ORM\Metadata\EntityMetadata::KNOWN_LIFECYCLE_EVENTS as $ev) {
                $tagAllCallbacks += count($bareTagMeta->callbacksFor($ev));
            }
            self::assertSame(0, $tagAllCallbacks, 'Plain OrmTag without lifecycle attrs registers zero callbacks across all 7 events');

            $bareUserMeta = $registry->for(OrmUser::class);
            $userAllCallbacks = 0;
            foreach (\Quantum\Database\ORM\Metadata\EntityMetadata::KNOWN_LIFECYCLE_EVENTS as $ev) {
                $userAllCallbacks += count($bareUserMeta->callbacksFor($ev));
            }
            self::assertSame(0, $userAllCallbacks, 'Plain OrmUser without lifecycle attrs also registers zero callbacks');
            // All existing OneToMany/ManyToOne associations on old fixtures default to cascade=[] + orphan=false.
            foreach ($bareUserMeta->associations() as $bareAssoc) {
                self::assertFalse($bareAssoc->cascadesPersist());
                self::assertFalse($bareAssoc->cascadesRemove());
                self::assertFalse($bareAssoc->orphanRemoval);
            }
        } finally {
            $scope->end();
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }

    private function isPropertyInitialized(object $object, string $property): bool
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->isInitialized($object);
    }
}

#[Entity(repository: OrmUserRepository::class)]
final class OrmUser
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[Column]
    public bool $active = false;
}

final class OrmUserRepository extends EntityRepository
{
    /**
     * @return list<string>
     */
    public function activeNames(): array
    {
        return array_map(
            static fn(OrmUser $user): string => $user->name,
            $this->findBy(['active' => true], ['name' => 'asc']),
        );
    }
}

final class OrmPost extends Model
{
    protected static string $table = 'orm_posts';

    #[Id]
    public ?int $id = null;

    #[Column]
    public string $title;

    #[Column]
    public bool $published = false;
}

enum OrmEventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final class OrmEvent extends Model
{
    protected static string $table = 'orm_events';

    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[Column(type: 'enum', enumType: OrmEventStatus::class)]
    public OrmEventStatus $status;

    #[Column(name: 'occurred_at', type: 'datetime_immutable')]
    public DateTimeImmutable $occurredAt;

    #[Column(type: 'json')]
    public array $payload = [];
}

#[Entity]
final class OrmBlogPost
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $title;

    #[Column]
    public bool $published;

    /** @var list<OrmBlogComment> */
    #[OneToMany(targetEntity: OrmBlogComment::class, mappedBy: 'post')]
    public array $comments = [];
}

#[Entity]
final class OrmBlogComment
{
    #[Id]
    public ?int $id = null;

    #[Column(name: 'post_id')]
    public int $postId;

    #[Column]
    public string $body;

    #[ManyToOne(targetEntity: OrmBlogPost::class, inversedBy: 'comments')]
    public ?OrmBlogPost $post = null;
}

final class OrmMoney
{
    #[Column(type: 'int')]
    public int $amount;

    #[Column]
    public string $currency;
}

final class OrmDimensions
{
    #[Column]
    public string $width;

    #[Column]
    public string $height;

    #[Column]
    public string $depth;
}

#[Entity]
final class OrmProduct
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $sku;

    #[Embedded(class: OrmMoney::class)]
    public ?OrmMoney $price = null;

    #[Embedded(class: OrmDimensions::class, prefix: 'dim_')]
    public ?OrmDimensions $dimensions = null;
}

#[Entity]
final class OrmTag
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[Column]
    public string $slug;

    #[Column]
    public bool $visible = false;
}

#[RepositoryFor(OrmProduct::class)]
final class OrmProductRepository extends EntityRepository
{
    public function label(): string
    {
        return 'sku-based-lookup';
    }
}

/* -------------------------------------------------------------------------
 * DV-DB-014 Fixtures para Lifecycle + Cascade + OrphanRemoval feature test.
 * ------------------------------------------------------------------------- */

#[Entity]
#[Table(name: 'orm_cascade_posts')]
final class OrmCascadePost
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $title;

    #[Column]
    public bool $published;

    #[Column(name: 'created_by')]
    public ?string $createdBy = null;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime_immutable')]
    public ?DateTimeImmutable $updatedAt = null;

    /** @var list<OrmCascadeComment> */
    #[OneToMany(
        targetEntity: OrmCascadeComment::class,
        mappedBy: 'post',
        cascade: [Cascade::PERSIST, Cascade::REMOVE],
        orphanRemoval: true,
    )]
    public array $comments = [];

    #[PrePersist]
    public function stampCreatedAt(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new DateTimeImmutable();
        }
    }
}

#[Entity]
#[Table(name: 'orm_cascade_comments')]
final class OrmCascadeComment
{
    #[Id]
    public ?int $id = null;

    #[Column(name: 'post_id')]
    public int $postId;

    #[Column]
    public string $body;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    public ?DateTimeImmutable $createdAt = null;

    #[ManyToOne(
        targetEntity: OrmCascadePost::class,
        inversedBy: 'comments',
        cascade: [Cascade::PERSIST],
    )]
    public ?OrmCascadePost $post = null;

    #[PrePersist]
    public function stampCreatedAt(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new DateTimeImmutable();
        }
    }
}

#[Entity(lifecycleListeners: [OrmLifecyclePostListener::class])]
#[Table(name: 'orm_timestamped_posts')]
final class OrmLifecyclePost
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $title;

    #[Column(name: 'updated_by')]
    public ?string $updatedBy = null;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime_immutable')]
    public ?DateTimeImmutable $updatedAt = null;

    #[PrePersist]
    public function stampCreatedAt(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new DateTimeImmutable();
        }
    }
}

final class OrmLifecyclePostListener extends AbstractEntityLifecycleListener
{
    /**
     * @var array<string, array{entity:object, em:object, context:array<string, mixed>}>
     */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function prePersist(object $entity, object $entityManager, array $context = []): void
    {
        self::record('prePersist', $entity, $entityManager, $context);
        if ($entity instanceof OrmLifecyclePost) {
            $entity->updatedAt = new DateTimeImmutable();
            $entity->updatedBy = 'listener@example.test';
        }
    }

    public function postPersist(object $entity, object $entityManager, array $context = []): void
    {
        self::record('postPersist', $entity, $entityManager, $context);
    }

    public function preUpdate(object $entity, object $entityManager, array $context = []): void
    {
        self::record('preUpdate', $entity, $entityManager, $context);
        if ($entity instanceof OrmLifecyclePost) {
            $entity->updatedAt = new DateTimeImmutable();
            $entity->updatedBy = 'listener@example.test';
        }
    }

    public function postUpdate(object $entity, object $entityManager, array $context = []): void
    {
        self::record('postUpdate', $entity, $entityManager, $context);
    }

    public function preRemove(object $entity, object $entityManager, array $context = []): void
    {
        self::record('preRemove', $entity, $entityManager, $context);
    }

    public function postRemove(object $entity, object $entityManager, array $context = []): void
    {
        self::record('postRemove', $entity, $entityManager, $context);
    }

    public function postLoad(object $entity, object $entityManager, array $context = []): void
    {
        self::record('postLoad', $entity, $entityManager, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function record(string $event, object $entity, object $entityManager, array $context): void
    {
        self::$calls[$event] = [
            'entity'  => $entity,
            'em'      => $entityManager,
            'context' => $context,
        ];
    }
}

#[Entity]
#[Table(name: 'orm_account_users')]
final class OrmAccountUser
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[OneToOne(targetEntity: OrmUserProfile::class, mappedBy: 'user')]
    public ?OrmUserProfile $profile = null;
}

#[Entity]
#[Table(name: 'orm_user_profiles')]
final class OrmUserProfile
{
    #[Id]
    public ?int $id = null;

    #[Column(name: 'user_id')]
    public ?int $userId = null;

    #[Column]
    public string $bio;

    #[OneToOne(targetEntity: OrmAccountUser::class, inversedBy: 'profile')]
    public ?OrmAccountUser $user = null;
}

#[Entity]
#[Table(name: 'orm_students')]
final class OrmStudent
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    /** @var list<OrmCourse> */
    #[ManyToMany(
        targetEntity: OrmCourse::class,
        inversedBy: 'students',
        cascade: [Cascade::PERSIST],
    )]
    #[JoinTable(
        name: 'orm_student_courses',
        joinColumns: ['student_id'],
        inverseJoinColumns: ['course_id'],
    )]
    public array $courses = [];
}

#[Entity]
#[Table(name: 'orm_courses')]
final class OrmCourse
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $title;

    /** @var list<OrmStudent> */
    #[ManyToMany(targetEntity: OrmStudent::class, mappedBy: 'courses')]
    public array $students = [];
}

#[Entity]
#[Table(name: 'orm_fetch_authors')]
final class OrmFetchAuthor
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    /** @var list<OrmFetchBook> */
    #[OneToMany(targetEntity: OrmFetchBook::class, mappedBy: 'author', fetch: 'eager')]
    public array $books = [];
}

#[Entity]
#[Table(name: 'orm_fetch_publishers')]
final class OrmFetchPublisher
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;
}

#[Entity]
#[Table(name: 'orm_fetch_books')]
final class OrmFetchBook
{
    #[Id]
    public ?int $id = null;

    #[Column(name: 'author_id')]
    public int $authorId;

    #[Column(name: 'publisher_id')]
    public int $publisherId;

    #[Column]
    public string $title;

    #[ManyToOne(targetEntity: OrmFetchAuthor::class, inversedBy: 'books', fetch: 'eager')]
    public ?OrmFetchAuthor $author = null;

    #[ManyToOne(targetEntity: OrmFetchPublisher::class, fetch: 'eager')]
    public ?OrmFetchPublisher $publisher = null;
}

<?php

namespace tests\unit;

use app\models\db\Album;
use app\models\db\Photo;
use app\models\db\User;
use app\models\repository\PermissionRepository;
use app\models\repository\RoleRepository;
use app\models\service\AccessControlService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use Yii;
use yii\web\ForbiddenHttpException;

class AccessControlServiceTest extends Unit
{
    private AccessControlService $service;
    private PermissionRepository $permissionsMock;
    private RoleRepository $rolesMock;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionsMock = $this->createMock(PermissionRepository::class);
        $this->rolesMock = $this->createMock(RoleRepository::class);
        $this->service = new AccessControlService($this->permissionsMock, $this->rolesMock);
    }

    protected function tearDown(): void
    {
        Yii::$app->user->setIdentity(null);
        parent::tearDown();
    }

    // ==================== can ====================

    public function testCanReturnsTrueForRoleGrantedPermission(): void
    {
        $this->actAs(7, ['photo.delete.any']);

        $this->assertTrue($this->service->can('photo.delete.any'));
        $this->assertFalse($this->service->can('role.manage'));
    }

    public function testGuestHasNoPermissions(): void
    {
        $this->assertFalse($this->service->can('photo.delete.any'));
        $this->assertSame([], $this->service->getPermissions());
        $this->assertSame([], $this->service->getRoles());
    }

    // ==================== canOn ====================

    /**
     * An `.any` permission wins regardless of ownership, and grants exactly
     * its own ability — deleting without viewing is possible.
     */
    public function testCanOnAllowsAnyPermissionOnForeignRecord(): void
    {
        $this->actAs(7, ['photo.delete.any']);
        $photo = $this->photoOwnedBy(99);

        $this->assertTrue($this->service->canOn('photo.delete', $photo));
        $this->assertFalse($this->service->canOn('photo.view', $photo));
    }

    /**
     * Ownership grants the base abilities implicitly — no role needed.
     */
    public function testCanOnAllowsOwnerWithoutAnyRole(): void
    {
        $this->actAs(7, []);

        $album = new Album();
        $album->user_id = 7;

        $this->assertTrue($this->service->canOn('album.view', $album));
        $this->assertTrue($this->service->canOn('album.update', $album));
        $this->assertTrue($this->service->canOn('album.delete', $album));
        $this->assertTrue($this->service->canOn('photo.create', $album));
    }

    public function testCanOnDeniesStranger(): void
    {
        $this->actAs(7, []);

        $album = new Album();
        $album->user_id = 99;

        $this->assertFalse($this->service->canOn('album.view', $album));
        $this->assertFalse($this->service->canOn('album.update', $album));
    }

    /**
     * A photo belongs to whoever owns its album.
     */
    public function testCanOnResolvesPhotoOwnershipThroughAlbum(): void
    {
        $this->actAs(7, []);

        $this->assertTrue($this->service->canOn('photo.update', $this->photoOwnedBy(7)));
        $this->assertFalse($this->service->canOn('photo.update', $this->photoOwnedBy(99)));
    }

    /**
     * Owning your account lets you update it, but never view it through the
     * admin endpoint or delete it — those abilities are deliberately not
     * ownership-granted.
     */
    public function testOwnershipDoesNotGrantUserViewOrDeletion(): void
    {
        $this->actAs(7, []);

        $self = new User();
        $self->id = 7;

        $this->assertTrue($this->service->canOn('user.update', $self));
        $this->assertFalse($this->service->canOn('user.view', $self));
        $this->assertFalse($this->service->canOn('user.delete', $self));
    }

    // ==================== require* ====================

    public function testRequirePermissionThrowsWhenMissing(): void
    {
        $this->actAs(7, []);

        $this->expectException(ForbiddenHttpException::class);
        $this->service->requirePermission('role.manage');
    }

    public function testRequireOnThrowsForStranger(): void
    {
        $this->actAs(7, []);

        $album = new Album();
        $album->user_id = 99;

        $this->expectException(ForbiddenHttpException::class);
        $this->service->requireOn('album.update', $album);
    }

    // ==================== roles / permissions ====================

    public function testGetRolesReturnsRoleNames(): void
    {
        $this->actAs(7, [], ['moderator']);

        $this->assertSame(['moderator'], $this->service->getRoles());
    }

    // ==================== helpers ====================

    private function actAs(int $userId, array $permissions, array $roles = []): void
    {
        $user = new User();
        $user->id = $userId;
        Yii::$app->user->setIdentity($user);

        $this->permissionsMock->method('namesByUser')->with($userId)->willReturn($permissions);
        $this->rolesMock->method('namesByUser')->with($userId)->willReturn($roles);
    }

    private function photoOwnedBy(int $userId): Photo
    {
        $album = new Album();
        $album->user_id = $userId;

        $photo = new Photo();
        $photo->populateRelation('album', $album);

        return $photo;
    }
}
